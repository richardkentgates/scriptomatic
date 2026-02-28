<?php
/**
 * Trait: REST API for Scriptomatic.
 *
 * Registers all routes under /wp-json/scriptomatic/v1/.
 * Every route uses POST — credentials travel in the Authorization header
 * only, never in the URL.
 *
 * Authentication is handled entirely by WordPress Application Passwords
 * (built into WordPress 5.6+). No custom key storage is needed:
 *   - Admin creates an Application Password at Users → Profile.
 *   - Caller includes it in every request as Basic Auth:
 *       Authorization: Basic base64("admin:xxxx xxxx xxxx xxxx xxxx xxxx")
 *   - WordPress validates the credential, sets current_user, and every
 *     endpoint checks current_user_can('manage_options') as normal.
 *
 * Routes:
 *
 *   POST /wp-json/scriptomatic/v1/script          → get current inline script
 *   POST /wp-json/scriptomatic/v1/script/set      → save inline script (+ conditions)
 *   POST /wp-json/scriptomatic/v1/script/rollback → restore inline script
 *   POST /wp-json/scriptomatic/v1/history         → list inline script history
 *
 *   POST /wp-json/scriptomatic/v1/urls            → get current external URL list
 *   POST /wp-json/scriptomatic/v1/urls/set        → save external URL list
 *   POST /wp-json/scriptomatic/v1/urls/rollback   → restore external URL list
 *   POST /wp-json/scriptomatic/v1/urls/history    → list URL history
 *
 *   POST /wp-json/scriptomatic/v1/files           → list managed JS files
 *   POST /wp-json/scriptomatic/v1/files/get       → get one file's content + metadata
 *   POST /wp-json/scriptomatic/v1/files/set       → create or update a managed JS file
 *   POST /wp-json/scriptomatic/v1/files/delete    → delete a managed JS file
 *   POST /wp-json/scriptomatic/v1/files/upload    → upload a .js file from multipart form-data
 *
 * Index scheme used by rollback endpoints:
 *   0 = current live state (read-only; rollback returns 400)
 *   1 = most recent snapshot
 *   2 = second-most-recent snapshot …
 *
 * All write routes enforce the same transient-based rate limit as the
 * admin UI and call write_activity_entry() so every change is logged.
 *
 * @package  Scriptomatic
 * @since    2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API route registration and shared service-layer methods.
 */
trait Scriptomatic_API {

    // =========================================================================
    // ROUTE REGISTRATION
    // =========================================================================

    /**
     * Register all Scriptomatic REST API routes.
     *
     * Hooked on `rest_api_init`.
     *
     * @since  2.6.0
     * @return void
     */
    public function register_rest_routes() {
        $ns = 'scriptomatic/v1';
        $pc = array( $this, 'api_permission_check' );

        // --- Inline script ---
        register_rest_route( $ns, '/script', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_get_script' ),
            'permission_callback' => $pc,
            'args'                => $this->api_location_args(),
        ) );
        register_rest_route( $ns, '/script/set', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_set_script' ),
            'permission_callback' => $pc,
            'args'                => $this->api_set_script_args(),
        ) );
        register_rest_route( $ns, '/script/rollback', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_rollback_script' ),
            'permission_callback' => $pc,
            'args'                => $this->api_rollback_args(),
        ) );
        register_rest_route( $ns, '/history', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_get_history' ),
            'permission_callback' => $pc,
            'args'                => $this->api_location_args(),
        ) );

        // --- External URLs ---
        register_rest_route( $ns, '/urls', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_get_urls' ),
            'permission_callback' => $pc,
            'args'                => $this->api_location_args(),
        ) );
        register_rest_route( $ns, '/urls/set', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_set_urls' ),
            'permission_callback' => $pc,
            'args'                => $this->api_set_urls_args(),
        ) );
        register_rest_route( $ns, '/urls/rollback', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_rollback_urls' ),
            'permission_callback' => $pc,
            'args'                => $this->api_rollback_args(),
        ) );
        register_rest_route( $ns, '/urls/history', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_get_url_history' ),
            'permission_callback' => $pc,
            'args'                => $this->api_location_args(),
        ) );

        // --- Managed JS files ---
        register_rest_route( $ns, '/files', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_list_files' ),
            'permission_callback' => $pc,
            'args'                => array(),
        ) );
        register_rest_route( $ns, '/files/get', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_get_file' ),
            'permission_callback' => $pc,
            'args'                => $this->api_file_id_args(),
        ) );
        register_rest_route( $ns, '/files/set', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_set_file' ),
            'permission_callback' => $pc,
            'args'                => $this->api_set_file_args(),
        ) );
        register_rest_route( $ns, '/files/delete', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_delete_file' ),
            'permission_callback' => $pc,
            'args'                => $this->api_file_id_args(),
        ) );
        register_rest_route( $ns, '/files/upload', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_upload_file' ),
            'permission_callback' => $pc,
            'args'                => $this->api_upload_file_args(),
        ) );
    }

    // =========================================================================
    // PERMISSION CALLBACK
    // =========================================================================

    /**
     * Verify the current user has the required capability.
     *
     * Returns a WP_Error (which WP converts to a 403 JSON response) when the
     * user is not authenticated or lacks manage_options.
     *
     * @since  2.6.0
     * @return true|WP_Error
     */
    public function api_permission_check() {
        $ip_check = $this->api_check_ip_allowlist();
        if ( is_wp_error( $ip_check ) ) {
            return $ip_check;
        }

        if ( ! current_user_can( $this->get_required_cap() ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to manage Scriptomatic. Authenticate with an Application Password from Users → Profile.', 'scriptomatic' ),
                array( 'status' => 403 )
            );
        }
        return true;
    }

    // =========================================================================
    // ARG SCHEMAS
    // =========================================================================

    /**
     * Check the REST request IP against the configured allowlist.
     *
     * Returns true immediately when the list is empty (allow all).
     * Uses $_SERVER['REMOTE_ADDR'] to avoid X-Forwarded-For spoofing.
     *
     * @since  2.7.0
     * @access private
     * @return true|WP_Error
     */
    private function api_check_ip_allowlist() {
        $settings    = $this->get_plugin_settings();
        $allowed_raw = isset( $settings['api_allowed_ips'] ) ? trim( (string) $settings['api_allowed_ips'] ) : '';

        if ( '' === $allowed_raw ) {
            return true; // Empty list = allow all.
        }

        $client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if ( '' === $client_ip ) {
            return new WP_Error(
                'rest_ip_forbidden',
                __( 'REST API access is restricted by IP address.', 'scriptomatic' ),
                array( 'status' => 403 )
            );
        }

        foreach ( preg_split( '/[\r\n]+/', $allowed_raw ) as $entry ) {
            $entry = trim( $entry );
            if ( '' === $entry ) { continue; }
            if ( false !== strpos( $entry, '/' ) ) {
                if ( $this->api_ip_in_cidr( $client_ip, $entry ) ) { return true; }
            } elseif ( $client_ip === $entry ) {
                return true;
            }
        }

        return new WP_Error(
            'rest_ip_forbidden',
            __( 'REST API access is restricted by IP address.', 'scriptomatic' ),
            array( 'status' => 403 )
        );
    }

    /**
     * Determine whether $ip falls within the $cidr range.
     *
     * Supports IPv4 dotted-decimal CIDR (e.g. 198.51.100.0/24).
     * IPv6 addresses are compared byte-by-byte after inet_pton().
     *
     * @since  2.7.0
     * @access private
     * @param  string $ip   Client IP address.
     * @param  string $cidr Network in CIDR notation.
     * @return bool
     */
    private function api_ip_in_cidr( $ip, $cidr ) {
        list( $subnet, $prefix ) = explode( '/', $cidr, 2 );
        $prefix = (int) $prefix;

        // IPv4.
        if ( false !== strpos( $subnet, '.' ) ) {
            $ip_long     = ip2long( $ip );
            $subnet_long = ip2long( $subnet );
            if ( false === $ip_long || false === $subnet_long ) { return false; }
            if ( $prefix < 0 || $prefix > 32 ) { return false; }
            $mask = ( 0 === $prefix ) ? 0 : ( ~0 << ( 32 - $prefix ) );
            return ( $ip_long & $mask ) === ( $subnet_long & $mask );
        }

        // IPv6.
        $ip_bin     = @inet_pton( $ip );     // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $subnet_bin = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
            return false;
        }
        $bits   = strlen( $ip_bin ) * 8;
        if ( $prefix < 0 || $prefix > $bits ) { return false; }
        $full_bytes  = (int) floor( $prefix / 8 );
        $extra_bits  = $prefix % 8;
        for ( $i = 0; $i < $full_bytes; $i++ ) {
            if ( ord( $ip_bin[ $i ] ) !== ord( $subnet_bin[ $i ] ) ) { return false; }
        }
        if ( $extra_bits > 0 && $full_bytes < strlen( $ip_bin ) ) {
            $mask = 0xFF & ( 0xFF << ( 8 - $extra_bits ) );
            if ( ( ord( $ip_bin[ $full_bytes ] ) & $mask ) !== ( ord( $subnet_bin[ $full_bytes ] ) & $mask ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Shared `location` argument definition.
     *
     * @since  2.6.0
     * @access private
     * @return array[]
     */
    private function api_location_args() {
        return array(
            'location' => array(
                'required'          => true,
                'type'              => 'string',
                'enum'              => array( 'head', 'footer' ),
                'sanitize_callback' => 'sanitize_key',
                'description'       => __( 'Injection location: "head" or "footer".', 'scriptomatic' ),
            ),
        );
    }

    /**
     * Shared `location` + `index` argument definitions (rollback endpoints).
     *
     * @since  2.6.0
     * @access private
     * @return array[]
     */
    private function api_rollback_args() {
        return array_merge( $this->api_location_args(), array(
            'index' => array(
                'required'          => true,
                'type'              => 'integer',
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
                'description'       => __( 'Snapshot index to restore (1 = most recent). Index 0 is the current live state and cannot be restored via this endpoint.', 'scriptomatic' ),
            ),
        ) );
    }

    /**
     * Argument definitions for the script/set endpoint.
     *
     * @since  2.6.0
     * @access private
     * @return array[]
     */
    private function api_set_script_args() {
        return array_merge( $this->api_location_args(), array(
            'content' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'strval',
                'description'       => __( 'JavaScript content (without <script> tags). Maximum 100 KB.', 'scriptomatic' ),
            ),
            'conditions' => array(
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'strval',
                'description'       => __( 'JSON-encoded load conditions {logic, rules}. Omit to leave existing conditions unchanged.', 'scriptomatic' ),
            ),
        ) );
    }

    /**
     * Argument definitions for the urls/set endpoint.
     *
     * @since  2.6.0
     * @access private
     * @return array[]
     */
    private function api_set_urls_args() {
        return array_merge( $this->api_location_args(), array(
            'urls' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'strval',
                'description'       => __( 'JSON-encoded array of {url, conditions} objects.', 'scriptomatic' ),
            ),
        ) );
    }

    /**
     * Argument definitions for the files/set endpoint.
     *
     * @since  2.6.0
     * @access private
     * @return array[]
     */
    private function api_set_file_args() {
        return array(
            'label' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Human-readable label shown in the file list.', 'scriptomatic' ),
            ),
            'content' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'strval',
                'description'       => __( 'JavaScript file content (without <script> tags).', 'scriptomatic' ),
            ),
            'file_id' => array(
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_key',
                'description'       => __( 'Existing file ID to update. Omit to create a new file.', 'scriptomatic' ),
            ),
            'filename' => array(
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_file_name',
                'description'       => __( 'Filename (e.g. my-script.js). Auto-generated from label if omitted.', 'scriptomatic' ),
            ),
            'location' => array(
                'required'          => false,
                'type'              => 'string',
                'enum'              => array( 'head', 'footer' ),
                'default'           => 'head',
                'sanitize_callback' => 'sanitize_key',
                'description'       => __( 'Injection location: "head" or "footer". Default: "head".', 'scriptomatic' ),
            ),
            'conditions' => array(
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'strval',
                'description'       => __( 'JSON-encoded load conditions {logic, rules}. Omit for all pages.', 'scriptomatic' ),
            ),
        );
    }

    /**
     * Argument definitions for file endpoints that require a file ID.
     *
     * @since  2.6.0
     * @access private
     * @return array[]
     */
    private function api_file_id_args() {
        return array(
            'file_id' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
                'description'       => __( 'The managed file ID (from the files list).', 'scriptomatic' ),
            ),
        );
    }

    /**
     * Argument definitions for the files/upload endpoint.
     *
     * The actual file data arrives as multipart/form-data under the key `file`.
     *
     * @since  2.7.0
     * @access private
     * @return array[]
     */
    private function api_upload_file_args() {
        return array(
            'label' => array(
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Human-readable label. Auto-derived from filename if omitted.', 'scriptomatic' ),
            ),
            'file_id' => array(
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_key',
                'description'       => __( 'Existing file ID to overwrite. Omit to create a new file.', 'scriptomatic' ),
            ),
            'location' => array(
                'required'          => false,
                'type'              => 'string',
                'enum'              => array( 'head', 'footer' ),
                'default'           => 'head',
                'sanitize_callback' => 'sanitize_key',
                'description'       => __( 'Injection location: "head" or "footer". Default: "head".', 'scriptomatic' ),
            ),
            'conditions' => array(
                'required'          => false,
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'strval',
                'description'       => __( 'JSON-encoded load conditions {logic, rules}. Omit for all pages.', 'scriptomatic' ),
            ),
        );
    }

    // =========================================================================
    // REST CALLBACKS — delegate to the service layer
    // =========================================================================

    /**
     * POST /wp-json/scriptomatic/v1/script
     *
     * @since  2.6.0
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_get_script( WP_REST_Request $request ) {
        $location   = $request['location'];
        $opt_s      = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_SCRIPT      : SCRIPTOMATIC_HEAD_SCRIPT;
        $opt_c      = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_CONDITIONS   : SCRIPTOMATIC_HEAD_CONDITIONS;
        $content    = (string) get_option( $opt_s, '' );
        $cond_raw   = (string) get_option( $opt_c, '' );
        return rest_ensure_response( array(
            'location'   => $location,
            'content'    => $content,
            'chars'      => strlen( $content ),
            'conditions' => ( '' !== $cond_raw ) ? json_decode( $cond_raw, true ) : null,
        ) );
    }

    /**
     * POST /wp-json/scriptomatic/v1/script/set
     *
     * @since  2.6.0
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_set_script( WP_REST_Request $request ) {
        $cond_str = ( '' !== (string) $request['conditions'] ) ? (string) $request['conditions'] : null;
        $result   = $this->service_set_script( $request['location'], (string) $request['content'], $cond_str );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( $result );
    }

    /**
     * POST /wp-json/scriptomatic/v1/script/rollback
     *
     * @since  2.6.0
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_rollback_script( WP_REST_Request $request ) {
        $result = $this->service_rollback_script( $request['location'], (int) $request['index'] );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( $result );
    }

    /**
     * POST /wp-json/scriptomatic/v1/history
     *
     * @since  2.6.0
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_get_history( WP_REST_Request $request ) {
        return rest_ensure_response( $this->service_get_history( $request['location'] ) );
    }

    /**
     * POST /wp-json/scriptomatic/v1/urls
     *
     * @since  2.6.0
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_get_urls( WP_REST_Request $request ) {
        $location = $request['location'];
        $opt      = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_LINKED : SCRIPTOMATIC_HEAD_LINKED;
        $raw      = get_option( $opt, '[]' );
        $list     = json_decode( $raw, true );
        return rest_ensure_response( array(
            'location' => $location,
            'urls'     => is_array( $list ) ? $list : array(),
        ) );
    }

    /**
     * POST /wp-json/scriptomatic/v1/urls/set
     *
     * @since  2.6.0
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_set_urls( WP_REST_Request $request ) {
        $result = $this->service_set_urls( $request['location'], (string) $request['urls'] );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( $result );
    }

    /**
     * POST /wp-json/scriptomatic/v1/urls/rollback
     *
     * @since  2.6.0
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_rollback_urls( WP_REST_Request $request ) {
        $result = $this->service_rollback_urls( $request['location'], (int) $request['index'] );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( $result );
    }

    /**
     * POST /wp-json/scriptomatic/v1/urls/history
     *
     * @since  2.6.0
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_get_url_history( WP_REST_Request $request ) {
        return rest_ensure_response( $this->service_get_url_history( $request['location'] ) );
    }

    /**
     * POST /wp-json/scriptomatic/v1/files
     *
     * @since  2.6.0
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_list_files( WP_REST_Request $request ) {
        return rest_ensure_response( array( 'files' => $this->get_js_files_meta() ) );
    }

    /**
     * POST /wp-json/scriptomatic/v1/files/get
     *
     * @since  2.6.0
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_get_file( WP_REST_Request $request ) {
        $result = $this->service_get_file( (string) $request['file_id'] );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( $result );
    }

    /**
     * POST /wp-json/scriptomatic/v1/files/set
     *
     * @since  2.6.0
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_set_file( WP_REST_Request $request ) {
        $result = $this->service_set_file( array(
            'file_id'    => (string) $request['file_id'],
            'label'      => (string) $request['label'],
            'content'    => (string) $request['content'],
            'filename'   => (string) $request['filename'],
            'location'   => (string) $request['location'],
            'conditions' => (string) $request['conditions'],
        ) );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( $result );
    }

    /**
     * POST /wp-json/scriptomatic/v1/files/delete
     *
     * @since  2.6.0
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_delete_file( WP_REST_Request $request ) {
        $result = $this->service_delete_file( (string) $request['file_id'] );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( $result );
    }

    /**
     * POST /wp-json/scriptomatic/v1/files/upload
     *
     * Accepts a multipart/form-data request with a `file` field containing a
     * .js file. The file is validated and stored via service_upload_file().
     *
     * @since  2.7.0
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_upload_file( WP_REST_Request $request ) {
        $files = $request->get_file_params();
        if ( empty( $files['file'] ) ) {
            return new WP_Error(
                'missing_file',
                __( 'No file was uploaded. Send the file as multipart/form-data under the key "file".', 'scriptomatic' ),
                array( 'status' => 400 )
            );
        }
        $result = $this->service_upload_file( $files['file'], array(
            'file_id'    => (string) $request['file_id'],
            'label'      => (string) $request['label'],
            'location'   => (string) $request['location'],
            'conditions' => (string) $request['conditions'],
        ) );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( $result );
    }

    // =========================================================================
    // SERVICE LAYER — public methods shared by both REST and WP-CLI
    // =========================================================================

    /**
     * Validate and save inline script content (and optionally load conditions).
     *
     * Mirrors the validation pipeline in sanitize_script_for() but returns a
     * WP_Error on failure instead of calling add_settings_error(). Writes
     * directly to wp_options via $wpdb (same pattern as ajax_rollback()) to
     * bypass the Settings API sanitize callbacks.
     *
     * @since  2.6.0
     * @param  string      $location  'head'|'footer'.
     * @param  string      $content   Raw JavaScript.
     * @param  string|null $cond_json JSON conditions string. Null = leave unchanged.
     * @return array|WP_Error  On success: {location, chars, message}.
     */
    public function service_set_script( $location, $content, $cond_json = null ) {
        $location = ( 'footer' === $location ) ? 'footer' : 'head';

        if ( $this->is_rate_limited( $location ) ) {
            return new WP_Error(
                'rate_limited',
                sprintf(
                    /* translators: %d: seconds to wait */
                    __( 'You are saving too quickly. Please wait %d seconds before trying again.', 'scriptomatic' ),
                    SCRIPTOMATIC_RATE_LIMIT_SECONDS
                ),
                array( 'status' => 429 )
            );
        }

        $validated = $this->api_validate_script_content( $content );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }
        $content = $validated;

        $script_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_SCRIPT : SCRIPTOMATIC_HEAD_SCRIPT;
        global $wpdb;
        $wpdb->update(
            $wpdb->options,
            array( 'option_value' => $content ),
            array( 'option_name'  => $script_key ),
            array( '%s' ),
            array( '%s' )
        );
        wp_cache_delete( $script_key, 'options' );

        // Conditions: validate and save if provided.
        $conditions_saved = null;
        if ( null !== $cond_json && '' !== $cond_json ) {
            $cond_decoded = json_decode( $cond_json, true );
            if ( ! is_array( $cond_decoded ) ) {
                return new WP_Error(
                    'invalid_conditions',
                    __( 'The conditions value must be a valid JSON object with "logic" and "rules" keys.', 'scriptomatic' ),
                    array( 'status' => 400 )
                );
            }
            $clean_cond       = $this->sanitize_conditions_array( $cond_decoded );
            $conditions_saved = wp_json_encode( $clean_cond );
            $cond_key         = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_CONDITIONS : SCRIPTOMATIC_HEAD_CONDITIONS;
            $wpdb->update(
                $wpdb->options,
                array( 'option_value' => $conditions_saved ),
                array( 'option_name'  => $cond_key ),
                array( '%s' ),
                array( '%s' )
            );
            wp_cache_delete( $cond_key, 'options' );
        }

        $log_entry = array(
            'action'   => 'save',
            'location' => $location,
            'content'  => $content,
            'chars'    => strlen( $content ),
            'detail'   => sprintf(
                /* translators: %s: character count */
                __( 'API: %s chars', 'scriptomatic' ),
                number_format( strlen( $content ) )
            ),
        );
        if ( null !== $conditions_saved ) {
            $log_entry['conditions_snapshot'] = $conditions_saved;
        }
        $this->write_activity_entry( $log_entry );
        $this->record_save_timestamp( $location );

        return array(
            'location' => $location,
            'chars'    => strlen( $content ),
            'message'  => __( 'Script saved.', 'scriptomatic' ),
        );
    }

    /**
     * Restore an inline script history snapshot.
     *
     * @since  2.6.0
     * @param  string $location  'head'|'footer'.
     * @param  int    $index     Snapshot index (1-based; 0 is current state).
     * @return array|WP_Error  On success: {location, content, chars, message}.
     */
    public function service_rollback_script( $location, $index ) {
        $location = ( 'footer' === $location ) ? 'footer' : 'head';
        $history  = $this->get_history( $location );

        if ( 0 === $index ) {
            return new WP_Error(
                'current_state',
                __( 'Index 0 is the current live state — nothing to restore. Use index 1 or higher.', 'scriptomatic' ),
                array( 'status' => 400 )
            );
        }

        if ( ! array_key_exists( $index, $history ) ) {
            return new WP_Error(
                'not_found',
                __( 'History entry not found.', 'scriptomatic' ),
                array( 'status' => 404 )
            );
        }

        $entry      = $history[ $index ];
        $content    = $entry['content'];
        $script_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_SCRIPT : SCRIPTOMATIC_HEAD_SCRIPT;

        global $wpdb;
        $wpdb->update(
            $wpdb->options,
            array( 'option_value' => $content ),
            array( 'option_name'  => $script_key ),
            array( '%s' ),
            array( '%s' )
        );
        wp_cache_delete( $script_key, 'options' );

        // Restore load conditions from the snapshot when present.
        if ( array_key_exists( 'conditions_snapshot', $entry ) && null !== $entry['conditions_snapshot'] ) {
            $cond_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_CONDITIONS : SCRIPTOMATIC_HEAD_CONDITIONS;
            $wpdb->update(
                $wpdb->options,
                array( 'option_value' => $entry['conditions_snapshot'] ),
                array( 'option_name'  => $cond_key ),
                array( '%s' ),
                array( '%s' )
            );
            wp_cache_delete( $cond_key, 'options' );
        }

        $rollback_entry = array(
            'action'   => 'rollback',
            'location' => $location,
            'content'  => $content,
            'chars'    => strlen( $content ),
            'detail'   => __( 'Restored from snapshot via API', 'scriptomatic' ),
        );
        if ( array_key_exists( 'conditions_snapshot', $entry ) ) {
            $rollback_entry['conditions_snapshot'] = $entry['conditions_snapshot'];
        }
        $this->write_activity_entry( $rollback_entry );

        return array(
            'location' => $location,
            'content'  => $content,
            'chars'    => strlen( $content ),
            'message'  => __( 'Script restored.', 'scriptomatic' ),
        );
    }

    /**
     * Return the inline script history for a location, formatted for the API.
     *
     * @since  2.6.0
     * @param  string $location  'head'|'footer'.
     * @return array  {location, entries[]}
     */
    public function service_get_history( $location ) {
        $location = ( 'footer' === $location ) ? 'footer' : 'head';
        $raw      = $this->get_history( $location );
        $entries  = array();
        foreach ( $raw as $i => $entry ) {
            $entries[] = array(
                'index'      => $i,
                'action'     => isset( $entry['action'] )     ? $entry['action']                : '',
                'timestamp'  => isset( $entry['timestamp'] )  ? (int) $entry['timestamp']       : 0,
                'user'       => isset( $entry['user_login'] ) ? $entry['user_login']             : '',
                'chars'      => isset( $entry['chars'] )      ? (int) $entry['chars']            : strlen( isset( $entry['content'] ) ? $entry['content'] : '' ),
                'detail'     => isset( $entry['detail'] )     ? $entry['detail']                : '',
                'has_conditions' => array_key_exists( 'conditions_snapshot', $entry ),
            );
        }
        return array(
            'location' => $location,
            'entries'  => $entries,
        );
    }

    /**
     * Save the external URL list for a location.
     *
     * @since  2.6.0
     * @param  string $location   'head'|'footer'.
     * @param  string $urls_json  JSON array of {url, conditions} objects.
     * @return array|WP_Error  On success: {location, count, urls, message}.
     */
    public function service_set_urls( $location, $urls_json ) {
        $location = ( 'footer' === $location ) ? 'footer' : 'head';
        $decoded  = json_decode( $urls_json, true );

        if ( ! is_array( $decoded ) ) {
            return new WP_Error(
                'invalid_json',
                __( 'The "urls" value must be a JSON array of {url, conditions} objects.', 'scriptomatic' ),
                array( 'status' => 400 )
            );
        }

        $clean = array();
        foreach ( $decoded as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $url = isset( $item['url'] ) ? esc_url_raw( trim( (string) $item['url'] ) ) : '';
            if ( empty( $url ) || ! preg_match( '/^https?:\/\//i', $url ) ) {
                continue;
            }
            $raw_cond = ( isset( $item['conditions'] ) && is_array( $item['conditions'] ) )
                        ? $item['conditions']
                        : array( 'logic' => 'and', 'rules' => array() );
            $clean[]  = array(
                'url'        => $url,
                'conditions' => $this->sanitize_conditions_array( $raw_cond ),
            );
        }

        $linked_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_LINKED : SCRIPTOMATIC_HEAD_LINKED;
        $new_json   = wp_json_encode( $clean );

        global $wpdb;
        $wpdb->update(
            $wpdb->options,
            array( 'option_value' => $new_json ),
            array( 'option_name'  => $linked_key ),
            array( '%s' ),
            array( '%s' )
        );
        wp_cache_delete( $linked_key, 'options' );

        $this->write_activity_entry( array(
            'action'        => 'url_save',
            'location'      => $location,
            'urls_snapshot' => $new_json,
            'detail'        => sprintf(
                /* translators: %d: number of URLs saved */
                _n( 'API: %d URL saved', 'API: %d URLs saved', count( $clean ), 'scriptomatic' ),
                count( $clean )
            ),
        ) );

        return array(
            'location' => $location,
            'count'    => count( $clean ),
            'urls'     => $clean,
            'message'  => __( 'External URLs saved.', 'scriptomatic' ),
        );
    }

    /**
     * Restore an external URL list snapshot.
     *
     * @since  2.6.0
     * @param  string $location  'head'|'footer'.
     * @param  int    $index     Snapshot index (1-based).
     * @return array|WP_Error  On success: {location, urls, message}.
     */
    public function service_rollback_urls( $location, $index ) {
        $location = ( 'footer' === $location ) ? 'footer' : 'head';
        $history  = $this->get_url_history( $location );

        if ( 0 === $index ) {
            return new WP_Error(
                'current_state',
                __( 'Index 0 is the current live state — nothing to restore. Use index 1 or higher.', 'scriptomatic' ),
                array( 'status' => 400 )
            );
        }

        if ( ! array_key_exists( $index, $history ) ) {
            return new WP_Error(
                'not_found',
                __( 'URL history entry not found.', 'scriptomatic' ),
                array( 'status' => 404 )
            );
        }

        $entry      = $history[ $index ];
        $linked_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_LINKED : SCRIPTOMATIC_HEAD_LINKED;

        global $wpdb;
        $wpdb->update(
            $wpdb->options,
            array( 'option_value' => $entry['urls_snapshot'] ),
            array( 'option_name'  => $linked_key ),
            array( '%s' ),
            array( '%s' )
        );
        wp_cache_delete( $linked_key, 'options' );

        $this->write_activity_entry( array(
            'action'        => 'url_rollback',
            'location'      => $location,
            'urls_snapshot' => $entry['urls_snapshot'],
            'detail'        => __( 'External URLs restored from snapshot via API', 'scriptomatic' ),
        ) );

        $list = json_decode( $entry['urls_snapshot'], true );
        return array(
            'location' => $location,
            'urls'     => is_array( $list ) ? $list : array(),
            'message'  => __( 'External URLs restored.', 'scriptomatic' ),
        );
    }

    /**
     * Return the URL history for a location, formatted for the API.
     *
     * @since  2.6.0
     * @param  string $location  'head'|'footer'.
     * @return array  {location, entries[]}
     */
    public function service_get_url_history( $location ) {
        $location = ( 'footer' === $location ) ? 'footer' : 'head';
        $raw      = $this->get_url_history( $location );
        $entries  = array();
        foreach ( $raw as $i => $entry ) {
            $snap = isset( $entry['urls_snapshot'] ) ? json_decode( $entry['urls_snapshot'], true ) : array();
            $entries[] = array(
                'index'     => $i,
                'action'    => isset( $entry['action'] )     ? $entry['action']          : '',
                'timestamp' => isset( $entry['timestamp'] )  ? (int) $entry['timestamp'] : 0,
                'user'      => isset( $entry['user_login'] ) ? $entry['user_login']       : '',
                'url_count' => is_array( $snap ) ? count( $snap ) : 0,
                'detail'    => isset( $entry['detail'] )     ? $entry['detail']           : '',
            );
        }
        return array(
            'location' => $location,
            'entries'  => $entries,
        );
    }

    /**
     * Return one managed JS file's metadata and disk content.
     *
     * @since  2.6.0
     * @param  string $file_id
     * @return array|WP_Error
     */
    public function service_get_file( $file_id ) {
        $file_id = sanitize_key( $file_id );
        $files   = $this->get_js_files_meta();
        $found   = null;
        foreach ( $files as $f ) {
            if ( $f['id'] === $file_id ) { $found = $f; break; }
        }
        if ( null === $found ) {
            return new WP_Error( 'not_found', __( 'File not found.', 'scriptomatic' ), array( 'status' => 404 ) );
        }
        $dir     = $this->get_js_files_dir();
        $path    = $dir . $found['filename'];
        $content = file_exists( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
        return array(
            'file_id'    => $found['id'],
            'label'      => $found['label'],
            'filename'   => $found['filename'],
            'location'   => isset( $found['location'] ) ? $found['location'] : 'head',
            'conditions' => isset( $found['conditions'] ) ? $found['conditions'] : array(),
            'content'    => $content,
            'chars'      => strlen( $content ),
        );
    }

    /**
     * Create or update a managed JS file.
     *
     * @since  2.6.0
     * @param  array $params { file_id?, label, content, filename?, location?, conditions? }.
     * @return array|WP_Error  On success: {file_id, filename, label, location, chars, message}.
     */
    public function service_set_file( array $params ) {
        $original_id = sanitize_key( (string) ( $params['file_id'] ?? '' ) );
        $label       = sanitize_text_field( (string) ( $params['label']    ?? '' ) );
        $content     = (string) ( $params['content']  ?? '' );
        $filename    = sanitize_file_name( (string) ( $params['filename'] ?? '' ) );
        $loc         = ( isset( $params['location'] ) && 'footer' === $params['location'] ) ? 'footer' : 'head';
        $cond_str    = (string) ( $params['conditions'] ?? '' );

        if ( '' === $label ) {
            return new WP_Error( 'missing_label',   __( 'A label is required.', 'scriptomatic' ),          array( 'status' => 400 ) );
        }
        if ( '' === trim( $content ) ) {
            return new WP_Error( 'empty_content',   __( 'File content cannot be empty.', 'scriptomatic' ), array( 'status' => 400 ) );
        }

        $max_bytes = wp_max_upload_size();
        if ( strlen( $content ) > $max_bytes ) {
            return new WP_Error(
                'too_large',
                sprintf(
                    /* translators: %s: human-readable upload size limit */
                    __( 'Content exceeds the server upload limit of %s.', 'scriptomatic' ),
                    size_format( $max_bytes )
                ),
                array( 'status' => 400 )
            );
        }

        // Auto-slug filename from label if not supplied.
        if ( '' === $filename ) { $filename = sanitize_title( $label ) . '.js'; }
        if ( ! preg_match( '/\.js$/i', $filename ) ) { $filename .= '.js'; }
        $filename = preg_replace( '/[^a-zA-Z0-9_\-.]/', '-', $filename );
        $filename = ltrim( $filename, '.' );
        $filename = preg_replace( '/-+/', '-', $filename );

        if ( '' === $filename ) {
            return new WP_Error( 'invalid_filename', __( 'The filename is invalid.', 'scriptomatic' ), array( 'status' => 400 ) );
        }

        // Parse conditions.
        $conditions = array( 'logic' => 'and', 'rules' => array() );
        if ( '' !== $cond_str ) {
            $decoded = json_decode( $cond_str, true );
            if ( is_array( $decoded ) ) {
                $conditions = $this->sanitize_conditions_array( $decoded );
            }
        }

        $dir   = $this->get_js_files_dir();
        $files = $this->get_js_files_meta();
        $new_id = sanitize_key( preg_replace( '/\.js$/i', '', $filename ) );
        if ( '' === $new_id ) { $new_id = 'file'; }

        // Rename: remove old file from disk if filename changed.
        if ( '' !== $original_id ) {
            foreach ( $files as $f ) {
                if ( $f['id'] === $original_id && $f['filename'] !== $filename ) {
                    $old_path = $dir . $f['filename'];
                    if ( file_exists( $old_path ) ) { @unlink( $old_path ); } // phpcs:ignore
                    break;
                }
            }
        }

        // New file: ensure unique filename on disk.
        if ( '' === $original_id ) {
            $base   = preg_replace( '/\.js$/i', '', $filename );
            $suffix = 1;
            while ( file_exists( $dir . $filename ) ) {
                $filename = $base . '-' . $suffix . '.js';
                $suffix++;
            }
            $new_id = sanitize_key( preg_replace( '/\.js$/i', '', $filename ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( false === file_put_contents( $dir . $filename, $content ) ) {
            return new WP_Error( 'write_failed', __( 'Could not write file to disk. Check directory permissions.', 'scriptomatic' ), array( 'status' => 500 ) );
        }

        $meta_entry = array(
            'id'         => $new_id,
            'label'      => $label,
            'filename'   => $filename,
            'location'   => $loc,
            'conditions' => $conditions,
        );

        if ( '' !== $original_id ) {
            $replaced = false;
            foreach ( $files as $i => $f ) {
                if ( $f['id'] === $original_id ) { $files[ $i ] = $meta_entry; $replaced = true; break; }
            }
            if ( ! $replaced ) { $files[] = $meta_entry; }
        } else {
            $files[] = $meta_entry;
        }
        $this->save_js_files_meta( $files );

        $this->write_activity_entry( array(
            'action'     => 'file_save',
            'location'   => 'file',
            'file_id'    => $new_id,
            'content'    => $content,
            'chars'      => strlen( $content ),
            'detail'     => $label,
            'conditions' => $conditions,
            'meta'       => array( 'label' => $label, 'filename' => $filename, 'location' => $loc ),
        ) );

        return array(
            'file_id'  => $new_id,
            'filename' => $filename,
            'label'    => $label,
            'location' => $loc,
            'chars'    => strlen( $content ),
            'message'  => __( 'File saved.', 'scriptomatic' ),
        );
    }

    /**
     * Delete a managed JS file and write an activity log entry.
     *
     * @since  2.6.0
     * @param  string $file_id
     * @return array|WP_Error  On success: {file_id, message}.
     */
    public function service_delete_file( $file_id ) {
        $file_id = sanitize_key( $file_id );
        $files   = $this->get_js_files_meta();
        $found   = null;

        foreach ( $files as $i => $f ) {
            if ( $f['id'] === $file_id ) {
                $found = $f;
                array_splice( $files, $i, 1 );
                break;
            }
        }

        if ( null === $found ) {
            return new WP_Error( 'not_found', __( 'File not found.', 'scriptomatic' ), array( 'status' => 404 ) );
        }

        $dir          = $this->get_js_files_dir();
        $path         = $dir . $found['filename'];
        $file_content = '';
        if ( file_exists( $path ) ) {
            $file_content = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            @unlink( $path ); // phpcs:ignore
        }

        $this->save_js_files_meta( $files );
        $this->write_activity_entry( array(
            'action'     => 'file_delete',
            'location'   => 'file',
            'file_id'    => $file_id,
            'detail'     => $found['label'],
            'content'    => $file_content,
            'conditions' => isset( $found['conditions'] ) ? $found['conditions'] : array(),
            'meta'       => array(
                'label'    => $found['label'],
                'filename' => $found['filename'],
                'location' => isset( $found['location'] ) ? $found['location'] : 'head',
            ),
        ) );

        return array(
            'file_id' => $file_id,
            'message' => __( 'File deleted.', 'scriptomatic' ),
        );
    }

    /**
     * Validate and store an uploaded .js file.
     *
     * Used by rest_upload_file() and the CLI `files upload` command.
     * Delegates content validation to validate_js_upload() (trait-files.php)
     * and persistence to service_set_file().
     *
     * @since  2.7.0
     * @param  array $file_data  PHP $_FILES-style entry: {name, tmp_name, error, size, type}.
     *                           CLI callers may pass a synthetic array with tmp_name already set.
     * @param  array $params     { file_id?, label?, location?, conditions? }
     * @return array|WP_Error    On success: same shape as service_set_file().
     */
    public function service_upload_file( array $file_data, array $params ) {
        // validate_js_upload() lives in Scriptomatic_Files trait.
        $content = $this->validate_js_upload( $file_data );
        if ( is_wp_error( $content ) ) {
            return $content;
        }

        // Derive label and filename from the original filename when not supplied.
        $original_name = isset( $file_data['name'] ) ? basename( (string) $file_data['name'] ) : 'upload.js';
        $label   = ( isset( $params['label'] ) && '' !== trim( (string) $params['label'] ) )
                   ? trim( (string) $params['label'] )
                   : preg_replace( '/\.js$/i', '', $original_name );
        $file_id = isset( $params['file_id'] ) ? (string) $params['file_id'] : '';

        return $this->service_set_file( array(
            'file_id'    => $file_id,
            'label'      => $label,
            'content'    => $content,
            'filename'   => $original_name,
            'location'   => isset( $params['location'] )   ? (string) $params['location']   : 'head',
            'conditions' => isset( $params['conditions'] ) ? (string) $params['conditions'] : '',
        ) );
    }

    // =========================================================================
    // SCRIPT CONTENT VALIDATION
    // =========================================================================

    /**
     * Validate and lightly sanitise raw script content for the API.
     *
     * Mirrors the validation pipeline in sanitize_script_for() but returns a
     * structured WP_Error instead of calling add_settings_error().
     *
     * @since  2.6.0
     * @access private
     * @param  string $content  Raw JavaScript string.
     * @return string|WP_Error  Sanitised content on success.
     */
    private function api_validate_script_content( $content ) {
        if ( ! is_string( $content ) ) {
            return new WP_Error( 'invalid_type', __( 'Script content must be a string.', 'scriptomatic' ), array( 'status' => 400 ) );
        }

        $content = wp_kses_no_null( wp_unslash( $content ) );
        $content = str_replace( "\r\n", "\n", $content );

        $validated = wp_check_invalid_utf8( $content, true );
        if ( '' === $validated && '' !== $content ) {
            return new WP_Error( 'invalid_utf8', __( 'Script content contains invalid UTF-8 characters.', 'scriptomatic' ), array( 'status' => 400 ) );
        }
        $content = $validated;

        if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content ) ) {
            return new WP_Error( 'control_chars', __( 'Script content contains disallowed control characters.', 'scriptomatic' ), array( 'status' => 400 ) );
        }

        if ( preg_match( '/<\?(php|=)?/i', $content ) ) {
            return new WP_Error( 'php_tags', __( 'PHP tags are not allowed in script content.', 'scriptomatic' ), array( 'status' => 400 ) );
        }

        // Strip <script> tags silently (same behaviour as the admin UI sanitizer).
        if ( preg_match( '/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', $content ) ) {
            $content = preg_replace( '/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', '$1', $content );
        }

        if ( strlen( $content ) > SCRIPTOMATIC_MAX_SCRIPT_LENGTH ) {
            return new WP_Error(
                'too_long',
                sprintf(
                    /* translators: %s: maximum character limit */
                    __( 'Script content exceeds the maximum length of %s characters.', 'scriptomatic' ),
                    number_format( SCRIPTOMATIC_MAX_SCRIPT_LENGTH )
                ),
                array( 'status' => 400 )
            );
        }

        return trim( $content );
    }
}
