<?php
/**
 * Trait: Front-end script injection for Scriptomatic.
 *
 * Hooks into `wp_head` and `wp_footer` to output stored linked-URL `<script>`
 * tags and inline `<script>` blocks, gated by `check_load_conditions()`.
 *
 * @package  Scriptomatic
 * @since    1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Front-end HTML output for head and footer scripts.
 */
trait Scriptomatic_Injector {

    /**
     * Inject stored scripts into the page `<head>` on the front-end.
     *
     * Guards against admin context, then delegates to {@see inject_scripts_for()}.
     *
     * @since  1.0.0
     * @return void
     */
    public function inject_head_scripts() {
        if ( is_admin() ) {
            return;
        }
        $this->inject_scripts_for( 'head' );
    }

    /**
     * Inject stored scripts before the closing `</body>` tag.
     *
     * @since  1.2.0
     * @return void
     */
    public function inject_footer_scripts() {
        if ( is_admin() ) {
            return;
        }
        $this->inject_scripts_for( 'footer' );
    }

    /**
     * Core injection logic shared by head and footer output.
     *
     * Each linked-URL entry is evaluated against its own stored conditions.
     * The inline script block is evaluated against the location-level
     * `SCRIPTOMATIC_{HEAD|FOOTER}_CONDITIONS` option.  Output is wrapped in
     * the Scriptomatic comment block only when at least one item passes.
     *
     * @since  1.2.0
     * @since  1.6.0 Per-entry conditions for external URLs; inline script
     *               conditions evaluated separately via check_load_conditions().
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return void
     */
    private function inject_scripts_for( $location ) {
        $script_key     = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_SCRIPT : SCRIPTOMATIC_HEAD_SCRIPT;
        $linked_key     = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_LINKED : SCRIPTOMATIC_HEAD_LINKED;
        $script_content = $this->get_front_end_option( $script_key, '' );
        $linked_raw     = $this->get_front_end_option( $linked_key, '[]' );
        $linked_entries = json_decode( $linked_raw, true );
        if ( ! is_array( $linked_entries ) ) {
            $linked_entries = array();
        }

        // Collect output, evaluating conditions per item.
        $output_parts = array();

        foreach ( $linked_entries as $entry ) {
            // Legacy plain URL string — treat as All Pages.
            if ( is_string( $entry ) ) {
                $url = trim( $entry );
                if ( '' !== $url ) {
                    $output_parts[] = '<script src="' . esc_url( $url ) . '"></script>';
                }
                continue;
            }
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $url        = isset( $entry['url'] ) ? (string) $entry['url'] : '';
            $conditions = ( isset( $entry['conditions'] ) && is_array( $entry['conditions'] ) )
                          ? $entry['conditions']
                          : array( 'type' => 'all', 'values' => array() );

            if ( '' === $url ) {
                continue;
            }

            if ( $this->evaluate_conditions_object( $conditions ) ) {
                $output_parts[] = '<script src="' . esc_url( $url ) . '"></script>';
            }
        }

        // Inline script: check the location-level conditions option.
        if ( ! empty( trim( $script_content ) ) && $this->check_load_conditions( $location ) ) {
            $output_parts[] = '<script>';
            $output_parts[] = $script_content; // Intentionally unescaped — validated at write-time.
            $output_parts[] = '</script>';
        }

        if ( empty( $output_parts ) ) {
            return;
        }

        $label = ( 'footer' === $location ) ? 'footer' : 'head';
        echo "\n<!-- Scriptomatic v" . esc_html( SCRIPTOMATIC_VERSION ) . " ({$label}) -->\n";
        foreach ( $output_parts as $part ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $part . "\n";
        }
        echo "<!-- /Scriptomatic ({$label}) -->\n";
    }

}
