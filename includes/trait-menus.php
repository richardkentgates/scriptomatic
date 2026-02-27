<?php
/**
 * Trait: Admin menu registration for Scriptomatic.
 *
 * Provides add_admin_menus() and add_network_admin_menus() for both the
 * per-site and network-admin contexts.
 *
 * @package  Scriptomatic
 * @since    1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers all Scriptomatic admin menus (per-site and network).
 */
trait Scriptomatic_Menus {

    /**
     * Register the top-level Scriptomatic menu and its three sub-pages for
     * the regular (per-site) admin area.
     *
     * Menu position 82 places the entry just after Settings (80),
     * without conflicting with any default WordPress items.
     *
     * @since  1.2.0
     * @return void
     */
    public function add_admin_menus() {
        $cap = $this->get_required_cap();

        // Top-level entry — doubles as the Head Scripts sub-page.
        add_menu_page(
            __( 'Scriptomatic', 'scriptomatic' ),
            __( 'Scriptomatic', 'scriptomatic' ),
            $cap,
            'scriptomatic',
            array( $this, 'render_head_page' ),
            'dashicons-editor-code',
            82
        );

        // Sub-page: Head Scripts (replaces the auto-generated duplicate).
        $head_hook = add_submenu_page(
            'scriptomatic',
            __( 'Head Scripts — Scriptomatic', 'scriptomatic' ),
            __( 'Head Scripts', 'scriptomatic' ),
            $cap,
            'scriptomatic',
            array( $this, 'render_head_page' )
        );

        // Sub-page: Footer Scripts.
        $footer_hook = add_submenu_page(
            'scriptomatic',
            __( 'Footer Scripts — Scriptomatic', 'scriptomatic' ),
            __( 'Footer Scripts', 'scriptomatic' ),
            $cap,
            'scriptomatic-footer',
            array( $this, 'render_footer_page' )
        );

        // Sub-page: General Settings.
        $general_hook = add_submenu_page(
            'scriptomatic',
            __( 'General Settings — Scriptomatic', 'scriptomatic' ),
            __( 'General Settings', 'scriptomatic' ),
            $cap,
            'scriptomatic-settings',
            array( $this, 'render_general_settings_page' )
        );

        // Sub-page: Audit Log.
        add_submenu_page(
            'scriptomatic',
            __( 'Audit Log — Scriptomatic', 'scriptomatic' ),
            __( 'Audit Log', 'scriptomatic' ),
            $cap,
            'scriptomatic-audit-log',
            array( $this, 'render_audit_log_page' )
        );

        // Attach contextual help to each sub-page.
        add_action( 'load-' . $head_hook,    array( $this, 'add_help_tab' ) );
        add_action( 'load-' . $footer_hook,  array( $this, 'add_help_tab' ) );
        add_action( 'load-' . $general_hook, array( $this, 'add_help_tab' ) );
    }

    /**
     * Register the parallel Scriptomatic menu in the Network Admin area.
     *
     * Only available when the plugin is network-activated.  Network admin
     * pages cannot use `options.php` for saving, so each page form targets
     * `edit.php?action=scriptomatic_network_save` which is handled by
     * {@see Scriptomatic::handle_network_settings_save()}.
     *
     * @since  1.2.0
     * @return void
     */
    public function add_network_admin_menus() {
        if ( ! $this->is_network_active() ) {
            return;
        }

        $cap = $this->get_network_cap();

        add_menu_page(
            __( 'Scriptomatic Network', 'scriptomatic' ),
            __( 'Scriptomatic', 'scriptomatic' ),
            $cap,
            'scriptomatic-network',
            array( $this, 'render_network_head_page' ),
            'dashicons-editor-code',
            82
        );

        add_submenu_page(
            'scriptomatic-network',
            __( 'Head Scripts — Scriptomatic Network', 'scriptomatic' ),
            __( 'Head Scripts', 'scriptomatic' ),
            $cap,
            'scriptomatic-network',
            array( $this, 'render_network_head_page' )
        );

        $net_footer_hook = add_submenu_page(
            'scriptomatic-network',
            __( 'Footer Scripts — Scriptomatic Network', 'scriptomatic' ),
            __( 'Footer Scripts', 'scriptomatic' ),
            $cap,
            'scriptomatic-network-footer',
            array( $this, 'render_network_footer_page' )
        );

        $net_general_hook = add_submenu_page(
            'scriptomatic-network',
            __( 'General Settings — Scriptomatic Network', 'scriptomatic' ),
            __( 'General Settings', 'scriptomatic' ),
            $cap,
            'scriptomatic-network-settings',
            array( $this, 'render_network_general_page' )
        );

        add_submenu_page(
            'scriptomatic-network',
            __( 'Audit Log — Scriptomatic Network', 'scriptomatic' ),
            __( 'Audit Log', 'scriptomatic' ),
            $cap,
            'scriptomatic-network-audit-log',
            array( $this, 'render_network_audit_log_page' )
        );

        // Attach contextual help to each network sub-page.
        add_action( 'load-toplevel_page_scriptomatic-network', array( $this, 'add_help_tab' ) );
        add_action( 'load-' . $net_footer_hook,                array( $this, 'add_help_tab' ) );
        add_action( 'load-' . $net_general_hook,               array( $this, 'add_help_tab' ) );
    }
}
