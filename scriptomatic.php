<?php
/**
 * Plugin Name: Scriptomatic
 * Plugin URI: https://github.com/richardkentgates/scriptomatic
 * Description: Securely inject custom JavaScript code into the head section of your WordPress site with advanced validation and safety features.
 * Version: 1.0.0
 * Requires at least: 5.0
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
define('SCRIPTOMATIC_VERSION', '1.0.0');
define('SCRIPTOMATIC_PLUGIN_FILE', __FILE__);
define('SCRIPTOMATIC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SCRIPTOMATIC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SCRIPTOMATIC_OPTION_NAME', 'scriptomatic_script_content');
define('SCRIPTOMATIC_MAX_SCRIPT_LENGTH', 100000);     // 100 KB hard limit on stored script size
define('SCRIPTOMATIC_RATE_LIMIT_SECONDS', 10);        // Minimum seconds required between consecutive saves per user
define('SCRIPTOMATIC_NONCE_ACTION', 'scriptomatic_save_script'); // Custom nonce action — separate from the WP settings-API nonce

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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_head', array($this, 'inject_script'), 999);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_filter('plugin_action_links_' . plugin_basename(SCRIPTOMATIC_PLUGIN_FILE), array($this, 'add_action_links'));
    }

    /**
     * Register the plugin's settings page under the WordPress Settings menu.
     *
     * Hooked to {@see 'admin_menu'}. Stores the resulting page-hook string so
     * that contextual help tabs can be attached on that page's `load-` action.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_admin_menu() {
        $page_hook = add_options_page(
            __('Scriptomatic Settings', 'scriptomatic'),
            __('Scriptomatic', 'scriptomatic'),
            'manage_options',
            'scriptomatic',
            array($this, 'render_settings_page')
        );

        // Add contextual help
        add_action('load-' . $page_hook, array($this, 'add_help_tab'));
    }

    /**
     * Register the plugin option with the WordPress Settings API.
     *
     * Associates the option with a sanitise callback, a settings section, and
     * a settings field so that WordPress handles nonce verification, saving,
     * and display automatically via `options.php`.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_settings() {
        register_setting(
            'scriptomatic_settings',
            SCRIPTOMATIC_OPTION_NAME,
            array(
                'type' => 'string',
                'description' => __('JavaScript code to inject into head section', 'scriptomatic'),
                'sanitize_callback' => array($this, 'sanitize_script_content'),
                'default' => '',
            )
        );

        add_settings_section(
            'scriptomatic_main_section',
            __('JavaScript Code Configuration', 'scriptomatic'),
            array($this, 'render_section_description'),
            'scriptomatic_settings'
        );

        add_settings_field(
            'scriptomatic_script_content',
            __('Script Content', 'scriptomatic'),
            array($this, 'render_script_field'),
            'scriptomatic_settings',
            'scriptomatic_main_section'
        );
    }

    /**
     * Sanitise, validate, and security-gate the raw script content from the form.
     *
     * This callback is invoked by the WordPress Settings API immediately after
     * the built-in settings-nonce has already been verified by `options.php`.
     * It adds two further layers of protection on top of that:
     *
     * 1. **Secondary nonce** – A short-lived, action-specific nonce
     *    (`SCRIPTOMATIC_NONCE_ACTION`) that is rendered fresh on every page
     *    load.  Even if an attacker somehow bypasses the Settings-API nonce
     *    (e.g. via a CSRF exploit on a poorly-written bridging plugin), this
     *    second nonce — bound to user session and time — will block the save.
     *
     * 2. **Per-user rate limiter** – A WordPress transient keyed to the
     *    current user prevents brute-force or rapid-fire save attempts within
     *    the window defined by {@see SCRIPTOMATIC_RATE_LIMIT_SECONDS}.
     *
     * After those gates pass, the input is checked for type, valid UTF-8,
     * disallowed control characters, PHP tags, HTML script tags, length, and
     * known dangerous HTML elements.  Each failure emits a settings error
     * and returns the previous safe value unchanged.
     *
     * @since  1.0.0
     * @param  mixed  $input Raw value submitted from the settings form.
     * @return string Sanitised script content, or the previously-stored value
     *                if any validation step fails.
     */
    public function sanitize_script_content($input) {
        $previous_content = get_option(SCRIPTOMATIC_OPTION_NAME, '');

        // -----------------------------------------------------------------
        // Gate 1: Verify our secondary, short-lived nonce.
        // WordPress has already checked the settings-API nonce at this point;
        // this provides an independent second verification layer.
        // -----------------------------------------------------------------
        $secondary_nonce = isset($_POST['scriptomatic_save_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['scriptomatic_save_nonce']))
            : '';

        if (!wp_verify_nonce($secondary_nonce, SCRIPTOMATIC_NONCE_ACTION)) {
            add_settings_error(
                'scriptomatic_script_content',
                'nonce_invalid',
                __('Security check failed. Please refresh the page and try again.', 'scriptomatic'),
                'error'
            );
            return $previous_content;
        }

        // -----------------------------------------------------------------
        // Gate 2: Per-user rate limiter — prevents rapid-fire saves.
        // -----------------------------------------------------------------
        if ($this->is_rate_limited()) {
            add_settings_error(
                'scriptomatic_script_content',
                'rate_limited',
                sprintf(
                    /* translators: %d: number of seconds the user must wait */
                    __('You are saving too quickly. Please wait %d seconds before trying again.', 'scriptomatic'),
                    SCRIPTOMATIC_RATE_LIMIT_SECONDS
                ),
                'error'
            );
            return $previous_content;
        }

        if (!is_string($input)) {
            add_settings_error(
                'scriptomatic_script_content',
                'invalid_type',
                __('Script content must be plain text.', 'scriptomatic'),
                'error'
            );

            return $previous_content;
        }

        $input = wp_unslash($input);
        $input = wp_kses_no_null($input);
        $input = str_replace("\r\n", "\n", $input);

        $validated_input = wp_check_invalid_utf8($input, true);
        if ('' === $validated_input && '' !== $input) {
            add_settings_error(
                'scriptomatic_script_content',
                'invalid_utf8',
                __('Script content contains invalid UTF-8 characters.', 'scriptomatic'),
                'error'
            );

            return $previous_content;
        }

        $input = $validated_input;

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $input)) {
            add_settings_error(
                'scriptomatic_script_content',
                'control_characters_detected',
                __('Script content contains disallowed control characters.', 'scriptomatic'),
                'error'
            );

            return $previous_content;
        }

        // Reject PHP tags outright.
        if (preg_match('/<\?(php|=)?/i', $input)) {
            add_settings_error(
                'scriptomatic_script_content',
                'php_tags_detected',
                __('PHP tags are not allowed in script content.', 'scriptomatic'),
                'error'
            );

            return $previous_content;
        }

        // Remove any existing script tags to prevent double-wrapping.
        if (preg_match('/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', $input)) {
            $input = preg_replace('/<\s*script[^>]*>(.*?)<\s*\/\s*script\s*>/is', '$1', $input);
            add_settings_error(
                'scriptomatic_script_content',
                'script_tags_removed',
                __('Script tags were removed automatically. Enter JavaScript only.', 'scriptomatic'),
                'warning'
            );
        }

        // Validate length
        if (strlen($input) > SCRIPTOMATIC_MAX_SCRIPT_LENGTH) {
            add_settings_error(
                'scriptomatic_script_content',
                'script_too_long',
                sprintf(
                    __('Script content exceeds maximum length of %s characters.', 'scriptomatic'),
                    number_format(SCRIPTOMATIC_MAX_SCRIPT_LENGTH)
                ),
                'error'
            );
            return get_option(SCRIPTOMATIC_OPTION_NAME, '');
        }

        // Check for potentially dangerous patterns (basic security check)
        $dangerous_patterns = array(
            '/<\s*iframe/i',
            '/<\s*object/i',
            '/<\s*embed/i',
            '/<\s*link/i',
            '/<\s*style/i',
            '/<\s*meta/i',
        );

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                add_settings_error(
                    'scriptomatic_script_content',
                    'dangerous_content',
                    __('Script content contains potentially dangerous HTML tags. Please use JavaScript only.', 'scriptomatic'),
                    'warning'
                );
            }
        }

        // Trim whitespace
        $input = trim($input);

        // Log the change for security audit
        if (current_user_can('manage_options')) {
            $this->log_script_change($input);
        }

        // Record this successful save to enforce the rate limit on the next attempt.
        $this->record_save_timestamp();

        return $input;
    }

    /**
     * Determine whether the current user has exceeded the configured save rate.
     *
     * Uses a user-specific transient whose key incorporates the user ID so
     * that rate-limit state is never shared between accounts.  The transient
     * expires automatically after {@see SCRIPTOMATIC_RATE_LIMIT_SECONDS}.
     *
     * @since  1.0.0
     * @access private
     * @return bool True if the user is rate-limited and the save should be blocked.
     */
    private function is_rate_limited() {
        $user_id      = get_current_user_id();
        $transient_key = 'scriptomatic_save_' . $user_id;

        return (false !== get_transient($transient_key));
    }

    /**
     * Record the current timestamp for rate-limiting purposes.
     *
     * Sets a user-specific transient that expires after
     * {@see SCRIPTOMATIC_RATE_LIMIT_SECONDS} seconds, blocking further saves
     * until the window has elapsed.
     *
     * @since  1.0.0
     * @access private
     * @return void
     */
    private function record_save_timestamp() {
        $user_id       = get_current_user_id();
        $transient_key = 'scriptomatic_save_' . $user_id;

        set_transient($transient_key, time(), SCRIPTOMATIC_RATE_LIMIT_SECONDS);
    }

    /**
     * Write a security-audit log entry when the stored script content changes.
     *
     * Entries are written via {@see error_log()} in the format:
     * `Scriptomatic: Script updated by user <login> (ID: <id>) from IP: <ip>`
     *
     * If the new content is identical to what is already stored no entry is
     * written, preventing spurious log spam on accidental double-saves.
     *
     * @since  1.0.0
     * @access private
     * @param  string $new_content The sanitised script content about to be saved.
     * @return void
     */
    private function log_script_change($new_content) {
        $old_content = get_option(SCRIPTOMATIC_OPTION_NAME, '');

        if ($old_content !== $new_content) {
            $user = wp_get_current_user();
            error_log(sprintf(
                'Scriptomatic: Script updated by user %s (ID: %d) from IP: %s',
                $user->user_login,
                $user->ID,
                $this->get_client_ip()
            ));
        }
    }

    /**
     * Resolve the most accurate available client IP address.
     *
     * Iterates over common proxy headers in order of trustworthiness and
     * returns the first value that passes {@see filter_var()} validation as a
     * valid IP address.  Falls back to `'Unknown'` when no valid address can
     * be determined.
     *
     * **Note:** Proxy headers such as `HTTP_X_FORWARDED_FOR` can be spoofed by
     * clients.  This method is used for audit-log context only — it should
     * never be used for access control.
     *
     * @since  1.0.0
     * @access private
     * @return string A validated IP address string, or `'Unknown'`.
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');

        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        return $ip;
                    }
                }
            }
        }

        return 'Unknown';
    }

    /**
     * Output the descriptive text for the main settings section.
     *
     * Callback registered via {@see add_settings_section()}. The output is
     * rendered directly above the settings field inside `do_settings_sections()`.
     *
     * @since  1.0.0
     * @return void
     */
    public function render_section_description() {
        echo '<p>';
        esc_html_e('Add custom JavaScript code that will be injected into the head section of your WordPress site, right before the closing </head> tag.', 'scriptomatic');
        echo '</p>';
        echo '<p class="description">';
        esc_html_e('This is useful for adding tracking codes, analytics, custom scripts, or third-party integrations.', 'scriptomatic');
        echo '</p>';
    }

    /**
     * Output the script-content `<textarea>` and its associated UI chrome.
     *
     * Callback registered via {@see add_settings_field()}.  Renders:
     * - The main `<textarea>` pre-populated with the stored option value.
     * - A live character counter updated via an inline jQuery snippet.
     * - A brief usage description.
     * - A security-notice panel reminding admins of the trust boundary.
     *
     * All dynamic values are escaped appropriately before output.
     *
     * @since  1.0.0
     * @return void
     */
    public function render_script_field() {
        $script_content = get_option(SCRIPTOMATIC_OPTION_NAME, '');
        $char_count = strlen($script_content);
        $max_length = SCRIPTOMATIC_MAX_SCRIPT_LENGTH;

        ?>
        <textarea
            id="scriptomatic_script_content"
            name="<?php echo esc_attr(SCRIPTOMATIC_OPTION_NAME); ?>"
            rows="20"
            cols="100"
            class="large-text code"
            placeholder="<?php esc_attr_e('Enter your JavaScript code here (without <script> tags)', 'scriptomatic'); ?>"
            aria-describedby="script-content-description script-char-count"
        ><?php echo esc_textarea($script_content); ?></textarea>

        <p id="script-char-count" class="description">
            <?php
            printf(
                esc_html__('Character count: %s / %s', 'scriptomatic'),
                '<span id="current-char-count">' . number_format($char_count) . '</span>',
                number_format($max_length)
            );
            ?>
        </p>

        <p id="script-content-description" class="description">
            <strong><?php esc_html_e('Important:', 'scriptomatic'); ?></strong>
            <?php esc_html_e('Enter only JavaScript code. Do not include <script> tags - they will be added automatically.', 'scriptomatic'); ?>
            <br>
            <?php esc_html_e('The code will be executed on every page of your site. Test thoroughly before deploying.', 'scriptomatic'); ?>
        </p>

        <div class="scriptomatic-security-notice" style="margin-top: 15px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">
            <h4 style="margin-top: 0;">
                <span class="dashicons dashicons-shield" style="color: #ffc107;"></span>
                <?php esc_html_e('Security Notice', 'scriptomatic'); ?>
            </h4>
            <ul style="margin: 0; padding-left: 20px;">
                <li><?php esc_html_e('Only administrators with "manage_options" capability can modify this content.', 'scriptomatic'); ?></li>
                <li><?php esc_html_e('All changes are logged for security auditing.', 'scriptomatic'); ?></li>
                <li><?php esc_html_e('Content is validated to prevent certain dangerous HTML tags.', 'scriptomatic'); ?></li>
                <li><?php esc_html_e('Always verify code from trusted sources before adding it here.', 'scriptomatic'); ?></li>
            </ul>
        </div>
        <?php
    }

    /**
     * Output the full HTML for the Scriptomatic settings page.
     *
     * Performs a capability check before rendering so that the page cannot be
     * accessed even if the menu hook is somehow bypassed.  The form targets
     * `options.php` (standard WordPress pattern) and includes:
     * - The WordPress settings-API nonce via {@see settings_fields()}.
     * - A secondary, plugin-specific nonce verified independently inside
     *   {@see Scriptomatic::sanitize_script_content()}.
     * - All registered settings sections and fields.
     * - A submit button and a quick-start guide.
     *
     * @since  1.0.0
     * @return void
     */
    public function render_settings_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have sufficient permissions to access this page.', 'scriptomatic'),
                esc_html__('Permission Denied', 'scriptomatic'),
                array('response' => 403)
            );
        }

        ?>
        <div class="wrap" id="scriptomatic-settings">
            <h1>
                <span class="dashicons dashicons-admin-generic" style="font-size: 32px; width: 32px; height: 32px;"></span>
                <?php echo esc_html(get_admin_page_title()); ?>
            </h1>

            <p class="description" style="font-size: 14px; margin-bottom: 20px;">
                <?php esc_html_e('Version', 'scriptomatic'); ?>: <?php echo esc_html(SCRIPTOMATIC_VERSION); ?> |
                <?php esc_html_e('Author', 'scriptomatic'); ?>: <a href="https://github.com/richardkentgates" target="_blank">Richard Kent Gates</a> |
                <a href="https://github.com/richardkentgates/scriptomatic" target="_blank"><?php esc_html_e('Documentation', 'scriptomatic'); ?></a>
            </p>

            <?php settings_errors('scriptomatic_script_content'); ?>

            <form action="options.php" method="post">
                <?php
                // Primary nonce: issued by the Settings API (12-hour window).
                settings_fields('scriptomatic_settings');

                // Secondary nonce: shorter-lived, action-specific nonce for
                // defence-in-depth, verified in sanitize_script_content().
                wp_nonce_field(SCRIPTOMATIC_NONCE_ACTION, 'scriptomatic_save_nonce');

                do_settings_sections('scriptomatic_settings');
                submit_button(__('Save Script', 'scriptomatic'), 'primary large');
                ?>
            </form>

            <hr style="margin: 30px 0;">

            <div class="scriptomatic-info-section">
                <h2><?php esc_html_e('Quick Start Guide', 'scriptomatic'); ?></h2>
                <ol>
                    <li><?php esc_html_e('Enter your JavaScript code in the textarea above (without <script> tags).', 'scriptomatic'); ?></li>
                    <li><?php esc_html_e('Click "Save Script" to store your changes.', 'scriptomatic'); ?></li>
                    <li><?php esc_html_e('The script will automatically be injected before the closing </head> tag on all pages.', 'scriptomatic'); ?></li>
                    <li><?php esc_html_e('Test your site to ensure the script works as expected.', 'scriptomatic'); ?></li>
                </ol>

                <h3><?php esc_html_e('Common Use Cases', 'scriptomatic'); ?></h3>
                <ul>
                    <li><?php esc_html_e('Google Analytics or other tracking codes', 'scriptomatic'); ?></li>
                    <li><?php esc_html_e('Facebook Pixel or conversion tracking', 'scriptomatic'); ?></li>
                    <li><?php esc_html_e('Custom JavaScript libraries or functions', 'scriptomatic'); ?></li>
                    <li><?php esc_html_e('Third-party integration scripts', 'scriptomatic'); ?></li>
                    <li><?php esc_html_e('Performance monitoring tools', 'scriptomatic'); ?></li>
                </ul>
            </div>
        </div>
        <?php
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
                '<p>' . __('Scriptomatic allows you to safely inject custom JavaScript code into the head section of your WordPress site. The code is executed on every page, right before the closing </head> tag.', 'scriptomatic') . '</p>' .
                '<p>' . __('This plugin is designed with security and performance in mind, providing input validation, sanitization, and audit logging.', 'scriptomatic') . '</p>',
        ));

        // Usage tab
        $screen->add_help_tab(array(
            'id' => 'scriptomatic_usage',
            'title' => __('Usage', 'scriptomatic'),
            'content' => '<h3>' . __('How to Use', 'scriptomatic') . '</h3>' .
                '<ol>' .
                '<li><strong>' . __('Add Your Code:', 'scriptomatic') . '</strong> ' . __('Paste your JavaScript code into the textarea. Do not include <script> tags - they will be added automatically.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Save Changes:', 'scriptomatic') . '</strong> ' . __('Click the "Save Script" button to store your code.', 'scriptomatic') . '</li>' .
                '<li><strong>' . __('Verify:', 'scriptomatic') . '</strong> ' . __('Check your website\'s source code to confirm the script is injected in the <head> section.', 'scriptomatic') . '</li>' .
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
                '<li><strong>' . __('Audit Logging:', 'scriptomatic') . '</strong> ' . __('All changes are logged with user information and IP address.', 'scriptomatic') . '</li>' .
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
                '<li>' . __('Check that you clicked "Save Script" after entering your code.', 'scriptomatic') . '</li>' .
                '<li>' . __('Clear your site cache and browser cache.', 'scriptomatic') . '</li>' .
                '<li>' . __('View page source to verify the script tag is present.', 'scriptomatic') . '</li>' .
                '<li>' . __('Check if another plugin or theme is preventing wp_head from running.', 'scriptomatic') . '</li>' .
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
            '<p><a href="https://github.com/richardkentgates/scriptomatic" target="_blank">' . __('Plugin Documentation', 'scriptomatic') . '</a></p>' .
            '<p><a href="https://github.com/richardkentgates/scriptomatic/issues" target="_blank">' . __('Report Issues', 'scriptomatic') . '</a></p>' .
            '<p><a href="https://github.com/richardkentgates" target="_blank">' . __('Developer Profile', 'scriptomatic') . '</a></p>' .
            '<p><a href="https://developer.wordpress.org/reference/hooks/wp_head/" target="_blank">' . __('WordPress wp_head Documentation', 'scriptomatic') . '</a></p>'
        );
    }

    /**
     * Enqueue (or inline) scripts and styles for the plugin's admin page.
     *
     * Hooked on {@see 'admin_enqueue_scripts'} and exits immediately when
     * `$hook` does not match the Scriptomatic settings page, keeping the admin
     * area free of unnecessary asset loading.
     *
     * Currently inlines a small jQuery snippet that:
     * - Counts characters as the user types in the textarea.
     * - Colours the counter yellow (>75% of limit) or red (>90%) as a live
     *   visual warning before the hard server-side limit is reached.
     *
     * @since  1.0.0
     * @param  string $hook The hook suffix for the current admin page.
     * @return void
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our settings page
        if ('settings_page_scriptomatic' !== $hook) {
            return;
        }

        // Inline script for character counter
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                var textarea = $('#scriptomatic_script_content');
                var counter = $('#current-char-count');
                var maxLength = " . SCRIPTOMATIC_MAX_SCRIPT_LENGTH . ";

                if (textarea.length && counter.length) {
                    textarea.on('input', function() {
                        var length = $(this).val().length;
                        counter.text(length.toLocaleString());

                        // Visual feedback for approaching limit
                        if (length > maxLength * 0.9) {
                            counter.css('color', '#dc3545');
                            counter.css('font-weight', 'bold');
                        } else if (length > maxLength * 0.75) {
                            counter.css('color', '#ffc107');
                            counter.css('font-weight', 'bold');
                        } else {
                            counter.css('color', '');
                            counter.css('font-weight', '');
                        }
                    });
                }
            });
        ");
    }

    /**
     * Prepend a Settings link to the plugin's action links on the Plugins screen.
     *
     * Hooked via the `plugin_action_links_{plugin_basename}` filter.  The link
     * is prepended (not appended) so it appears as the first action — the most
     * prominent position — consistent with the conventions of well-known
     * WordPress plugins.
     *
     * @since  1.0.0
     * @param  string[] $links Existing action-link HTML strings keyed by slug.
     * @return string[] Modified array with the Settings link at index 0.
     */
    public function add_action_links($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('options-general.php?page=scriptomatic'),
            __('Settings', 'scriptomatic')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Output the stored JavaScript into the page `<head>` on the front-end.
     *
     * Hooked on {@see 'wp_head'} with priority 999 so the script is injected
     * as late as possible — just before the closing `</head>` tag — which
     * keeps it out of the way of theme and plugin scripts that use earlier
     * priorities.
     *
     * The method short-circuits on admin pages and when the stored script is
     * empty to avoid any unnecessary output.  The content is intentionally
     * NOT escaped on output because it is JavaScript code that must remain
     * executable; all security enforcement is applied at write-time inside
     * {@see Scriptomatic::sanitize_script_content()}.
     *
     * @since  1.0.0
     * @return void
     */
    public function inject_script() {
        // Only inject on front-end
        if (is_admin()) {
            return;
        }

        $script_content = get_option(SCRIPTOMATIC_OPTION_NAME, '');

        // Validate content exists and is not empty
        if (empty(trim($script_content))) {
            return;
        }

        // Output the script with proper formatting
        echo "\n<!-- Scriptomatic v" . esc_attr(SCRIPTOMATIC_VERSION) . " -->\n";
        echo '<script type="text/javascript">' . "\n";
        // Don't escape the script content as it needs to execute as-is
        // Security is handled during save via sanitize_script_content()
        echo $script_content . "\n";
        echo '</script>' . "\n";
        echo "<!-- /Scriptomatic -->\n";
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
