<?php
/**
 * Main Scriptomatic class.
 *
 * Requires all trait files, then declares the singleton class that wires
 * every WordPress hook and delegates all logic to the appropriate trait.
 *
 * @package  Scriptomatic
 * @since    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once SCRIPTOMATIC_PLUGIN_DIR . 'includes/trait-menus.php';
require_once SCRIPTOMATIC_PLUGIN_DIR . 'includes/trait-sanitizer.php';
require_once SCRIPTOMATIC_PLUGIN_DIR . 'includes/trait-history.php';
require_once SCRIPTOMATIC_PLUGIN_DIR . 'includes/trait-settings.php';
require_once SCRIPTOMATIC_PLUGIN_DIR . 'includes/trait-renderer.php';
require_once SCRIPTOMATIC_PLUGIN_DIR . 'includes/trait-pages.php';
require_once SCRIPTOMATIC_PLUGIN_DIR . 'includes/trait-enqueue.php';
require_once SCRIPTOMATIC_PLUGIN_DIR . 'includes/trait-injector.php';

/**
 * Main plugin class.
 *
 * Implements a singleton that wires all WordPress hooks, registers the plugin
 * settings, sanitises user input, enforces rate-limiting and nonce security,
 * renders the admin UI, and injects stored scripts into the front-end.
 *
 * All method groups are implemented in dedicated traits:
 * - Scriptomatic_Menus     — admin menu registrations.
 * - Scriptomatic_Sanitizer — input validation and sanitisation.
 * - Scriptomatic_History   — revision history and AJAX rollback.
 * - Scriptomatic_Settings  — Settings API wiring + plugin-settings CRUD.
 * - Scriptomatic_Renderer  — settings-field callbacks + load conditions.
 * - Scriptomatic_Pages     — page renderers (including Audit Log), network pages, help tabs, clear-audit-log action, and action links.
 * - Scriptomatic_Enqueue   — admin asset enqueueing.
 * - Scriptomatic_Injector  — front-end script injection.
 *
 * @package Scriptomatic
 * @author  Richard Kent Gates <mail@richardkentgates.com>
 * @since   1.0.0
 * @link    https://github.com/richardkentgates/scriptomatic
 */
class Scriptomatic {

    use Scriptomatic_Menus;
    use Scriptomatic_Sanitizer;
    use Scriptomatic_History;
    use Scriptomatic_Settings;
    use Scriptomatic_Renderer;
    use Scriptomatic_Pages;
    use Scriptomatic_Enqueue;
    use Scriptomatic_Injector;

    // =========================================================================
    // SINGLETON
    // =========================================================================

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
     * @since  1.0.0
     * @return Scriptomatic
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor — prevents direct instantiation.
     *
     * @since  1.0.0
     * @access private
     */
    private function __construct() {
        $this->init_hooks();
    }

    // =========================================================================
    // HOOK REGISTRATION
    // =========================================================================

    /**
     * Register all WordPress action and filter hooks used by this plugin.
     *
     * @since  1.0.0
     * @return void
     */
    private function init_hooks() {
        add_action( 'init',                  array( $this, 'load_textdomain' ) );
        add_action( 'admin_menu',            array( $this, 'add_admin_menus' ) );
        add_action( 'admin_init',            array( $this, 'register_settings' ) );
        add_action( 'wp_head',               array( $this, 'inject_head_scripts' ), 999 );
        add_action( 'wp_footer',             array( $this, 'inject_footer_scripts' ), 999 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_filter(
            'plugin_action_links_' . plugin_basename( SCRIPTOMATIC_PLUGIN_FILE ),
            array( $this, 'add_action_links' )
        );

        // AJAX — wp_ajax_ prefix ensures only logged-in users can trigger it.
        add_action( 'wp_ajax_scriptomatic_rollback', array( $this, 'ajax_rollback' ) );

        // Audit log — clear action must run before any output.
        add_action( 'admin_init', array( $this, 'maybe_clear_audit_log' ) );

        // Multisite: network-admin menu + custom save handler + settings registration.
        if ( is_multisite() ) {
            add_action( 'network_admin_menu',                                  array( $this, 'add_network_admin_menus' ) );
            add_action( 'network_admin_init',                                  array( $this, 'register_settings' ) );
            add_action( 'network_admin_edit_scriptomatic_network_save',        array( $this, 'handle_network_settings_save' ) );
            add_action( 'network_admin_enqueue_scripts',                       array( $this, 'enqueue_admin_scripts' ) );
            add_filter(
                'network_admin_plugin_action_links_' . plugin_basename( SCRIPTOMATIC_PLUGIN_FILE ),
                array( $this, 'add_network_action_links' )
            );
        }
    }

    // =========================================================================
    // I18N
    // =========================================================================

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
            dirname( plugin_basename( SCRIPTOMATIC_PLUGIN_FILE ) ) . '/languages/'
        );
    }

    // =========================================================================
    // CAPABILITY HELPERS
    // =========================================================================

    /**
     * The capability required to manage Scriptomatic on a single site.
     *
     * `manage_options` maps to Administrator.
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
        if ( ! is_multisite() ) {
            return false;
        }
        if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
            require_once ABSPATH . '/wp-admin/includes/plugin.php';
        }
        return is_plugin_active_for_network( plugin_basename( SCRIPTOMATIC_PLUGIN_FILE ) );
    }

    // =========================================================================
    // NETWORK / PER-SITE OPTION HELPER
    // =========================================================================

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
    private function get_front_end_option( $key, $default = '' ) {
        $value = get_option( $key, false );
        if ( false === $value && is_multisite() ) {
            return get_site_option( $key, $default );
        }
        return ( false !== $value ) ? $value : $default;
    }
}
