<?php
/**
 * Plugin Name: Scriptomatic
 * Plugin URI: https://github.com/richardkentgates/scriptomatic
 * Description: Securely inject custom JavaScript into the head and footer of your WordPress site. Features per-location inline scripts, external URL management, full revision history with rollback, multisite support, and fine-grained admin controls.
 * Version: 1.3.0
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

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SCRIPTOMATIC_VERSION', '1.3.0');
define('SCRIPTOMATIC_PLUGIN_FILE', __FILE__);
define('SCRIPTOMATIC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SCRIPTOMATIC_PLUGIN_URL', plugin_dir_url(__FILE__));

// ---- Option keys: head scripts ----
define('SCRIPTOMATIC_HEAD_SCRIPT',   'scriptomatic_script_content');   // backward-compat key
define('SCRIPTOMATIC_HEAD_HISTORY',  'scriptomatic_script_history');   // backward-compat key
define('SCRIPTOMATIC_HEAD_LINKED',   'scriptomatic_linked_scripts');   // backward-compat key

// ---- Option keys: footer scripts ----
define('SCRIPTOMATIC_FOOTER_SCRIPT',  'scriptomatic_footer_script');
define('SCRIPTOMATIC_FOOTER_HISTORY', 'scriptomatic_footer_history');
define('SCRIPTOMATIC_FOOTER_LINKED',  'scriptomatic_footer_linked');

// ---- Option keys: load conditions ----
define('SCRIPTOMATIC_HEAD_CONDITIONS',   'scriptomatic_head_conditions');   // JSON condition for head injection
define('SCRIPTOMATIC_FOOTER_CONDITIONS', 'scriptomatic_footer_conditions'); // JSON condition for footer injection

// ---- Option keys: plugin settings ----
define('SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION', 'scriptomatic_plugin_settings');

// ---- Legacy aliases (kept so old option references in code resolve correctly) ----
define('SCRIPTOMATIC_OPTION_NAME',           SCRIPTOMATIC_HEAD_SCRIPT);
define('SCRIPTOMATIC_HISTORY_OPTION',        SCRIPTOMATIC_HEAD_HISTORY);
define('SCRIPTOMATIC_LINKED_SCRIPTS_OPTION', SCRIPTOMATIC_HEAD_LINKED);

// ---- Limits / timing ----
define('SCRIPTOMATIC_MAX_SCRIPT_LENGTH',   100000); // 100 KB hard limit per inline script
define('SCRIPTOMATIC_RATE_LIMIT_SECONDS',  10);     // Minimum seconds between saves per user
define('SCRIPTOMATIC_DEFAULT_MAX_HISTORY', 25);     // Default revisions retained per location

// ---- Nonces ----
define('SCRIPTOMATIC_HEAD_NONCE',     'scriptomatic_save_head');    // Head script form secondary nonce
define('SCRIPTOMATIC_FOOTER_NONCE',   'scriptomatic_save_footer');  // Footer script form secondary nonce
define('SCRIPTOMATIC_GENERAL_NONCE',  'scriptomatic_save_general'); // General settings form secondary nonce
define('SCRIPTOMATIC_ROLLBACK_NONCE', 'scriptomatic_rollback');     // AJAX rollback nonce
define('SCRIPTOMATIC_NETWORK_NONCE',  'scriptomatic_network_save'); // Network admin save nonce

// Keep old constant name pointing to head nonce so any external referencing code still works
define('SCRIPTOMATIC_NONCE_ACTION', SCRIPTOMATIC_HEAD_NONCE);

/**
 * Main plugin class.
 *
 * Implements a singleton that wires all WordPress hooks, registers the plugin
 * settings, sanitises user input, enforces rate-limiting and nonce security,
 * renders the admin UI, and injects the stored script into the front-end
 * <head> section.
 *
 * @package Scriptomatic
 * @author  Richard Kent Gates <mail@richardkentgates.com>
 * @since   1.0.0
 * @link    https://github.com/richardkentgates/scriptomatic
 */
class Scriptomatic {

    /**
     * Singleton instance of this class.
     *
     * @since 1.0.0
     * @var   Scriptomatic|null
     */
    private static $instance = null;

    /**
     * Return the single instance of this class, creating it on first call.
     *
     * Using a singleton ensures that hooks are registered exactly once even if
     * the plugin file is loaded multiple times (e.g. in test environments).
     *
     * @since  1.0.0
     * @return Scriptomatic
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor — prevents direct instantiation.
     *
     * All set-up logic is delegated to {@see Scriptomatic::init_hooks()} so
     * that the constructor itself remains lean and easy to test.
     *
     * @since  1.0.0
     * @access private
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Register all WordPress action and filter hooks used by this plugin.
     *
     * Hooks are registered here rather than in the constructor so the list
     * remains easy to scan and adjust without touching bootstrap logic.
     *
     * @since 1.0.0
     */
    private function init_hooks() {
        add_action('init',                  array($this, 'load_textdomain'));
        add_action('admin_menu',            array($this, 'add_admin_menus'));
        add_action('admin_init',            array($this, 'register_settings'));
        add_action('wp_head',               array($this, 'inject_head_scripts'), 999);
        add_action('wp_footer',             array($this, 'inject_footer_scripts'), 999);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_filter('plugin_action_links_' . plugin_basename(SCRIPTOMATIC_PLUGIN_FILE), array($this, 'add_action_links'));

        // AJAX handlers — wp_ajax_ prefix ensures only logged-in users can trigger them.
        add_action('wp_ajax_scriptomatic_rollback', array($this, 'ajax_rollback'));

        // Multisite: network-admin menu + custom save handler + settings registration.
        // options.php does not exist in the network-admin context, so saves are handled manually.
        if (is_multisite()) {
            add_action('network_admin_menu',                                           array($this, 'add_network_admin_menus'));
            add_action('network_admin_init',                                           array($this, 'register_settings'));
            add_action('network_admin_edit_scriptomatic_network_save',                 array($this, 'handle_network_settings_save'));
            add_filter('network_admin_plugin_action_links_' . plugin_basename(SCRIPTOMATIC_PLUGIN_FILE), array($this, 'add_network_action_links'));
        }
    }

    /**
     * Load the plugin text domain for i18n.
     *
     * Called on the `init` action so WordPress has already set the locale.
     * The .mo files are expected under `languages/` inside the plugin folder.
     *
     * @since  1.2.0
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'scriptomatic',
            false,
            dirname(plugin_basename(SCRIPTOMATIC_PLUGIN_FILE)) . '/languages/'
        );
    }

    // =========================================================================
    // CAPABILITY HELPERS
    // =========================================================================

    /**
     * The capability required to manage Scriptomatic on a single site.
     *
     * `manage_options` maps to Administrator on a standard install and to
     * per-site Administrator on WordPress multisite.
     *
     * @since  1.2.0
     * @return string
     */
    private function get_required_cap() {
        return 'manage_options';
    }

    /**
     * The capability required to manage Scriptomatic in the network admin.
     *
     * `manage_network_options` is held exclusively by Super Admins.
     *
     * @since  1.2.0
     * @return string
     */
    private function get_network_cap() {
        return 'manage_network_options';
    }

    /**
     * Return true when this plugin is network-activated on a multisite install.
     *
     * @since  1.2.0
     * @return bool
     */
    private function is_network_active() {
        if (!is_multisite()) {
            return false;
        }
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . '/wp-admin/includes/plugin.php';
        }
        return is_plugin_active_for_network(plugin_basename(SCRIPTOMATIC_PLUGIN_FILE));
    }

    /**
     * Read a plugin option for front-end injection, falling back to the
     * network-level site option when the per-site option has never been set.
     *
     * On a network-activated install, scripts saved via the Network Admin are
     * stored with `update_site_option()`.  Per-site overrides use the regular
     * `update_option()` path.  This helper tries the per-site option first;
     * when it has never been written (`get_option` returns `false`), it falls
     * back to the network option so that network-admin-saved scripts appear on
     * every site.
     *
     * @since  1.2.1
     * @access private
     * @param  string $key     Option name.
     * @param  string $default Value returned when neither option is set.
     * @return string
     */
    private function get_front_end_option($key, $default = '') {
        $value = get_option($key, false);
        if (false === $value && is_multisite()) {
            return get_site_option($key, $default);
        }
        return (false !== $value) ? $value : $default;
    }

    /**
     * Validate and sanitise raw script content without security-gate checks.
     *
     * Runs the same content checks as {@see sanitize_script_for()} — length
     * cap, control characters, PHP-tag detection, dangerous HTML detection,
     * and script-tag stripping — but omits the capability, nonce, and
     * rate-limit gates.  Used by the network admin save handler, which
     * performs its own capability and nonce verification before calling this.
     *
     * @since  1.2.1
     * @access private
     * @param  string $input    Raw script content.
     * @param  string $location `'head'` or `'footer'`.
     * @return string Sanitised content, or the existing stored value on failure.
     */
    private function validate_inline_script($input, $location) {
        $option_key = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_SCRIPT : SCRIPTOMATIC_HEAD_SCRIPT;
        $fallback   = get_option($option_key, '');

        if (!is_string($input)) {
            return $fallback;
        }

        $input = wp_kses_no_null(str_replace("\r\n", "\n", wp_unslash($input)));

        $validated = wp_check_invalid_utf8($input, true);
        if ('' === $validated && '' !== $input) {
            return $fallback;
        }
        $input = $validated;

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $input)) {
            return $fallback;
        }

        if (preg_match('/<\?(php|=)?/i', $input)) {
            return $fallback;
        }

        if (preg_match('/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', $input)) {
            $input = preg_replace('/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', '$1', $input);
        }

        if (strlen($input) > SCRIPTOMATIC_MAX_SCRIPT_LENGTH) {
            return $fallback;
        }

        return trim($input);
    }

    // =========================================================================
    // ADMIN MENUS
    // =========================================================================

    /**
     * Register the top-level Scriptomatic menu and its three sub-pages for
     * the regular (per-site) admin area.
     *
     * Menu position 82 places the entry between Comments (60) and Appearance (60+)
     * without conflicting with any default WordPress items.
     *
     * @since  1.2.0
     * @return void
     */
    public function add_admin_menus() {
        $cap = $this->get_required_cap();

        // Top-level entry — doubles as the Head Scripts sub-page.
        add_menu_page(
            __('Scriptomatic', 'scriptomatic'),
            __('Scriptomatic', 'scriptomatic'),
            $cap,
            'scriptomatic',
            array($this, 'render_head_page'),
            'dashicons-editor-code',
            82
        );

        // Sub-page: Head Scripts (replaces the auto-generated duplicate).
        $head_hook = add_submenu_page(
            'scriptomatic',
            __('Head Scripts — Scriptomatic', 'scriptomatic'),
            __('Head Scripts', 'scriptomatic'),
            $cap,
            'scriptomatic',
            array($this, 'render_head_page')
        );

        // Sub-page: Footer Scripts.
        $footer_hook = add_submenu_page(
            'scriptomatic',
            __('Footer Scripts — Scriptomatic', 'scriptomatic'),
            __('Footer Scripts', 'scriptomatic'),
            $cap,
            'scriptomatic-footer',
            array($this, 'render_footer_page')
        );

        // Sub-page: General Settings.
        $general_hook = add_submenu_page(
            'scriptomatic',
            __('General Settings — Scriptomatic', 'scriptomatic'),
            __('General Settings', 'scriptomatic'),
            $cap,
            'scriptomatic-settings',
            array($this, 'render_general_settings_page')
        );

        // Attach contextual help to each sub-page.
        add_action('load-' . $head_hook,    array($this, 'add_help_tab'));
        add_action('load-' . $footer_hook,  array($this, 'add_help_tab'));
        add_action('load-' . $general_hook, array($this, 'add_help_tab'));
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
        if (!$this->is_network_active()) {
            return;
        }

        $cap = $this->get_network_cap();

        add_menu_page(
            __('Scriptomatic Network', 'scriptomatic'),
            __('Scriptomatic', 'scriptomatic'),
            $cap,
            'scriptomatic-network',
            array($this, 'render_network_head_page'),
            'dashicons-editor-code',
            82
        );

        add_submenu_page(
            'scriptomatic-network',
            __('Head Scripts — Scriptomatic Network', 'scriptomatic'),
            __('Head Scripts', 'scriptomatic'),
            $cap,
            'scriptomatic-network',
            array($this, 'render_network_head_page')
        );

        add_submenu_page(
            'scriptomatic-network',
            __('Footer Scripts — Scriptomatic Network', 'scriptomatic'),
            __('Footer Scripts', 'scriptomatic'),
            $cap,
            'scriptomatic-network-footer',
            array($this, 'render_network_footer_page')
        );

        add_submenu_page(
            'scriptomatic-network',
            __('General Settings — Scriptomatic Network', 'scriptomatic'),
            __('General Settings', 'scriptomatic'),
            $cap,
            'scriptomatic-network-settings',
            array($this, 'render_network_general_page')
        );
    }

    /**
     * Register all plugin options and their associated Settings API sections
     * and fields for the three per-site admin pages.
     *
     * Three separate settings groups are used so each page can call
     * `settings_fields()` / `do_settings_sections()` independently and
     * `options.php` only processes the options belonging to that page:
     *
     * - `scriptomatic_head_group`    → Head Scripts page
     * - `scriptomatic_footer_group`  → Footer Scripts page
     * - `scriptomatic_general_group` → General Settings page
     *
     * @since  1.2.0
     * @return void
     */
    public function register_settings() {
        // ---- HEAD SCRIPTS GROUP ----
        register_setting('scriptomatic_head_group', SCRIPTOMATIC_HEAD_SCRIPT, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_head_script'),
            'default'           => '',
        ));
        register_setting('scriptomatic_head_group', SCRIPTOMATIC_HEAD_LINKED, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_head_linked'),
            'default'           => '[]',
        ));

        // Page slug constants — must match the string passed to do_settings_sections().
        add_settings_section('sm_head_code',  __('Inline Script', 'scriptomatic'),         array($this, 'render_head_code_section'),   'scriptomatic_head_page');
        add_settings_section('sm_head_links', __('External Script URLs', 'scriptomatic'),   array($this, 'render_head_links_section'),  'scriptomatic_head_page');

        add_settings_field(SCRIPTOMATIC_HEAD_SCRIPT, __('Script Content', 'scriptomatic'),
            array($this, 'render_head_script_field'), 'scriptomatic_head_page', 'sm_head_code');
        add_settings_field(SCRIPTOMATIC_HEAD_LINKED, __('Script URLs', 'scriptomatic'),
            array($this, 'render_head_linked_field'), 'scriptomatic_head_page', 'sm_head_links');

        // ---- HEAD CONDITIONS ----
        register_setting('scriptomatic_head_group', SCRIPTOMATIC_HEAD_CONDITIONS, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_head_conditions'),
            'default'           => '{"type":"all","values":[]}',
        ));
        add_settings_section('sm_head_conditions', __('Load Conditions', 'scriptomatic'), array($this, 'render_head_conditions_section'), 'scriptomatic_head_page');
        add_settings_field(SCRIPTOMATIC_HEAD_CONDITIONS, __('When to inject', 'scriptomatic'),
            array($this, 'render_head_conditions_field'), 'scriptomatic_head_page', 'sm_head_conditions');

        // ---- FOOTER SCRIPTS GROUP ----
        register_setting('scriptomatic_footer_group', SCRIPTOMATIC_FOOTER_SCRIPT, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_footer_script'),
            'default'           => '',
        ));
        register_setting('scriptomatic_footer_group', SCRIPTOMATIC_FOOTER_LINKED, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_footer_linked'),
            'default'           => '[]',
        ));

        add_settings_section('sm_footer_code',  __('Inline Script', 'scriptomatic'),       array($this, 'render_footer_code_section'),   'scriptomatic_footer_page');
        add_settings_section('sm_footer_links', __('External Script URLs', 'scriptomatic'), array($this, 'render_footer_links_section'),  'scriptomatic_footer_page');

        add_settings_field(SCRIPTOMATIC_FOOTER_SCRIPT, __('Script Content', 'scriptomatic'),
            array($this, 'render_footer_script_field'), 'scriptomatic_footer_page', 'sm_footer_code');
        add_settings_field(SCRIPTOMATIC_FOOTER_LINKED, __('Script URLs', 'scriptomatic'),
            array($this, 'render_footer_linked_field'), 'scriptomatic_footer_page', 'sm_footer_links');

        // ---- FOOTER CONDITIONS ----
        register_setting('scriptomatic_footer_group', SCRIPTOMATIC_FOOTER_CONDITIONS, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_footer_conditions'),
            'default'           => '{"type":"all","values":[]}',
        ));
        add_settings_section('sm_footer_conditions', __('Load Conditions', 'scriptomatic'), array($this, 'render_footer_conditions_section'), 'scriptomatic_footer_page');
        add_settings_field(SCRIPTOMATIC_FOOTER_CONDITIONS, __('When to inject', 'scriptomatic'),
            array($this, 'render_footer_conditions_field'), 'scriptomatic_footer_page', 'sm_footer_conditions');

        // ---- GENERAL SETTINGS GROUP ----
        register_setting('scriptomatic_general_group', SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION, array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'sanitize_plugin_settings'),
            'default'           => array(
                'max_history'            => SCRIPTOMATIC_DEFAULT_MAX_HISTORY,
                'keep_data_on_uninstall' => false,
            ),
        ));

        add_settings_section('sm_advanced', __('Advanced Settings', 'scriptomatic'), array($this, 'render_advanced_section'), 'scriptomatic_general_page');

        add_settings_field('scriptomatic_max_history', __('History Limit', 'scriptomatic'),
            array($this, 'render_max_history_field'), 'scriptomatic_general_page', 'sm_advanced');
        add_settings_field('scriptomatic_keep_data', __('Data on Uninstall', 'scriptomatic'),
            array($this, 'render_keep_data_field'), 'scriptomatic_general_page', 'sm_advanced');
    }

    /**
     * Sanitise raw head-script content submitted from the Head Scripts form.
     *
     * Delegates to {@see Scriptomatic::sanitize_script_for()} using the
     * `head` context.
     *
     * @since  1.2.0
     * @param  mixed $input Raw value from the settings form.
     * @return string Sanitised script, or the previously-stored value on failure.
     */
    public function sanitize_head_script($input) {
        return $this->sanitize_script_for($input, 'head');
    }

    /**
     * Sanitise raw footer-script content submitted from the Footer Scripts form.
     *
     * Delegates to {@see Scriptomatic::sanitize_script_for()} using the
     * `footer` context.
     *
     * @since  1.2.0
     * @param  mixed $input Raw value from the settings form.
     * @return string Sanitised script, or the previously-stored value on failure.
     */
    public function sanitize_footer_script($input) {
        return $this->sanitize_script_for($input, 'footer');
    }

    /**
     * Core sanitise-and-validate logic shared by head and footer script inputs.
     *
     * Security gates (executed in order before any content validation):
     *
     * 1. **Capability check** — aborts if the current user does not hold
     *    `manage_options`.  This gate runs even though WordPress should have
     *    already enforced the capability; it is a defence-in-depth measure.
     *
     * 2. **Secondary nonce** — a short-lived, location-specific nonce
     *    (distinct from the Settings API nonce) verifies that the POST
     *    originated from the correct page of our own admin UI.
     *
     * 3. **Per-user rate limiter** — a transient keyed to the current user
     *    blocks rapid-fire save attempts.
     *
     * Content validation gates:
     * UTF-8 validity, control characters, PHP tags, script-tag stripping,
     * length cap, and dangerous-element detection.
     *
     * @since  1.2.0
     * @access private
     * @param  mixed  $input    Raw value submitted from the settings form.
     * @param  string $location `'head'` or `'footer'`.
     * @return string Sanitised content, or the previously-stored value on any failure.
     */
    private function sanitize_script_for($input, $location) {
        $option_key       = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_SCRIPT : SCRIPTOMATIC_HEAD_SCRIPT;
        $previous_content = get_option($option_key, '');
        $nonce_action     = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_NONCE : SCRIPTOMATIC_HEAD_NONCE;
        $nonce_field      = ('footer' === $location) ? 'scriptomatic_footer_nonce' : 'scriptomatic_save_nonce';
        $error_slug       = 'scriptomatic_' . $location . '_script';

        // Gate 0: Capability.
        if (!current_user_can($this->get_required_cap())) {
            return $previous_content;
        }

        // Gate 1: Secondary nonce.
        $secondary_nonce = isset($_POST[$nonce_field])
            ? sanitize_text_field(wp_unslash($_POST[$nonce_field]))
            : '';
        if (!wp_verify_nonce($secondary_nonce, $nonce_action)) {
            add_settings_error($error_slug, 'nonce_invalid',
                __('Security check failed. Please refresh the page and try again.', 'scriptomatic'), 'error');
            return $previous_content;
        }

        // Gate 2: Rate limiter.
        if ($this->is_rate_limited($location)) {
            add_settings_error($error_slug, 'rate_limited',
                sprintf(
                    /* translators: %d: seconds to wait */
                    __('You are saving too quickly. Please wait %d seconds before trying again.', 'scriptomatic'),
                    SCRIPTOMATIC_RATE_LIMIT_SECONDS
                ), 'error');
            return $previous_content;
        }

        if (!is_string($input)) {
            add_settings_error($error_slug, 'invalid_type',
                __('Script content must be plain text.', 'scriptomatic'), 'error');
            return $previous_content;
        }

        $input = wp_unslash($input);
        $input = wp_kses_no_null($input);
        $input = str_replace("\r\n", "\n", $input);

        $validated_input = wp_check_invalid_utf8($input, true);
        if ('' === $validated_input && '' !== $input) {
            add_settings_error($error_slug, 'invalid_utf8',
                __('Script content contains invalid UTF-8 characters.', 'scriptomatic'), 'error');
            return $previous_content;
        }
        $input = $validated_input;

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $input)) {
            add_settings_error($error_slug, 'control_characters_detected',
                __('Script content contains disallowed control characters.', 'scriptomatic'), 'error');
            return $previous_content;
        }

        if (preg_match('/<\?(php|=)?/i', $input)) {
            add_settings_error($error_slug, 'php_tags_detected',
                __('PHP tags are not allowed in script content.', 'scriptomatic'), 'error');
            return $previous_content;
        }

        if (preg_match('/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', $input)) {
            $input = preg_replace('/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', '$1', $input);
            add_settings_error($error_slug, 'script_tags_removed',
                __('Script tags were removed automatically. Enter JavaScript only.', 'scriptomatic'), 'warning');
        }

        if (strlen($input) > SCRIPTOMATIC_MAX_SCRIPT_LENGTH) {
            add_settings_error($error_slug, 'script_too_long',
                sprintf(
                    __('Script content exceeds maximum length of %s characters.', 'scriptomatic'),
                    number_format(SCRIPTOMATIC_MAX_SCRIPT_LENGTH)
                ), 'error');
            return $previous_content;
        }

        foreach (array('/<\s*iframe/i','/<\s*object/i','/<\s*embed/i','/<\s*link/i','/<\s*style/i','/<\s*meta/i') as $pattern) {
            if (preg_match($pattern, $input)) {
                add_settings_error($error_slug, 'dangerous_content',
                    __('Script content contains potentially dangerous HTML tags. Please use JavaScript only.', 'scriptomatic'), 'warning');
            }
        }

        $input = trim($input);

        if (current_user_can($this->get_required_cap())) {
            $this->log_change($input, $option_key, $location);
            $this->push_history($input, $location);
        }

        $this->record_save_timestamp($location);

        return $input;
    }

    /**
     * Public alias of sanitize_script_for('head') — kept for backward
     * compatibility with any code that may call it directly.
     *
     * @since  1.0.0
     * @param  mixed $input
     * @return string
     */
    public function sanitize_script_content($input) {
        return $this->sanitize_script_for($input, 'head');
    }

    /**
     * Determine whether the current user has exceeded the configured save rate
     * for the given location.
     *
     * @since  1.2.0 (was 1.0.0, single-location)
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return bool
     */
    private function is_rate_limited($location = 'head') {
        $user_id       = get_current_user_id();
        $transient_key = 'scriptomatic_save_' . $location . '_' . $user_id;
        return (false !== get_transient($transient_key));
    }

    /**
     * Record a successful save timestamp for rate-limiting.
     *
     * @since  1.2.0
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return void
     */
    private function record_save_timestamp($location = 'head') {
        $user_id       = get_current_user_id();
        $transient_key = 'scriptomatic_save_' . $location . '_' . $user_id;
        set_transient($transient_key, time(), SCRIPTOMATIC_RATE_LIMIT_SECONDS);
    }

    // =========================================================================
    // HISTORY
    // =========================================================================

    /**
     * Push a new revision onto the history stack for the given location.
     *
     * @since  1.2.0 (location-aware; was 1.1.0 head-only)
     * @access private
     * @param  string $content  The sanitised script content just saved.
     * @param  string $location `'head'` or `'footer'`.
     * @return void
     */
    private function push_history($content, $location = 'head') {
        $option  = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_HISTORY : SCRIPTOMATIC_HEAD_HISTORY;
        $history = $this->get_history($location);
        $max     = $this->get_max_history();
        $user    = wp_get_current_user();

        if (!empty($history) && isset($history[0]['content']) && $history[0]['content'] === $content) {
            return;
        }

        array_unshift($history, array(
            'content'    => $content,
            'timestamp'  => time(),
            'user_login' => $user->user_login,
            'user_id'    => (int) $user->ID,
            'length'     => strlen($content),
        ));

        if (count($history) > $max) {
            $history = array_slice($history, 0, $max);
        }

        update_option($option, $history);
    }

    /**
     * Retrieve the stored revision history for the given location.
     *
     * @since  1.2.0
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return array
     */
    private function get_history($location = 'head') {
        $option  = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_HISTORY : SCRIPTOMATIC_HEAD_HISTORY;
        $history = get_option($option, array());
        return is_array($history) ? $history : array();
    }

    /**
     * Return the configured maximum number of history entries to retain.
     *
     * @since  1.1.0
     * @access private
     * @return int
     */
    private function get_max_history() {
        $settings = $this->get_plugin_settings();
        return isset($settings['max_history']) ? (int) $settings['max_history'] : SCRIPTOMATIC_DEFAULT_MAX_HISTORY;
    }

    /**
     * Handle the AJAX rollback request for either head or footer scripts.
     *
     * Expects POST fields: `nonce`, `index` (int), `location` ('head'|'footer').
     *
     * @since  1.2.0
     * @return void  Sends a JSON response and exits.
     */
    public function ajax_rollback() {
        check_ajax_referer(SCRIPTOMATIC_ROLLBACK_NONCE, 'nonce');

        if (!current_user_can($this->get_required_cap())) {
            wp_send_json_error(array('message' => __('Permission denied.', 'scriptomatic')));
        }

        $location   = isset($_POST['location']) && 'footer' === $_POST['location'] ? 'footer' : 'head';
        $option_key = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_SCRIPT : SCRIPTOMATIC_HEAD_SCRIPT;
        $index      = isset($_POST['index']) ? absint($_POST['index']) : PHP_INT_MAX;
        $history    = $this->get_history($location);

        if (!array_key_exists($index, $history)) {
            wp_send_json_error(array('message' => __('History entry not found.', 'scriptomatic')));
        }

        $entry   = $history[$index];
        $content = $entry['content'];

        update_option($option_key, $content);
        $this->push_history($content, $location);

        $user = wp_get_current_user();
        error_log(sprintf(
            'Scriptomatic: %s script rolled back to revision from %s by user %s (ID: %d)',
            ucfirst($location),
            gmdate('Y-m-d H:i:s', $entry['timestamp']),
            $user->user_login,
            $user->ID
        ));

        wp_send_json_success(array(
            'content'  => $content,
            'length'   => strlen($content),
            'location' => $location,
            'message'  => __('Script restored successfully.', 'scriptomatic'),
        ));
    }

    /**
     * Sanitise linked-script URLs for the head location.
     *
     * @since  1.2.0
     * @param  mixed $input Raw JSON string from the form.
     * @return string JSON-encoded array of sanitised URLs.
     */
    public function sanitize_head_linked($input) {
        return $this->sanitize_linked_for($input, 'head');
    }

    /**
     * Sanitise linked-script URLs for the footer location.
     *
     * @since  1.2.0
     * @param  mixed $input Raw JSON string from the form.
     * @return string JSON-encoded array of sanitised URLs.
     */
    public function sanitize_footer_linked($input) {
        return $this->sanitize_linked_for($input, 'footer');
    }

    /**
     * Core URL sanitisation logic shared by head and footer linked-script fields.
     *
     * @since  1.2.0
     * @access private
     * @param  mixed  $input    Raw value (expected JSON string).
     * @param  string $location `'head'` or `'footer'`.
     * @return string JSON-encoded array of valid URLs.
     */
    private function sanitize_linked_for($input, $location) {
        $option_key = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_LINKED : SCRIPTOMATIC_HEAD_LINKED;

        if (empty($input)) {
            return '[]';
        }

        $decoded = json_decode(wp_unslash($input), true);
        if (!is_array($decoded)) {
            return get_option($option_key, '[]');
        }

        $clean = array();
        foreach ($decoded as $url) {
            $url = esc_url_raw(trim((string) $url));
            if (!empty($url) && preg_match('/^https?:\/\//i', $url)) {
                $clean[] = $url;
            }
        }

        return wp_json_encode($clean);
    }

    /**
     * Backward-compat alias — sanitises head linked scripts.
     *
     * @since  1.1.0
     * @param  mixed $input
     * @return string
     */
    public function sanitize_linked_scripts($input) {
        return $this->sanitize_linked_for($input, 'head');
    }

    // =========================================================================
    // CONDITIONS SANITISE
    // =========================================================================

    /**
     * Sanitise load-conditions JSON for the head location.
     *
     * @since  1.3.0
     * @param  mixed $input Raw JSON string from the form.
     * @return string JSON-encoded conditions object.
     */
    public function sanitize_head_conditions($input) {
        return $this->sanitize_conditions_for($input, 'head');
    }

    /**
     * Sanitise load-conditions JSON for the footer location.
     *
     * @since  1.3.0
     * @param  mixed $input Raw JSON string from the form.
     * @return string JSON-encoded conditions object.
     */
    public function sanitize_footer_conditions($input) {
        return $this->sanitize_conditions_for($input, 'footer');
    }

    /**
     * Core conditions sanitise logic shared by head and footer.
     *
     * Validates and normalises the JSON conditions object submitted from the
     * Load Conditions field.  Only whitelisted condition types are accepted;
     * values are sanitised per-type (post-type slugs, integer IDs, or plain
     * text URL substrings).
     *
     * @since  1.3.0
     * @access private
     * @param  mixed  $input    Raw JSON string.
     * @param  string $location `'head'` or `'footer'`.
     * @return string JSON-encoded array: `{type: string, values: array}`.
     */
    private function sanitize_conditions_for($input, $location) {
        $option_key = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_CONDITIONS : SCRIPTOMATIC_HEAD_CONDITIONS;
        $default    = wp_json_encode(array('type' => 'all', 'values' => array()));

        if (empty($input)) {
            return $default;
        }

        $decoded = json_decode(wp_unslash($input), true);
        if (!is_array($decoded)) {
            return get_option($option_key, $default);
        }

        $allowed_types = array('all', 'front_page', 'singular', 'post_type', 'page_id', 'url_contains', 'logged_in', 'logged_out');
        $type          = (isset($decoded['type']) && in_array($decoded['type'], $allowed_types, true))
                         ? $decoded['type'] : 'all';
        $raw_values    = (isset($decoded['values']) && is_array($decoded['values'])) ? $decoded['values'] : array();

        $clean_values = array();
        switch ($type) {
            case 'post_type':
                foreach ($raw_values as $pt) {
                    $pt = sanitize_key((string) $pt);
                    if ('' !== $pt && post_type_exists($pt)) {
                        $clean_values[] = $pt;
                    }
                }
                break;

            case 'page_id':
                foreach ($raw_values as $id) {
                    $id = absint($id);
                    if ($id > 0) {
                        $clean_values[] = $id;
                    }
                }
                break;

            case 'url_contains':
                foreach ($raw_values as $pattern) {
                    $pattern = sanitize_text_field(wp_unslash((string) $pattern));
                    if ('' !== $pattern) {
                        $clean_values[] = $pattern;
                    }
                }
                break;

            default:
                // all / front_page / singular / logged_in / logged_out: no values needed.
                break;
        }

        return wp_json_encode(array('type' => $type, 'values' => $clean_values));
    }
    // =========================================================================

    /**
     * Return the merged plugin settings, filling missing keys with defaults.
     *
     * @since  1.1.0
     * @return array Associative array: 'max_history' (int), 'keep_data_on_uninstall' (bool).
     */
    public function get_plugin_settings() {
        $defaults = array(
            'max_history'            => SCRIPTOMATIC_DEFAULT_MAX_HISTORY,
            'keep_data_on_uninstall' => false,
        );
        $saved = get_option(SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), $defaults);
    }

    /**
     * Sanitise and validate the plugin settings array submitted from the form.
     *
     * If `max_history` is being reduced, the stored history is immediately
     * trimmed so it never exceeds the new limit.
     *
     * @since  1.1.0
     * @param  mixed $input Raw array value from the settings form.
     * @return array Sanitised settings array.
     */
    public function sanitize_plugin_settings($input) {
        $current = $this->get_plugin_settings();

        if (!is_array($input)) {
            return $current;
        }

        // Secondary nonce — only present (and enforced) when saving via the Settings API
        // form (options.php).  Skipped when called directly from handle_network_settings_save,
        // which performs its own nonce verification before reaching this method.
        if (isset($_POST['scriptomatic_general_nonce'])) {
            $secondary = sanitize_text_field(wp_unslash($_POST['scriptomatic_general_nonce']));
            if (!wp_verify_nonce($secondary, SCRIPTOMATIC_GENERAL_NONCE)) {
                add_settings_error(
                    SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION,
                    'nonce_invalid',
                    __('Security check failed. Please refresh the page and try again.', 'scriptomatic'),
                    'error'
                );
                return $current;
            }
        }

        $clean = array();

        // max_history: integer clamped to 1–100.
        $max                   = isset($input['max_history']) ? (int) $input['max_history'] : $current['max_history'];
        $clean['max_history']  = max(1, min(100, $max));

        // keep_data_on_uninstall: boolean.
        $clean['keep_data_on_uninstall'] = !empty($input['keep_data_on_uninstall']);

        // If the limit was reduced, immediately trim both history stacks.
        if ($clean['max_history'] < $this->get_max_history()) {
            foreach (array('head', 'footer') as $loc) {
                $history    = $this->get_history($loc);
                $option_key = ('footer' === $loc) ? SCRIPTOMATIC_FOOTER_HISTORY : SCRIPTOMATIC_HEAD_HISTORY;
                if (count($history) > $clean['max_history']) {
                    update_option($option_key, array_slice($history, 0, $clean['max_history']));
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
     * @param  string $new_content  Sanitised content about to be saved.
     * @param  string $option_key   WordPress option key being updated.
     * @param  string $location     'head' or 'footer'.
     * @return void
     */
    private function log_change($new_content, $option_key, $location) {
        $old_content = get_option($option_key, '');
        if ($old_content !== $new_content) {
            $user = wp_get_current_user();
            error_log(sprintf(
                'Scriptomatic: %s script updated by user %s (ID: %d)',
                ucfirst($location),
                $user->user_login,
                $user->ID
            ));
        }
    }

    /**
     * Backward-compat alias for log_change().
     *
     * @since  1.0.0
     * @access private
     * @param  string $new_content
     * @return void
     */
    private function log_script_change($new_content) {
        $this->log_change($new_content, SCRIPTOMATIC_HEAD_SCRIPT, 'head');
    }

    // =========================================================================
    // RENDER METHODS — HEAD SCRIPTS
    // =========================================================================

    /**
     * Output the description for the Head Code settings section.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_head_code_section() {
        echo '<p>';
        esc_html_e('Add custom JavaScript that will be injected into every page <head>, right before the closing </head> tag.', 'scriptomatic');
        echo '</p>';
    }

    /**
     * Output the head-script <textarea> field.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_head_script_field() {
        $this->render_script_field_for('head');
    }

    /**
     * Output the description for the Head External URLs section.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_head_links_section() {
        echo '<p>';
        esc_html_e('Add external JavaScript URLs. Each is output as a <script src="..."> tag in <head>, before the inline block.', 'scriptomatic');
        echo '</p>';
    }

    /**
     * Output the head linked-scripts chicklet manager.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_head_linked_field() {
        $this->render_linked_field_for('head');
    }

    // =========================================================================
    // RENDER METHODS — FOOTER SCRIPTS
    // =========================================================================

    /**
     * Output the description for the Footer Code settings section.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_footer_code_section() {
        echo '<p>';
        esc_html_e('Add custom JavaScript that will be injected into every page before the closing </body> tag.', 'scriptomatic');
        echo '</p>';
    }

    /**
     * Output the footer-script <textarea> field.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_footer_script_field() {
        $this->render_script_field_for('footer');
    }

    /**
     * Output the description for the Footer External URLs section.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_footer_links_section() {
        echo '<p>';
        esc_html_e('Add external JavaScript URLs to be output as <script src="..."> tags just before </body>, before the inline block.', 'scriptomatic');
        echo '</p>';
    }

    /**
     * Output the footer linked-scripts chicklet manager.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_footer_linked_field() {
        $this->render_linked_field_for('footer');
    }

    // =========================================================================
    // RENDER HELPERS — SHARED FIELD IMPLEMENTATIONS
    // =========================================================================

    /**
     * Render a script-content textarea for a given injection location.
     *
     * @since  1.2.0
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return void
     */
    private function render_script_field_for($location) {
        $option_key     = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_SCRIPT : SCRIPTOMATIC_HEAD_SCRIPT;
        $script_content = get_option($option_key, '');
        $char_count     = strlen($script_content);
        $max_length     = SCRIPTOMATIC_MAX_SCRIPT_LENGTH;
        $textarea_id    = 'scriptomatic-' . $location . '-script';
        $counter_id     = 'scriptomatic-' . $location . '-char-count';
        ?>
        <textarea
            id="<?php echo esc_attr($textarea_id); ?>"
            name="<?php echo esc_attr($option_key); ?>"
            rows="20"
            cols="100"
            class="large-text code"
            placeholder="<?php esc_attr_e('Enter your JavaScript code here (without <script> tags)', 'scriptomatic'); ?>"
            aria-describedby="<?php echo esc_attr($location); ?>-script-desc <?php echo esc_attr($location); ?>-char-count"
        ><?php echo esc_textarea($script_content); ?></textarea>

        <p id="<?php echo esc_attr($location); ?>-char-count" class="description">
            <?php
            printf(
                esc_html__('Character count: %s / %s', 'scriptomatic'),
                '<span id="' . esc_attr($counter_id) . '">' . number_format($char_count) . '</span>',
                number_format($max_length)
            );
            ?>
        </p>
        <p id="<?php echo esc_attr($location); ?>-script-desc" class="description">
            <strong><?php esc_html_e('Important:', 'scriptomatic'); ?></strong>
            <?php esc_html_e('Enter only JavaScript code. Do not include <script> tags — they are added automatically.', 'scriptomatic'); ?>
        </p>
        <div class="scriptomatic-security-notice" style="margin-top:12px;padding:10px;background:#fff3cd;border-left:4px solid #ffc107;">
            <h4 style="margin-top:0;"><span class="dashicons dashicons-shield" style="color:#ffc107;"></span>
            <?php esc_html_e('Security Notice', 'scriptomatic'); ?></h4>
            <ul style="margin:0;padding-left:20px;">
                <li><?php esc_html_e('Only administrators can modify this content.', 'scriptomatic'); ?></li>
                <li><?php esc_html_e('All changes are logged for security auditing.', 'scriptomatic'); ?></li>
                <li><?php esc_html_e('Always verify code from trusted sources before adding it here.', 'scriptomatic'); ?></li>
            </ul>
        </div>
        <?php
    }

    /**
     * Render the chicklet-based URL manager for a given injection location.
     *
     * @since  1.2.0
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return void
     */
    private function render_linked_field_for($location) {
        $option_key  = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_LINKED : SCRIPTOMATIC_HEAD_LINKED;
        $raw         = get_option($option_key, '[]');
        $urls        = json_decode($raw, true);
        if (!is_array($urls)) {
            $urls = array();
        }
        $prefix = 'scriptomatic-' . $location;
        ?>
        <div id="<?php echo esc_attr($prefix); ?>-url-manager">
            <div
                id="<?php echo esc_attr($prefix); ?>-url-chicklets"
                class="scriptomatic-chicklet-list"
                aria-label="<?php esc_attr_e('Added script URLs', 'scriptomatic'); ?>"
            >
                <?php foreach ($urls as $url) : ?>
                <span class="scriptomatic-chicklet" data-url="<?php echo esc_attr($url); ?>">
                    <span class="chicklet-label" title="<?php echo esc_attr($url); ?>"><?php echo esc_html($url); ?></span>
                    <button type="button" class="scriptomatic-remove-url" aria-label="<?php esc_attr_e('Remove URL', 'scriptomatic'); ?>">&times;</button>
                </span>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px;align-items:center;max-width:600px;">
                <input type="url" id="<?php echo esc_attr($prefix); ?>-new-url" class="regular-text"
                    placeholder="https://cdn.example.com/script.js"
                    aria-label="<?php esc_attr_e('External script URL', 'scriptomatic'); ?>" style="flex:1;">
                <button type="button" id="<?php echo esc_attr($prefix); ?>-add-url" class="button button-secondary">
                    <?php esc_html_e('Add URL', 'scriptomatic'); ?>
                </button>
            </div>
            <p id="<?php echo esc_attr($prefix); ?>-url-error" class="scriptomatic-url-error" style="color:#dc3545;display:none;margin-top:4px;"></p>
            <input type="hidden" id="<?php echo esc_attr($prefix); ?>-linked-scripts-input"
                name="<?php echo esc_attr($option_key); ?>"
                value="<?php echo esc_attr(wp_json_encode($urls)); ?>">
            <p class="description" style="margin-top:8px;max-width:600px;">
                <?php esc_html_e('Only HTTP and HTTPS URLs are accepted. Scripts are loaded in the order listed.', 'scriptomatic'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Backward-compat alias: section description for head code (was render_section_description).
     *
     * @since  1.0.0
     * @return void
     */
    public function render_section_description() {
        $this->render_head_code_section();
    }

    /**
     * Backward-compat alias: head script field (was render_script_field).
     *
     * @since  1.0.0
     * @return void
     */
    public function render_script_field() {
        $this->render_script_field_for('head');
    }

    /**
     * Backward-compat alias: head linked section description.
     *
     * @since  1.1.0
     * @return void
     */
    public function render_linked_scripts_section() {
        $this->render_head_links_section();
    }

    /**
     * Backward-compat alias: head linked scripts field.
     *
     * @since  1.1.0
     * @return void
     */
    public function render_linked_scripts_field() {
        $this->render_linked_field_for('head');
    }

    /**
     * Evaluate the stored load condition for a given injection location.
     *
     * Called from {@see Scriptomatic::inject_scripts_for()} on every front-end
     * page load.  Returns `true` when the script block should be output,
     * `false` when it must be suppressed.
     *
     * Supported condition types:
     * - `all`          — always inject (default).
     * - `front_page`   — is_front_page().
     * - `singular`     — is_singular().
     * - `post_type`    — is_singular($values) where values are post-type slugs.
     * - `page_id`      — get_queried_object_id() in the stored ID list.
     * - `url_contains` — REQUEST_URI contains any of the stored patterns.
     * - `logged_in`    — is_user_logged_in().
     * - `logged_out`   — !is_user_logged_in().
     *
     * @since  1.3.0
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return bool
     */
    private function check_load_conditions($location) {
        $option_key = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_CONDITIONS : SCRIPTOMATIC_HEAD_CONDITIONS;
        $raw        = $this->get_front_end_option($option_key, '');
        $conditions = json_decode($raw, true);

        if (!is_array($conditions) || empty($conditions['type']) || 'all' === $conditions['type']) {
            return true; // Default: inject everywhere.
        }

        $type   = $conditions['type'];
        $values = (isset($conditions['values']) && is_array($conditions['values'])) ? $conditions['values'] : array();

        switch ($type) {
            case 'front_page':
                return is_front_page();

            case 'singular':
                return is_singular();

            case 'post_type':
                return !empty($values) && is_singular($values);

            case 'page_id':
                $ids = array_map('intval', $values);
                return in_array((int) get_queried_object_id(), $ids, true);

            case 'url_contains':
                if (empty($values)) {
                    return false;
                }
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only for substring comparison, not output.
                $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
                foreach ($values as $pattern) {
                    if ('' !== $pattern && false !== strpos($uri, $pattern)) {
                        return true;
                    }
                }
                return false;

            case 'logged_in':
                return is_user_logged_in();

            case 'logged_out':
                return !is_user_logged_in();

            default:
                return true;
        }
    }

    // =========================================================================
    // RENDER METHODS — LOAD CONDITIONS
    // =========================================================================

    /**
     * Description for the head Load Conditions settings section.
     *
     * @since  1.3.0
     * @return void
     */
    public function render_head_conditions_section() {
        echo '<p>';
        esc_html_e('Control which pages this head script block is injected on. Scripts are skipped entirely — no output written — when the condition is not met.', 'scriptomatic');
        echo '</p>';
    }

    /**
     * Description for the footer Load Conditions settings section.
     *
     * @since  1.3.0
     * @return void
     */
    public function render_footer_conditions_section() {
        echo '<p>';
        esc_html_e('Control which pages this footer script block is injected on. Scripts are skipped entirely — no output written — when the condition is not met.', 'scriptomatic');
        echo '</p>';
    }

    /**
     * Output the Load Conditions field for the head location.
     *
     * @since  1.3.0
     * @return void
     */
    public function render_head_conditions_field() {
        $this->render_conditions_field_for('head');
    }

    /**
     * Output the Load Conditions field for the footer location.
     *
     * @since  1.3.0
     * @return void
     */
    public function render_footer_conditions_field() {
        $this->render_conditions_field_for('footer');
    }

    /**
     * Shared Load Conditions UI renderer.
     *
     * Renders a `<select>` for condition type and three conditionally-visible
     * sub-panels (post-type checkboxes, page-ID chicklets, URL-pattern
     * chicklets).  All sub-panels are server-rendered; JS handles show/hide
     * transitions and keeps the hidden JSON input in sync.
     *
     * @since  1.3.0
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return void
     */
    private function render_conditions_field_for($location) {
        $option_key = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_CONDITIONS : SCRIPTOMATIC_HEAD_CONDITIONS;
        $raw        = get_option($option_key, '');
        $conditions = json_decode($raw, true);
        $type       = (is_array($conditions) && !empty($conditions['type'])) ? $conditions['type'] : 'all';
        $values     = (is_array($conditions) && isset($conditions['values']) && is_array($conditions['values'])) ? $conditions['values'] : array();
        $pfx        = 'scriptomatic-' . $location . '-cond';
        $post_types = get_post_types(array('public' => true), 'objects');

        $condition_labels = array(
            'all'          => __('All pages (default)', 'scriptomatic'),
            'front_page'   => __('Front page only', 'scriptomatic'),
            'singular'     => __('Any single post or page', 'scriptomatic'),
            'post_type'    => __('Specific post types', 'scriptomatic'),
            'page_id'      => __('Specific pages / posts by ID', 'scriptomatic'),
            'url_contains' => __('URL contains (any match)', 'scriptomatic'),
            'logged_in'    => __('Logged-in users only', 'scriptomatic'),
            'logged_out'   => __('Logged-out visitors only', 'scriptomatic'),
        );
        ?>
        <div class="scriptomatic-conditions-wrap" data-location="<?php echo esc_attr($location); ?>" data-prefix="<?php echo esc_attr($pfx); ?>">

            <select
                id="<?php echo esc_attr($pfx); ?>-type"
                class="scriptomatic-condition-type"
                style="min-width:280px;"
                aria-label="<?php esc_attr_e('Load condition', 'scriptomatic'); ?>"
            >
                <?php foreach ($condition_labels as $val => $label) : ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected($type, $val); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>

            <?php /* --- Panel: post_type --- */ ?>
            <div class="sm-cond-panel" data-panel="post_type" <?php echo 'post_type' !== $type ? 'hidden' : ''; ?>>
                <fieldset class="sm-cond-fieldset">
                    <legend><?php esc_html_e('Load on these post types:', 'scriptomatic'); ?></legend>
                    <div class="sm-pt-grid">
                    <?php foreach ($post_types as $pt) :
                        $checked = in_array($pt->name, $values, true); ?>
                        <label class="sm-pt-label">
                            <input type="checkbox" class="sm-pt-checkbox"
                                data-prefix="<?php echo esc_attr($pfx); ?>"
                                value="<?php echo esc_attr($pt->name); ?>"
                                <?php checked($checked); ?>
                            >
                            <span>
                                <strong><?php echo esc_html($pt->labels->singular_name); ?></strong>
                                <code><?php echo esc_html($pt->name); ?></code>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    </div>
                </fieldset>
            </div>

            <?php /* --- Panel: page_id --- */ ?>
            <div class="sm-cond-panel" data-panel="page_id" <?php echo 'page_id' !== $type ? 'hidden' : ''; ?>>
                <div class="sm-cond-inner">
                    <p class="description"><?php esc_html_e('Add the numeric ID of each post, page, or custom post entry. Find IDs in the URL bar when editing (post=123).', 'scriptomatic'); ?></p>
                    <div id="<?php echo esc_attr($pfx); ?>-id-chicklets" class="scriptomatic-chicklet-list scriptomatic-chicklet-list--alt" aria-label="<?php esc_attr_e('Added page IDs', 'scriptomatic'); ?>">
                        <?php foreach ($values as $id) :
                            $id    = absint($id);
                            if (!$id) continue;
                            $title = get_the_title($id);
                            $label = $title ? $id . ' — ' . $title : (string) $id;
                        ?>
                        <span class="scriptomatic-chicklet" data-val="<?php echo esc_attr($id); ?>">
                            <span class="chicklet-label" title="<?php echo esc_attr($label); ?>"><?php echo esc_html($label); ?></span>
                            <button type="button" class="scriptomatic-remove-url" aria-label="<?php esc_attr_e('Remove ID', 'scriptomatic'); ?>">&times;</button>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <div class="sm-cond-add-row">
                        <input type="number" id="<?php echo esc_attr($pfx); ?>-id-new" class="small-text" min="1" step="1"
                            placeholder="<?php esc_attr_e('ID', 'scriptomatic'); ?>"
                            aria-label="<?php esc_attr_e('Post or page ID to add', 'scriptomatic'); ?>">
                        <button type="button" id="<?php echo esc_attr($pfx); ?>-id-add" class="button button-secondary"><?php esc_html_e('Add ID', 'scriptomatic'); ?></button>
                    </div>
                    <p id="<?php echo esc_attr($pfx); ?>-id-error" class="scriptomatic-url-error" style="display:none;"></p>
                </div>
            </div>

            <?php /* --- Panel: url_contains --- */ ?>
            <div class="sm-cond-panel" data-panel="url_contains" <?php echo 'url_contains' !== $type ? 'hidden' : ''; ?>>
                <div class="sm-cond-inner">
                    <p class="description"><?php esc_html_e('Script loads when the request URL contains any of the listed strings. Partial paths work — e.g. /blog/ or /checkout.', 'scriptomatic'); ?></p>
                    <div id="<?php echo esc_attr($pfx); ?>-url-chicklets" class="scriptomatic-chicklet-list scriptomatic-chicklet-list--alt" aria-label="<?php esc_attr_e('Added URL patterns', 'scriptomatic'); ?>">
                        <?php foreach ($values as $pattern) :
                            $pattern = sanitize_text_field((string) $pattern);
                            if ('' === $pattern) continue;
                        ?>
                        <span class="scriptomatic-chicklet" data-val="<?php echo esc_attr($pattern); ?>">
                            <span class="chicklet-label" title="<?php echo esc_attr($pattern); ?>"><?php echo esc_html($pattern); ?></span>
                            <button type="button" class="scriptomatic-remove-url" aria-label="<?php esc_attr_e('Remove pattern', 'scriptomatic'); ?>">&times;</button>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <div class="sm-cond-add-row">
                        <input type="text" id="<?php echo esc_attr($pfx); ?>-url-new" class="regular-text"
                            placeholder="<?php esc_attr_e('/my-page or /category/name', 'scriptomatic'); ?>"
                            aria-label="<?php esc_attr_e('URL pattern to add', 'scriptomatic'); ?>">
                        <button type="button" id="<?php echo esc_attr($pfx); ?>-url-add" class="button button-secondary"><?php esc_html_e('Add Pattern', 'scriptomatic'); ?></button>
                    </div>
                    <p id="<?php echo esc_attr($pfx); ?>-url-error" class="scriptomatic-url-error" style="display:none;"></p>
                </div>
            </div>

            <input type="hidden"
                id="<?php echo esc_attr($pfx); ?>-json"
                name="<?php echo esc_attr($option_key); ?>"
                value="<?php echo esc_attr(wp_json_encode(array('type' => $type, 'values' => $values))); ?>"
            >
        </div><!-- .scriptomatic-conditions-wrap -->
        <?php
    }
    // =========================================================================

    /**
     * Output the description for the Advanced Settings section.
     *
     * @since  1.1.0
     * @return void
     */
    public function render_advanced_section() {
        echo '<p>';
        esc_html_e('Configure history retention and data lifecycle behaviour for this plugin.', 'scriptomatic');
        echo '</p>';
    }

    /**
     * Render the max-history number input field.
     *
     * @since  1.1.0
     * @return void
     */
    public function render_max_history_field() {
        $settings    = $this->get_plugin_settings();
        $max_history = (int) $settings['max_history'];
        ?>
        <input
            type="number"
            id="scriptomatic_max_history"
            name="<?php echo esc_attr(SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION); ?>[max_history]"
            value="<?php echo esc_attr($max_history); ?>"
            min="1"
            max="100"
            step="1"
            class="small-text"
            aria-describedby="max-history-description"
        >
        <p id="max-history-description" class="description">
            <?php
            printf(
                /* translators: %d: default max history entries */
                esc_html__('Maximum number of script revisions to retain (1\u2013100). Default: %d. Reducing this value will immediately trim the existing history.', 'scriptomatic'),
                SCRIPTOMATIC_DEFAULT_MAX_HISTORY
            );
            ?>
        </p>
        <?php
    }

    /**
     * Render the keep-data-on-uninstall checkbox field.
     *
     * @since  1.1.0
     * @return void
     */
    public function render_keep_data_field() {
        $settings = $this->get_plugin_settings();
        $keep     = !empty($settings['keep_data_on_uninstall']);
        ?>
        <label for="scriptomatic_keep_data_on_uninstall">
            <input
                type="checkbox"
                id="scriptomatic_keep_data_on_uninstall"
                name="<?php echo esc_attr(SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION); ?>[keep_data_on_uninstall]"
                value="1"
                <?php checked($keep, true); ?>
                aria-describedby="keep-data-description"
            >
            <?php esc_html_e('Preserve all plugin data when Scriptomatic is uninstalled.', 'scriptomatic'); ?>
        </label>
        <p id="keep-data-description" class="description">
            <?php esc_html_e('When unchecked (default), all scripts, history, linked URLs, and settings are permanently deleted on uninstall.', 'scriptomatic'); ?>
        </p>
        <?php
    }

    // =========================================================================
    // PAGE RENDERERS — PER-SITE ADMIN
    // =========================================================================

    /**
     * Shared page header for all Scriptomatic admin pages.
     *
     * @since  1.2.0
     * @access private
     * @param  string $error_slug Settings-errors slug to display.
     * @return void
     */
    private function render_page_header($error_slug = '') {
        if (!current_user_can($this->get_required_cap())) {
            wp_die(
                esc_html__('You do not have sufficient permissions to access this page.', 'scriptomatic'),
                esc_html__('Permission Denied', 'scriptomatic'),
                array('response' => 403)
            );
        }
        ?>
        <div class="wrap" id="scriptomatic-settings">
        <h1>
            <span class="dashicons dashicons-editor-code" style="font-size:32px;width:32px;height:32px;"></span>
            <?php echo esc_html(get_admin_page_title()); ?>
        </h1>
        <p class="description" style="font-size:14px;margin-bottom:20px;">
            <?php esc_html_e('Version', 'scriptomatic'); ?>: <?php echo esc_html(SCRIPTOMATIC_VERSION); ?> |
            <?php esc_html_e('Author', 'scriptomatic'); ?>: <a href="https://github.com/richardkentgates" target="_blank" rel="noopener noreferrer">Richard Kent Gates</a> |
            <a href="https://github.com/richardkentgates/scriptomatic" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Documentation', 'scriptomatic'); ?></a>
        </p>
        <?php if ($error_slug) { settings_errors($error_slug); } ?>
        <?php
    }

    /**
     * Output the revision history panel for a given location.
     *
     * @since  1.2.0
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return void
     */
    private function render_history_panel($location) {
        $history = $this->get_history($location);
        if (empty($history)) {
            return;
        }
        ?>
        <hr style="margin:30px 0;">
        <div class="scriptomatic-history-section">
            <h2>
                <span class="dashicons dashicons-backup" style="font-size:24px;width:24px;height:24px;margin-right:4px;vertical-align:middle;"></span>
                <?php
                printf(
                    /* translators: %s: 'Head' or 'Footer' */
                    esc_html__('%s Script History', 'scriptomatic'),
                    esc_html(ucfirst($location))
                );
                ?>
            </h2>
            <p class="description">
                <?php
                printf(
                    /* translators: %d: number of stored revisions */
                    esc_html(_n(
                        'Showing %d saved revision. Click Restore to roll back to a previous version.',
                        'Showing %d saved revisions. Click Restore to roll back to a previous version.',
                        count($history),
                        'scriptomatic'
                    )),
                    count($history)
                );
                ?>
            </p>
            <table class="widefat scriptomatic-history-table" style="max-width:900px;">
                <thead>
                    <tr>
                        <th style="width:40px;"><?php esc_html_e('#', 'scriptomatic'); ?></th>
                        <th><?php esc_html_e('Saved', 'scriptomatic'); ?></th>
                        <th><?php esc_html_e('By', 'scriptomatic'); ?></th>
                        <th style="width:100px;"><?php esc_html_e('Characters', 'scriptomatic'); ?></th>
                        <th style="width:110px;"><?php esc_html_e('Action', 'scriptomatic'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $index => $entry) : ?>
                    <tr>
                        <td><?php echo esc_html($index + 1); ?></td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $entry['timestamp'])); ?></td>
                        <td><?php echo esc_html($entry['user_login']); ?></td>
                        <td><?php echo esc_html(number_format($entry['length'])); ?></td>
                        <td>
                            <button
                                type="button"
                                class="button button-small scriptomatic-history-restore"
                                data-index="<?php echo esc_attr($index); ?>"
                                data-location="<?php echo esc_attr($location); ?>"
                                data-original-text="<?php esc_attr_e('Restore', 'scriptomatic'); ?>"
                            >
                                <?php esc_html_e('Restore', 'scriptomatic'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render the Head Scripts admin page.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_head_page() {
        $this->render_page_header('scriptomatic_head_script');
        ?>
        <form action="options.php" method="post">
            <?php
            settings_fields('scriptomatic_head_group');
            wp_nonce_field(SCRIPTOMATIC_HEAD_NONCE, 'scriptomatic_save_nonce');
            do_settings_sections('scriptomatic_head_page');
            submit_button(__('Save Head Scripts', 'scriptomatic'), 'primary large');
            ?>
        </form>
        <?php
        $this->render_history_panel('head');
        echo '</div>'; // .wrap
    }

    /**
     * Render the Footer Scripts admin page.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_footer_page() {
        $this->render_page_header('scriptomatic_footer_script');
        ?>
        <form action="options.php" method="post">
            <?php
            settings_fields('scriptomatic_footer_group');
            wp_nonce_field(SCRIPTOMATIC_FOOTER_NONCE, 'scriptomatic_footer_nonce');
            do_settings_sections('scriptomatic_footer_page');
            submit_button(__('Save Footer Scripts', 'scriptomatic'), 'primary large');
            ?>
        </form>
        <?php
        $this->render_history_panel('footer');
        echo '</div>'; // .wrap
    }

    /**
     * Render the General Settings admin page.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_general_settings_page() {
        $this->render_page_header('scriptomatic_plugin_settings');
        ?>
        <form action="options.php" method="post">
            <?php
            settings_fields('scriptomatic_general_group');
            wp_nonce_field(SCRIPTOMATIC_GENERAL_NONCE, 'scriptomatic_general_nonce');
            do_settings_sections('scriptomatic_general_page');
            submit_button(__('Save Settings', 'scriptomatic'), 'primary large');
            ?>
        </form>
        </div><!-- .wrap -->
        <?php
    }

    /**
     * Backward-compat alias — renders head page (was render_settings_page).
     *
     * @since  1.0.0
     * @return void
     */
    public function render_settings_page() {
        $this->render_head_page();
    }

    // =========================================================================
    // PAGE RENDERERS — NETWORK ADMIN
    // =========================================================================

    /**
     * Shared header for network-admin Scriptomatic pages.
     *
     * @since  1.2.0
     * @access private
     * @return void
     */
    private function render_network_page_header() {
        if (!current_user_can($this->get_network_cap())) {
            wp_die(
                esc_html__('You do not have sufficient permissions to access this page.', 'scriptomatic'),
                esc_html__('Permission Denied', 'scriptomatic'),
                array('response' => 403)
            );
        }

        if (isset($_GET['updated'])) { // phpcs:ignore WordPress.Security.NonceVerification
            ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'scriptomatic'); ?></p></div>
            <?php
        }
        if (isset($_GET['error'])) { // phpcs:ignore WordPress.Security.NonceVerification
            ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Security check failed. Settings not saved.', 'scriptomatic'); ?></p></div>
            <?php
        }
        ?>
        <div class="wrap" id="scriptomatic-settings">
        <h1>
            <span class="dashicons dashicons-editor-code" style="font-size:32px;width:32px;height:32px;"></span>
            <?php echo esc_html(get_admin_page_title()); ?>
        </h1>
        <?php
    }

    /**
     * Render the Network Admin — Head Scripts page.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_network_head_page() {
        $this->render_network_page_header();
        ?>
        <form method="post" action="<?php echo esc_url(network_admin_url('edit.php?action=scriptomatic_network_save')); ?>">
            <?php
            wp_nonce_field(SCRIPTOMATIC_NETWORK_NONCE, 'scriptomatic_network_nonce');
            echo '<input type="hidden" name="scriptomatic_network_location" value="head">';
            do_settings_sections('scriptomatic_head_page');
            submit_button(__('Save Network Head Scripts', 'scriptomatic'), 'primary large');
            ?>
        </form>
        <?php $this->render_history_panel('head'); ?>
        </div><!-- .wrap -->
        <?php
    }

    /**
     * Render the Network Admin — Footer Scripts page.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_network_footer_page() {
        $this->render_network_page_header();
        ?>
        <form method="post" action="<?php echo esc_url(network_admin_url('edit.php?action=scriptomatic_network_save')); ?>">
            <?php
            wp_nonce_field(SCRIPTOMATIC_NETWORK_NONCE, 'scriptomatic_network_nonce');
            echo '<input type="hidden" name="scriptomatic_network_location" value="footer">';
            do_settings_sections('scriptomatic_footer_page');
            submit_button(__('Save Network Footer Scripts', 'scriptomatic'), 'primary large');
            ?>
        </form>
        <?php $this->render_history_panel('footer'); ?>
        </div><!-- .wrap -->
        <?php
    }

    /**
     * Render the Network Admin — General Settings page.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_network_general_page() {
        $this->render_network_page_header();
        ?>
        <form method="post" action="<?php echo esc_url(network_admin_url('edit.php?action=scriptomatic_network_save')); ?>">
            <?php
            wp_nonce_field(SCRIPTOMATIC_NETWORK_NONCE, 'scriptomatic_network_nonce');
            echo '<input type="hidden" name="scriptomatic_network_location" value="general">';
            do_settings_sections('scriptomatic_general_page');
            submit_button(__('Save Network Settings', 'scriptomatic'), 'primary large');
            ?>
        </form>
        </div><!-- .wrap -->
        <?php
    }

    // =========================================================================
    // NETWORK ADMIN SAVE HANDLER
    // =========================================================================

    /**
     * Process the custom POST from all three network-admin forms.
     *
     * WordPress network admin does not route through `options.php`, so we
     * handle saves via a `network_admin_edit_` action hook.  All three forms
     * (head, footer, general) post here; the `scriptomatic_network_location`
     * hidden field distinguishes them.
     *
     * Security gates: nonce verify + `manage_network_options` capability.
     *
     * @since  1.2.0
     * @return void  Redirects on completion (or terminates on error).
     */
    public function handle_network_settings_save() {
        if (!isset($_POST['scriptomatic_network_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['scriptomatic_network_nonce'])), SCRIPTOMATIC_NETWORK_NONCE)) {
            wp_redirect(add_query_arg('error', '1', wp_get_referer()));
            exit;
        }

        if (!current_user_can($this->get_network_cap())) {
            wp_die(esc_html__('Permission denied.', 'scriptomatic'), 403);
        }

        $location = isset($_POST['scriptomatic_network_location'])
            ? sanitize_key($_POST['scriptomatic_network_location'])
            : '';

        if ('head' === $location || 'footer' === $location) {
            $script_key  = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_SCRIPT      : SCRIPTOMATIC_HEAD_SCRIPT;
            $linked_key  = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_LINKED      : SCRIPTOMATIC_HEAD_LINKED;
            $cond_key    = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_CONDITIONS  : SCRIPTOMATIC_HEAD_CONDITIONS;

            $raw_script  = isset($_POST[$script_key])  ? (string) wp_unslash($_POST[$script_key])  : '';
            $raw_linked  = isset($_POST[$linked_key])  ? (string) wp_unslash($_POST[$linked_key])  : '[]';
            $raw_cond    = isset($_POST[$cond_key])    ? (string) wp_unslash($_POST[$cond_key])    : '';
            $raw_cond    = isset($_POST[$cond_key])    ? (string) wp_unslash($_POST[$cond_key])    : '';

            // Full content validation via validate_inline_script() — same checks as the
            // per-site save path (length, control chars, PHP tags, dangerous HTML).
            update_site_option($script_key, $this->validate_inline_script($raw_script, $location));
            update_site_option($linked_key, $this->sanitize_linked_for($raw_linked, $location));
            update_site_option($cond_key,   $this->sanitize_conditions_for($raw_cond, $location));

        } elseif ('general' === $location) {
            $raw = isset($_POST[SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION])
                ? (array) wp_unslash($_POST[SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION])
                : array();
            update_site_option(SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION, $this->sanitize_plugin_settings($raw));
        }

        $redirect = add_query_arg('updated', '1', wp_get_referer());
        wp_redirect(esc_url_raw($redirect));
        exit;
    }

    /**
     * Attach contextual help tabs to the Scriptomatic settings screen.
     *
     * Hooked to `load-{page_hook}` so it runs only when the admin navigates to
     * the plugin's settings page.  Five tabs are registered:
     * - **Overview** – high-level plugin description.
     * - **Usage** – step-by-step guide for entering and saving scripts.
     * - **Security** – summary of built-in security features.
     * - **Best Practices** – recommendations for safe script management.
     * - **Troubleshooting** – common issues and how to resolve them.
     *
     * A contextual sidebar with external resource links is also attached.
     *
     * @since  1.0.0
     * @return void
     */
    public function add_help_tab() {
        $screen = get_current_screen();

        // Overview tab
        $screen->add_help_tab(array(
            'id' => 'scriptomatic_overview',
            'title' => __('Overview', 'scriptomatic'),
            'content' => '<h3>' . __('Scriptomatic Overview', 'scriptomatic') . '</h3>' .
                '<p>' . __('Scriptomatic safely injects custom JavaScript into both the <strong>head</strong> (before &lt;/head&gt;) and the <strong>footer</strong> (before &lt;/body&gt;) of every page on your WordPress site.', 'scriptomatic') . '</p>' .
                '<p>' . __('Use the <strong>Head Scripts</strong> page for analytics tags, pixel codes, and scripts that must load early. Use the <strong>Footer Scripts</strong> page for scripts that should run after page content has loaded.', 'scriptomatic') . '</p>' .
                '<p>' . __('This plugin is designed with security and performance in mind, providing input validation, sanitisation, revision history, and audit logging.', 'scriptomatic') . '</p>',
        ));

        // Usage tab
        $screen->add_help_tab(array(
            'id' => 'scriptomatic_usage',
            'title' => __('Usage', 'scriptomatic'),
            'content' => '<h3>' . __('How to Use', 'scriptomatic') . '</h3>' .
                '<ol>' .
                '<li><strong>' . __('Choose a location:', 'scriptomatic') . '</strong> ' . __('Use <em>Head Scripts</em> for early-loading code (analytics, pixels) or <em>Footer Scripts</em> for deferred code.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Add Your Code:', 'scriptomatic') . '</strong> ' . __('Paste your JavaScript code into the textarea. Do not include &lt;script&gt; tags — they are added automatically.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Add external URLs (optional):', 'scriptomatic') . '</strong> ' . __('Enter remote script URLs in the External Script URLs section. They load before the inline block.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Save Changes:', 'scriptomatic') . '</strong> ' . __('Click the Save button at the bottom of the page.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Verify:', 'scriptomatic') . '</strong> ' . __('View your page source to confirm the script is injected in the correct location.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Test:', 'scriptomatic') . '</strong> ' . __('Thoroughly test your site to ensure the script functions correctly.', 'scriptomatic') . '</li>' .
                '</ol>' .
                '<p><strong>' . __('Example:', 'scriptomatic') . '</strong></p>' .
                '<pre>console.log("Hello from Scriptomatic!");\n' .
                'var myCustomVar = "Hello World";</pre>',
        ));

        // Security tab
        $screen->add_help_tab(array(
            'id' => 'scriptomatic_security',
            'title' => __('Security', 'scriptomatic'),
            'content' => '<h3>' . __('Security Features', 'scriptomatic') . '</h3>' .
                '<ul>' .
                '<li><strong>' . __('Capability Check:', 'scriptomatic') . '</strong> ' . __('Only users with "manage_options" capability (typically administrators) can modify scripts.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Input Validation:', 'scriptomatic') . '</strong> ' . __('All input is validated for length and potentially dangerous content.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Sanitization:', 'scriptomatic') . '</strong> ' . __('Script tags are automatically removed to prevent double-wrapping.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Audit Logging:', 'scriptomatic') . '</strong> ' . __('All changes are logged with user information (username and user ID).', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Output Escaping:', 'scriptomatic') . '</strong> ' . __('Content is properly escaped when displayed in the admin interface.', 'scriptomatic') . '</li>' .
                '</ul>' .
                '<p class="description">' . __('Note: Always verify code from external sources before adding it to your site. Malicious JavaScript can compromise your website and user data.', 'scriptomatic') . '</p>',
        ));

        // Best Practices tab
        $screen->add_help_tab(array(
            'id' => 'scriptomatic_best_practices',
            'title' => __('Best Practices', 'scriptomatic'),
            'content' => '<h3>' . __('Best Practices', 'scriptomatic') . '</h3>' .
                '<ul>' .
                '<li><strong>' . __('Test First:', 'scriptomatic') . '</strong> ' . __('Always test scripts in a staging environment before deploying to production.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Use Comments:', 'scriptomatic') . '</strong> ' . __('Add comments to your code to document what it does and where it came from.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Keep It Clean:', 'scriptomatic') . '</strong> ' . __('Remove unused or outdated scripts regularly.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Verify Sources:', 'scriptomatic') . '</strong> ' . __('Only use code from trusted sources. Review all third-party scripts.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Monitor Performance:', 'scriptomatic') . '</strong> ' . __('Heavy scripts can slow down your site. Use browser dev tools to monitor impact.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Backup:', 'scriptomatic') . '</strong> ' . __('Keep a backup of your script content before making major changes.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Async/Defer:', 'scriptomatic') . '</strong> ' . __('Consider using async or defer attributes for external scripts to improve page load times.', 'scriptomatic') . '</li>' .
                '</ul>',
        ));

        // Troubleshooting tab
        $screen->add_help_tab(array(
            'id' => 'scriptomatic_troubleshooting',
            'title' => __('Troubleshooting', 'scriptomatic'),
            'content' => '<h3>' . __('Troubleshooting', 'scriptomatic') . '</h3>' .
                '<h4>' . __('Script not appearing:', 'scriptomatic') . '</h4>' .
                '<ul>' .
                '<li>' . __('Check that you clicked the Save button after entering your code.', 'scriptomatic') . '</li>' .
                '<li>' . __('Clear your site cache and browser cache.', 'scriptomatic') . '</li>' .
                '<li>' . __('View page source to verify the script tag is present in the expected location (head or footer).', 'scriptomatic') . '</li>' .
                '<li>' . __('Check if another plugin or theme is preventing wp_head() or wp_footer() from running.', 'scriptomatic') . '</li>' .
                '</ul>' .
                '<h4>' . __('Script causing errors:', 'scriptomatic') . '</h4>' .
                '<ul>' .
                '<li>' . __('Check the browser console for JavaScript errors.', 'scriptomatic') . '</li>' .
                '<li>' . __('Verify syntax errors in your JavaScript code.', 'scriptomatic') . '</li>' .
                '<li>' . __('Ensure external resources are loading properly (check network tab).', 'scriptomatic') . '</li>' .
                '<li>' . __('Test with a simple console.log() first to verify injection is working.', 'scriptomatic') . '</li>' .
                '</ul>' .
                '<h4>' . __('Cannot save:', 'scriptomatic') . '</h4>' .
                '<ul>' .
                '<li>' . __('Verify you have administrator privileges.', 'scriptomatic') . '</li>' .
                '<li>' . __('Check if script exceeds the maximum length limit.', 'scriptomatic') . '</li>' .
                '<li>' . __('Remove any HTML tags (only JavaScript is allowed).', 'scriptomatic') . '</li>' .
                '</ul>',
        ));

        // Sidebar
        $screen->set_help_sidebar(
            '<p><strong>' . __('For more information:', 'scriptomatic') . '</strong></p>' .
            '<p><a href="https://github.com/richardkentgates/scriptomatic" target="_blank" rel="noopener noreferrer">' . __('Plugin Documentation', 'scriptomatic') . '</a></p>' .
            '<p><a href="https://github.com/richardkentgates/scriptomatic/issues" target="_blank" rel="noopener noreferrer">' . __('Report Issues', 'scriptomatic') . '</a></p>' .
            '<p><a href="https://github.com/richardkentgates" target="_blank" rel="noopener noreferrer">' . __('Developer Profile', 'scriptomatic') . '</a></p>' .
            '<p><a href="https://developer.wordpress.org/reference/hooks/wp_head/" target="_blank" rel="noopener noreferrer">' . __('WordPress wp_head Documentation', 'scriptomatic') . '</a></p>'
        );
    }

    /**
     * Enqueue (or inline) scripts and styles for Scriptomatic admin pages.
     *
     * @since  1.0.0
     * @param  string $hook The hook suffix for the current admin page.
     * @return void
     */
    public function enqueue_admin_scripts($hook) {
        // Detect which Scriptomatic page is being viewed.
        $head_hooks = array(
            'toplevel_page_scriptomatic',
            'scriptomatic_page_scriptomatic',
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

        if (!in_array($hook, $all_hooks, true)) {
            return;
        }

        // Determine location for JS context.
        if (in_array($hook, array_merge($footer_hooks, $network_footer_hooks), true)) {
            $location = 'footer';
        } elseif (in_array($hook, array_merge($general_hooks, $network_general_hooks), true)) {
            $location = 'general';
        } else {
            $location = 'head';
        }

        // Attach inline CSS via a registered (empty-src) style handle.
        wp_register_style('scriptomatic-admin', false, array(), SCRIPTOMATIC_VERSION);
        wp_enqueue_style('scriptomatic-admin');
        wp_add_inline_style('scriptomatic-admin', $this->get_admin_css());

        // Pass PHP data to JS.
        wp_localize_script('jquery', 'scriptomaticData', array(
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'rollbackNonce' => wp_create_nonce(SCRIPTOMATIC_ROLLBACK_NONCE),
            'maxLength'     => SCRIPTOMATIC_MAX_SCRIPT_LENGTH,
            'location'      => $location,
            'i18n'          => array(
                'invalidUrl'      => __('Please enter a valid http:// or https:// URL.', 'scriptomatic'),
                'duplicateUrl'    => __('This URL has already been added.', 'scriptomatic'),
                'rollbackConfirm' => __('Restore this revision? The current script will be preserved in history.', 'scriptomatic'),
                'rollbackSuccess' => __('Script restored successfully.', 'scriptomatic'),
                'rollbackError'   => __('Restore failed. Please try again.', 'scriptomatic'),
                'restoring'       => __('Restoring\u2026', 'scriptomatic'),
            ),
        ));

        wp_add_inline_script('jquery', $this->get_admin_js());
    }

    /**
     * Return the inline CSS string for the Scriptomatic admin page.
     *
     * Styles the chicklet URL manager and history table.
     *
     * @since  1.1.0
     * @access private
     * @return string Raw CSS.
     */
    private function get_admin_css() {
        return '
/* Chicklet URL list */
.scriptomatic-chicklet-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    min-height: 40px;
    padding: 8px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    max-width: 600px;
    align-items: flex-start;
    align-content: flex-start;
}
.scriptomatic-chicklet {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px 4px 12px;
    background: #2271b1;
    color: #fff;
    border-radius: 20px;
    font-size: 12px;
    line-height: 1.4;
    max-width: 380px;
}
.scriptomatic-chicklet .chicklet-label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 320px;
}
.scriptomatic-remove-url {
    background: none;
    border: none;
    color: rgba(255,255,255,0.85);
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    padding: 0 2px;
    flex-shrink: 0;
    transition: color 0.15s;
}
.scriptomatic-remove-url:hover { color: #fff; }

/* History table */
.scriptomatic-history-table th,
.scriptomatic-history-table td {
    padding: 8px 12px;
    vertical-align: middle;
}
.scriptomatic-history-table th { font-weight: 600; }
.scriptomatic-history-table tbody tr:hover { background: #f6f7f7; }

/* Load Conditions */
.scriptomatic-conditions-wrap { max-width: 600px; }
.sm-cond-panel {
    margin-top: 12px;
    padding: 14px 16px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
}
.sm-cond-panel[hidden] { display: none; }
.sm-cond-fieldset {
    border: none;
    margin: 0;
    padding: 0;
}
.sm-cond-fieldset legend {
    font-weight: 600;
    margin-bottom: 10px;
    float: left;
    width: 100%;
}
.sm-pt-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 24px;
    margin-top: 4px;
}
.sm-pt-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    min-width: 180px;
}
.sm-pt-label code {
    font-size: 11px;
    color: #666;
    background: #eee;
    padding: 1px 5px;
    border-radius: 3px;
}
.sm-cond-inner { max-width: 100%; }
.sm-cond-add-row {
    display: flex;
    gap: 8px;
    margin-top: 8px;
    align-items: center;
}
.scriptomatic-chicklet-list--alt {
    background: #fff;
    min-height: 36px;
}
.scriptomatic-url-error { color: #dc3545; margin-top: 4px; }
';
    }

    /**
     * Return the inline JavaScript string for Scriptomatic admin pages.
     *
     * Location-aware — reads `scriptomaticData.location` (`'head'`|`'footer'`)
     * and uses it to build element IDs that match the rendered page.
     *
     * Features:
     * 1. Live character counter.
     * 2. Chicklet URL manager.
     * 3. AJAX history rollback with `location` param.
     *
     * @since  1.1.0
     * @access private
     * @return string Raw JavaScript (no <script> wrapper).
     */
    private function get_admin_js() {
        return '
jQuery(document).ready(function ($) {
    var data   = window.scriptomaticData || {};
    var loc    = data.location || "head";
    var maxLen = data.maxLength || ' . SCRIPTOMATIC_MAX_SCRIPT_LENGTH . ';
    var i18n   = data.i18n || {};

    /* 1. Character counter */
    var $textarea = $("#scriptomatic-" + loc + "-script");
    var $counter  = $("#scriptomatic-" + loc + "-char-count");

    function updateCounter(len) {
        $counter.text(len.toLocaleString());
        if (len > maxLen * 0.9) {
            $counter.css({ color: "#dc3545", fontWeight: "bold" });
        } else if (len > maxLen * 0.75) {
            $counter.css({ color: "#ffc107", fontWeight: "bold" });
        } else {
            $counter.css({ color: "", fontWeight: "" });
        }
    }
    if ($textarea.length && $counter.length) {
        $textarea.on("input", function () { updateCounter(this.value.length); });
    }

    /* 2. Chicklet URL manager */
    var pfx          = "#scriptomatic-" + loc;
    var $chicklets   = $(pfx + "-url-chicklets");
    var $urlInput    = $(pfx + "-new-url");
    var $addBtn      = $(pfx + "-add-url");
    var $hiddenInput = $(pfx + "-linked-scripts-input");
    var $urlError    = $(pfx + "-url-error");

    function getUrls() {
        try { return JSON.parse($hiddenInput.val()) || []; } catch (e) { return []; }
    }
    function setUrls(urls) { $hiddenInput.val(JSON.stringify(urls)); }

    function makeChicklet(url) {
        var $c = $("<span>").addClass("scriptomatic-chicklet").attr("data-url", url);
        $("<span>").addClass("chicklet-label").attr("title", url).text(url).appendTo($c);
        $("<button>").attr({ type: "button", "aria-label": "Remove URL" })
            .addClass("scriptomatic-remove-url").html("&times;").appendTo($c);
        return $c;
    }

    function addUrl() {
        var url = $urlInput.val().trim();
        $urlError.hide().text("");
        if (!url.match(/^https?:\/\/.+/i)) {
            $urlError.text(i18n.invalidUrl).show();
            $urlInput.trigger("focus");
            return;
        }
        var urls = getUrls();
        if (urls.indexOf(url) !== -1) {
            $urlError.text(i18n.duplicateUrl).show();
            $urlInput.trigger("focus");
            return;
        }
        urls.push(url);
        setUrls(urls);
        $chicklets.append(makeChicklet(url));
        $urlInput.val("").trigger("focus");
    }

    if ($addBtn.length) {
        $addBtn.on("click", addUrl);
        $urlInput.on("keydown", function (e) {
            if (e.key === "Enter") { e.preventDefault(); addUrl(); }
        });
        $chicklets.on("click", ".scriptomatic-remove-url", function () {
            var $c = $(this).closest(".scriptomatic-chicklet");
            setUrls(getUrls().filter(function (u) { return u !== $c.data("url"); }));
            $c.remove();
        });
    }

    /* 3. AJAX history rollback */
    $(document).on("click", ".scriptomatic-history-restore", function () {
        if (!confirm(i18n.rollbackConfirm)) { return; }
        var $btn     = $(this);
        var index    = $btn.data("index");
        var entryLoc = $btn.data("location") || loc;
        var orig     = $btn.data("original-text") || "Restore";
        $btn.prop("disabled", true).text(i18n.restoring || "Restoring\u2026");
        $.post(data.ajaxUrl, {
            action: "scriptomatic_rollback",
            nonce: data.rollbackNonce,
            index: index,
            location: entryLoc
        }, function (response) {
            if (response.success) {
                var rLoc = response.data.location || loc;
                var $ta  = $("#scriptomatic-" + rLoc + "-script");
                if ($ta.length) { $ta.val(response.data.content).trigger("input"); }
                $("<div>").addClass("notice notice-success is-dismissible")
                    .html("<p>" + i18n.rollbackSuccess + "</p>")
                    .insertAfter(".wp-header-end");
                setTimeout(function () { location.reload(); }, 800);
            } else {
                var msg = (response.data && response.data.message) ? response.data.message : "";
                alert(i18n.rollbackError + (msg ? " " + msg : ""));
                $btn.prop("disabled", false).text(orig);
            }
        }).fail(function () {
            alert(i18n.rollbackError);
            $btn.prop("disabled", false).text(orig);
        });
    });  /* end document.ready */

    /* 4. Load Conditions */
    function initConditions($wrap) {
        var pfx   = $wrap.data("prefix");
        var $type  = $("#" + pfx + "-type");
        var $json  = $("#" + pfx + "-json");

        function syncJson() {
            var t      = $type.val();
            var values = [];

            if (t === "post_type") {
                $wrap.find(".sm-pt-checkbox:checked").each(function () {
                    values.push($(this).val());
                });
            } else if (t === "page_id") {
                $("#" + pfx + "-id-chicklets .scriptomatic-chicklet").each(function () {
                    values.push(parseInt($(this).data("val"), 10));
                });
            } else if (t === "url_contains") {
                $("#" + pfx + "-url-chicklets .scriptomatic-chicklet").each(function () {
                    values.push($(this).data("val"));
                });
            }
            $json.val(JSON.stringify({ type: t, values: values }));
        }

        function showPanel(t) {
            $wrap.find(".sm-cond-panel").attr("hidden", true);
            var $panel = $wrap.find(".sm-cond-panel[data-panel=\"" + t + "\"]");
            if ($panel.length) {
                $panel.removeAttr("hidden");
            }
        }

        $type.on("change", function () {
            showPanel($(this).val());
            syncJson();
        });
        showPanel($type.val());

        $wrap.on("change", ".sm-pt-checkbox", syncJson);

        /* ID chicklet manager */
        var $idList  = $("#" + pfx + "-id-chicklets");
        var $idInput = $("#" + pfx + "-id-new");
        var $idAdd   = $("#" + pfx + "-id-add");
        var $idError = $("#" + pfx + "-id-error");

        function makeChicklet(val, label) {
            var $c = $("<span>").addClass("scriptomatic-chicklet").attr("data-val", val);
            $("<span>").addClass("chicklet-label").attr("title", label).text(label).appendTo($c);
            $("<button>").attr({ type: "button", "aria-label": "Remove" })
                .addClass("scriptomatic-remove-url").html("&times;").appendTo($c);
            return $c;
        }

        function addId() {
            var id = parseInt($idInput.val(), 10);
            $idError.hide().text("");
            if (!id || id < 1) {
                $idError.text("Please enter a valid positive integer ID.").show();
                $idInput.trigger("focus");
                return;
            }
            if ($idList.find("[data-val=\"" + id + "\"]").length) {
                $idError.text("This ID has already been added.").show();
                $idInput.trigger("focus");
                return;
            }
            $idList.append(makeChicklet(id, String(id)));
            $idInput.val("").trigger("focus");
            syncJson();
        }

        if ($idAdd.length) {
            $idAdd.on("click", addId);
            $idInput.on("keydown", function (e) {
                if (e.key === "Enter") { e.preventDefault(); addId(); }
            });
        }

        /* URL-pattern chicklet manager */
        var $urlList  = $("#" + pfx + "-url-chicklets");
        var $urlInput = $("#" + pfx + "-url-new");
        var $urlAdd   = $("#" + pfx + "-url-add");
        var $urlError = $("#" + pfx + "-url-error");

        function addPattern() {
            var pat = $.trim($urlInput.val());
            $urlError.hide().text("");
            if (!pat) {
                $urlError.text("Please enter a URL path or pattern.").show();
                $urlInput.trigger("focus");
                return;
            }
            if ($urlList.find("[data-val=\"" + pat.replace(/\"/g, \'\\\"\') + "\"]").length) {
                $urlError.text("This pattern has already been added.").show();
                $urlInput.trigger("focus");
                return;
            }
            $urlList.append(makeChicklet(pat, pat));
            $urlInput.val("").trigger("focus");
            syncJson();
        }

        if ($urlAdd.length) {
            $urlAdd.on("click", addPattern);
            $urlInput.on("keydown", function (e) {
                if (e.key === "Enter") { e.preventDefault(); addPattern(); }
            });
        }

        /* Shared remove handler for both ID and URL chicklets */
        $wrap.on("click", "#" + pfx + "-id-chicklets .scriptomatic-remove-url, #" + pfx + "-url-chicklets .scriptomatic-remove-url", function () {
            $(this).closest(".scriptomatic-chicklet").remove();
            syncJson();
        });

        syncJson();
    }

    $(".scriptomatic-conditions-wrap").each(function () {
        initConditions($(this));
    });

});
';
    }

    /**
     * Prepend a Settings link to the plugin's action links on the Plugins screen.
     *
     * @since  1.0.0
     * @param  string[] $links Existing action-link HTML strings.
     * @return string[] Modified array with the Head Scripts link at index 0.
     */
    public function add_action_links($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=scriptomatic'),
            __('Head Scripts', 'scriptomatic')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Prepend a Settings link on the Network Plugins screen.
     *
     * @since  1.2.0
     * @param  string[] $links Existing action-link HTML strings.
     * @return string[] Modified array with the Network Head Scripts link prepended.
     */
    public function add_network_action_links($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            network_admin_url('admin.php?page=scriptomatic-network'),
            __('Head Scripts', 'scriptomatic')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Inject stored scripts into the page `<head>` on the front-end.
     *
     * Handles two sources:
     * 1. **Linked URLs** — each emitted as a `<script src="...">` tag.
     * 2. **Inline content** — wrapped in a `<script>` block.
     *
     * @since  1.0.0
     * @return void
     */
    public function inject_head_scripts() {
        if (is_admin()) {
            return;
        }

        $this->inject_scripts_for('head');
    }

    /**
     * Inject stored scripts before the closing `</body>` tag.
     *
     * @since  1.2.0
     * @return void
     */
    public function inject_footer_scripts() {
        if (is_admin()) {
            return;
        }

        $this->inject_scripts_for('footer');
    }

    /**
     * Core injection logic shared by head and footer output.
     *
     * @since  1.2.0
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return void
     */
    private function inject_scripts_for($location) {
        // Evaluate load condition first — bail out with no output if not met.
        if (!$this->check_load_conditions($location)) {
            return;
        }

        $script_key  = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_SCRIPT  : SCRIPTOMATIC_HEAD_SCRIPT;
        $linked_key  = ('footer' === $location) ? SCRIPTOMATIC_FOOTER_LINKED  : SCRIPTOMATIC_HEAD_LINKED;

        $script_content = $this->get_front_end_option($script_key, '');
        $linked_raw     = $this->get_front_end_option($linked_key, '[]');
        $linked_urls    = json_decode($linked_raw, true);
        if (!is_array($linked_urls)) {
            $linked_urls = array();
        }

        $has_inline = !empty(trim($script_content));
        $has_linked = !empty($linked_urls);

        if (!$has_inline && !$has_linked) {
            return;
        }

        $label = ('footer' === $location) ? 'footer' : 'head';
        echo "\n<!-- Scriptomatic v" . esc_attr(SCRIPTOMATIC_VERSION) . " ({$label}) -->\n";

        foreach ($linked_urls as $url) {
            echo '<script src="' . esc_url($url) . '"></script>' . "\n";
        }

        if ($has_inline) {
            echo '<script>' . "\n";
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- content is
            // intentionally unescaped: it must execute as valid JavaScript.  All security
            // validation (type, length, control chars, PHP tags, dangerous HTML) is enforced
            // at write-time inside sanitize_script_for().
            echo $script_content . "\n";
            echo '</script>' . "\n";
        }

        echo "<!-- /Scriptomatic ({$label}) -->\n";
    }

    /**
     * Backward-compat alias — injects head scripts (was inject_script).
     *
     * @since  1.0.0
     * @return void
     */
    public function inject_script() {
        $this->inject_head_scripts();
    }
}

/**
 * Bootstrap the plugin by returning (and, on first call, creating) the
 * singleton {@see Scriptomatic} instance.
 *
 * Hooked on {@see 'plugins_loaded'} so that all WordPress APIs, including
 * `load_plugin_textdomain()` and multi-site functions, are available before
 * any plugin logic runs.
 *
 * @since  1.0.0
 * @return Scriptomatic The singleton plugin instance.
 */
function scriptomatic_init() {
    return Scriptomatic::get_instance();
}

// Boot the plugin after all plugins have been loaded.
add_action('plugins_loaded', 'scriptomatic_init');
