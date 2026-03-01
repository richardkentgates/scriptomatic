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
            $product_id = 'REPLACE_WITH_PRODUCT_ID'; // TODO: numeric product ID from Freemius dashboard.
            $public_key = 'pk_REPLACE_WITH_PUBLIC_KEY'; // TODO: public key from Freemius dashboard.

            // Don't attempt to initialise the SDK until real credentials are in place.
            if ( 'REPLACE_WITH_PRODUCT_ID' === $product_id || 'pk_REPLACE_WITH_PUBLIC_KEY' === $public_key ) {
                return null;
            }

            // Include the Freemius SDK.
            require_once SCRIPTOMATIC_PLUGIN_DIR . 'freemius/start.php';

            $scriptomatic_fs = fs_dynamic_init( array(
                'id'               => $product_id,
                'slug'             => 'scriptomatic',
                'type'             => 'plugin',
                'public_key'       => $public_key,
                'is_premium'       => true,
                'premium_suffix'   => 'Pro',
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'trial'            => array(
                    'days'               => 14,
                    'is_require_payment' => false,
                ),
                'has_affiliation'  => false,
                'menu'             => array(
                    'slug'    => 'scriptomatic',
                    'contact' => true,
                    'support' => false,
                    'parent'  => array(
                        'slug' => 'scriptomatic',
                    ),
                ),
                // Set to true once the product is published in the Freemius dashboard.
                'is_live'          => false,
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
