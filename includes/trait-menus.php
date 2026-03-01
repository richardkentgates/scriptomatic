<?php
/**
 * Trait: Admin menu registration for Scriptomatic.
 *
 * Provides add_admin_menus() for the per-site admin area.
 * Install, uninstall, activate, and deactivate are the only network-level
 * operations; all script management is per-site.
 *
 * @package  Scriptomatic
 * @since    1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers all Scriptomatic admin menus and sub-pages (per-site only).
 *
 * Per-site: Head Scripts, Footer Scripts, Preferences.
 */
trait Scriptomatic_Menus {

    /**
     * Register the top-level Scriptomatic menu and its four sub-pages for
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

        // Sub-page: JS Files (Pro feature — free users see an upgrade notice).
        $files_hook = add_submenu_page(
            'scriptomatic',
            __( 'JS Files — Scriptomatic', 'scriptomatic' ),
            __( 'JS Files', 'scriptomatic' ),
            $cap,
            'scriptomatic-files',
            array( $this, scriptomatic_is_premium() ? 'render_js_files_page' : 'render_js_files_upgrade_page' )
        );

        // Sub-page: Preferences.
        $general_hook = add_submenu_page(
            'scriptomatic',
            __( 'Preferences — Scriptomatic', 'scriptomatic' ),
            __( 'Preferences', 'scriptomatic' ),
            $cap,
            'scriptomatic-settings',
            array( $this, 'render_general_settings_page' )
        );

        // Attach contextual help to each sub-page.
        add_action( 'load-' . $head_hook,    array( $this, 'add_help_tab' ) );
        add_action( 'load-' . $footer_hook,  array( $this, 'add_help_tab' ) );
        add_action( 'load-' . $files_hook,   array( $this, 'add_help_tab' ) );
        add_action( 'load-' . $general_hook, array( $this, 'add_help_tab' ) );
    }

}
