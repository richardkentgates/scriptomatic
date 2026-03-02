<?php
/**
 * Trait: Front-end script injection for Scriptomatic.
 *
 * Hooks into `wp_enqueue_scripts` to register and enqueue stored linked-URL
 * scripts and inline script blocks via the WordPress enqueue API.
 *
 * @package  Scriptomatic
 * @since    1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end script enqueuing for head and footer locations.
 */
trait Scriptomatic_Injector {

    /**
     * Enqueue stored scripts on the front-end via the WordPress script API.
     *
     * Hooked to `wp_enqueue_scripts`. Evaluates conditions for each entry and
     * calls `wp_enqueue_script()` for external URLs and `wp_add_inline_script()`
     * for inline script content, keeping full compatibility with the WP script
     * dependency system.
     *
     * @since  3.0.1
     * @return void
     */
    public function enqueue_frontend_scripts() {
        if ( is_admin() ) {
            return;
        }
        $this->enqueue_scripts_for( 'head' );
        $this->enqueue_scripts_for( 'footer' );
    }

    /**
     * Core enqueue logic shared by head and footer locations.
     *
     * Each linked-URL entry is evaluated against its own stored conditions.
     * The inline script block is evaluated against the location-level conditions.
     *
     * @since  3.0.1
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return void
     */
    private function enqueue_scripts_for( $location ) {
        $loc_data       = $this->get_location( $location );
        $script_content = $loc_data['script'];
        $linked_entries = $loc_data['urls'];
        $loc_conditions = $loc_data['conditions'];
        $in_footer      = ( 'footer' === $location );

        if ( ! is_array( $linked_entries ) ) {
            $linked_entries = array();
        }

        $is_premium = scriptomatic_is_premium();

        // External URLs. Free tier: load on every page. Pro: evaluate per-entry conditions.
        foreach ( $linked_entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $url              = isset( $entry['url'] ) ? (string) $entry['url'] : '';
            $entry_conditions = ( isset( $entry['conditions'] ) && is_array( $entry['conditions'] ) )
                                ? $entry['conditions']
                                : array( 'logic' => 'and', 'rules' => array() );

            if ( '' === $url ) {
                continue;
            }

            if ( ! $is_premium || $this->evaluate_conditions_object( $entry_conditions ) ) {
                wp_enqueue_script(
                    'scriptomatic-url-' . md5( $url ),
                    $url,
                    array(),
                    SCRIPTOMATIC_VERSION,
                    $in_footer
                );
            }
        }

        // Managed JS files: Pro feature only.
        if ( $is_premium ) {
            $js_files = $this->get_js_files_meta();
            foreach ( $js_files as $file ) {
                if ( ! isset( $file['location'] ) || $file['location'] !== $location ) {
                    continue;
                }
                if ( empty( $file['filename'] ) ) {
                    continue;
                }
                $file_conditions = ( isset( $file['conditions'] ) && is_array( $file['conditions'] ) )
                    ? $file['conditions']
                    : array( 'logic' => 'and', 'rules' => array() );

                if ( $this->evaluate_conditions_object( $file_conditions ) ) {
                    $file_url = $this->get_js_files_url() . $file['filename'];
                    wp_enqueue_script(
                        'scriptomatic-file-' . md5( $file_url ),
                        $file_url,
                        array(),
                        SCRIPTOMATIC_VERSION,
                        $in_footer
                    );
                }
            }
        }

        // Inline script. Free tier: load on every page. Pro: evaluate location-level conditions.
        $inline_passes = ! $is_premium || $this->evaluate_conditions_object( $loc_conditions );
        if ( ! empty( trim( $script_content ) ) && $inline_passes ) {
            $handle = 'scriptomatic-inline-' . $location;
            // Register an empty placeholder script to attach inline content to.
            wp_register_script( $handle, false, array(), SCRIPTOMATIC_VERSION, $in_footer );
            wp_enqueue_script( $handle );
            wp_add_inline_script( $handle, $script_content );
        }

    }

}
