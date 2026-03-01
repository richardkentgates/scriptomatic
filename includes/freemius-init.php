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
                'is_org_compliant'    => true,
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
