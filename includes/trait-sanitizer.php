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

    /**
     * Validate and sanitise raw script content without security-gate checks.
     *
     * Runs the same content checks as {@see sanitize_script_for()} — length
     * cap, control characters, PHP-tag detection, dangerous HTML detection,
     * and script-tag stripping — but omits the capability, nonce, and
     * rate-limit gates.  Used by the network admin save handler, which
     * performs its own capability and nonce verification before calling this.
     *
     * @since  1.2.1
     * @access private
     * @param  string $input    Raw script content.
     * @param  string $location `'head'` or `'footer'`.
     * @return string Sanitised content, or the existing stored value on failure.
     */
    private function validate_inline_script( $input, $location ) {
        $option_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_SCRIPT : SCRIPTOMATIC_HEAD_SCRIPT;
        $fallback   = is_network_admin()
            ? get_site_option( $option_key, '' )
            : get_option( $option_key, '' );

        if ( ! is_string( $input ) ) {
            return $fallback;
        }

        $input = wp_kses_no_null( str_replace( "\r\n", "\n", wp_unslash( $input ) ) );

        $validated = wp_check_invalid_utf8( $input, true );
        if ( '' === $validated && '' !== $input ) {
            return $fallback;
        }
        $input = $validated;

        if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $input ) ) {
            return $fallback;
        }

        if ( preg_match( '/<\?(php|=)?/i', $input ) ) {
            return $fallback;
        }

        if ( preg_match( '/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', $input ) ) {
            $input = preg_replace( '/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', '$1', $input );
        }

        if ( strlen( $input ) > SCRIPTOMATIC_MAX_SCRIPT_LENGTH ) {
            return $fallback;
        }

        return trim( $input );
    }

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

        // Gate 2: Rate limiter.
        if ( $this->is_rate_limited( $location ) ) {
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

        $this->log_change( $input, $option_key, $location );
        $this->push_history( $input, $location );

        $this->record_save_timestamp( $location );

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
     * Core URL sanitisation logic shared by head and footer linked-script fields.
     *
     * @since  1.2.0
     * @access private
     * @param  mixed  $input    Raw value (expected JSON string).
     * @param  string $location `'head'` or `'footer'`.
     * @return string JSON-encoded array of valid URLs.
     */
    private function sanitize_linked_for( $input, $location ) {
        $option_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_LINKED : SCRIPTOMATIC_HEAD_LINKED;

        if ( empty( $input ) ) {
            return '[]';
        }

        $decoded = json_decode( wp_unslash( $input ), true );
        if ( ! is_array( $decoded ) ) {
            return is_network_admin()
                ? get_site_option( $option_key, '[]' )
                : get_option( $option_key, '[]' );
        }

        $clean = array();
        foreach ( $decoded as $url ) {
            $url = esc_url_raw( trim( (string) $url ) );
            if ( ! empty( $url ) && preg_match( '/^https?:\/\//i', $url ) ) {
                $clean[] = $url;
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
        $option_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_CONDITIONS : SCRIPTOMATIC_HEAD_CONDITIONS;
        $default    = wp_json_encode( array( 'type' => 'all', 'values' => array() ) );

        if ( empty( $input ) ) {
            return $default;
        }

        $decoded = json_decode( wp_unslash( $input ), true );
        if ( ! is_array( $decoded ) ) {
            return is_network_admin()
                ? get_site_option( $option_key, $default )
                : get_option( $option_key, $default );
        }

        $allowed_types = array( 'all', 'front_page', 'singular', 'post_type', 'page_id', 'url_contains', 'logged_in', 'logged_out' );
        $type          = ( isset( $decoded['type'] ) && in_array( $decoded['type'], $allowed_types, true ) )
                         ? $decoded['type'] : 'all';
        $raw_values    = ( isset( $decoded['values'] ) && is_array( $decoded['values'] ) ) ? $decoded['values'] : array();

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
                // all / front_page / singular / logged_in / logged_out: no values needed.
                break;
        }

        return wp_json_encode( array( 'type' => $type, 'values' => $clean_values ) );
    }
}
