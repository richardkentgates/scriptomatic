<?php
/**
 * Uninstall Scriptomatic Plugin
 *
 * Runs when the plugin is deleted from WordPress.  Removes all plugin data
 * from the database unless the administrator has opted to preserve data via
 * the "Data on Uninstall" setting.
 *
 * @package Scriptomatic
 * @author  Richard Kent Gates
 * @copyright 2026 Richard Kent Gates
 * @license GPL-2.0-or-later
 */

// Exit if accessed directly or not uninstalling.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Remove all Scriptomatic data from the database.
 *
 * Honours the "keep_data_on_uninstall" setting: when the administrator has
 * ticked that option, this function returns immediately without deleting
 * anything.  For multisite installations it iterates every sub-site.
 *
 * @return void
 */
function scriptomatic_uninstall_cleanup() {
    $options = array(
        'scriptomatic_head',
        'scriptomatic_footer',
        'scriptomatic_js_files',
        'scriptomatic_plugin_settings',
        'scriptomatic_activity_log',
    );

    // Read the plugin settings BEFORE deciding whether to delete anything.
    $plugin_settings        = get_option( 'scriptomatic_plugin_settings', array() );
    $keep_data_on_uninstall = ! empty( $plugin_settings['keep_data_on_uninstall'] );

    if ( $keep_data_on_uninstall ) {
        return;
    }

    // Single-site: delete from the current site.
    foreach ( $options as $option_key ) {
        delete_option( $option_key );
    }
    scriptomatic_delete_uploads_dir();

    // Multisite: iterate every sub-site.
    if ( is_multisite() ) {
        global $wpdb;

        $blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        foreach ( (array) $blog_ids as $blog_id ) {
            switch_to_blog( $blog_id );
            foreach ( $options as $option_key ) {
                delete_option( $option_key );
            }
            scriptomatic_delete_uploads_dir();
            restore_current_blog();
        }

        // Network-wide options (if any were ever stored at the network level).
        foreach ( $options as $option_key ) {
            delete_site_option( $option_key );
        }
    }
}

/**
 * Remove the wp-content/uploads/scriptomatic/ directory and all files in it.
 *
 * Called once per site during uninstall.
 *
 * @return void
 */
function scriptomatic_delete_uploads_dir() {
    $upload = wp_upload_dir();
    $dir    = trailingslashit( $upload['basedir'] ) . 'scriptomatic/';

    if ( ! is_dir( $dir ) ) {
        return;
    }

    $files = glob( $dir . '*', GLOB_NOSORT );
    if ( is_array( $files ) ) {
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $file );
            }
        }
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    rmdir( $dir );
}

scriptomatic_uninstall_cleanup();
