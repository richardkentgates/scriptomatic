<?php
/**
 * Trait: Admin-asset enqueueing for Scriptomatic.
 *
 * Registers and enqueues `assets/admin.css` and `assets/admin.js` on all
 * Scriptomatic admin pages, and passes PHP data to JS via `wp_localize_script`.
 *
 * Replaces the old inline-string approach (`wp_add_inline_style` /
 * `wp_add_inline_script` via `get_admin_css()` / `get_admin_js()`).
 *
 * @package  Scriptomatic
 * @since    1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin CSS/JS enqueue logic.
 */
trait Scriptomatic_Enqueue {

    /**
     * Enqueue scripts and styles for Scriptomatic admin pages.
     *
     * Fires on `admin_enqueue_scripts` (and `network_admin_enqueue_scripts`).
     * Early-returns on any hook not belonging to this plugin.
     *
     * @since  1.4.0
     * @param  string $hook The current admin-page hook suffix.
     * @return void
     */
    public function enqueue_admin_scripts( $hook ) {
        $head_hooks = array(
            'toplevel_page_scriptomatic',
        );
        $footer_hooks = array(
            'scriptomatic_page_scriptomatic-footer',
        );
        $general_hooks = array(
            'scriptomatic_page_scriptomatic-settings',
        );
        $network_head_hooks = array(
            'toplevel_page_scriptomatic-network',
            'scriptomatic-network_page_scriptomatic-network',
        );
        $network_footer_hooks = array(
            'scriptomatic-network_page_scriptomatic-network-footer',
        );
        $network_general_hooks = array(
            'scriptomatic-network_page_scriptomatic-network-settings',
        );

        $all_hooks = array_merge(
            $head_hooks, $footer_hooks, $general_hooks,
            $network_head_hooks, $network_footer_hooks, $network_general_hooks
        );

        if ( ! in_array( $hook, $all_hooks, true ) ) {
            return;
        }

        // Determine the active location for the JS context object.
        if ( in_array( $hook, array_merge( $footer_hooks, $network_footer_hooks ), true ) ) {
            $location = 'footer';
        } elseif ( in_array( $hook, array_merge( $general_hooks, $network_general_hooks ), true ) ) {
            $location = 'general';
        } else {
            $location = 'head';
        }

        // Enqueue the real CSS file.
        wp_enqueue_style(
            'scriptomatic-admin',
            SCRIPTOMATIC_PLUGIN_URL . 'assets/admin.css',
            array(),
            SCRIPTOMATIC_VERSION
        );

        // Enqueue the real JS file (depends on jQuery, loads in footer).
        wp_enqueue_script(
            'scriptomatic-admin-js',
            SCRIPTOMATIC_PLUGIN_URL . 'assets/admin.js',
            array( 'jquery' ),
            SCRIPTOMATIC_VERSION,
            true
        );

        // Pass PHP data to the JS module.
        wp_localize_script( 'scriptomatic-admin-js', 'scriptomaticData', array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'rollbackNonce' => wp_create_nonce( SCRIPTOMATIC_ROLLBACK_NONCE ),
            'maxLength'     => SCRIPTOMATIC_MAX_SCRIPT_LENGTH,
            'location'      => $location,
            'i18n'          => array(
                'invalidUrl'       => __( 'Please enter a valid http:// or https:// URL.', 'scriptomatic' ),
                'duplicateUrl'     => __( 'This URL has already been added.', 'scriptomatic' ),
                'rollbackConfirm'  => __( 'Restore this revision? The current script will be preserved in history.', 'scriptomatic' ),
                'rollbackSuccess'  => __( 'Script restored successfully.', 'scriptomatic' ),
                'rollbackError'    => __( 'Restore failed. Please try again.', 'scriptomatic' ),
                'restoring'        => __( "Restoring\u2026", 'scriptomatic' ),
                'invalidId'        => __( 'Please enter a valid positive integer ID.', 'scriptomatic' ),
                'duplicateId'      => __( 'This ID has already been added.', 'scriptomatic' ),
                'emptyPattern'     => __( 'Please enter a URL path or pattern.', 'scriptomatic' ),
                'duplicatePattern' => __( 'This pattern has already been added.', 'scriptomatic' ),
            ),
        ) );
    }
}
