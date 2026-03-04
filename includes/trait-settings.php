<?php
/**
 * Trait: Settings registration and plugin-settings sanitisation for Scriptomatic.
 *
 * Registers the WordPress Settings API groups, sections, and fields for the
 * Head, Footer, and General pages; provides the plugin-settings accessor and
 * sanitiser; and writes security-audit log entries on content change.
 *
 * @package  Scriptomatic
 * @since    1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress Settings API wiring plus plugin-settings CRUD.
 */
trait Scriptomatic_Settings {

    /**
     * Register all settings, sections, and fields via the Settings API.
     *
     * Hooked to `admin_init`.
     *
     * @since  1.0.0
     * @return void
     */
    public function register_settings() {
        // Ensure the custom log tables exist (idempotent via dbDelta).
        $this->maybe_create_log_table();
        $this->maybe_create_prefs_log_table();

        // Default location data structure.
        $location_default = array(
            'script'     => '',
            'conditions' => array( 'logic' => 'and', 'rules' => array() ),
            'urls'       => array(),
        );

        // ---- HEAD LOCATION ----
        register_setting( 'scriptomatic_head_group', SCRIPTOMATIC_LOCATION_HEAD, array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize_head_location' ),
            'default'           => $location_default,
        ) );

        add_settings_section( 'sm_head_code',       __( 'Inline Script', 'scriptomatic' ),       array( $this, 'render_head_code_section' ),       'scriptomatic_head_page' );
        add_settings_field(   'sm_head_script',      __( 'Script Content', 'scriptomatic' ),       array( $this, 'render_head_script_field' ),       'scriptomatic_head_page', 'sm_head_code' );
        add_settings_section( 'sm_head_conditions',  __( 'Load Conditions', 'scriptomatic' ),      array( $this, 'render_head_conditions_section' ), 'scriptomatic_head_page' );
        add_settings_field(   'sm_head_conditions',  __( 'When to inject', 'scriptomatic' ),       array( $this, 'render_head_conditions_field' ),   'scriptomatic_head_page', 'sm_head_conditions' );
        add_settings_section( 'sm_head_links',       __( 'External Script URLs', 'scriptomatic' ), array( $this, 'render_head_links_section' ),      'scriptomatic_head_page' );
        add_settings_field(   'sm_head_linked',      __( 'Script URLs', 'scriptomatic' ),          array( $this, 'render_head_linked_field' ),       'scriptomatic_head_page', 'sm_head_links' );

        // ---- FOOTER LOCATION ----
        register_setting( 'scriptomatic_footer_group', SCRIPTOMATIC_LOCATION_FOOTER, array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize_footer_location' ),
            'default'           => $location_default,
        ) );

        add_settings_section( 'sm_footer_code',       __( 'Inline Script', 'scriptomatic' ),       array( $this, 'render_footer_code_section' ),       'scriptomatic_footer_page' );
        add_settings_field(   'sm_footer_script',      __( 'Script Content', 'scriptomatic' ),       array( $this, 'render_footer_script_field' ),       'scriptomatic_footer_page', 'sm_footer_code' );
        add_settings_section( 'sm_footer_conditions',  __( 'Load Conditions', 'scriptomatic' ),      array( $this, 'render_footer_conditions_section' ), 'scriptomatic_footer_page' );
        add_settings_field(   'sm_footer_conditions',  __( 'When to inject', 'scriptomatic' ),       array( $this, 'render_footer_conditions_field' ),   'scriptomatic_footer_page', 'sm_footer_conditions' );
        add_settings_section( 'sm_footer_links',       __( 'External Script URLs', 'scriptomatic' ), array( $this, 'render_footer_links_section' ),      'scriptomatic_footer_page' );
        add_settings_field(   'sm_footer_linked',      __( 'Script URLs', 'scriptomatic' ),          array( $this, 'render_footer_linked_field' ),       'scriptomatic_footer_page', 'sm_footer_links' );

        // ---- GENERAL SETTINGS GROUP ----
        register_setting( 'scriptomatic_general_group', SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION, array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize_plugin_settings' ),
            'default'           => array(
                'max_log_entries'        => SCRIPTOMATIC_MAX_LOG_ENTRIES,
                'keep_data_on_uninstall' => false,
                'save_confirm_enabled'   => true,
                'api_allowed_ips'        => '',
                'api_enabled'            => true,
                'api_allowed_users'      => array(),
            ),
        ) );

        add_settings_section( 'sm_advanced', '', array( $this, 'render_advanced_section' ), 'scriptomatic_general_page' );
        add_settings_field( 'scriptomatic_max_log_entries', __( 'Activity Log Limit', 'scriptomatic' ),
            array( $this, 'render_max_log_field' ), 'scriptomatic_general_page', 'sm_advanced' );
        add_settings_field( 'scriptomatic_keep_data', __( 'Data on Uninstall', 'scriptomatic' ),
            array( $this, 'render_keep_data_field' ), 'scriptomatic_general_page', 'sm_advanced' );
        add_settings_field( 'scriptomatic_save_confirm', __( 'Save Confirmation', 'scriptomatic' ),
            array( $this, 'render_save_confirm_field' ), 'scriptomatic_general_page', 'sm_advanced' );

        // API fields: Pro feature.
        if ( scriptomatic_is_premium() ) {
            add_settings_field( 'scriptomatic_api_enabled', __( 'REST API', 'scriptomatic' ),
                array( $this, 'render_api_enabled_field' ), 'scriptomatic_general_page', 'sm_advanced' );
            add_settings_field( 'scriptomatic_api_allowed_ips', __( 'API Allowed IPs', 'scriptomatic' ),
                array( $this, 'render_api_allowed_ips_field' ), 'scriptomatic_general_page', 'sm_advanced' );
            add_settings_field( 'scriptomatic_api_allowed_users', __( 'API Allowed Users', 'scriptomatic' ),
                array( $this, 'render_api_allowed_users_field' ), 'scriptomatic_general_page', 'sm_advanced' );
        }
    }

    // =========================================================================
    // LOCATION DATA HELPERS
    // =========================================================================

    /**
     * Return the stored location data for head or footer.
     *
     * Always returns a complete array with 'script', 'conditions', and 'urls'
     * keys set to sane defaults if missing from the stored value.
     *
     * @since  2.8.0
     * @param  string $location 'head'|'footer'
     * @return array { script: string, conditions: array, urls: array }
     */
    public function get_location( $location ) {
        $key  = ( 'footer' === $location ) ? SCRIPTOMATIC_LOCATION_FOOTER : SCRIPTOMATIC_LOCATION_HEAD;
        $data = get_option( $key, array() );
        if ( ! is_array( $data ) ) {
            $data = array();
        }
        return wp_parse_args( $data, array(
            'script'     => '',
            'conditions' => array( 'logic' => 'and', 'rules' => array() ),
            'urls'       => array(),
        ) );
    }

    /**
     * Persist the location data for head or footer.
     *
     * @since  2.8.0
     * @param  string $location 'head'|'footer'
     * @param  array  $data     { script, conditions, urls }
     * @return void
     */
    public function save_location( $location, array $data ) {
        $key = ( 'footer' === $location ) ? SCRIPTOMATIC_LOCATION_FOOTER : SCRIPTOMATIC_LOCATION_HEAD;
        update_option( $key, $data );
    }

    /**
     * Return the current plugin settings, merged with defaults.
     *
     * @since  1.1.0
     * @return array
     */
    public function get_plugin_settings() {
        $defaults = array(
            'max_log_entries'        => SCRIPTOMATIC_MAX_LOG_ENTRIES,
            'keep_data_on_uninstall' => false,
            'save_confirm_enabled'   => true,
            'api_allowed_ips'        => '',
            'api_enabled'            => true,
            'api_allowed_users'      => array(),
        );
        $saved = get_option( SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION, false );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
    }

    /**
     * Sanitise and validate the plugin settings array submitted from the form.
     *
     * If `max_log_entries` is being reduced the activity log is immediately
     * trimmed so it never exceeds the new limit.
     *
     * @since  1.1.0
     * @param  mixed $input Raw array value from the settings form.
     * @return array Sanitised settings array.
     */
    public function sanitize_plugin_settings( $input ) {
        $current = $this->get_plugin_settings();

        // Gate 0: Capability.
        if ( ! current_user_can( $this->get_required_cap() ) ) {
            return $current;
        }

        if ( ! is_array( $input ) ) {
            return $current;
        }

        // Secondary nonce — only present (and enforced) when saving via the Settings API form.
        if ( isset( $_POST['scriptomatic_general_nonce'] ) ) {
            $secondary = sanitize_text_field( wp_unslash( $_POST['scriptomatic_general_nonce'] ) );
            if ( ! wp_verify_nonce( $secondary, SCRIPTOMATIC_GENERAL_NONCE ) ) {
                add_settings_error(
                    SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION,
                    'nonce_invalid',
                    __( 'Security check failed. Please refresh the page and try again.', 'scriptomatic' ),
                    'error'
                );
                return $current;
            }
        }

        $clean = array();

        // max_log_entries: integer clamped to 3–1000.
        $max_log                  = isset( $input['max_log_entries'] ) ? (int) $input['max_log_entries'] : $current['max_log_entries'];
        $clean['max_log_entries'] = max( 3, min( 1000, $max_log ) );

        // If the log limit was reduced, immediately trim the activity log table.
        if ( $clean['max_log_entries'] < $this->get_max_log_entries() ) {
            global $wpdb;
            $log_table = $wpdb->prefix . SCRIPTOMATIC_LOG_TABLE;
            $keep_id   = $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d',
                $log_table,
                $clean['max_log_entries'] - 1
            ) );
            if ( $keep_id ) {
                $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', $log_table, (int) $keep_id ) );
            }
        }

        // keep_data_on_uninstall: boolean.
        $clean['keep_data_on_uninstall'] = ! empty( $input['keep_data_on_uninstall'] );

        // save_confirm_enabled: boolean.
        $clean['save_confirm_enabled'] = ! empty( $input['save_confirm_enabled'] );

        // api_allowed_ips: newline-separated list of valid IPs and CIDR ranges.
        $raw_ips   = isset( $input['api_allowed_ips'] ) ? (string) $input['api_allowed_ips'] : '';
        $ip_lines  = preg_split( '/[\r\n]+/', $raw_ips );
        $clean_ips = array();
        foreach ( $ip_lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            if ( false !== strpos( $line, '/' ) ) {
                // CIDR notation — validate the network address and prefix.
                list( $subnet, $prefix ) = explode( '/', $line, 2 );
                if ( filter_var( trim( $subnet ), FILTER_VALIDATE_IP ) && is_numeric( $prefix ) ) {
                    $clean_ips[] = $line;
                }
            } elseif ( filter_var( $line, FILTER_VALIDATE_IP ) ) {
                $clean_ips[] = $line;
            }
        }
        $clean['api_allowed_ips'] = implode( "\n", $clean_ips );

        // api_enabled: boolean (Pro feature; defaults true so existing installs are unaffected).
        $clean['api_enabled'] = isset( $input['api_enabled'] ) ? (bool) $input['api_enabled'] : true;

        // api_allowed_users: array of valid admin user IDs.
        $raw_users   = isset( $input['api_allowed_users'] ) ? (array) $input['api_allowed_users'] : array();
        $clean_users = array();
        foreach ( $raw_users as $uid ) {
            $uid = absint( $uid );
            if ( $uid > 0 ) {
                $u = get_userdata( $uid );
                if ( $u && user_can( $u, 'manage_options' ) ) {
                    $clean_users[] = $uid;
                }
            }
        }
        $clean['api_allowed_users'] = array_values( array_unique( $clean_users ) );

        add_settings_error(
            SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION,
            'settings_saved',
            __( 'Settings saved.', 'scriptomatic' ),
            'updated'
        );

        // Diff $current vs $clean and write a prefs log entry when anything changed.
        $this->maybe_write_prefs_change( $current, $clean );

        return $clean;
    }

    /**
     * Diff two settings arrays and write a prefs log entry if anything changed.
     *
     * Compares only the fields that are user-editable. No-op when $current and
     * $clean are identical. Called from sanitize_plugin_settings() after the
     * sanitised array is finalised.
     *
     * @since  3.2.0
     * @access private
     * @param  array $old  Previous settings (before this save).
     * @param  array $new  Sanitised incoming settings.
     * @return void
     */
    private function maybe_write_prefs_change( array $old, array $new ) {
        $labels = array(
            'max_log_entries'        => __( 'Activity Log Limit', 'scriptomatic' ),
            'keep_data_on_uninstall' => __( 'Keep Data on Uninstall', 'scriptomatic' ),
            'save_confirm_enabled'   => __( 'Save Confirmation', 'scriptomatic' ),
            'api_enabled'            => __( 'REST API', 'scriptomatic' ),
            'api_allowed_ips'        => __( 'API Allowed IPs', 'scriptomatic' ),
            'api_allowed_users'      => __( 'API Allowed Users', 'scriptomatic' ),
        );

        $diff         = array();
        $detail_parts = array();

        foreach ( $labels as $key => $label ) {
            $old_val = isset( $old[ $key ] ) ? $old[ $key ] : null;
            $new_val = isset( $new[ $key ] ) ? $new[ $key ] : null;

            // Normalise for comparison: encode arrays, cast booleans to string.
            $old_cmp = is_array( $old_val ) ? wp_json_encode( $old_val ) : (string) $old_val;
            $new_cmp = is_array( $new_val ) ? wp_json_encode( $new_val ) : (string) $new_val;

            if ( $old_cmp === $new_cmp ) {
                continue;
            }

            // Build a human-readable old/new string for each type.
            if ( is_bool( $old_val ) || is_bool( $new_val ) ) {
                $old_str = $old_val ? __( 'on', 'scriptomatic' ) : __( 'off', 'scriptomatic' );
                $new_str = $new_val ? __( 'on', 'scriptomatic' ) : __( 'off', 'scriptomatic' );
            } elseif ( 'api_allowed_users' === $key ) {
                $old_str = sprintf( _n( '%d user', '%d users', count( (array) $old_val ), 'scriptomatic' ), count( (array) $old_val ) );
                $new_str = sprintf( _n( '%d user', '%d users', count( (array) $new_val ), 'scriptomatic' ), count( (array) $new_val ) );
            } elseif ( 'api_allowed_ips' === $key ) {
                $old_str = __( '(previous list)', 'scriptomatic' );
                $new_str = __( '(updated)', 'scriptomatic' );
            } else {
                $old_str = (string) $old_val;
                $new_str = (string) $new_val;
            }

            $diff[ $key ] = array( 'old' => $old_str, 'new' => $new_str );
            /* translators: 1: setting label, 2: old value, 3: new value */
            $detail_parts[] = sprintf( __( '%1$s: %2$s → %3$s', 'scriptomatic' ), $label, $old_str, $new_str );
        }

        if ( empty( $diff ) ) {
            return; // Nothing changed — Settings API called sanitize on a no-op submit.
        }

        $this->write_prefs_log_entry( implode( ' · ', $detail_parts ), $diff );
    }

    /**
     * Return the configured maximum number of audit log entries to retain.
     *
     * Falls back to the {@see SCRIPTOMATIC_MAX_LOG_ENTRIES} constant when no
     * saved setting exists.
     *
     * @since  1.7.0
     * @return int
     */
    public function get_max_log_entries() {
        $settings = $this->get_plugin_settings();
        return isset( $settings['max_log_entries'] ) ? (int) $settings['max_log_entries'] : SCRIPTOMATIC_MAX_LOG_ENTRIES;
    }

    /**
     * Return whether the save confirmation dialog is enabled.
     *
     * @since  3.2.0
     * @return bool
     */
    public function is_save_confirm_enabled() {
        $settings = $this->get_plugin_settings();
        return isset( $settings['save_confirm_enabled'] ) ? (bool) $settings['save_confirm_enabled'] : true;
    }

    // =========================================================================
    // PREFERENCES LOG — READ / WRITE / TABLE
    // =========================================================================

    /**
     * Create the preferences log DB table if it does not exist.
     *
     * Table `{prefix}scriptomatic_prefs_log`:
     *   id         — auto-increment primary key
     *   timestamp  — unix epoch
     *   user_id    — acting user ID
     *   user_login — acting user login name (snapshot at write time)
     *   detail     — human-readable summary, e.g. "Activity Log Limit: 200 → 100"
     *   changes    — JSON object: { field: { old: string, new: string } }
     *
     * Hard-capped at 100 rows; oldest rows pruned after each insert.
     *
     * @since  3.2.0
     * @access private
     * @return void
     */
    private function maybe_create_prefs_log_table() {
        global $wpdb;
        $table           = $wpdb->prefix . SCRIPTOMATIC_PREFS_LOG_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            timestamp  INT UNSIGNED NOT NULL DEFAULT 0,
            user_id    INT UNSIGNED NOT NULL DEFAULT 0,
            user_login VARCHAR(60)  NOT NULL DEFAULT '',
            detail     TEXT         NOT NULL,
            source     VARCHAR(16)  NOT NULL DEFAULT 'dashboard',
            changes    TEXT                  DEFAULT NULL,
            PRIMARY KEY (id)
        ) {$charset_collate};"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Return 'dashboard', 'api', or 'cli' depending on the current execution context.
     *
     * Used to populate the `source` column on every log write so that each entry
     * records whether it originated from the admin Dashboard, a REST API call, or
     * a WP-CLI command.
     *
     * @since  3.2.0
     * @access private
     * @return string 'cli'|'api'|'dashboard'
     */
    private function get_current_source() {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return 'cli';
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return 'api';
        }
        return 'dashboard';
    }

    /**
     * Insert a row into the preferences log table and prune to 100 rows.
     *
     * @since  3.2.0
     * @access private
     * @param  string $detail   Human-readable summary of what changed.
     * @param  array  $changes  Associative array: { field => { old, new } }.
     * @return void
     */
    private function write_prefs_log_entry( $detail, array $changes = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . SCRIPTOMATIC_PREFS_LOG_TABLE;
        $user  = wp_get_current_user();

        $wpdb->insert(
            $table,
            array(
                'timestamp'  => time(),
                'user_id'    => (int) $user->ID,
                'user_login' => (string) $user->user_login,
                'detail'     => (string) $detail,
                'source'     => $this->get_current_source(),
                'changes'    => ! empty( $changes ) ? wp_json_encode( $changes ) : null,
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s' )
        );

        // Hard cap at 100 rows.
        $keep_id = $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d',
            $table,
            99
        ) );
        if ( $keep_id ) {
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', $table, (int) $keep_id ) );
        }
    }

    /**
     * Fetch rows from the preferences log table, newest first.
     *
     * @since  3.2.0
     * @access private
     * @param  int $limit   Maximum rows to return.
     * @param  int $offset  Rows to skip.
     * @return array  Each element: { id, timestamp, user_id, user_login, detail, changes (array) }.
     */
    private function get_prefs_log( $limit = 20, $offset = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . SCRIPTOMATIC_PREFS_LOG_TABLE;
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
                $table, (int) $limit, (int) $offset
            ),
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) {
            return array();
        }
        $result = array();
        foreach ( $rows as $row ) {
            $entry = array(
                'id'         => (int) $row['id'],
                'timestamp'  => (int) $row['timestamp'],
                'user_id'    => (int) $row['user_id'],
                'user_login' => (string) $row['user_login'],
                'detail'     => (string) $row['detail'],
                'source'     => isset( $row['source'] ) ? (string) $row['source'] : 'dashboard',
                'changes'    => array(),
            );
            if ( ! empty( $row['changes'] ) ) {
                $decoded = json_decode( $row['changes'], true );
                if ( is_array( $decoded ) ) {
                    $entry['changes'] = $decoded;
                }
            }
            $result[] = $entry;
        }
        return $result;
    }

    // =========================================================================
    // ACTIVITY LOG DB TABLE
    // =========================================================================

    /**
     * Create the custom activity log DB table if it does not exist.
     *
     * Uses dbDelta() so it is safe to call on every admin_init after a version
     * bump and on plugin activation.
     *
     * Table `{prefix}scriptomatic_log`:
     *   id         — auto-increment primary key
     *   timestamp  — unix epoch (INT not DATETIME, consistent with WP convention)
     *   user_id    — acting user ID
     *   user_login — acting user login name (snapshot at write time)
     *   action     — event type: save|rollback|url_save|url_rollback|file_save|…
     *   location   — head|footer|file
     *   file_id    — populated for file actions, NULL otherwise
     *   detail     — human-readable change summary (TEXT)
     *   chars      — byte-length of content snapshot, NULL when not applicable
     *   snapshot   — JSON blob: {content?, conditions_snapshot?, urls_snapshot?, conditions?, meta?}
     *
     * @since  2.9.0
     * @access private
     * @return void
     */
    private function maybe_create_log_table() {
        global $wpdb;
        $table      = $wpdb->prefix . SCRIPTOMATIC_LOG_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            timestamp  INT UNSIGNED NOT NULL DEFAULT 0,
            user_id    INT UNSIGNED NOT NULL DEFAULT 0,
            user_login VARCHAR(60)  NOT NULL DEFAULT '',
            action     VARCHAR(40)  NOT NULL DEFAULT '',
            location   VARCHAR(20)  NOT NULL DEFAULT '',
            file_id    VARCHAR(60)           DEFAULT NULL,
            detail     TEXT         NOT NULL,
            chars      INT UNSIGNED          DEFAULT NULL,
            source     VARCHAR(16)  NOT NULL DEFAULT 'dashboard',
            snapshot   LONGTEXT              DEFAULT NULL,
            PRIMARY KEY (id),
            KEY location_action (location(20), action(40)),
            KEY file_id (file_id(60))
        ) {$charset_collate};"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // =========================================================================
    // ACTIVITY LOG — READ / WRITE HELPERS
    // =========================================================================

    /**
     * Decode a raw DB row from `{prefix}scriptomatic_log` into a flat PHP array
     * compatible with the rest of the codebase (same shape as the old option entries).
     *
     * @since  2.9.0
     * @access private
     * @param  array $row  Associative row from $wpdb->get_row() / get_results().
     * @return array
     */
    private function decode_log_row( array $row ) {
        $entry = array(
            'id'         => (int) $row['id'],
            'timestamp'  => (int) $row['timestamp'],
            'user_id'    => (int) $row['user_id'],
            'user_login' => (string) $row['user_login'],
            'action'     => (string) $row['action'],
            'location'   => (string) $row['location'],
            'detail'     => (string) $row['detail'],
            'source'     => isset( $row['source'] ) ? (string) $row['source'] : 'dashboard',
        );
        if ( null !== $row['file_id'] ) {
            $entry['file_id'] = (string) $row['file_id'];
        }
        if ( null !== $row['chars'] ) {
            $entry['chars'] = (int) $row['chars'];
        }
        if ( null !== $row['snapshot'] ) {
            $snap = json_decode( $row['snapshot'], true );
            if ( is_array( $snap ) ) {
                foreach ( $snap as $k => $v ) {
                    $entry[ $k ] = $v;
                }
            }
        }
        return $entry;
    }

    /**
     * Fetch a single log entry by its primary-key ID.
     *
     * @since  2.9.0
     * @access private
     * @param  int $id  Row primary key.
     * @return array|null  Decoded entry array, or null if not found.
     */
    private function get_log_entry_by_id( $id ) {
        global $wpdb;
        $table     = $wpdb->prefix . SCRIPTOMATIC_LOG_TABLE;
        $cache_key = 'log_entry_' . (int) $id;
        $cached    = wp_cache_get( $cache_key, 'scriptomatic_log' );
        if ( false !== $cached ) {
            return is_array( $cached ) ? $this->decode_log_row( $cached ) : null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, (int) $id ),
            ARRAY_A
        );
        wp_cache_set( $cache_key, $row ? $row : 0, 'scriptomatic_log', 300 );
        if ( ! $row ) {
            return null;
        }
        return $this->decode_log_row( $row );
    }

    /**
     * Append an entry to the custom activity log table.
     *
     * Stamps the entry with the current time and acting user; after inserting,
     * prunes the oldest rows when the total count exceeds the configured maximum.
     *
     * Accepted $data keys:
     *   action               (string) — 'save'|'rollback'|'url_save'|'url_rollback'|'file_save'|…
     *   location             (string) — 'head'|'footer'|'file'
     *   content              (string) — snapshot; for save/rollback types (enables View+Restore)
     *   chars                (int)    — byte length of content
     *   detail               (string) — human-readable summary
     *   urls_snapshot        (array)  — URL list snapshot
     *   conditions_snapshot  (array)  — conditions snapshot
     *   file_id              (string) — only for file actions
     *   source               (string) — auto-detected; do not pass; set by write_activity_entry()
     *
     * @since  1.0.0
     * @since  2.9.0 Rewrites to INSERT into `{prefix}scriptomatic_log` instead of wp_options.
     * @access private
     * @param  array $data Entry data.
     * @return void
     */
    private function write_activity_entry( array $data ) {
        global $wpdb;

        $table = $wpdb->prefix . SCRIPTOMATIC_LOG_TABLE;
        $user  = wp_get_current_user();

        // Collect snapshot-only keys into the JSON blob.
        $snapshot_keys = array( 'content', 'conditions_snapshot', 'urls_snapshot', 'conditions', 'meta' );
        $snap_data     = array();
        foreach ( $snapshot_keys as $k ) {
            if ( array_key_exists( $k, $data ) ) {
                $snap_data[ $k ] = $data[ $k ];
            }
        }

        $chars = ( isset( $data['chars'] ) && null !== $data['chars'] ) ? (int) $data['chars'] : null;

        $wpdb->insert(
            $table,
            array(
                'timestamp'  => time(),
                'user_id'    => (int) $user->ID,
                'user_login' => (string) $user->user_login,
                'action'     => isset( $data['action'] )   ? (string) $data['action']   : '',
                'location'   => isset( $data['location'] ) ? (string) $data['location'] : '',
                'file_id'    => isset( $data['file_id'] )  ? (string) $data['file_id']  : null,
                'detail'     => isset( $data['detail'] )   ? (string) $data['detail']   : '',
                'chars'      => $chars,
                'source'     => $this->get_current_source(),
                'snapshot'   => ! empty( $snap_data )      ? wp_json_encode( $snap_data ) : null,
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        // Prune oldest rows when total row count exceeds the configured maximum.
        $max       = $this->get_max_log_entries();
        $prune_key = 'prune_id_' . (int) $max;
        $keep_id   = wp_cache_get( $prune_key, 'scriptomatic_log' );
        if ( false === $keep_id ) {
            $keep_id = $wpdb->get_var( $wpdb->prepare(
                'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d',
                $table,
                $max - 1
            ) );
            wp_cache_set( $prune_key, $keep_id ? $keep_id : 0, 'scriptomatic_log', 5 );
        }
        if ( $keep_id ) {
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', $table, (int) $keep_id ) );
            wp_cache_delete( $prune_key, 'scriptomatic_log' );
        }
    }

    /**
     * Return activity log entries from the custom DB table.
     *
     * @since  1.0.0
     * @since  2.9.0 Reads from `{prefix}scriptomatic_log` instead of wp_options.
     * @access private
     * @param int    $limit    Maximum rows to return (0 = use get_max_log_entries()).
     * @param int    $offset   Number of rows to skip (for pagination).
     * @param string $location Optional: filter by location ('head'|'footer'|'file'|'').
     * @param string $file_id  Optional: further filter by file_id when non-empty.
     * @return array  Each element is a decoded entry array (same shape as old option entries, plus 'id').
     */
    private function get_activity_log( $limit = 0, $offset = 0, $location = '', $file_id = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . SCRIPTOMATIC_LOG_TABLE;
        if ( $limit <= 0 ) {
            $limit = $this->get_max_log_entries();
        }

        $cache_key = 'log_query_' . md5( $limit . '|' . $offset . '|' . $location . '|' . $file_id );
        $cached    = wp_cache_get( $cache_key, 'scriptomatic_log' );
        if ( false !== $cached ) {
            return $cached;
        }

        if ( '' !== $location && '' !== $file_id ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE location = %s AND file_id = %s ORDER BY id DESC LIMIT %d OFFSET %d',
                    $table, $location, $file_id, $limit, $offset
                ),
                ARRAY_A
            );
        } elseif ( '' !== $location ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE location = %s ORDER BY id DESC LIMIT %d OFFSET %d',
                    $table, $location, $limit, $offset
                ),
                ARRAY_A
            );
        } elseif ( '' !== $file_id ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE file_id = %s ORDER BY id DESC LIMIT %d OFFSET %d',
                    $table, $file_id, $limit, $offset
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
                    $table, $limit, $offset
                ),
                ARRAY_A
            );
        }

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $result = array_map( array( $this, 'decode_log_row' ), $rows );
        wp_cache_set( $cache_key, $result, 'scriptomatic_log', 60 );
        return $result;
    }
}
