<?php
/**
 * Trait: Input sanitisation and validation for Scriptomatic.
 *
 * Covers inline-script validation, linked-URL sanitisation, load-conditions
 * sanitisation, and the per-user save rate limiter.
 *
 * @package  Scriptomatic
 * @since    1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * All sanitise/validate logic for scripts, linked URLs, and load conditions.
 */
trait Scriptomatic_Sanitizer {

    // =========================================================================
    // SCRIPT SANITISATION
    // =========================================================================

    /**
     * Sanitise raw head-script content submitted from the Head Scripts form.
     *
     * @since  1.2.0
     * @param  mixed $input Raw value from the settings form.
     * @return string Sanitised script, or the previously-stored value on failure.
     */
    public function sanitize_head_script( $input ) {
        return $this->sanitize_script_for( $input, 'head' );
    }

    /**
     * Sanitise raw footer-script content submitted from the Footer Scripts form.
     *
     * @since  1.2.0
     * @param  mixed $input Raw value from the settings form.
     * @return string Sanitised script, or the previously-stored value on failure.
     */
    public function sanitize_footer_script( $input ) {
        return $this->sanitize_script_for( $input, 'footer' );
    }

    /**
     * Core sanitise-and-validate logic shared by head and footer script inputs.
     *
     * Security gates (executed in order before any content validation):
     *
     * 1. **Capability check** — aborts if the current user does not hold
     *    `manage_options`.  Defence-in-depth measure.
     *
     * 2. **Secondary nonce** — a short-lived, location-specific nonce
     *    (distinct from the Settings API nonce) verifies that the POST
     *    originated from the correct page of our own admin UI.
     *
     * 3. **Per-user rate limiter** — a transient keyed to the current user
     *    blocks rapid-fire save attempts.
     *
     * Content validation gates:
     * UTF-8 validity, control characters, PHP tags, script-tag stripping,
     * length cap, and dangerous-element detection.
     *
     * @since  1.2.0
     * @access private
     * @param  mixed  $input    Raw value submitted from the settings form.
     * @param  string $location `'head'` or `'footer'`.
     * @return string Sanitised content, or the previously-stored value on any failure.
     */
    private function sanitize_script_for( $input, $location ) {
        // WordPress Settings API can invoke the sanitize callback twice per
        // request (once for comparison, once to persist). Track which locations
        // have already been processed in this request so the second call skips
        // the rate limiter and duplicate log/history recording.
        static $processed_this_request = array();

        $option_key       = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_SCRIPT : SCRIPTOMATIC_HEAD_SCRIPT;
        $previous_content = get_option( $option_key, '' );
        $nonce_action     = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_NONCE : SCRIPTOMATIC_HEAD_NONCE;
        $nonce_field      = ( 'footer' === $location ) ? 'scriptomatic_footer_nonce' : 'scriptomatic_save_nonce';
        $error_slug       = 'scriptomatic_' . $location . '_script';

        // Gate 0: Capability.
        if ( ! current_user_can( $this->get_required_cap() ) ) {
            return $previous_content;
        }

        // Gate 1: Secondary nonce.
        $secondary_nonce = isset( $_POST[ $nonce_field ] )
            ? sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) )
            : '';
        if ( ! wp_verify_nonce( $secondary_nonce, $nonce_action ) ) {
            add_settings_error( $error_slug, 'nonce_invalid',
                __( 'Security check failed. Please refresh the page and try again.', 'scriptomatic' ), 'error' );
            return $previous_content;
        }

        // Gate 2: Rate limiter — skipped on the second invocation within the
        // same request (Settings API double-call) to avoid false positives.
        $already_processed = isset( $processed_this_request[ $location ] );
        if ( ! $already_processed && $this->is_rate_limited( $location ) ) {
            add_settings_error( $error_slug, 'rate_limited',
                sprintf(
                    /* translators: %d: seconds to wait */
                    __( 'You are saving too quickly. Please wait %d seconds before trying again.', 'scriptomatic' ),
                    SCRIPTOMATIC_RATE_LIMIT_SECONDS
                ), 'error' );
            return $previous_content;
        }

        if ( ! is_string( $input ) ) {
            add_settings_error( $error_slug, 'invalid_type',
                __( 'Script content must be plain text.', 'scriptomatic' ), 'error' );
            return $previous_content;
        }

        $input = wp_unslash( $input );
        $input = wp_kses_no_null( $input );
        $input = str_replace( "\r\n", "\n", $input );

        $validated_input = wp_check_invalid_utf8( $input, true );
        if ( '' === $validated_input && '' !== $input ) {
            add_settings_error( $error_slug, 'invalid_utf8',
                __( 'Script content contains invalid UTF-8 characters.', 'scriptomatic' ), 'error' );
            return $previous_content;
        }
        $input = $validated_input;

        if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $input ) ) {
            add_settings_error( $error_slug, 'control_characters_detected',
                __( 'Script content contains disallowed control characters.', 'scriptomatic' ), 'error' );
            return $previous_content;
        }

        if ( preg_match( '/<\?(php|=)?/i', $input ) ) {
            add_settings_error( $error_slug, 'php_tags_detected',
                __( 'PHP tags are not allowed in script content.', 'scriptomatic' ), 'error' );
            return $previous_content;
        }

        if ( preg_match( '/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', $input ) ) {
            $input = preg_replace( '/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', '$1', $input );
            add_settings_error( $error_slug, 'script_tags_removed',
                __( 'Script tags were removed automatically. Enter JavaScript only.', 'scriptomatic' ), 'warning' );
        }

        if ( strlen( $input ) > SCRIPTOMATIC_MAX_SCRIPT_LENGTH ) {
            add_settings_error( $error_slug, 'script_too_long',
                sprintf(
                    /* translators: %s: maximum script length in characters */
                    __( 'Script content exceeds maximum length of %s characters.', 'scriptomatic' ),
                    number_format( SCRIPTOMATIC_MAX_SCRIPT_LENGTH )
                ), 'error' );
            return $previous_content;
        }

        foreach ( array( '/<\s*iframe/i', '/<\s*object/i', '/<\s*embed/i', '/<\s*link/i', '/<\s*style/i', '/<\s*meta/i' ) as $pattern ) {
            if ( preg_match( $pattern, $input ) ) {
                add_settings_error( $error_slug, 'dangerous_content',
                    __( 'Script content contains potentially dangerous HTML tags. Please use JavaScript only.', 'scriptomatic' ), 'warning' );
            }
        }

        $input = trim( $input );

        // Only log, record history, and stamp the rate-limit transient once per
        // request — guards against the Settings API double-call.
        if ( ! $already_processed ) {
            // Contribute the script snapshot to the combined pending-save entry.
            // The entry is flushed once at shutdown after all three callbacks
            // (script, urls, conditions) have contributed their pieces.
            $old_content  = get_option( $option_key, '' );
            $pending_data = array(
                'content' => $input,
                'chars'   => strlen( $input ),
            );
            if ( $old_content !== $input ) {
                $pending_data['script_changed'] = true;
            }
            $this->contribute_to_pending_save( $location, $pending_data );
            $this->record_save_timestamp( $location );
            $processed_this_request[ $location ] = true;
            add_settings_error(
                $error_slug,
                'settings_saved',
                __( 'Settings saved.', 'scriptomatic' ),
                'updated'
            );
        }

        return $input;
    }

    // =========================================================================
    // RATE LIMITER
    // =========================================================================

    /**
     * Determine whether the current user has exceeded the configured save rate.
     *
     * @since  1.2.0
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return bool
     */
    private function is_rate_limited( $location = 'head' ) {
        $user_id       = get_current_user_id();
        $transient_key = 'scriptomatic_save_' . $location . '_' . $user_id;
        return ( false !== get_transient( $transient_key ) );
    }

    /**
     * Record a successful save timestamp for the rate limiter.
     *
     * @since  1.2.0
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return void
     */
    private function record_save_timestamp( $location = 'head' ) {
        $user_id       = get_current_user_id();
        $transient_key = 'scriptomatic_save_' . $location . '_' . $user_id;
        set_transient( $transient_key, time(), SCRIPTOMATIC_RATE_LIMIT_SECONDS );
    }

    // =========================================================================
    // LINKED-URL SANITISATION
    // =========================================================================

    /**
     * Sanitise linked-script URLs for the head location.
     *
     * @since  1.2.0
     * @param  mixed $input Raw JSON string from the form.
     * @return string JSON-encoded array of sanitised URLs.
     */
    public function sanitize_head_linked( $input ) {
        return $this->sanitize_linked_for( $input, 'head' );
    }

    /**
     * Sanitise linked-script URLs for the footer location.
     *
     * @since  1.2.0
     * @param  mixed $input Raw JSON string from the form.
     * @return string JSON-encoded array of sanitised URLs.
     */
    public function sanitize_footer_linked( $input ) {
        return $this->sanitize_linked_for( $input, 'footer' );
    }

    /**
     * Sanitise a decoded load-conditions array.
     *
     * Shared by {@see sanitize_conditions_for()} (inline-script conditions) and
     * {@see sanitize_linked_for()} (per-URL conditions embedded in every entry).
     *
     * @since  1.0.0
     * @access private
     * @param  array $raw Decoded conditions array ({logic, rules} format).
     * @return array      Sanitised {logic, rules} array.
     */
    private function sanitize_conditions_array( array $raw ) {
        $logic     = ( isset( $raw['logic'] ) && 'or' === $raw['logic'] ) ? 'or' : 'and';
        $raw_rules = ( isset( $raw['rules'] ) && is_array( $raw['rules'] ) ) ? $raw['rules'] : array();

        $clean_rules = array();
        foreach ( $raw_rules as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }
            $clean = $this->sanitize_single_rule( $rule );
            if ( null !== $clean ) {
                $clean_rules[] = $clean;
            }
        }

        return array( 'logic' => $logic, 'rules' => $clean_rules );
    }

    /**
     * Sanitise a single {type, values} condition rule object.
     *
     * @since  1.11.0
     * @access private
     * @param  array $raw Raw {type, values} rule.
     * @return array|null Sanitised rule, or null if the type is unrecognised.
     */
    private function sanitize_single_rule( array $raw ) {
        $allowed_types = array(
            'front_page', 'singular', 'post_type', 'page_id', 'url_contains',
            'logged_in', 'logged_out', 'by_date', 'by_datetime', 'week_number', 'by_month',
        );
        $type = ( isset( $raw['type'] ) && in_array( $raw['type'], $allowed_types, true ) )
                ? $raw['type'] : null;
        if ( null === $type ) {
            return null;
        }

        $raw_values   = ( isset( $raw['values'] ) && is_array( $raw['values'] ) ) ? $raw['values'] : array();
        $clean_values = array();

        switch ( $type ) {
            case 'post_type':
                foreach ( $raw_values as $pt ) {
                    $pt = sanitize_key( (string) $pt );
                    if ( '' !== $pt && post_type_exists( $pt ) ) {
                        $clean_values[] = $pt;
                    }
                }
                break;

            case 'page_id':
                foreach ( $raw_values as $id ) {
                    $id = absint( $id );
                    if ( $id > 0 ) {
                        $clean_values[] = $id;
                    }
                }
                break;

            case 'url_contains':
                foreach ( $raw_values as $pattern ) {
                    $pattern = sanitize_text_field( wp_unslash( (string) $pattern ) );
                    if ( '' !== $pattern ) {
                        $clean_values[] = $pattern;
                    }
                }
                break;

            case 'by_date':
            case 'by_datetime':
                foreach ( $raw_values as $i => $v ) {
                    if ( $i >= 2 ) {
                        break;
                    }
                    $v = sanitize_text_field( wp_unslash( (string) $v ) );
                    if ( '' !== $v ) {
                        $clean_values[] = $v;
                    }
                }
                break;

            case 'week_number':
                foreach ( $raw_values as $w ) {
                    $w = absint( $w );
                    if ( $w >= 1 && $w <= 53 ) {
                        $clean_values[] = $w;
                    }
                }
                break;

            case 'by_month':
                foreach ( $raw_values as $m ) {
                    $m = absint( $m );
                    if ( $m >= 1 && $m <= 12 ) {
                        $clean_values[] = $m;
                    }
                }
                break;

            default:
                break;
        }

        return array( 'type' => $type, 'values' => $clean_values );
    }

    /**
     * Core sanitisation logic for linked-script entries with per-URL conditions.
     *
     * Validates each URL and sanitises each entry's conditions object via
     * {@see sanitize_conditions_array()}.
     *
     * Diffs the incoming URL list against the stored list and writes an audit
     * log entry for every URL that was added or removed.
     *
     * @since  1.0.0
     * @access private
     * @param  mixed  $input    Raw value (expected JSON string).
     * @param  string $location `'head'` or `'footer'`.
     * @return string JSON-encoded array of `{url, conditions}` objects.
     */
    private function sanitize_linked_for( $input, $location ) {
        // Guard against the Settings API double-call (same as sanitize_script_for).
        static $logged_this_request = array();

        $option_key   = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_LINKED : SCRIPTOMATIC_HEAD_LINKED;
        $nonce_action = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_NONCE  : SCRIPTOMATIC_HEAD_NONCE;
        $nonce_field  = ( 'footer' === $location ) ? 'scriptomatic_footer_nonce' : 'scriptomatic_save_nonce';

        // Gate 0: Capability.
        if ( ! current_user_can( $this->get_required_cap() ) ) {
            return get_option( $option_key, '[]' );
        }

        // Gate 1: Secondary nonce (present on Settings API form submissions).
        if ( isset( $_POST[ $nonce_field ] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) );
            if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
                return get_option( $option_key, '[]' );
            }
        }

        if ( empty( $input ) ) {
            return '[]';
        }

        $decoded = json_decode( wp_unslash( $input ), true );
        if ( ! is_array( $decoded ) ) {
            return get_option( $option_key, '[]' );
        }

        $clean = array();
        foreach ( $decoded as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $url = isset( $entry['url'] ) ? esc_url_raw( trim( (string) $entry['url'] ) ) : '';
            if ( empty( $url ) || ! preg_match( '/^https?:\/\//i', $url ) ) {
                continue;
            }

            $raw_cond = ( isset( $entry['conditions'] ) && is_array( $entry['conditions'] ) )
                        ? $entry['conditions']
                        : array();

            $clean[] = array(
                'url'        => $url,
                'conditions' => $this->sanitize_conditions_array( $raw_cond ),
            );
        }

        // Diff old vs new URL lists and audit-log each addition and removal.
        // Skipped on the second invocation within the same request.
        if ( ! isset( $logged_this_request[ $location ] ) ) {
            $logged_this_request[ $location ] = true;

            $old_raw     = get_option( $option_key, '[]' );
            $old_decoded = json_decode( $old_raw, true );
            $old_urls    = array();
            if ( is_array( $old_decoded ) ) {
                foreach ( $old_decoded as $e ) {
                    $u = isset( $e['url'] ) ? (string) $e['url'] : '';
                    if ( '' !== $u ) {
                        $old_urls[] = $u;
                    }
                }
            }

            $new_urls = array_column( $clean, 'url' );

            $new_urls_json = wp_json_encode( $clean );

            $added_urls   = array_diff( $new_urls, $old_urls );
            $removed_urls = array_diff( $old_urls, $new_urls );

            // Contribute the URL snapshot and change counts to the pending-save
            // accumulator so a separate URL dataset entry can be written at shutdown.
            $pending_data = array( 'urls_snapshot' => $new_urls_json );
            if ( $old_raw !== $new_urls_json ) {
                // Any change to the list including per-URL conditions.
                $pending_data['url_list_changed'] = true;
            }
            if ( count( $added_urls ) > 0 ) {
                $pending_data['urls_added'] = count( $added_urls );
            }
            if ( count( $removed_urls ) > 0 ) {
                $pending_data['urls_removed'] = count( $removed_urls );
            }
            $this->contribute_to_pending_save( $location, $pending_data );
        }

        return wp_json_encode( $clean );
    }

    // =========================================================================
    // CONDITIONS SANITISATION
    // =========================================================================

    /**
     * Sanitise load-conditions JSON for the head location.
     *
     * @since  1.3.0
     * @param  mixed $input Raw JSON string from the form.
     * @return string JSON-encoded conditions object.
     */
    public function sanitize_head_conditions( $input ) {
        return $this->sanitize_conditions_for( $input, 'head' );
    }

    /**
     * Sanitise load-conditions JSON for the footer location.
     *
     * @since  1.3.0
     * @param  mixed $input Raw JSON string from the form.
     * @return string JSON-encoded conditions object.
     */
    public function sanitize_footer_conditions( $input ) {
        return $this->sanitize_conditions_for( $input, 'footer' );
    }

    /**
     * Core conditions sanitise logic shared by head and footer.
     *
     * Only whitelisted condition types are accepted; values are sanitised
     * per-type (post-type slugs, integer IDs, or plain-text URL substrings).
     *
     * @since  1.3.0
     * @access private
     * @param  mixed  $input    Raw JSON string.
     * @param  string $location `'head'` or `'footer'`.
     * @return string JSON-encoded array: `{type: string, values: array}`.
     */
    private function sanitize_conditions_for( $input, $location ) {
        // Guard against the Settings API double-call (same as sanitize_script_for / sanitize_linked_for).
        static $logged_this_request = array();

        $option_key   = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_CONDITIONS : SCRIPTOMATIC_HEAD_CONDITIONS;
        $nonce_action = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_NONCE       : SCRIPTOMATIC_HEAD_NONCE;
        $nonce_field  = ( 'footer' === $location ) ? 'scriptomatic_footer_nonce'     : 'scriptomatic_save_nonce';
        $default      = wp_json_encode( array( 'logic' => 'and', 'rules' => array() ) );

        // Gate 0: Capability.
        if ( ! current_user_can( $this->get_required_cap() ) ) {
            return get_option( $option_key, $default );
        }

        // Gate 1: Secondary nonce (present on Settings API form submissions).
        if ( isset( $_POST[ $nonce_field ] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) );
            if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
                return get_option( $option_key, $default );
            }
        }

        if ( empty( $input ) ) {
            return $default;
        }

        $decoded = json_decode( wp_unslash( $input ), true );
        if ( ! is_array( $decoded ) ) {
            return get_option( $option_key, $default );
        }

        $new_conditions = $this->sanitize_conditions_array( $decoded );
        $new_json       = wp_json_encode( $new_conditions );
        $old_json       = get_option( $option_key, $default );

        if ( ! isset( $logged_this_request[ $location ] ) ) {
            $logged_this_request[ $location ] = true;

            // Always contribute the conditions snapshot so the combined entry
            // can fully restore all three fields regardless of what changed.
            $pending_data = array( 'conditions_snapshot' => $new_json );

            if ( $old_json !== $new_json ) {
                // Derive a short human-readable summary for the Changes column.
                $ctype_labels = array(
                    'front_page'   => __( 'Front page only', 'scriptomatic' ),
                    'singular'     => __( 'Any singular post/page', 'scriptomatic' ),
                    'post_type'    => __( 'Specific post types', 'scriptomatic' ),
                    'page_id'      => __( 'Specific page IDs', 'scriptomatic' ),
                    'url_contains' => __( 'URL contains', 'scriptomatic' ),
                    'logged_in'    => __( 'Logged-in users only', 'scriptomatic' ),
                    'logged_out'   => __( 'Logged-out visitors only', 'scriptomatic' ),
                    'by_date'      => __( 'Date range', 'scriptomatic' ),
                    'by_datetime'  => __( 'Date & time range', 'scriptomatic' ),
                    'week_number'  => __( 'Specific week numbers', 'scriptomatic' ),
                    'by_month'     => __( 'Specific months', 'scriptomatic' ),
                );
                if ( isset( $new_conditions['rules'] ) && is_array( $new_conditions['rules'] ) ) {
                    $stack_rules  = $new_conditions['rules'];
                    $stack_logic  = isset( $new_conditions['logic'] ) ? strtoupper( $new_conditions['logic'] ) : 'AND';
                } else {
                    $stack_rules = array();
                    $stack_logic = 'AND';
                }
                $rcount = count( $stack_rules );
                if ( 0 === $rcount ) {
                    $cond_detail = __( 'All pages', 'scriptomatic' );
                } elseif ( 1 === $rcount ) {
                    $rt          = isset( $stack_rules[0]['type'] ) ? $stack_rules[0]['type'] : '';
                    $cond_detail = isset( $ctype_labels[ $rt ] ) ? $ctype_labels[ $rt ] : $rt;
                } else {
                    $cond_detail = sprintf( '%d rules (%s)', $rcount, $stack_logic );
                }

                $pending_data['conditions_changed'] = true;
                $pending_data['conditions_detail']  = $cond_detail;
            }

            $this->contribute_to_pending_save( $location, $pending_data );
        }

        return $new_json;
    }

    // =========================================================================
    // COMBINED SAVE ENTRY ACCUMULATOR
    // =========================================================================

    /**
     * Accumulate data from each Settings API sanitize callback into a single
     * pending-save record, then flush it as one combined log entry at shutdown.
     *
     * The WordPress Settings API fires a separate sanitize callback for every
     * registered option in the group. For a Head/Footer Save click that means
     * three separate invocations: script, linked URLs, and conditions. Rather
     * than write three partial entries, each callback contributes its piece
     * here and one combined snapshot entry is written once at shutdown.
     *
     * @since  2.5.0
     * @access private
     * @param  string $location 'head'|'footer'.
     * @param  array  $data     Data fragment to merge into the pending entry.
     * @return void
     */
    private function contribute_to_pending_save( $location, array $data ) {
        static $pending = array();
        static $hooked  = array();

        $pending[ $location ] = isset( $pending[ $location ] )
            ? array_merge( $pending[ $location ], $data )
            : $data;

        if ( empty( $hooked[ $location ] ) ) {
            $hooked[ $location ] = true;

            // Bind the flush closure to $this so it can access private methods.
            $bound = Closure::bind(
                function () use ( $location, &$pending ) {
                    if ( ! empty( $pending[ $location ] ) ) {
                        $this->flush_pending_save_entry( $location, $pending[ $location ] );
                    }
                },
                $this,
                get_class( $this )
            );
            add_action( 'shutdown', $bound );
        }
    }

    /**
     * Write the combined save entry assembled from all three sanitize callbacks.
     *
     * Called once per location at PHP shutdown, after the Settings API has
     * finished invoking all callbacks. Builds the entry from the accumulated
     * data, derives a human-readable "Changes" summary, and persists it via
     * {@see write_activity_entry()}.
     *
     * @since  2.5.0
     * @access private
     * @param  string $location   'head'|'footer'.
     * @param  array  $accumulated Merged data contributed by the three callbacks.
     * @return void
     */
    private function flush_pending_save_entry( $location, array $accumulated ) {
        // === INLINE SCRIPT DATASET ENTRY ===
        // Script content + its load conditions are one dataset. Write one entry
        // only when the script OR its conditions actually changed. Never includes
        // URL list data so each dataset can be viewed and restored independently.
        $inline_changed = ! empty( $accumulated['script_changed'] )
                       || ! empty( $accumulated['conditions_changed'] );

        if ( $inline_changed ) {
            $inline_entry = array(
                'action'   => 'save',
                'location' => $location,
            );

            // Always snapshot the script content so the entry represents a
            // complete, restorable inline dataset even when only conditions
            // changed (in which case the script sanitizer never ran and
            // 'content' was never contributed to $accumulated).
            if ( array_key_exists( 'content', $accumulated ) ) {
                $snap_content = $accumulated['content'];
            } else {
                $script_key   = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_SCRIPT : SCRIPTOMATIC_HEAD_SCRIPT;
                $snap_content = (string) get_option( $script_key, '' );
            }
            $inline_entry['content'] = $snap_content;
            $inline_entry['chars']   = isset( $accumulated['chars'] )
                ? (int) $accumulated['chars']
                : strlen( $snap_content );

            // Snapshot the current inline conditions alongside the script so
            // a single Restore brings back the full inline dataset at once.
            if ( array_key_exists( 'conditions_snapshot', $accumulated ) ) {
                $inline_entry['conditions_snapshot'] = $accumulated['conditions_snapshot'];
            }

            $detail_parts = array();
            if ( ! empty( $accumulated['script_changed'] ) ) {
                $chars          = isset( $inline_entry['chars'] ) ? (int) $inline_entry['chars'] : 0;
                $detail_parts[] = sprintf(
                    /* translators: %s: character count */
                    __( 'Script: %s chars', 'scriptomatic' ),
                    number_format( $chars )
                );
            }
            if ( ! empty( $accumulated['conditions_changed'] ) && ! empty( $accumulated['conditions_detail'] ) ) {
                $detail_parts[] = sprintf(
                    /* translators: %s: conditions summary label */
                    __( 'Conditions: %s', 'scriptomatic' ),
                    $accumulated['conditions_detail']
                );
            }

            $inline_entry['detail'] = implode( ' · ', $detail_parts );
            $this->write_activity_entry( $inline_entry );
        }

        // === URL DATASET ENTRY ===
        // External URL list + per-URL conditions are a separate dataset. Write
        // one entry only when the list actually changed. Never combined with the
        // inline entry so each dataset can be viewed and restored independently.
        if ( ! empty( $accumulated['url_list_changed'] ) && array_key_exists( 'urls_snapshot', $accumulated ) ) {
            $url_entry = array(
                'action'        => 'url_save',
                'location'      => $location,
                'urls_snapshot' => $accumulated['urls_snapshot'],
            );

            $detail_parts = array();
            if ( ! empty( $accumulated['urls_added'] ) ) {
                $n              = (int) $accumulated['urls_added'];
                $detail_parts[] = sprintf(
                    /* translators: %d: number of URLs added */
                    _n( '%d URL added', '%d URLs added', $n, 'scriptomatic' ),
                    $n
                );
            }
            if ( ! empty( $accumulated['urls_removed'] ) ) {
                $n              = (int) $accumulated['urls_removed'];
                $detail_parts[] = sprintf(
                    /* translators: %d: number of URLs removed */
                    _n( '%d URL removed', '%d URLs removed', $n, 'scriptomatic' ),
                    $n
                );
            }
            if ( empty( $detail_parts ) ) {
                // Per-URL conditions changed without adding or removing a URL.
                $detail_parts[] = __( 'URL conditions updated', 'scriptomatic' );
            }

            $url_entry['detail'] = implode( ' · ', $detail_parts );
            $this->write_activity_entry( $url_entry );
        }
    }
}
