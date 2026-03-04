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
     * Flags set by sanitize_location_for() to signal on_location_updated().
     *
     * Keyed by location ('head'|'footer'). Only set when sanitize fully
     * accepted the submitted input (i.e., did NOT return early due to a
     * capability or nonce failure). Cleared immediately after
     * on_location_updated() consumes it so subsequent saves start clean.
     *
     * @since 3.1.1
     * @var   bool[]
     */
    private $pending_saves = array();

    // =========================================================================
    // JAVASCRIPT CONTENT SANITISATION
    // =========================================================================

    /**
     * Validate the structural integrity of a JavaScript code string.
     *
     * Walks the content character-by-character using a state machine that
     * correctly skips string literals (single-quoted, double-quoted, template),
     * single-line comments (//), and block comments (/* ... *\/), then checks:
     *
     *   - All bracket / brace / parenthesis pairs are balanced and correctly
     *     nested: {}, [], ()
     *   - No unclosed string literals remain at end-of-input.
     *   - No unclosed block comment remains at end-of-input.
     *   - Nesting depth never exceeds 100 levels (guards against obfuscated /
     *     pathological inputs).
     *
     * Limitation: template literal interpolation expressions `${…}` are treated
     * as opaque content — brackets inside `${}` are not tracked. This is an
     * intentional trade-off to keep the parser simple; the client-side
     * new Function() check (which uses the real JS engine) catches those cases.
     *
     * @since  3.0.0
     * @access private
     * @param  string $content  Clean JavaScript string (already unslashed/trimmed).
     * @return true|WP_Error    true on success; WP_Error describing the first
     *                          structural problem found.
     */
    private function check_js_structure( $content ) {
        if ( '' === $content ) {
            return true;
        }

        $stack     = array(); // tracks unmatched open brackets: '{' '(' '['
        $max_depth = 50;      // reject absurdly deep nesting
        $len       = strlen( $content );
        $i         = 0;
        // States: 'code' | 'sl_comment' | 'ml_comment' | 'sq' | 'dq' | 'tpl'
        $state     = 'code';

        while ( $i < $len ) {
            $ch = $content[ $i ];

            // ---- single-line comment (//) --------------------------------
            if ( 'sl_comment' === $state ) {
                if ( "\n" === $ch ) {
                    $state = 'code';
                }
                $i++;
                continue;
            }

            // ---- block comment (/* … */) ---------------------------------
            if ( 'ml_comment' === $state ) {
                if ( '*' === $ch && $i + 1 < $len && '/' === $content[ $i + 1 ] ) {
                    $state = 'code';
                    $i    += 2;
                } else {
                    $i++;
                }
                continue;
            }

            // ---- single-quoted string '…' --------------------------------
            if ( 'sq' === $state ) {
                if ( '\\' === $ch ) {
                    $i += 2; // skip one escaped character
                } elseif ( "'" === $ch ) {
                    $state = 'code';
                    $i++;
                } else {
                    $i++;
                }
                continue;
            }

            // ---- double-quoted string "…" --------------------------------
            if ( 'dq' === $state ) {
                if ( '\\' === $ch ) {
                    $i += 2;
                } elseif ( '"' === $ch ) {
                    $state = 'code';
                    $i++;
                } else {
                    $i++;
                }
                continue;
            }

            // ---- template literal `…` -----------------------------------
            // ${ … } interpolation is treated as opaque — brackets inside
            // template expressions are not tracked (see docblock).
            if ( 'tpl' === $state ) {
                if ( '\\' === $ch ) {
                    $i += 2;
                } elseif ( '`' === $ch ) {
                    $state = 'code';
                    $i++;
                } else {
                    $i++;
                }
                continue;
            }

            // ---- normal code --------------------------------------------
            // Detect comment openers.
            if ( '/' === $ch && $i + 1 < $len ) {
                if ( '/' === $content[ $i + 1 ] ) {
                    $state = 'sl_comment';
                    $i    += 2;
                    continue;
                }
                if ( '*' === $content[ $i + 1 ] ) {
                    $state = 'ml_comment';
                    $i    += 2;
                    continue;
                }
            }

            // Detect string / template openers.
            if ( "'" === $ch ) { $state = 'sq';  $i++; continue; }
            if ( '"' === $ch ) { $state = 'dq';  $i++; continue; }
            if ( '`' === $ch ) { $state = 'tpl'; $i++; continue; }

            // Bracket tracking.
            if ( '{' === $ch || '(' === $ch || '[' === $ch ) {
                $stack[] = $ch;
                if ( count( $stack ) > $max_depth ) {
                    return new WP_Error(
                        'excessive_nesting',
                        sprintf(
                            /* translators: %d: maximum allowed nesting depth */
                            __( 'Script exceeds the maximum bracket nesting depth of %d. This may indicate obfuscated or malformed code.', 'scriptomatic' ),
                            $max_depth
                        ),
                        array( 'status' => 400 )
                    );
                }
            } elseif ( '}' === $ch || ')' === $ch || ']' === $ch ) {
                if ( empty( $stack ) ) {
                    return new WP_Error(
                        'unmatched_bracket',
                        sprintf(
                            /* translators: %s: the unexpected closing bracket character */
                            __( 'Script contains an unexpected closing bracket "%s" with no matching opener.', 'scriptomatic' ),
                            esc_html( $ch )
                        ),
                        array( 'status' => 400 )
                    );
                }
                $pair   = array( '}' => '{', ')' => '(', ']' => '[' );
                $opener = array_pop( $stack );
                if ( $opener !== $pair[ $ch ] ) {
                    return new WP_Error(
                        'mismatched_bracket',
                        sprintf(
                            /* translators: 1: expected closing bracket, 2: found closing bracket */
                            __( 'Script has mismatched brackets: "%1$s" was opened but "%2$s" was closed.', 'scriptomatic' ),
                            esc_html( $opener ),
                            esc_html( $ch )
                        ),
                        array( 'status' => 400 )
                    );
                }
            }

            $i++;
        } // end while

        // Unclosed string literal.
        if ( in_array( $state, array( 'sq', 'dq', 'tpl' ), true ) ) {
            $names = array( 'sq' => 'single-quote ( \' )', 'dq' => 'double-quote ( " )', 'tpl' => 'template literal ( ` )' );
            return new WP_Error(
                'unclosed_string',
                sprintf(
                    /* translators: %s: the type of string delimiter that was not closed */
                    __( 'Script contains an unclosed string literal: %s was opened but never closed.', 'scriptomatic' ),
                    $names[ $state ]
                ),
                array( 'status' => 400 )
            );
        }

        // Unclosed block comment.
        if ( 'ml_comment' === $state ) {
            return new WP_Error(
                'unclosed_comment',
                __( 'Script contains an unclosed block comment ( /* ) with no closing */ .', 'scriptomatic' ),
                array( 'status' => 400 )
            );
        }

        // Unclosed brackets.
        if ( ! empty( $stack ) ) {
            $pairs   = array( '{' => '{}', '(' => '()', '[' => '[]' );
            $missing = array();
            foreach ( array_reverse( $stack ) as $b ) {
                $missing[] = isset( $pairs[ $b ] ) ? $pairs[ $b ] : $b;
            }
            return new WP_Error(
                'unclosed_bracket',
                sprintf(
                    /* translators: %s: comma-separated list of unmatched bracket pairs */
                    __( 'Script has unclosed brackets still open at end of file: %s', 'scriptomatic' ),
                    implode( ', ', $missing )
                ),
                array( 'status' => 400 )
            );
        }

        return true;
    }

    /**
     * Sanitise raw JavaScript content submitted via POST.
     *
     * Recognised as a sanitisation function by static analysers (sanitize_*
     * naming convention). Applies wp_kses_no_null(), UTF-8 validation, control
     * character filtering, PHP tag rejection, <script> tag stripping, and a
     * length cap. Returns a clean string or empty string on hard failure.
     *
     * @since  3.0.0
     * @param  string $raw Raw value from $_POST.
     * @return string Sanitised JavaScript content.
     */
    public function sanitize_js_content( $raw ) {
        if ( ! is_string( $raw ) ) {
            return '';
        }
        $content = wp_kses_no_null( wp_unslash( $raw ) );
        $content = str_replace( "\r\n", "\n", $content );
        $checked = wp_check_invalid_utf8( $content, true );
        if ( '' === $checked && '' !== $content ) {
            return '';
        }
        $content = $checked;
        if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content ) ) {
            return '';
        }
        if ( preg_match( '/<\?(php|=)?/i', $content ) ) {
            return '';
        }
        $content = preg_replace( '/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', '$1', $content );
        if ( strlen( $content ) > SCRIPTOMATIC_MAX_SCRIPT_LENGTH ) {
            return '';
        }
        return trim( $content );
    }

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

        // Determine whether this call originates from a Settings API form POST
        // (wp-admin/options.php sets option_page) or from a programmatic call
        // such as ajax_rollback() / save_location().  Programmatic calls must
        // skip the form-specific gates (nonce, rate-limit, user notices) and
        // simply sanitize the already-validated data that was passed in.
        $is_form_post = isset( $_POST['option_page'] );  // phpcs:ignore WordPress.Security.NonceVerification

        // Gate 0: Capability (always enforced).
        if ( ! current_user_can( $this->get_required_cap() ) ) {
            return $previous;
        }

        if ( $is_form_post ) {
            // Suppress duplicate 'Settings saved.' notice on WP's second sanitize call.
            // WordPress calls sanitize callbacks twice per POST; the static flag ensures
            // the success notice is only added on the first (real) invocation.
            static $noticed = array();

            $secondary_nonce = isset( $_POST[ $nonce_field ] )
                ? sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) )
                : '';
            if ( ! wp_verify_nonce( $secondary_nonce, $nonce_action ) ) {
                add_settings_error( $error_slug, 'nonce_invalid',
                    __( 'Security check failed. Please refresh the page and try again.', 'scriptomatic' ), 'error' );
                return $previous;
            }

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

        // Structure check: balanced brackets, closed strings and block comments.
        $structure = $this->check_js_structure( $script );
        if ( is_wp_error( $structure ) ) {
            add_settings_error( $error_slug, $structure->get_error_code(),
                $structure->get_error_message(), 'warning' );
        }

        $script = trim( $script );

        // =========================================================
        // 2. Conditions (JSON string or pre-decoded PHP array)
        // =========================================================
        $cond_raw = isset( $input['conditions'] ) ? $input['conditions'] : '';
        if ( is_array( $cond_raw ) ) {
            // Programmatic call (e.g. rollback): conditions already decoded.
            $decoded_cond = $cond_raw;
        } elseif ( is_string( $cond_raw ) && '' !== $cond_raw ) {
            $decoded_cond = json_decode( wp_unslash( $cond_raw ), true );
        } else {
            $decoded_cond = null;
        }
        if ( ! is_array( $decoded_cond ) ) {
            $decoded_cond = array( 'logic' => 'and', 'rules' => array() );
        }
        $conditions = $this->sanitize_conditions_array( $decoded_cond );

        // =========================================================
        // 3. External URLs (JSON string or pre-decoded PHP array)
        // =========================================================
        $urls_raw = isset( $input['urls'] ) ? $input['urls'] : '';
        if ( is_array( $urls_raw ) ) {
            // Programmatic call (e.g. rollback): URLs already decoded.
            $decoded_urls = $urls_raw;
        } elseif ( is_string( $urls_raw ) && '' !== $urls_raw ) {
            $decoded_urls = json_decode( wp_unslash( $urls_raw ), true );
        } else {
            $decoded_urls = null;
        }
        $urls = $this->sanitize_url_entries( is_array( $decoded_urls ) ? $decoded_urls : array() );

        if ( $is_form_post ) {
            // Signal on_location_updated() that this save was accepted.
            $this->pending_saves[ $location ] = true;

            // Only add the success notice on the first invocation (not WP's internal second call).
            if ( ! isset( $noticed[ $location ] ) ) {
                add_settings_error( $error_slug, 'settings_saved',
                    __( 'Settings saved.', 'scriptomatic' ), 'updated' );
                $noticed[ $location ] = true;
            }
        }

        return array(
            'script'     => $script,
            'conditions' => $conditions,
            'urls'       => $urls,
        );
    }

    /**
     * Action callback for updated_option.
     *
     * Fires only after WordPress has successfully written the option to the
     * database, guaranteeing that every log entry corresponds to a real save.
     * The sanitize callback sets the `pending_saves` flag so we can distinguish
     * form POSTs (which should be logged) from programmatic calls like
     * ajax_rollback() (which handle their own logging).
     *
     * @since  3.2.0
     * @param  string $option     Option name.
     * @param  mixed  $old_value  Previously stored value.
     * @param  mixed  $new_value  Newly stored value.
     * @return void
     */
    public function on_location_updated( $option, $old_value, $new_value ) {
        if ( SCRIPTOMATIC_LOCATION_HEAD !== $option && SCRIPTOMATIC_LOCATION_FOOTER !== $option ) {
            return;
        }
        $location = ( SCRIPTOMATIC_LOCATION_FOOTER === $option ) ? 'footer' : 'head';

        // Only log when sanitize accepted the submitted input (form POST path).
        // Nonce failures and capability failures return $previous early without
        // setting this flag, so programmatic saves are never logged here.
        if ( ! isset( $this->pending_saves[ $location ] ) ) {
            return;
        }
        unset( $this->pending_saves[ $location ] );

        $old_script     = isset( $old_value['script'] )     ? $old_value['script']     : '';
        $old_conditions = isset( $old_value['conditions'] ) ? $old_value['conditions'] : array();
        $old_urls       = isset( $old_value['urls'] )       ? $old_value['urls']       : array();
        $previous = array(
            'script'     => $old_script,
            'conditions' => $old_conditions,
            'urls'       => $old_urls,
        );

        $new_script = isset( $new_value['script'] )     ? $new_value['script']     : '';
        $new_conds  = isset( $new_value['conditions'] ) ? $new_value['conditions'] : array();
        $new_urls   = isset( $new_value['urls'] )       ? $new_value['urls']       : array();

        $save_details = $this->log_location_save( $location, $previous, $new_script, $new_conds, $new_urls );
        $notif_parts  = array_filter( array( $save_details['script_detail'], $save_details['url_detail'] ) );
        $notif_detail = ! empty( $notif_parts ) ? implode( "\n", $notif_parts ) : '';
        $this->maybe_send_notifications( array(
            'action'   => __( 'Script saved', 'scriptomatic' ),
            'location' => ucfirst( $location ),
            'detail'   => $notif_detail,
        ) );
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
     * @return array{script_detail: string|null, url_detail: string|null} Detail strings built for each changed dataset.
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

        $script_detail = null;
        $url_detail    = null;

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

            $script_detail = implode( ' · ', $detail_parts );
            $this->write_activity_entry( array(
                'action'             => $action,
                'location'           => $location,
                'content'            => $snap_content,
                'chars'              => strlen( $script ),
                'conditions_snapshot' => $conditions,
                'detail'             => $script_detail,
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

            $url_detail = implode( ' · ', $detail_parts );
            $this->write_activity_entry( array(
                'action'        => 'url_save',
                'location'      => $location,
                'urls_snapshot' => $urls_snapshot,
                'detail'        => $url_detail,
            ) );
        }

        return array(
            'script_detail' => $script_detail,
            'url_detail'    => $url_detail,
        );
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
