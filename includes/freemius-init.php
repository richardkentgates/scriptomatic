<?php
/**
 * Freemius SDK initialisation for Scriptomatic.
 *
 * SETUP INSTRUCTIONS
 * ------------------
 * 1. Create a product at https://dashboard.freemius.com/
 *    → Add Product → Plugin → slug: "scriptomatic"
 * 2. From the product's Overview → Settings copy:
 *    • Product ID  → replace REPLACE_WITH_PRODUCT_ID below (numeric string)
 *    • Public Key  → replace pk_REPLACE_WITH_PUBLIC_KEY below
 * 3. Set 'is_live' to true once the product is published.
 *
 * @package Scriptomatic
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'scriptomatic_fs' ) ) {

    /**
     * Return (and on first call, create) the Freemius singleton for Scriptomatic.
     *
     * @since  3.0.0
     * @return Freemius
     */
    function scriptomatic_fs() {
        global $scriptomatic_fs;

        if ( ! isset( $scriptomatic_fs ) ) {
            // Include the Freemius SDK.
            require_once SCRIPTOMATIC_PLUGIN_DIR . 'freemius/start.php';

            $scriptomatic_fs = fs_dynamic_init( array(
                'id'                  => '25187',
                'slug'                => 'scriptomatic',
                'type'                => 'plugin',
                'public_key'          => 'pk_3704acdd7fcd6b01254ab6fae5a63',
                'is_premium'          => true,
                'premium_suffix'      => 'Pro',
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_live'             => true,
                'is_org_compliant'    => true,
                'trial'               => array(
                    'days'               => 3,
                    'is_require_payment' => false,
                ),
                // Automatically removed in the free version. If you're not using the
                // auto-generated free version, delete this line before uploading to wp.org.
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'menu'                => array(
                    'slug'    => 'scriptomatic',
                    'support' => false,
                    'parent'  => array(
                        'slug' => 'scriptomatic',
                    ),
                ),
            ) );
        }

        return $scriptomatic_fs;
    }

    // Initialise Freemius.
    scriptomatic_fs();

    // Allow other code to hook in after SDK initialisation.
    do_action( 'scriptomatic_fs_loaded' );
}

/**
 * Whether the current site has an active Scriptomatic Pro licence (or active trial).
 *
 * Wraps the Freemius SDK check so the rest of the plugin never has to
 * reference the SDK directly.  Returns false gracefully when the SDK has
 * not yet been initialised (e.g. during unit-test bootstrapping or when
 * placeholder credentials are still in place).
 *
 * @since  3.0.0
 * @return bool
 */
function scriptomatic_is_premium() {
    if ( ! function_exists( 'scriptomatic_fs' ) ) {
        return false;
    }
    try {
        $fs = scriptomatic_fs();
        return $fs ? $fs->can_use_premium_code() : false;
    } catch ( Exception $e ) {
        return false;
    }
}

/**
 * Uninstall callback registered with Freemius.
 *
 * Freemius fires 'after_uninstall' after reporting the uninstall event to
 * its servers, which allows us to collect opt-out feedback.  This replaces
 * the previous uninstall.php static hook.
 *
 * Honours the "keep_data_on_uninstall" setting: when the administrator has
 * ticked that option, this function returns immediately without deleting
 * anything.  For multisite installations it iterates every sub-site.
 *
 * @since  3.0.0
 * @return void
 */
function scriptomatic_fs_uninstall_cleanup() {
    $options = array(
        'scriptomatic_head',
        'scriptomatic_footer',
        'scriptomatic_js_files',
        'scriptomatic_plugin_settings',
    );

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
    scriptomatic_drop_log_table();

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
            scriptomatic_drop_log_table();
            restore_current_blog();
        }

        // Network-wide options (if any were ever stored at the network level).
        foreach ( $options as $option_key ) {
            delete_site_option( $option_key );
        }
    }
}

/**
 * Drop the custom activity log table for the current site.
 *
 * @since  3.0.0
 * @return void
 */
function scriptomatic_drop_log_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'scriptomatic_log';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

/**
 * Remove the wp-content/uploads/scriptomatic/ directory and all files in it.
 *
 * @since  3.0.0
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

// Register uninstall cleanup with Freemius (fires after uninstall is reported).
scriptomatic_fs()->add_action( 'after_uninstall', 'scriptomatic_fs_uninstall_cleanup' );
