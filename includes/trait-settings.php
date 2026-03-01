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
        // Ensure the custom log table exists (idempotent via dbDelta).
        $this->maybe_create_log_table();
        // Migrate any data from the legacy wp_options log to the new table.
        $this->maybe_migrate_log_to_table();
        // Migrate data from pre-v2.8 fragmented options on first load.
        $this->maybe_migrate_to_v2_8();

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
                'api_allowed_ips'        => '',
            ),
        ) );

        add_settings_section( 'sm_advanced', '', array( $this, 'render_advanced_section' ), 'scriptomatic_general_page' );
        add_settings_field( 'scriptomatic_max_log_entries', __( 'Activity Log Limit', 'scriptomatic' ),
            array( $this, 'render_max_log_field' ), 'scriptomatic_general_page', 'sm_advanced' );
        add_settings_field( 'scriptomatic_keep_data', __( 'Data on Uninstall', 'scriptomatic' ),
            array( $this, 'render_keep_data_field' ), 'scriptomatic_general_page', 'sm_advanced' );
        add_settings_field( 'scriptomatic_api_allowed_ips', __( 'API Allowed IPs', 'scriptomatic' ),
            array( $this, 'render_api_allowed_ips_field' ), 'scriptomatic_general_page', 'sm_advanced' );
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

    // =========================================================================
    // MIGRATION — pre-v2.8 fragmented options → unified location arrays
    // =========================================================================

    /**
     * One-time migration from the six separate pre-v2.8 options to the two
     * unified location options.  Runs once at admin_init; no-ops on subsequent
     * requests once the new options exist.
     *
     * @since  2.8.0
     * @access private
     * @return void
     */
    private function maybe_migrate_to_v2_8() {
        // If either new option already exists, migration has already run.
        if ( false !== get_option( SCRIPTOMATIC_LOCATION_HEAD, false ) ) {
            return;
        }

        foreach ( array( 'head', 'footer' ) as $loc ) {
            if ( 'footer' === $loc ) {
                $script_opt = 'scriptomatic_footer_script';
                $cond_opt   = 'scriptomatic_footer_conditions';
                $urls_opt   = 'scriptomatic_footer_linked';
            } else {
                $script_opt = 'scriptomatic_script_content';
                $cond_opt   = 'scriptomatic_head_conditions';
                $urls_opt   = 'scriptomatic_linked_scripts';
            }

            $script   = (string) get_option( $script_opt, '' );
            $cond_raw = get_option( $cond_opt, '' );
            $cond     = is_string( $cond_raw ) && '' !== $cond_raw ? json_decode( $cond_raw, true ) : null;
            if ( ! is_array( $cond ) ) {
                $cond = array( 'logic' => 'and', 'rules' => array() );
            }
            $urls_raw = get_option( $urls_opt, '[]' );
            $urls     = json_decode( $urls_raw, true );
            if ( ! is_array( $urls ) ) {
                $urls = array();
            }

            $new_key = ( 'footer' === $loc ) ? SCRIPTOMATIC_LOCATION_FOOTER : SCRIPTOMATIC_LOCATION_HEAD;
            add_option( $new_key, array(
                'script'     => $script,
                'conditions' => $cond,
                'urls'       => $urls,
            ) );

            delete_option( $script_opt );
            delete_option( $cond_opt );
            delete_option( $urls_opt );
        }
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
            'api_allowed_ips'        => '',
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
                "SELECT id FROM `{$log_table}` ORDER BY id DESC LIMIT 1 OFFSET %d",
                $clean['max_log_entries'] - 1
            ) );
            if ( $keep_id ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM `{$log_table}` WHERE id < %d", (int) $keep_id ) );
            }
        }

        // keep_data_on_uninstall: boolean.
        $clean['keep_data_on_uninstall'] = ! empty( $input['keep_data_on_uninstall'] );

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

        add_settings_error(
            SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION,
            'settings_saved',
            __( 'Settings saved.', 'scriptomatic' ),
            'updated'
        );

        return $clean;
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
            snapshot   LONGTEXT              DEFAULT NULL,
            PRIMARY KEY (id),
            KEY location_action (location(20), action(40)),
            KEY file_id (file_id(60))
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * One-time migration from the legacy `scriptomatic_activity_log` wp_option
     * to the new custom DB table.
     *
     * Runs only when the option exists and is non-empty; deletes the option
     * on completion. Safe to call repeatedly — exits immediately on second call.
     *
     * @since  2.9.0
     * @access private
     * @return void
     */
    private function maybe_migrate_log_to_table() {
        global $wpdb;

        $old_log = get_option( SCRIPTOMATIC_ACTIVITY_LOG_OPTION, null );
        if ( ! is_array( $old_log ) || empty( $old_log ) ) {
            delete_option( SCRIPTOMATIC_ACTIVITY_LOG_OPTION );
            return;
        }

        $table = $wpdb->prefix . SCRIPTOMATIC_LOG_TABLE;

        // Insert in chronological order (oldest first → lowest IDs).
        foreach ( array_reverse( $old_log ) as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $snapshot_keys = array( 'content', 'conditions_snapshot', 'urls_snapshot', 'conditions', 'meta' );
            $snap_data     = array();
            foreach ( $snapshot_keys as $k ) {
                if ( array_key_exists( $k, $entry ) ) {
                    $snap_data[ $k ] = $entry[ $k ];
                }
            }

            $wpdb->insert(
                $table,
                array(
                    'timestamp'  => isset( $entry['timestamp'] )  ? (int) $entry['timestamp']     : time(),
                    'user_id'    => isset( $entry['user_id'] )    ? (int) $entry['user_id']        : 0,
                    'user_login' => isset( $entry['user_login'] ) ? (string) $entry['user_login']  : '',
                    'action'     => isset( $entry['action'] )     ? (string) $entry['action']      : '',
                    'location'   => isset( $entry['location'] )   ? (string) $entry['location']    : '',
                    'file_id'    => isset( $entry['file_id'] )    ? (string) $entry['file_id']     : null,
                    'detail'     => isset( $entry['detail'] )     ? (string) $entry['detail']      : '',
                    'chars'      => isset( $entry['chars'] )      ? (int) $entry['chars']           : null,
                    'snapshot'   => ! empty( $snap_data )         ? wp_json_encode( $snap_data )    : null,
                ),
                array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
            );
        }

        delete_option( SCRIPTOMATIC_ACTIVITY_LOG_OPTION );
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
        $table = $wpdb->prefix . SCRIPTOMATIC_LOG_TABLE;
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $id ), ARRAY_A );
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
                'snapshot'   => ! empty( $snap_data )      ? wp_json_encode( $snap_data ) : null,
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
        );

        // Prune oldest rows when total row count exceeds the configured maximum.
        $max     = $this->get_max_log_entries();
        $keep_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` ORDER BY id DESC LIMIT 1 OFFSET %d",
            $max - 1
        ) );
        if ( $keep_id ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id < %d", (int) $keep_id ) );
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

        $wheres = array( '1=1' );
        $args   = array();

        if ( '' !== $location ) {
            $wheres[] = 'location = %s';
            $args[]   = $location;
        }
        if ( '' !== $file_id ) {
            $wheres[] = 'file_id = %s';
            $args[]   = $file_id;
        }

        $args[] = $limit;
        $args[] = $offset;

        $sql  = 'SELECT * FROM `' . $table . '` WHERE ' . implode( ' AND ', $wheres )
              . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_map( array( $this, 'decode_log_row' ), $rows );
    }
}
