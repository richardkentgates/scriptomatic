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
if (!defined('WP_UNINSTALL_PLUGIN')) {
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
        // Head scripts
        'scriptomatic_script_content',
        'scriptomatic_script_history',
        'scriptomatic_linked_scripts',
        // Footer scripts (v1.2+)
        'scriptomatic_footer_script',
        'scriptomatic_footer_history',
        'scriptomatic_footer_linked',
        // Load conditions (v1.3+)
        'scriptomatic_head_conditions',
        'scriptomatic_footer_conditions',
        // Managed JS files (v1.8+)
        'scriptomatic_js_files',
        // General settings
        'scriptomatic_plugin_settings',
        // Activity log (v1.9+) — unified log; legacy keys kept for transition period
        'scriptomatic_activity_log',
        // Legacy audit log (v1.5–v1.8)
        'scriptomatic_audit_log',
    );

    // Read the plugin settings BEFORE deciding whether to delete anything.
    $plugin_settings        = get_option('scriptomatic_plugin_settings', array());
    $keep_data_on_uninstall = !empty($plugin_settings['keep_data_on_uninstall']);

    if ($keep_data_on_uninstall) {
        // Administrator chose to preserve data — nothing to do.
        error_log('Scriptomatic: Plugin uninstalled. Data preserved per plugin settings.');
        return;
    }

    // Single-site: delete from the current site.
    foreach ($options as $option_key) {
        delete_option($option_key);
    }
    scriptomatic_delete_uploads_dir();

    // Multisite: iterate every sub-site.
    if (is_multisite()) {
        global $wpdb;

        $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");

        foreach ((array) $blog_ids as $blog_id) {
            switch_to_blog($blog_id);
            foreach ($options as $option_key) {
                delete_option($option_key);
            }
            scriptomatic_delete_uploads_dir();
            restore_current_blog();
        }

        // Network-wide options (if any were ever stored at the network level).
        foreach ($options as $option_key) {
            delete_site_option($option_key);
        }
    }

    error_log('Scriptomatic: Plugin uninstalled and all data removed.');
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
    $dir    = trailingslashit($upload['basedir']) . 'scriptomatic/';

    if (!is_dir($dir)) {
        return;
    }

    // Remove all files, then the directory itself.
    $files = glob($dir . '*', GLOB_NOSORT);
    if (is_array($files)) {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file); // phpcs:ignore
            }
        }
    }
    @rmdir($dir); // phpcs:ignore
}

scriptomatic_uninstall_cleanup();
