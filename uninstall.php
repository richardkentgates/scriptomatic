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
 * All option keys written by the plugin.
 */
$scriptomatic_options = array(
    'scriptomatic_script_content',
    'scriptomatic_script_history',
    'scriptomatic_linked_scripts',
    'scriptomatic_plugin_settings',
);

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
    global $scriptomatic_options;

    // Read the plugin settings BEFORE deciding whether to delete anything.
    $plugin_settings        = get_option('scriptomatic_plugin_settings', array());
    $keep_data_on_uninstall = !empty($plugin_settings['keep_data_on_uninstall']);

    if ($keep_data_on_uninstall) {
        // Administrator chose to preserve data — nothing to do.
        error_log('Scriptomatic: Plugin uninstalled. Data preserved per plugin settings.');
        return;
    }

    // Single-site: delete from the current site.
    foreach ($scriptomatic_options as $option_key) {
        delete_option($option_key);
    }

    // Multisite: iterate every sub-site.
    if (is_multisite()) {
        global $wpdb;

        $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");

        foreach ($blog_ids as $blog_id) {
            switch_to_blog($blog_id);
            foreach ($scriptomatic_options as $option_key) {
                delete_option($option_key);
            }
            restore_current_blog();
        }

        // Network-wide options (if any were ever stored at the network level).
        foreach ($scriptomatic_options as $option_key) {
            delete_site_option($option_key);
        }
    }

    error_log('Scriptomatic: Plugin uninstalled and all data removed.');
}

scriptomatic_uninstall_cleanup();
