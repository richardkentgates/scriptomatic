<?php
/**
 * Plugin Name: Scriptomatic
 * Plugin URI: https://github.com/richardkentgates/scriptomatic
 * Description: Inject custom JavaScript into the head and footer of your WordPress site. Manage standalone JS files with a built-in code editor, conditional per-page loading, external script URLs, revision history, rollback, activity logging, and fine-grained admin controls.
 * Version: 2.5.2
 * Requires at least: 5.3
 * Tested up to: 6.7
 * Requires PHP: 7.2
 * Author: Richard Kent Gates
 * Author URI: https://richardkentgates.com
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
define( 'SCRIPTOMATIC_VERSION',     '2.5.2' );
define( 'SCRIPTOMATIC_PLUGIN_FILE', __FILE__ );
define( 'SCRIPTOMATIC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'SCRIPTOMATIC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// ---- Option keys: head scripts ----
define( 'SCRIPTOMATIC_HEAD_SCRIPT',  'scriptomatic_script_content' );
define( 'SCRIPTOMATIC_HEAD_LINKED',  'scriptomatic_linked_scripts' );

// ---- Option keys: footer scripts ----
define( 'SCRIPTOMATIC_FOOTER_SCRIPT',  'scriptomatic_footer_script' );
define( 'SCRIPTOMATIC_FOOTER_LINKED',  'scriptomatic_footer_linked' );

// ---- Option keys: load conditions ----
define( 'SCRIPTOMATIC_HEAD_CONDITIONS',   'scriptomatic_head_conditions' );
define( 'SCRIPTOMATIC_FOOTER_CONDITIONS', 'scriptomatic_footer_conditions' );

// ---- Option keys: plugin settings ----
define( 'SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION', 'scriptomatic_plugin_settings' );

// ---- Limits / timing ----
define( 'SCRIPTOMATIC_MAX_SCRIPT_LENGTH',  100000 ); // 100 KB hard limit per inline script
define( 'SCRIPTOMATIC_RATE_LIMIT_SECONDS', 10 );     // Minimum seconds between saves per user

// ---- Nonces ----
define( 'SCRIPTOMATIC_HEAD_NONCE',     'scriptomatic_save_head' );    // Head script form secondary nonce
define( 'SCRIPTOMATIC_FOOTER_NONCE',   'scriptomatic_save_footer' );  // Footer script form secondary nonce
define( 'SCRIPTOMATIC_GENERAL_NONCE',  'scriptomatic_save_general' ); // General settings form secondary nonce
define( 'SCRIPTOMATIC_ROLLBACK_NONCE', 'scriptomatic_rollback' );     // AJAX rollback nonce

// ---- Option keys: managed JS files ----
define( 'SCRIPTOMATIC_JS_FILES_OPTION', 'scriptomatic_js_files' );    // DB option key for JS file metadata array

// ---- Activity log ----
define( 'SCRIPTOMATIC_ACTIVITY_LOG_OPTION', 'scriptomatic_activity_log' ); // Unified activity log
define( 'SCRIPTOMATIC_MAX_LOG_ENTRIES',     200 );                         // Default maximum entries retained

// ---- Nonces: JS files ----
define( 'SCRIPTOMATIC_FILES_NONCE', 'scriptomatic_save_js_file' );    // File edit form + AJAX delete nonce

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
