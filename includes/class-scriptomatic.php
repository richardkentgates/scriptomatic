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
require_once SCRIPTOMATIC_PLUGIN_DIR . 'includes/trait-files.php';

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
 * - Scriptomatic_Pages     — page renderers (Head/Footer/General pages, embedded Activity Log, help tabs, and action links).
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
    use Scriptomatic_Files;

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
        add_action( 'wp_ajax_scriptomatic_rollback',                  array( $this, 'ajax_rollback' ) );
        add_action( 'wp_ajax_scriptomatic_get_history_content',       array( $this, 'ajax_get_history_content' ) );
        add_action( 'wp_ajax_scriptomatic_rollback_js_file',          array( $this, 'ajax_rollback_js_file' ) );
        add_action( 'wp_ajax_scriptomatic_get_file_activity_content', array( $this, 'ajax_get_file_activity_content' ) );
        add_action( 'wp_ajax_scriptomatic_delete_js_file',            array( $this, 'ajax_delete_js_file' ) );
        add_action( 'wp_ajax_scriptomatic_restore_deleted_file',       array( $this, 'ajax_restore_deleted_file' ) );
        add_action( 'wp_ajax_scriptomatic_rollback_urls',              array( $this, 'ajax_rollback_urls' ) );
        add_action( 'wp_ajax_scriptomatic_get_url_history_content',    array( $this, 'ajax_get_url_history_content' ) );

        // Admin-post: JS file save form.
        add_action( 'admin_post_scriptomatic_save_js_file',      array( $this, 'handle_save_js_file' ) );
    }

    /**
     * Prevent cloning the singleton instance.
     *
     * @since  1.7.1
     * @return void
     */
    private function __clone() {}

    /**
     * Prevent deserialization of the singleton instance.
     *
     * @since  1.7.1
     * @return void
     */
    public function __wakeup() {
        _doing_it_wrong( __FUNCTION__, 'Cannot unserialize a singleton.', '1.7.1' );
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
    // CAPABILITY HELPER
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

}
