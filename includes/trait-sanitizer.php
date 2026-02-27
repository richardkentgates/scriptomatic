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
            $this->log_change( $input, $option_key, $location );
            $this->push_history( $input, $location );
            $this->record_save_timestamp( $location );
            $processed_this_request[ $location ] = true;
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
     * @since  1.6.0
     * @access private
     * @param  array $raw Decoded `{type, values}` array.
     * @return array      Sanitised `{type, values}` array.
     */
    private function sanitize_conditions_array( array $raw ) {
        $allowed_types = array( 'all', 'front_page', 'singular', 'post_type', 'page_id', 'url_contains', 'logged_in', 'logged_out' );
        $type          = ( isset( $raw['type'] ) && in_array( $raw['type'], $allowed_types, true ) )
                         ? $raw['type'] : 'all';
        $raw_values    = ( isset( $raw['values'] ) && is_array( $raw['values'] ) ) ? $raw['values'] : array();

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

            default:
                break;
        }

        return array( 'type' => $type, 'values' => $clean_values );
    }

    /**
     * Core sanitisation logic for linked-script entries with per-URL conditions.
     *
     * Accepts the new `[{url, conditions}]` format or the legacy `["url"]` format
     * (plain strings are automatically migrated). Validates each URL and sanitises
     * each entry's conditions object via {@see sanitize_conditions_array()}.
     *
     * Diffs the incoming URL list against the stored list and writes an audit
     * log entry for every URL that was added or removed.
     *
     * @since  1.2.0 (rewritten 1.6.0)
     * @since  1.7.1 Audit-logs URL additions and removals.
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
            // Migrate legacy plain URL strings to the new {url, conditions} structure.
            if ( is_string( $entry ) ) {
                $url = esc_url_raw( trim( $entry ) );
                if ( ! empty( $url ) && preg_match( '/^https?:\/\//i', $url ) ) {
                    $clean[] = array(
                        'url'        => $url,
                        'conditions' => array( 'type' => 'all', 'values' => array() ),
                    );
                }
                continue;
            }

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
                    // Handle both legacy plain strings and the current {url, conditions} format.
                    $u = is_string( $e ) ? trim( $e ) : ( isset( $e['url'] ) ? (string) $e['url'] : '' );
                    if ( '' !== $u ) {
                        $old_urls[] = $u;
                    }
                }
            }

            $new_urls = array_column( $clean, 'url' );

            foreach ( array_diff( $new_urls, $old_urls ) as $url ) {
                $this->write_audit_log_entry( array(
                    'action'   => 'url_added',
                    'location' => $location,
                    'detail'   => $url,
                ) );
            }

            foreach ( array_diff( $old_urls, $new_urls ) as $url ) {
                $this->write_audit_log_entry( array(
                    'action'   => 'url_removed',
                    'location' => $location,
                    'detail'   => $url,
                ) );
            }
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
        $option_key   = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_CONDITIONS : SCRIPTOMATIC_HEAD_CONDITIONS;
        $nonce_action = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_NONCE       : SCRIPTOMATIC_HEAD_NONCE;
        $nonce_field  = ( 'footer' === $location ) ? 'scriptomatic_footer_nonce'     : 'scriptomatic_save_nonce';
        $default      = wp_json_encode( array( 'type' => 'all', 'values' => array() ) );

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

        return wp_json_encode( $this->sanitize_conditions_array( $decoded ) );
    }
}
