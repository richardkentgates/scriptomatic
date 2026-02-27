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
        // ---- HEAD SCRIPTS GROUP ----
        register_setting( 'scriptomatic_head_group', SCRIPTOMATIC_HEAD_SCRIPT, array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_head_script' ),
            'default'           => '',
        ) );
        register_setting( 'scriptomatic_head_group', SCRIPTOMATIC_HEAD_LINKED, array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_head_linked' ),
            'default'           => '[]',
        ) );

        add_settings_section( 'sm_head_code',  __( 'Inline Script', 'scriptomatic' ),       array( $this, 'render_head_code_section' ),  'scriptomatic_head_page' );

        add_settings_field( SCRIPTOMATIC_HEAD_SCRIPT, __( 'Script Content', 'scriptomatic' ),
            array( $this, 'render_head_script_field' ), 'scriptomatic_head_page', 'sm_head_code' );

        // ---- HEAD CONDITIONS ----
        register_setting( 'scriptomatic_head_group', SCRIPTOMATIC_HEAD_CONDITIONS, array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_head_conditions' ),
            'default'           => '{"type":"all","values":[]}',
        ) );
        add_settings_section( 'sm_head_conditions', __( 'Load Conditions', 'scriptomatic' ), array( $this, 'render_head_conditions_section' ), 'scriptomatic_head_page' );
        add_settings_field( SCRIPTOMATIC_HEAD_CONDITIONS, __( 'When to inject', 'scriptomatic' ),
            array( $this, 'render_head_conditions_field' ), 'scriptomatic_head_page', 'sm_head_conditions' );

        add_settings_section( 'sm_head_links', __( 'External Script URLs', 'scriptomatic' ), array( $this, 'render_head_links_section' ), 'scriptomatic_head_page' );

        add_settings_field( SCRIPTOMATIC_HEAD_LINKED, __( 'Script URLs', 'scriptomatic' ),
            array( $this, 'render_head_linked_field' ), 'scriptomatic_head_page', 'sm_head_links' );

        // ---- FOOTER SCRIPTS GROUP ----
        register_setting( 'scriptomatic_footer_group', SCRIPTOMATIC_FOOTER_SCRIPT, array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_footer_script' ),
            'default'           => '',
        ) );
        register_setting( 'scriptomatic_footer_group', SCRIPTOMATIC_FOOTER_LINKED, array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_footer_linked' ),
            'default'           => '[]',
        ) );

        add_settings_section( 'sm_footer_code',  __( 'Inline Script', 'scriptomatic' ),       array( $this, 'render_footer_code_section' ),  'scriptomatic_footer_page' );

        add_settings_field( SCRIPTOMATIC_FOOTER_SCRIPT, __( 'Script Content', 'scriptomatic' ),
            array( $this, 'render_footer_script_field' ), 'scriptomatic_footer_page', 'sm_footer_code' );

        // ---- FOOTER CONDITIONS ----
        register_setting( 'scriptomatic_footer_group', SCRIPTOMATIC_FOOTER_CONDITIONS, array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_footer_conditions' ),
            'default'           => '{"type":"all","values":[]}',
        ) );
        add_settings_section( 'sm_footer_conditions', __( 'Load Conditions', 'scriptomatic' ), array( $this, 'render_footer_conditions_section' ), 'scriptomatic_footer_page' );
        add_settings_field( SCRIPTOMATIC_FOOTER_CONDITIONS, __( 'When to inject', 'scriptomatic' ),
            array( $this, 'render_footer_conditions_field' ), 'scriptomatic_footer_page', 'sm_footer_conditions' );

        add_settings_section( 'sm_footer_links', __( 'External Script URLs', 'scriptomatic' ), array( $this, 'render_footer_links_section' ), 'scriptomatic_footer_page' );

        add_settings_field( SCRIPTOMATIC_FOOTER_LINKED, __( 'Script URLs', 'scriptomatic' ),
            array( $this, 'render_footer_linked_field' ), 'scriptomatic_footer_page', 'sm_footer_links' );

        // ---- GENERAL SETTINGS GROUP ----
        register_setting( 'scriptomatic_general_group', SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION, array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize_plugin_settings' ),
            'default'           => array(
                'max_log_entries'        => SCRIPTOMATIC_MAX_LOG_ENTRIES,
                'keep_data_on_uninstall' => false,
            ),
        ) );

        add_settings_section( 'sm_advanced', __( 'Advanced Settings', 'scriptomatic' ), array( $this, 'render_advanced_section' ), 'scriptomatic_general_page' );

        add_settings_field( 'scriptomatic_max_log_entries', __( 'Activity Log Limit', 'scriptomatic' ),
            array( $this, 'render_max_log_field' ), 'scriptomatic_general_page', 'sm_advanced' );
        add_settings_field( 'scriptomatic_keep_data', __( 'Data on Uninstall', 'scriptomatic' ),
            array( $this, 'render_keep_data_field' ), 'scriptomatic_general_page', 'sm_advanced' );
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
        );
        $saved = get_option( SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION, false );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
    }

    /**
     * Sanitise and validate the plugin settings array submitted from the form.
     *
     * If `max_history` is being reduced the stored history is immediately
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

        // If the log limit was reduced, immediately trim the unified activity log.
        if ( $clean['max_log_entries'] < $this->get_max_log_entries() ) {
            $log = $this->get_activity_log();
            if ( count( $log ) > $clean['max_log_entries'] ) {
                update_option( SCRIPTOMATIC_ACTIVITY_LOG_OPTION, array_slice( $log, 0, $clean['max_log_entries'] ) );
            }
        }

        // keep_data_on_uninstall: boolean.
        $clean['keep_data_on_uninstall'] = ! empty( $input['keep_data_on_uninstall'] );

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

    /**
     * Write a security-audit log entry when a script changes.
     *
     * In v1.2.0–v1.4.x this wrote to the PHP error log.  Since v1.5.0 it
     * delegates to {@see write_audit_log_entry()} which persists the entry
     * to the WordPress options table, visible in the Audit Log embedded on the
     * Head Scripts and Footer Scripts pages.
     *
     * @since  1.2.0
     * @since  1.5.0 Writes to the persistent in-admin audit log instead of
     *               the PHP error log.
     * @access private
     * @param  string $new_content Sanitised content about to be saved.
     * @param  string $option_key  WordPress option key being updated.
     * @param  string $location    `'head'` or `'footer'`.
     * @return void
     */
    private function log_change( $new_content, $option_key, $location ) {
        $old_content = get_option( $option_key, '' );
        if ( $old_content !== $new_content ) {
            $this->write_activity_entry( array(
                'action'   => 'save',
                'location' => $location,
                'content'  => $new_content,
                'chars'    => strlen( $new_content ),
            ) );
        }
    }

    // =========================================================================
    // AUDIT LOG
    // =========================================================================

    /**
     * Append an entry to the persistent audit log.
     *
     * Always writes to per-site options.
     * The log is capped at {@see get_max_log_entries()} to bound storage.
     *
     * @since  1.5.0
     * @access private
     * @param  array $data Associative array with keys: action (string),
     *                     location (string), chars (int, for save/rollback),
     *                     detail (string, optional — URL for url_added/url_removed).
     * @return void
     */
    /**
     * Backward-compat alias — delegates to write_activity_entry().
     *
     * @since  1.5.0
     * @access private
     * @param  array $data Entry data (see write_activity_entry).
     * @return void
     */
    private function write_audit_log_entry( array $data ) {
        $this->write_activity_entry( $data );
    }

    /**
     * Append an entry to the unified activity log.
     *
     * Stamps the entry with the current time and acting user, prepends it to
     * the stored array, and trims to the configured maximum.
     *
     * Accepted $data keys:
     *   action   (string) — 'save'|'rollback'|'url_added'|'url_removed'|'file_save'|'file_rollback'|'file_delete'
     *   location (string) — 'head'|'footer'|'file'
     *   content  (string) — snapshot; only for save/rollback/file_save/file_rollback (enables View+Restore buttons)
     *   chars    (int)    — byte length of content
     *   detail   (string) — URL for url_added/url_removed; human label for file events
     *   file_id  (string) — only for file actions
     *
     * @since  1.9.0
     * @access private
     * @param  array $data Entry data.
     * @return void
     */
    private function write_activity_entry( array $data ) {
        $user  = wp_get_current_user();
        $entry = array_merge(
            array(
                'timestamp'  => time(),
                'user_login' => $user->user_login,
                'user_id'    => (int) $user->ID,
            ),
            $data
        );

        $log = $this->get_activity_log();

        array_unshift( $log, $entry );
        $max = $this->get_max_log_entries();
        if ( count( $log ) > $max ) {
            $log = array_slice( $log, 0, $max );
        }

        update_option( SCRIPTOMATIC_ACTIVITY_LOG_OPTION, $log );
    }

    /**
     * Return the unified activity log, running a one-time migration on first call.
     *
     * @since  1.9.0
     * @access private
     * @return array
     */
    private function get_activity_log() {
        $log = get_option( SCRIPTOMATIC_ACTIVITY_LOG_OPTION, null );
        if ( null === $log ) {
            $log = $this->migrate_to_unified_log();
            update_option( SCRIPTOMATIC_ACTIVITY_LOG_OPTION, $log );
        }
        return is_array( $log ) ? $log : array();
    }

    /**
     * Build the initial unified log from the legacy per-location history options
     * and the legacy audit-log option.
     *
     * Called exactly once — when SCRIPTOMATIC_ACTIVITY_LOG_OPTION is absent.
     *
     * @since  1.9.0
     * @access private
     * @return array
     */
    private function migrate_to_unified_log() {
        $merged = array();

        // Old head history → 'save'/'head' entries with content snapshots.
        $head_history = get_option( SCRIPTOMATIC_HEAD_HISTORY, array() );
        if ( is_array( $head_history ) ) {
            foreach ( $head_history as $e ) {
                $merged[] = array(
                    'timestamp'  => isset( $e['timestamp'] )  ? (int) $e['timestamp']  : 0,
                    'user_login' => isset( $e['user_login'] ) ? (string) $e['user_login'] : '',
                    'user_id'    => isset( $e['user_id'] )    ? (int) $e['user_id']     : 0,
                    'action'     => 'save',
                    'location'   => 'head',
                    'content'    => isset( $e['content'] )    ? (string) $e['content']  : '',
                    'chars'      => isset( $e['length'] )     ? (int) $e['length']
                                    : ( isset( $e['content'] ) ? strlen( $e['content'] ) : 0 ),
                );
            }
        }

        // Old footer history → 'save'/'footer' entries with content snapshots.
        $footer_history = get_option( SCRIPTOMATIC_FOOTER_HISTORY, array() );
        if ( is_array( $footer_history ) ) {
            foreach ( $footer_history as $e ) {
                $merged[] = array(
                    'timestamp'  => isset( $e['timestamp'] )  ? (int) $e['timestamp']  : 0,
                    'user_login' => isset( $e['user_login'] ) ? (string) $e['user_login'] : '',
                    'user_id'    => isset( $e['user_id'] )    ? (int) $e['user_id']     : 0,
                    'action'     => 'save',
                    'location'   => 'footer',
                    'content'    => isset( $e['content'] )    ? (string) $e['content']  : '',
                    'chars'      => isset( $e['length'] )     ? (int) $e['length']
                                    : ( isset( $e['content'] ) ? strlen( $e['content'] ) : 0 ),
                );
            }
        }

        // Old audit log — migrate only URL events (save/rollback already captured above).
        $audit = get_option( SCRIPTOMATIC_AUDIT_LOG_OPTION, array() );
        if ( is_array( $audit ) ) {
            foreach ( $audit as $e ) {
                if ( isset( $e['action'] ) && in_array( $e['action'], array( 'url_added', 'url_removed' ), true ) ) {
                    $merged[] = $e;
                }
            }
        }

        // Sort descending by timestamp (newest first).
        usort( $merged, function ( $a, $b ) {
            return ( isset( $b['timestamp'] ) ? (int) $b['timestamp'] : 0 )
                 - ( isset( $a['timestamp'] ) ? (int) $a['timestamp'] : 0 );
        } );

        return $merged;
    }
}
