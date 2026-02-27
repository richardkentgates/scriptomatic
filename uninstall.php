<?php
/**
 * Uninstall Scriptomatic Plugin
 *
 * This file runs when the plugin is uninstalled (deleted) from WordPress.
 * It removes all plugin data from the database to ensure clean uninstallation.
 *
 * @package Scriptomatic
 * @author Richard Kent Gates
 * @copyright 2026 Richard Kent Gates
 * @license GPL-2.0-or-later
 */

// Exit if accessed directly or not uninstalling
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up plugin data
 */
function scriptomatic_uninstall_cleanup() {
    // Delete plugin option for single site
    delete_option('scriptomatic_script_content');

    // For multisite installations, delete from all sites
    if (is_multisite()) {
        global $wpdb;

        // Get all blog IDs
        $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");

        foreach ($blog_ids as $blog_id) {
            switch_to_blog($blog_id);
            delete_option('scriptomatic_script_content');
            restore_current_blog();
        }

        // Delete site-wide option
        delete_site_option('scriptomatic_script_content');
    }

    // Log uninstallation for security audit
    error_log('Scriptomatic: Plugin uninstalled and all data removed.');
}

// Execute cleanup
scriptomatic_uninstall_cleanup();
