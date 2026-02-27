<?php
/**
 * Plugin Name: Scriptomatic
 * Plugin URI: https://github.com/richardkentgates/scriptomatic
 * Description: Securely inject custom JavaScript into the head and footer of your WordPress site. Features per-location inline scripts, external URL management, full revision history with rollback, multisite support, and fine-grained admin controls.
 * Version: 1.7.0
 * Requires at least: 5.3
 * Requires PHP: 7.2
 * Author: Richard Kent Gates
 * Author URI: https://github.com/richardkentgates
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: scriptomatic
 * Domain Path: /languages
 *
 * @package Scriptomatic
 * @author Richard Kent Gates
 * @copyright 2026 Richard Kent Gates
 * @license GPL-2.0-or-later
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---- Core ----
define( 'SCRIPTOMATIC_VERSION',     '1.7.0' );
define( 'SCRIPTOMATIC_PLUGIN_FILE', __FILE__ );
define( 'SCRIPTOMATIC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'SCRIPTOMATIC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// ---- Option keys: head scripts ----
define( 'SCRIPTOMATIC_HEAD_SCRIPT',  'scriptomatic_script_content' );
define( 'SCRIPTOMATIC_HEAD_HISTORY', 'scriptomatic_script_history' );
define( 'SCRIPTOMATIC_HEAD_LINKED',  'scriptomatic_linked_scripts' );

// ---- Option keys: footer scripts ----
define( 'SCRIPTOMATIC_FOOTER_SCRIPT',  'scriptomatic_footer_script' );
define( 'SCRIPTOMATIC_FOOTER_HISTORY', 'scriptomatic_footer_history' );
define( 'SCRIPTOMATIC_FOOTER_LINKED',  'scriptomatic_footer_linked' );

// ---- Option keys: load conditions ----
define( 'SCRIPTOMATIC_HEAD_CONDITIONS',   'scriptomatic_head_conditions' );
define( 'SCRIPTOMATIC_FOOTER_CONDITIONS', 'scriptomatic_footer_conditions' );

// ---- Option keys: plugin settings ----
define( 'SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION', 'scriptomatic_plugin_settings' );

// ---- Limits / timing ----
define( 'SCRIPTOMATIC_MAX_SCRIPT_LENGTH',   100000 ); // 100 KB hard limit per inline script
define( 'SCRIPTOMATIC_RATE_LIMIT_SECONDS',  10 );     // Minimum seconds between saves per user
define( 'SCRIPTOMATIC_DEFAULT_MAX_HISTORY', 25 );     // Default revisions retained per location

// ---- Nonces ----
define( 'SCRIPTOMATIC_HEAD_NONCE',     'scriptomatic_save_head' );    // Head script form secondary nonce
define( 'SCRIPTOMATIC_FOOTER_NONCE',   'scriptomatic_save_footer' );  // Footer script form secondary nonce
define( 'SCRIPTOMATIC_GENERAL_NONCE',  'scriptomatic_save_general' ); // General settings form secondary nonce
define( 'SCRIPTOMATIC_ROLLBACK_NONCE', 'scriptomatic_rollback' );     // AJAX rollback nonce

// ---- Audit log ----
define( 'SCRIPTOMATIC_AUDIT_LOG_OPTION', 'scriptomatic_audit_log' );  // DB option key for the audit log
define( 'SCRIPTOMATIC_MAX_LOG_ENTRIES',  200 );                        // Maximum entries retained
define( 'SCRIPTOMATIC_CLEAR_LOG_NONCE',  'scriptomatic_clear_log' );  // Nonce action for clearing the log

// Load the main class (also requires all trait files).
require_once SCRIPTOMATIC_PLUGIN_DIR . 'includes/class-scriptomatic.php';

/**
 * Bootstrap the plugin by returning (and, on first call, creating) the
 * singleton {@see Scriptomatic} instance.
 *
 * Hooked on {@see 'plugins_loaded'} so that all WordPress APIs are available
 * before any plugin logic runs.
 *
 * @since  1.0.0
 * @return Scriptomatic
 */
function scriptomatic_init() {
    return Scriptomatic::get_instance();
}
add_action( 'plugins_loaded', 'scriptomatic_init' );
