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
    // LOCATION SANITISATION  (v2.8+ unified callbacks)
    // =========================================================================

    /**
     * Settings API sanitise callback for the head location option.
     *
     * @since  2.8.0
     * @param  mixed $input Array posted from the head settings form.
     * @return array Sanitised location data array.
     */
    public function sanitize_head_location( $input ) {
        return $this->sanitize_location_for( $input, 'head' );
    }

    /**
     * Settings API sanitise callback for the footer location option.
     *
     * @since  2.8.0
     * @param  mixed $input Array posted from the footer settings form.
     * @return array Sanitised location data array.
     */
    public function sanitize_footer_location( $input ) {
        return $this->sanitize_location_for( $input, 'footer' );
    }

    /**
     * Core unified sanitise-and-validate logic for a single location option.
     *
     * Receives the entire location array submitted from the settings form
     * (script textarea + conditions JSON hidden input + URLs JSON hidden input),
     * validates each field, and returns a clean PHP array suitable for storage.
     *
     * Security gates (executed in order):
     * 1. Capability check — must hold `manage_options`.
     * 2. Secondary nonce — location-specific nonce from the form page.
     * 3. Per-user rate limiter — transient-based save-throttle.
     *
     * @since  2.8.0
     * @access private
     * @param  mixed  $input    Array submitted from the settings form:
     *                          { script: string, conditions: JSON string, urls: JSON string }
     * @param  string $location 'head'|'footer'.
     * @return array  Sanitised { script: string, conditions: array, urls: array }
     */
    private function sanitize_location_for( $input, $location ) {
        $previous    = $this->get_location( $location );
        $nonce_action = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_NONCE : SCRIPTOMATIC_HEAD_NONCE;
        $nonce_field  = ( 'footer' === $location ) ? 'scriptomatic_footer_nonce' : 'scriptomatic_save_nonce';
        $error_slug   = 'scriptomatic_' . $location . '_script';

        // Gate 0: Capability.
        if ( ! current_user_can( $this->get_required_cap() ) ) {
            return $previous;
        }

        // Gate 1: Secondary nonce.
        $secondary_nonce = isset( $_POST[ $nonce_field ] )
            ? sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) )
            : '';
        if ( ! wp_verify_nonce( $secondary_nonce, $nonce_action ) ) {
            add_settings_error( $error_slug, 'nonce_invalid',
                __( 'Security check failed. Please refresh the page and try again.', 'scriptomatic' ), 'error' );
            return $previous;
        }

        // Gate 2: Rate limiter.
        if ( $this->is_rate_limited( $location ) ) {
            add_settings_error( $error_slug, 'rate_limited',
                sprintf(
                    /* translators: %d: seconds to wait */
                    __( 'You are saving too quickly. Please wait %d seconds before trying again.', 'scriptomatic' ),
                    SCRIPTOMATIC_RATE_LIMIT_SECONDS
                ), 'error' );
            return $previous;
        }

        // ---- Ensure $input is an array ----
        if ( ! is_array( $input ) ) {
            $input = array();
        }

        // =========================================================
        // 1. Inline script
        // =========================================================
        $script_raw = isset( $input['script'] ) ? $input['script'] : '';

        if ( ! is_string( $script_raw ) ) {
            add_settings_error( $error_slug, 'invalid_type',
                __( 'Script content must be plain text.', 'scriptomatic' ), 'error' );
            return $previous;
        }

        $script = wp_unslash( $script_raw );
        $script = wp_kses_no_null( $script );
        $script = str_replace( "\r\n", "\n", $script );

        $validated = wp_check_invalid_utf8( $script, true );
        if ( '' === $validated && '' !== $script ) {
            add_settings_error( $error_slug, 'invalid_utf8',
                __( 'Script content contains invalid UTF-8 characters.', 'scriptomatic' ), 'error' );
            return $previous;
        }
        $script = $validated;

        if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $script ) ) {
            add_settings_error( $error_slug, 'control_characters_detected',
                __( 'Script content contains disallowed control characters.', 'scriptomatic' ), 'error' );
            return $previous;
        }

        if ( preg_match( '/<\?(php|=)?/i', $script ) ) {
            add_settings_error( $error_slug, 'php_tags_detected',
                __( 'PHP tags are not allowed in script content.', 'scriptomatic' ), 'error' );
            return $previous;
        }

        if ( preg_match( '/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', $script ) ) {
            $script = preg_replace( '/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', '$1', $script );
            add_settings_error( $error_slug, 'script_tags_removed',
                __( 'Script tags were removed automatically. Enter JavaScript only.', 'scriptomatic' ), 'warning' );
        }

        if ( strlen( $script ) > SCRIPTOMATIC_MAX_SCRIPT_LENGTH ) {
            add_settings_error( $error_slug, 'script_too_long',
                sprintf(
                    /* translators: %s: maximum script length in characters */
                    __( 'Script content exceeds maximum length of %s characters.', 'scriptomatic' ),
                    number_format( SCRIPTOMATIC_MAX_SCRIPT_LENGTH )
                ), 'error' );
            return $previous;
        }

        foreach ( array( '/<\s*iframe/i', '/<\s*object/i', '/<\s*embed/i', '/<\s*link/i', '/<\s*style/i', '/<\s*meta/i' ) as $pattern ) {
            if ( preg_match( $pattern, $script ) ) {
                add_settings_error( $error_slug, 'dangerous_content',
                    __( 'Script content contains potentially dangerous HTML tags. Please use JavaScript only.', 'scriptomatic' ), 'warning' );
            }
        }

        $script = trim( $script );

        // =========================================================
        // 2. Conditions (JSON string → decoded PHP array)
        // =========================================================
        $cond_raw = isset( $input['conditions'] ) ? $input['conditions'] : '';
        if ( is_string( $cond_raw ) && '' !== $cond_raw ) {
            $decoded_cond = json_decode( wp_unslash( $cond_raw ), true );
        } else {
            $decoded_cond = null;
        }
        if ( ! is_array( $decoded_cond ) ) {
            $decoded_cond = array( 'logic' => 'and', 'rules' => array() );
        }
        $conditions = $this->sanitize_conditions_array( $decoded_cond );

        // =========================================================
        // 3. External URLs (JSON string → decoded PHP array)
        // =========================================================
        $urls_raw = isset( $input['urls'] ) ? $input['urls'] : '';
        if ( is_string( $urls_raw ) && '' !== $urls_raw ) {
            $decoded_urls = json_decode( wp_unslash( $urls_raw ), true );
        } else {
            $decoded_urls = null;
        }
        $urls = $this->sanitize_url_entries( is_array( $decoded_urls ) ? $decoded_urls : array() );

        // =========================================================
        // 4. Log the save and stamp the rate-limit transient
        // =========================================================
        $this->log_location_save( $location, $previous, $script, $conditions, $urls );
        $this->record_save_timestamp( $location );

        add_settings_error( $error_slug, 'settings_saved',
            __( 'Settings saved.', 'scriptomatic' ), 'updated' );

        return array(
            'script'     => $script,
            'conditions' => $conditions,
            'urls'       => $urls,
        );
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
    // URL AND CONDITIONS HELPERS
    // =========================================================================

    /**
     * Sanitise an already-decoded array of URL entry objects.
     *
     * Called by {@see sanitize_location_for()} after JSON-decoding the urls
     * field. Returns a clean PHP array — no JSON encoding.
     *
     * @since  2.8.0
     * @access private
     * @param  array $decoded Raw decoded array of {url, conditions} objects.
     * @return array          Sanitised array of {url, conditions} objects.
     */
    private function sanitize_url_entries( array $decoded ) {
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
        return $clean;
    }

    /**
     * Sanitise a decoded load-conditions array.
     *
     * Shared by {@see sanitize_location_for()} (inline-script conditions) and
     * {@see sanitize_url_entries()} (per-URL conditions embedded in every entry).
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

// =========================================================================
    // ACTIVITY LOG HELPERS
    // =========================================================================

    /**
     * Write the activity log entry (or entries) for a successful location save.
     *
     * Compares the incoming sanitised data against the previously-stored data
     * and writes:
     *  - One 'save' (or 'conditions_save') entry when the inline script OR its
     *    conditions changed.
     *  - One 'url_save' entry when the external URL list changed.
     *
     * Each dataset is recorded independently so they can be viewed and restored
     * independently via the history panel.
     *
     * @since  2.8.0
     * @access private
     * @param  string $location   'head'|'footer'.
     * @param  array  $previous   Previous location data from get_location().
     * @param  string $script     New sanitised script content.
     * @param  array  $conditions New sanitised conditions array.
     * @param  array  $urls       New sanitised URL entries array.
     * @return void
     */
    private function log_location_save( $location, array $previous, $script, array $conditions, array $urls ) {
        $old_script     = isset( $previous['script'] )     ? (string) $previous['script']     : '';
        $old_conditions = isset( $previous['conditions'] ) && is_array( $previous['conditions'] )
                          ? $previous['conditions'] : array( 'logic' => 'and', 'rules' => array() );
        $old_urls       = isset( $previous['urls'] )       && is_array( $previous['urls'] )
                          ? $previous['urls'] : array();

        $script_changed     = ( $old_script !== $script );
        $conditions_changed = ( wp_json_encode( $old_conditions ) !== wp_json_encode( $conditions ) );
        $urls_changed       = ( wp_json_encode( $old_urls ) !== wp_json_encode( $urls ) );

        // ---- Inline script dataset entry ----
        if ( $script_changed || $conditions_changed ) {
            $action = $script_changed ? 'save' : 'conditions_save';

            // When script is cleared, snapshot the OLD content so View shows what
            // was removed and Restore brings it back.
            $snap_content = ( '' === $script && '' !== $old_script ) ? $old_script : $script;

            $detail_parts = array();
            if ( $script_changed ) {
                $detail_parts[] = sprintf(
                    /* translators: %s: character count */
                    __( 'Script: %s chars', 'scriptomatic' ),
                    number_format( strlen( $script ) )
                );
            }
            if ( $conditions_changed ) {
                $detail_parts[] = sprintf(
                    /* translators: %s: conditions summary label */
                    __( 'Conditions: %s', 'scriptomatic' ),
                    $this->conditions_summary( $conditions )
                );
            }

            $this->write_activity_entry( array(
                'action'             => $action,
                'location'           => $location,
                'content'            => $snap_content,
                'chars'              => strlen( $script ),
                'conditions_snapshot' => $conditions,
                'detail'             => implode( ' · ', $detail_parts ),
            ) );
        }

        // ---- URL dataset entry ----
        if ( $urls_changed ) {
            $old_url_list = array_column( $old_urls, 'url' );
            $new_url_list = array_column( $urls, 'url' );
            $added_urls   = array_diff( $new_url_list, $old_url_list );
            $removed_urls = array_diff( $old_url_list, $new_url_list );

            // When URLs are removed (and none added), snapshot OLD list so
            // Restore brings it back.
            $urls_snapshot = ( count( $removed_urls ) > 0 && count( $added_urls ) === 0 )
                ? $old_urls
                : $urls;

            $detail_parts = array();
            if ( count( $added_urls ) > 0 ) {
                $n              = count( $added_urls );
                $detail_parts[] = sprintf(
                    /* translators: %d: number of URLs added */
                    _n( '%d URL added', '%d URLs added', $n, 'scriptomatic' ),
                    $n
                );
            }
            if ( count( $removed_urls ) > 0 ) {
                $n              = count( $removed_urls );
                $detail_parts[] = sprintf(
                    /* translators: %d: number of URLs removed */
                    _n( '%d URL removed', '%d URLs removed', $n, 'scriptomatic' ),
                    $n
                );
            }
            if ( empty( $detail_parts ) ) {
                $detail_parts[] = __( 'URL conditions updated', 'scriptomatic' );
            }

            $this->write_activity_entry( array(
                'action'        => 'url_save',
                'location'      => $location,
                'urls_snapshot' => $urls_snapshot,
                'detail'        => implode( ' · ', $detail_parts ),
            ) );
        }
    }

    /**
     * Build a short human-readable summary of a conditions object.
     *
     * @since  2.8.0
     * @access private
     * @param  array $conditions Sanitised { logic, rules } array.
     * @return string
     */
    private function conditions_summary( array $conditions ) {
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

        $rules  = ( isset( $conditions['rules'] ) && is_array( $conditions['rules'] ) ) ? $conditions['rules'] : array();
        $logic  = isset( $conditions['logic'] ) ? strtoupper( $conditions['logic'] ) : 'AND';
        $rcount = count( $rules );

        if ( 0 === $rcount ) {
            return __( 'All pages', 'scriptomatic' );
        }
        if ( 1 === $rcount ) {
            $rt = isset( $rules[0]['type'] ) ? $rules[0]['type'] : '';
            return isset( $ctype_labels[ $rt ] ) ? $ctype_labels[ $rt ] : $rt;
        }
        return sprintf( '%d rules (%s)', $rcount, $logic );
    }
}
