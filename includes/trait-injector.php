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
     * Evaluates load conditions first and produces no output when the
     * condition is not met.  Otherwise emits wrapped `<!-- Scriptomatic -->`
     * comment markers, zero or more `<script src>` tags for linked URLs, and
     * (when non-empty) one inline `<script>` block.
     *
     * The inline block intentionally bypasses `esc_html` / `esc_js` — the
     * content must remain executable JavaScript.  All security validation
     * (type, UTF-8, length, control characters, PHP tags, dangerous HTML) is
     * enforced at write-time inside `sanitize_script_for()`.
     *
     * @since  1.2.0
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return void
     */
    private function inject_scripts_for( $location ) {
        if ( ! $this->check_load_conditions( $location ) ) {
            return;
        }

        $script_key  = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_SCRIPT : SCRIPTOMATIC_HEAD_SCRIPT;
        $linked_key  = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_LINKED : SCRIPTOMATIC_HEAD_LINKED;

        $script_content = $this->get_front_end_option( $script_key, '' );
        $linked_raw     = $this->get_front_end_option( $linked_key, '[]' );
        $linked_urls    = json_decode( $linked_raw, true );
        if ( ! is_array( $linked_urls ) ) {
            $linked_urls = array();
        }

        $has_inline = ! empty( trim( $script_content ) );
        $has_linked = ! empty( $linked_urls );

        if ( ! $has_inline && ! $has_linked ) {
            return;
        }

        $label = ( 'footer' === $location ) ? 'footer' : 'head';
        echo "\n<!-- Scriptomatic v" . esc_attr( SCRIPTOMATIC_VERSION ) . " ({$label}) -->\n";

        foreach ( $linked_urls as $url ) {
            echo '<script src="' . esc_url( $url ) . '"></script>' . "\n";
        }

        if ( $has_inline ) {
            echo '<script>' . "\n";
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentionally
            // unescaped: content must execute as JavaScript.  Validated at write-time.
            echo $script_content . "\n";
            echo '</script>' . "\n";
        }

        echo "<!-- /Scriptomatic ({$label}) -->\n";
    }

}
