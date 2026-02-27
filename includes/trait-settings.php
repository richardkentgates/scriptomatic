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
        add_settings_section( 'sm_head_links', __( 'External Script URLs', 'scriptomatic' ), array( $this, 'render_head_links_section' ), 'scriptomatic_head_page' );

        add_settings_field( SCRIPTOMATIC_HEAD_SCRIPT, __( 'Script Content', 'scriptomatic' ),
            array( $this, 'render_head_script_field' ), 'scriptomatic_head_page', 'sm_head_code' );
        add_settings_field( SCRIPTOMATIC_HEAD_LINKED, __( 'Script URLs', 'scriptomatic' ),
            array( $this, 'render_head_linked_field' ), 'scriptomatic_head_page', 'sm_head_links' );

        // ---- HEAD CONDITIONS ----
        register_setting( 'scriptomatic_head_group', SCRIPTOMATIC_HEAD_CONDITIONS, array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_head_conditions' ),
            'default'           => '{"type":"all","values":[]}',
        ) );
        add_settings_section( 'sm_head_conditions', __( 'Load Conditions', 'scriptomatic' ), array( $this, 'render_head_conditions_section' ), 'scriptomatic_head_page' );
        add_settings_field( SCRIPTOMATIC_HEAD_CONDITIONS, __( 'When to inject', 'scriptomatic' ),
            array( $this, 'render_head_conditions_field' ), 'scriptomatic_head_page', 'sm_head_conditions' );

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
        add_settings_section( 'sm_footer_links', __( 'External Script URLs', 'scriptomatic' ), array( $this, 'render_footer_links_section' ), 'scriptomatic_footer_page' );

        add_settings_field( SCRIPTOMATIC_FOOTER_SCRIPT, __( 'Script Content', 'scriptomatic' ),
            array( $this, 'render_footer_script_field' ), 'scriptomatic_footer_page', 'sm_footer_code' );
        add_settings_field( SCRIPTOMATIC_FOOTER_LINKED, __( 'Script URLs', 'scriptomatic' ),
            array( $this, 'render_footer_linked_field' ), 'scriptomatic_footer_page', 'sm_footer_links' );

        // ---- FOOTER CONDITIONS ----
        register_setting( 'scriptomatic_footer_group', SCRIPTOMATIC_FOOTER_CONDITIONS, array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_footer_conditions' ),
            'default'           => '{"type":"all","values":[]}',
        ) );
        add_settings_section( 'sm_footer_conditions', __( 'Load Conditions', 'scriptomatic' ), array( $this, 'render_footer_conditions_section' ), 'scriptomatic_footer_page' );
        add_settings_field( SCRIPTOMATIC_FOOTER_CONDITIONS, __( 'When to inject', 'scriptomatic' ),
            array( $this, 'render_footer_conditions_field' ), 'scriptomatic_footer_page', 'sm_footer_conditions' );

        // ---- GENERAL SETTINGS GROUP ----
        register_setting( 'scriptomatic_general_group', SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION, array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize_plugin_settings' ),
            'default'           => array(
                'max_history'            => SCRIPTOMATIC_DEFAULT_MAX_HISTORY,
                'keep_data_on_uninstall' => false,
            ),
        ) );

        add_settings_section( 'sm_advanced', __( 'Advanced Settings', 'scriptomatic' ), array( $this, 'render_advanced_section' ), 'scriptomatic_general_page' );

        add_settings_field( 'scriptomatic_max_history', __( 'History Limit', 'scriptomatic' ),
            array( $this, 'render_max_history_field' ), 'scriptomatic_general_page', 'sm_advanced' );
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
            'max_history'            => SCRIPTOMATIC_DEFAULT_MAX_HISTORY,
            'keep_data_on_uninstall' => false,
        );
        if ( is_network_admin() ) {
            $saved = get_site_option( SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION, array() );
        } else {
            $saved = get_option( SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION, false );
            if ( false === $saved && is_multisite() ) {
                $saved = get_site_option( SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION, array() );
            }
        }
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

        if ( ! is_array( $input ) ) {
            return $current;
        }

        // Secondary nonce — only present (and enforced) when saving via the Settings API form
        // (options.php).  Skipped when called directly from handle_network_settings_save,
        // which performs its own nonce verification before reaching this method.
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

        // max_history: integer clamped to 1–100.
        $max                  = isset( $input['max_history'] ) ? (int) $input['max_history'] : $current['max_history'];
        $clean['max_history'] = max( 1, min( 100, $max ) );

        // keep_data_on_uninstall: boolean.
        $clean['keep_data_on_uninstall'] = ! empty( $input['keep_data_on_uninstall'] );

        // If the limit was reduced, immediately trim both history stacks.
        if ( $clean['max_history'] < $this->get_max_history() ) {
            foreach ( array( 'head', 'footer' ) as $loc ) {
                $history    = $this->get_history( $loc );
                $option_key = ( 'footer' === $loc ) ? SCRIPTOMATIC_FOOTER_HISTORY : SCRIPTOMATIC_HEAD_HISTORY;
                if ( count( $history ) > $clean['max_history'] ) {
                    update_option( $option_key, array_slice( $history, 0, $clean['max_history'] ) );
                }
            }
        }

        return $clean;
    }

    /**
     * Write a security-audit log entry when a script changes.
     *
     * @since  1.2.0
     * @access private
     * @param  string $new_content Sanitised content about to be saved.
     * @param  string $option_key  WordPress option key being updated.
     * @param  string $location    `'head'` or `'footer'`.
     * @return void
     */
    private function log_change( $new_content, $option_key, $location ) {
        $old_content = is_network_admin()
            ? get_site_option( $option_key, '' )
            : get_option( $option_key, '' );
        if ( $old_content !== $new_content ) {
            $user = wp_get_current_user();
            error_log( sprintf(
                'Scriptomatic: %s script updated by user %s (ID: %d)',
                ucfirst( $location ),
                $user->user_login,
                $user->ID
            ) );
        }
    }
}
