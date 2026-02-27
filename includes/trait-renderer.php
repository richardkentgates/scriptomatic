<?php
/**
 * Trait: Settings-field and section renderers for Scriptomatic.
 *
 * All WordPress Settings API callback methods for rendering:
 * - Inline-script textarea fields.
 * - Linked-URL chicklet managers.
 * - Load Conditions UI (type selector + sub-panels).
 * - Advanced Settings fields (history limit, keep-data toggle).
 *
 * Also contains `check_load_conditions()`, which evaluates the stored
 * condition JSON on every front-end page load.
 *
 * @package  Scriptomatic
 * @since    1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings-field renderers, section descriptions, and load-condition logic.
 */
trait Scriptomatic_Renderer {

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
        esc_html_e( 'Add custom JavaScript to inject into the <head> of your site. Use Load Conditions below to control which pages receive this script.', 'scriptomatic' );
        echo '</p>';
    }

    /**
     * Output the head-script <textarea> field.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_head_script_field() {
        $this->render_script_field_for( 'head' );
    }

    /**
     * Output the description for the Head External URLs section.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_head_links_section() {
        echo '<p>';
        esc_html_e( 'Add external JavaScript URLs to inject as <script src="..."> tags in <head>, before the inline block. Each URL has its own independent load conditions.', 'scriptomatic' );
        echo '</p>';
    }

    /**
     * Output the head linked-scripts chicklet manager.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_head_linked_field() {
        $this->render_linked_field_for( 'head' );
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
        esc_html_e( 'Add custom JavaScript to inject before the closing </body> tag. Use Load Conditions below to control which pages receive this script.', 'scriptomatic' );
        echo '</p>';
    }

    /**
     * Output the footer-script <textarea> field.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_footer_script_field() {
        $this->render_script_field_for( 'footer' );
    }

    /**
     * Output the description for the Footer External URLs section.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_footer_links_section() {
        echo '<p>';
        esc_html_e( 'Add external JavaScript URLs to inject as <script src="..."> tags just before </body>, before the inline block. Each URL has its own independent load conditions.', 'scriptomatic' );
        echo '</p>';
    }

    /**
     * Output the footer linked-scripts chicklet manager.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_footer_linked_field() {
        $this->render_linked_field_for( 'footer' );
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
    private function render_script_field_for( $location ) {
        $option_key     = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_SCRIPT : SCRIPTOMATIC_HEAD_SCRIPT;
        $script_content = is_network_admin()
            ? get_site_option( $option_key, '' )
            : get_option( $option_key, '' );
        $char_count     = strlen( $script_content );
        $max_length     = SCRIPTOMATIC_MAX_SCRIPT_LENGTH;
        $textarea_id    = 'scriptomatic-' . $location . '-script';
        $counter_id     = 'scriptomatic-' . $location . '-char-count';
        ?>
        <textarea
            id="<?php echo esc_attr( $textarea_id ); ?>"
            name="<?php echo esc_attr( $option_key ); ?>"
            rows="20"
            cols="100"
            class="large-text code"
            placeholder="<?php esc_attr_e( 'Enter your JavaScript code here (without <script> tags)', 'scriptomatic' ); ?>"
            aria-describedby="<?php echo esc_attr( $location ); ?>-script-desc <?php echo esc_attr( $location ); ?>-char-count"
        ><?php echo esc_textarea( $script_content ); ?></textarea>

        <p id="<?php echo esc_attr( $location ); ?>-char-count" class="description">
            <?php
            printf(
                esc_html__( 'Character count: %s / %s', 'scriptomatic' ),
                '<span id="' . esc_attr( $counter_id ) . '">' . number_format( $char_count ) . '</span>',
                number_format( $max_length )
            );
            ?>
        </p>
        <p id="<?php echo esc_attr( $location ); ?>-script-desc" class="description">
            <strong><?php esc_html_e( 'Important:', 'scriptomatic' ); ?></strong>
            <?php esc_html_e( 'Enter only JavaScript code. Do not include <script> tags — they are added automatically.', 'scriptomatic' ); ?>
        </p>
        <div class="scriptomatic-security-notice">
            <h4><span class="dashicons dashicons-shield"></span>
            <?php esc_html_e( 'Security Notice', 'scriptomatic' ); ?></h4>
            <ul style="margin:0;padding-left:20px;">
                <li><?php esc_html_e( 'Only administrators can modify this content.', 'scriptomatic' ); ?></li>
                <li><?php esc_html_e( 'All changes are logged for security auditing.', 'scriptomatic' ); ?></li>
                <li><?php esc_html_e( 'Always verify code from trusted sources before adding it here.', 'scriptomatic' ); ?></li>
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
    private function render_linked_field_for( $location ) {
        $option_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_LINKED : SCRIPTOMATIC_HEAD_LINKED;
        $raw        = is_network_admin()
            ? get_site_option( $option_key, '[]' )
            : get_option( $option_key, '[]' );
        $entries    = json_decode( $raw, true );
        if ( ! is_array( $entries ) ) {
            $entries = array();
        }

        // Migrate any legacy plain-URL strings to the {url, conditions} structure.
        $migrated = array();
        foreach ( $entries as $e ) {
            if ( is_string( $e ) && '' !== trim( $e ) ) {
                $migrated[] = array( 'url' => $e, 'conditions' => array( 'type' => 'all', 'values' => array() ) );
            } elseif ( is_array( $e ) && ! empty( $e['url'] ) ) {
                if ( ! isset( $e['conditions'] ) || ! is_array( $e['conditions'] ) ) {
                    $e['conditions'] = array( 'type' => 'all', 'values' => array() );
                }
                $migrated[] = $e;
            }
        }
        $entries = $migrated;

        $prefix     = 'scriptomatic-' . $location;
        $list_id    = $prefix . '-url-entries';
        $add_input  = $prefix . '-new-url';
        $add_btn    = $prefix . '-add-url';
        $hidden_id  = $prefix . '-linked-scripts-input';
        $err_id     = $prefix . '-url-error';
        $post_types = get_post_types( array( 'public' => true ), 'objects' );
        ?>
        <div id="<?php echo esc_attr( $prefix ); ?>-url-manager" class="sm-url-manager" data-location="<?php echo esc_attr( $location ); ?>">
            <div id="<?php echo esc_attr( $list_id ); ?>" class="sm-url-entry-list">
                <?php foreach ( $entries as $idx => $entry ) :
                    $url        = isset( $entry['url'] )        ? (string) $entry['url'] : '';
                    $conditions = ( isset( $entry['conditions'] ) && is_array( $entry['conditions'] ) )
                                  ? $entry['conditions']
                                  : array( 'type' => 'all', 'values' => array() );
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper
                    echo $this->render_url_entry_html( $location, $idx, $url, $conditions, $post_types );
                endforeach; ?>
            </div>

            <div style="display:flex;gap:8px;margin-top:12px;align-items:center;max-width:700px;">
                <input type="url" id="<?php echo esc_attr( $add_input ); ?>" class="regular-text"
                    placeholder="https://cdn.example.com/script.js"
                    aria-label="<?php esc_attr_e( 'External script URL', 'scriptomatic' ); ?>" style="flex:1;">
                <button type="button" id="<?php echo esc_attr( $add_btn ); ?>" class="button button-secondary">
                    <?php esc_html_e( 'Add URL', 'scriptomatic' ); ?>
                </button>
            </div>

            <p id="<?php echo esc_attr( $err_id ); ?>" class="scriptomatic-url-error" style="display:none;margin-top:4px;"></p>

            <input type="hidden"
                id="<?php echo esc_attr( $hidden_id ); ?>"
                name="<?php echo esc_attr( $option_key ); ?>"
                value="<?php echo esc_attr( wp_json_encode( $entries ) ); ?>">

            <p class="description" style="margin-top:8px;max-width:700px;">
                <?php esc_html_e( 'Only HTTP and HTTPS URLs are accepted. Scripts load in the order listed. Each URL has its own independent load conditions.', 'scriptomatic' ); ?>
            </p>

            <template id="<?php echo esc_attr( $prefix ); ?>-url-entry-template">
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper
                echo $this->render_url_entry_html(
                    $location,
                    '__IDX__',
                    '',
                    array( 'type' => 'all', 'values' => array() ),
                    $post_types,
                    true
                );
                ?>
            </template>

        </div><!-- .sm-url-manager -->
        <?php
    }

    /**
     * Build the HTML for a single URL entry card (URL label + per-entry conditions).
     *
     * Used by {@see render_linked_field_for()} for both server-rendered entries and
     * the JS clone template.  When `$is_template` is true, the placeholder string
     * `__IDX__` is used in all element IDs; JS replaces it with the real entry
     * index on clone.
     *
     * @since  1.6.0
     * @access private
     * @param  string     $location    `'head'` or `'footer'`.
     * @param  int|string $idx         Entry index, or `'__IDX__'` for the JS template.
     * @param  string     $url         URL for this entry (empty string for the template).
     * @param  array      $conditions  Decoded `{type, values}` conditions array.
     * @param  object[]   $post_types  Public post-type objects from get_post_types().
     * @param  bool       $is_template When true renders an inert template for JS cloning.
     * @return string HTML string.
     */
    private function render_url_entry_html( $location, $idx, $url, array $conditions, $post_types, $is_template = false ) {
        $pfx    = 'sm-url-' . $location . '-cond-' . $idx;
        $eid    = 'sm-url-' . $location . '-entry-' . $idx;
        $type   = ( isset( $conditions['type'] )   && '' !== $conditions['type'] )           ? $conditions['type']   : 'all';
        $values = ( isset( $conditions['values'] ) && is_array( $conditions['values'] ) ) ? $conditions['values'] : array();

        $condition_labels = array(
            'all'          => __( 'All pages (default)', 'scriptomatic' ),
            'front_page'   => __( 'Front page only', 'scriptomatic' ),
            'singular'     => __( 'Any single post or page', 'scriptomatic' ),
            'post_type'    => __( 'Specific post types', 'scriptomatic' ),
            'page_id'      => __( 'Specific pages / posts by ID', 'scriptomatic' ),
            'url_contains' => __( 'URL contains (any match)', 'scriptomatic' ),
            'logged_in'    => __( 'Logged-in users only', 'scriptomatic' ),
            'logged_out'   => __( 'Logged-out visitors only', 'scriptomatic' ),
        );

        ob_start();
        ?>
        <div class="sm-url-entry" id="<?php echo esc_attr( $eid ); ?>" data-index="<?php echo esc_attr( (string) $idx ); ?>" data-url="<?php echo $is_template ? '' : esc_attr( $url ); ?>">
            <div class="sm-url-entry__row">
                <span class="sm-url-entry__label" title="<?php echo $is_template ? '' : esc_attr( $url ); ?>"><?php echo $is_template ? '' : esc_html( $url ); ?></span>
                <button type="button" class="sm-url-entry__remove" aria-label="<?php esc_attr_e( 'Remove URL', 'scriptomatic' ); ?>">&times; <?php esc_html_e( 'Remove', 'scriptomatic' ); ?></button>
            </div>
            <div class="sm-url-entry__conditions">
                <span class="sm-url-entry__cond-label"><?php esc_html_e( 'Load conditions:', 'scriptomatic' ); ?></span>
                <div class="scriptomatic-conditions-wrap sm-url-conditions-wrap"
                     data-location="<?php echo esc_attr( $location ); ?>"
                     data-prefix="<?php echo esc_attr( $pfx ); ?>"
                     data-entry-index="<?php echo esc_attr( (string) $idx ); ?>">

                    <select id="<?php echo esc_attr( $pfx ); ?>-type"
                            class="scriptomatic-condition-type"
                            style="min-width:240px;"
                            aria-label="<?php esc_attr_e( 'Load condition', 'scriptomatic' ); ?>">
                        <?php foreach ( $condition_labels as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>"<?php echo ( ! $is_template && $type === $val ) ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <?php /* --- Panel: post_type --- */ ?>
                    <div class="sm-cond-panel" data-panel="post_type"<?php echo ( $is_template || 'post_type' !== $type ) ? ' hidden' : ''; ?>>
                        <fieldset class="sm-cond-fieldset">
                            <legend><?php esc_html_e( 'Load on these post types:', 'scriptomatic' ); ?></legend>
                            <div class="sm-pt-grid">
                            <?php foreach ( $post_types as $pt ) :
                                $chk = ! $is_template && in_array( $pt->name, $values, true ); ?>
                                <label class="sm-pt-label">
                                    <input type="checkbox" class="sm-pt-checkbox"
                                        data-prefix="<?php echo esc_attr( $pfx ); ?>"
                                        value="<?php echo esc_attr( $pt->name ); ?>"
                                        <?php if ( ! $is_template ) { checked( $chk ); } ?>>
                                    <span><strong><?php echo esc_html( $pt->labels->singular_name ); ?></strong>
                                    <code><?php echo esc_html( $pt->name ); ?></code></span>
                                </label>
                            <?php endforeach; ?>
                            </div>
                        </fieldset>
                    </div>

                    <?php /* --- Panel: page_id --- */ ?>
                    <div class="sm-cond-panel" data-panel="page_id"<?php echo ( $is_template || 'page_id' !== $type ) ? ' hidden' : ''; ?>>
                        <div class="sm-cond-inner">
                            <p class="description"><?php esc_html_e( 'Add the numeric ID of each post, page, or custom post entry. Find IDs in the URL bar when editing (post=123).', 'scriptomatic' ); ?></p>
                            <div id="<?php echo esc_attr( $pfx ); ?>-id-chicklets" class="scriptomatic-chicklet-list scriptomatic-chicklet-list--alt">
                                <?php if ( ! $is_template && 'page_id' === $type ) :
                                    foreach ( $values as $id ) :
                                        $id  = absint( $id );
                                        if ( ! $id ) { continue; }
                                        $ttl = get_the_title( $id );
                                        $lbl = $ttl ? $id . ' — ' . $ttl : (string) $id;
                                ?>
                                <span class="scriptomatic-chicklet" data-val="<?php echo esc_attr( $id ); ?>">
                                    <span class="chicklet-label" title="<?php echo esc_attr( $lbl ); ?>"><?php echo esc_html( $lbl ); ?></span>
                                    <button type="button" class="scriptomatic-remove-url" aria-label="<?php esc_attr_e( 'Remove ID', 'scriptomatic' ); ?>">&times;</button>
                                </span>
                                <?php endforeach; endif; ?>
                            </div>
                            <div class="sm-cond-add-row">
                                <input type="number" id="<?php echo esc_attr( $pfx ); ?>-id-new" class="small-text" min="1" step="1"
                                    placeholder="<?php esc_attr_e( 'ID', 'scriptomatic' ); ?>"
                                    aria-label="<?php esc_attr_e( 'Post or page ID to add', 'scriptomatic' ); ?>">
                                <button type="button" id="<?php echo esc_attr( $pfx ); ?>-id-add" class="button button-secondary"><?php esc_html_e( 'Add ID', 'scriptomatic' ); ?></button>
                            </div>
                            <p id="<?php echo esc_attr( $pfx ); ?>-id-error" class="scriptomatic-url-error" style="display:none;"></p>
                        </div>
                    </div>

                    <?php /* --- Panel: url_contains --- */ ?>
                    <div class="sm-cond-panel" data-panel="url_contains"<?php echo ( $is_template || 'url_contains' !== $type ) ? ' hidden' : ''; ?>>
                        <div class="sm-cond-inner">
                            <p class="description"><?php esc_html_e( 'Script loads when the request URL contains any of the listed strings. Partial paths work — e.g. /blog/ or /checkout.', 'scriptomatic' ); ?></p>
                            <div id="<?php echo esc_attr( $pfx ); ?>-url-chicklets" class="scriptomatic-chicklet-list scriptomatic-chicklet-list--alt">
                                <?php if ( ! $is_template && 'url_contains' === $type ) :
                                    foreach ( $values as $pattern ) :
                                        $pattern = sanitize_text_field( (string) $pattern );
                                        if ( '' === $pattern ) { continue; }
                                ?>
                                <span class="scriptomatic-chicklet" data-val="<?php echo esc_attr( $pattern ); ?>">
                                    <span class="chicklet-label" title="<?php echo esc_attr( $pattern ); ?>"><?php echo esc_html( $pattern ); ?></span>
                                    <button type="button" class="scriptomatic-remove-url" aria-label="<?php esc_attr_e( 'Remove pattern', 'scriptomatic' ); ?>">&times;</button>
                                </span>
                                <?php endforeach; endif; ?>
                            </div>
                            <div class="sm-cond-add-row">
                                <input type="text" id="<?php echo esc_attr( $pfx ); ?>-url-new" class="regular-text"
                                    placeholder="<?php esc_attr_e( '/my-page or /category/name', 'scriptomatic' ); ?>"
                                    aria-label="<?php esc_attr_e( 'URL pattern to add', 'scriptomatic' ); ?>">
                                <button type="button" id="<?php echo esc_attr( $pfx ); ?>-url-add" class="button button-secondary"><?php esc_html_e( 'Add Pattern', 'scriptomatic' ); ?></button>
                            </div>
                            <p id="<?php echo esc_attr( $pfx ); ?>-url-error" class="scriptomatic-url-error" style="display:none;"></p>
                        </div>
                    </div>

                    <input type="hidden"
                        id="<?php echo esc_attr( $pfx ); ?>-json"
                        data-entry-cond-json="true"
                        value="<?php echo $is_template ? '' : esc_attr( wp_json_encode( array( 'type' => $type, 'values' => $values ) ) ); ?>">

                </div><!-- .sm-url-conditions-wrap -->
            </div><!-- .sm-url-entry__conditions -->
        </div><!-- .sm-url-entry -->
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // LOAD-CONDITION EVALUATOR
    // =========================================================================

    /**
     * Evaluate a decoded conditions object against the current page request.
     *
     * Extracted from {@see check_load_conditions()} so the same logic can be
     * reused for per-URL conditions in {@see inject_scripts_for()}.
     *
     * @since  1.6.0
     * @access private
     * @param  array $conditions Decoded `{type, values}` conditions array.
     * @return bool  True when the script should be output, false otherwise.
     */
    private function evaluate_conditions_object( array $conditions ) {
        $type   = isset( $conditions['type'] ) ? $conditions['type'] : 'all';
        $values = ( isset( $conditions['values'] ) && is_array( $conditions['values'] ) ) ? $conditions['values'] : array();

        if ( empty( $type ) || 'all' === $type ) {
            return true;
        }

        switch ( $type ) {
            case 'front_page':
                return is_front_page();

            case 'singular':
                return is_singular();

            case 'post_type':
                return ! empty( $values ) && is_singular( $values );

            case 'page_id':
                $ids = array_map( 'intval', $values );
                return in_array( (int) get_queried_object_id(), $ids, true );

            case 'url_contains':
                if ( empty( $values ) ) {
                    return false;
                }
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- substring comparison only, not output.
                $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
                foreach ( $values as $pattern ) {
                    if ( '' !== $pattern && false !== strpos( $uri, $pattern ) ) {
                        return true;
                    }
                }
                return false;

            case 'logged_in':
                return is_user_logged_in();

            case 'logged_out':
                return ! is_user_logged_in();

            default:
                return true;
        }
    }

    /**
     * Evaluate the stored load condition for the inline script at a given location.
     *
     * Reads the stored `SCRIPTOMATIC_{HEAD|FOOTER}_CONDITIONS` option and delegates
     * to {@see evaluate_conditions_object()}.
     *
     * @since  1.3.0
     * @since  1.6.0 Delegates to evaluate_conditions_object(); applies to inline
     *               script only (external URLs have their own per-entry conditions).
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return bool
     */
    private function check_load_conditions( $location ) {
        $option_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_CONDITIONS : SCRIPTOMATIC_HEAD_CONDITIONS;
        $raw        = $this->get_front_end_option( $option_key, '' );
        $conditions = json_decode( $raw, true );

        if ( ! is_array( $conditions ) ) {
            return true;
        }

        return $this->evaluate_conditions_object( $conditions );
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
        esc_html_e( 'Control which pages the inline script textarea above is injected on. External script URL conditions are set per-URL in the External Script URLs section.', 'scriptomatic' );
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
        esc_html_e( 'Control which pages the inline script textarea above is injected on. External script URL conditions are set per-URL in the External Script URLs section.', 'scriptomatic' );
        echo '</p>';
    }

    /**
     * Output the Load Conditions field for the head location.
     *
     * @since  1.3.0
     * @return void
     */
    public function render_head_conditions_field() {
        $this->render_conditions_field_for( 'head' );
    }

    /**
     * Output the Load Conditions field for the footer location.
     *
     * @since  1.3.0
     * @return void
     */
    public function render_footer_conditions_field() {
        $this->render_conditions_field_for( 'footer' );
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
    private function render_conditions_field_for( $location ) {
        $option_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_CONDITIONS : SCRIPTOMATIC_HEAD_CONDITIONS;
        $raw        = is_network_admin()
            ? get_site_option( $option_key, '' )
            : get_option( $option_key, '' );
        $conditions = json_decode( $raw, true );
        $type       = ( is_array( $conditions ) && ! empty( $conditions['type'] ) ) ? $conditions['type'] : 'all';
        $values     = ( is_array( $conditions ) && isset( $conditions['values'] ) && is_array( $conditions['values'] ) ) ? $conditions['values'] : array();
        $pfx        = 'scriptomatic-' . $location . '-cond';
        $post_types = get_post_types( array( 'public' => true ), 'objects' );

        $condition_labels = array(
            'all'          => __( 'All pages (default)', 'scriptomatic' ),
            'front_page'   => __( 'Front page only', 'scriptomatic' ),
            'singular'     => __( 'Any single post or page', 'scriptomatic' ),
            'post_type'    => __( 'Specific post types', 'scriptomatic' ),
            'page_id'      => __( 'Specific pages / posts by ID', 'scriptomatic' ),
            'url_contains' => __( 'URL contains (any match)', 'scriptomatic' ),
            'logged_in'    => __( 'Logged-in users only', 'scriptomatic' ),
            'logged_out'   => __( 'Logged-out visitors only', 'scriptomatic' ),
        );
        ?>
        <div class="scriptomatic-conditions-wrap" data-location="<?php echo esc_attr( $location ); ?>" data-prefix="<?php echo esc_attr( $pfx ); ?>">

            <select
                id="<?php echo esc_attr( $pfx ); ?>-type"
                class="scriptomatic-condition-type"
                style="min-width:280px;"
                aria-label="<?php esc_attr_e( 'Load condition', 'scriptomatic' ); ?>"
            >
                <?php foreach ( $condition_labels as $val => $label ) : ?>
                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $type, $val ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>

            <?php /* --- Panel: post_type --- */ ?>
            <div class="sm-cond-panel" data-panel="post_type" <?php echo 'post_type' !== $type ? 'hidden' : ''; ?>>
                <fieldset class="sm-cond-fieldset">
                    <legend><?php esc_html_e( 'Load on these post types:', 'scriptomatic' ); ?></legend>
                    <div class="sm-pt-grid">
                    <?php foreach ( $post_types as $pt ) :
                        $checked = in_array( $pt->name, $values, true ); ?>
                        <label class="sm-pt-label">
                            <input type="checkbox" class="sm-pt-checkbox"
                                data-prefix="<?php echo esc_attr( $pfx ); ?>"
                                value="<?php echo esc_attr( $pt->name ); ?>"
                                <?php checked( $checked ); ?>
                            >
                            <span>
                                <strong><?php echo esc_html( $pt->labels->singular_name ); ?></strong>
                                <code><?php echo esc_html( $pt->name ); ?></code>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    </div>
                </fieldset>
            </div>

            <?php /* --- Panel: page_id --- */ ?>
            <div class="sm-cond-panel" data-panel="page_id" <?php echo 'page_id' !== $type ? 'hidden' : ''; ?>>
                <div class="sm-cond-inner">
                    <p class="description"><?php esc_html_e( 'Add the numeric ID of each post, page, or custom post entry. Find IDs in the URL bar when editing (post=123).', 'scriptomatic' ); ?></p>
                    <div id="<?php echo esc_attr( $pfx ); ?>-id-chicklets" class="scriptomatic-chicklet-list scriptomatic-chicklet-list--alt" aria-label="<?php esc_attr_e( 'Added page IDs', 'scriptomatic' ); ?>">
                        <?php foreach ( $values as $id ) :
                            $id    = absint( $id );
                            if ( ! $id ) continue;
                            $title = get_the_title( $id );
                            $label = $title ? $id . ' — ' . $title : (string) $id;
                        ?>
                        <span class="scriptomatic-chicklet" data-val="<?php echo esc_attr( $id ); ?>">
                            <span class="chicklet-label" title="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $label ); ?></span>
                            <button type="button" class="scriptomatic-remove-url" aria-label="<?php esc_attr_e( 'Remove ID', 'scriptomatic' ); ?>">&times;</button>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <div class="sm-cond-add-row">
                        <input type="number" id="<?php echo esc_attr( $pfx ); ?>-id-new" class="small-text" min="1" step="1"
                            placeholder="<?php esc_attr_e( 'ID', 'scriptomatic' ); ?>"
                            aria-label="<?php esc_attr_e( 'Post or page ID to add', 'scriptomatic' ); ?>">
                        <button type="button" id="<?php echo esc_attr( $pfx ); ?>-id-add" class="button button-secondary"><?php esc_html_e( 'Add ID', 'scriptomatic' ); ?></button>
                    </div>
                    <p id="<?php echo esc_attr( $pfx ); ?>-id-error" class="scriptomatic-url-error" style="display:none;"></p>
                </div>
            </div>

            <?php /* --- Panel: url_contains --- */ ?>
            <div class="sm-cond-panel" data-panel="url_contains" <?php echo 'url_contains' !== $type ? 'hidden' : ''; ?>>
                <div class="sm-cond-inner">
                    <p class="description"><?php esc_html_e( 'Script loads when the request URL contains any of the listed strings. Partial paths work — e.g. /blog/ or /checkout.', 'scriptomatic' ); ?></p>
                    <div id="<?php echo esc_attr( $pfx ); ?>-url-chicklets" class="scriptomatic-chicklet-list scriptomatic-chicklet-list--alt" aria-label="<?php esc_attr_e( 'Added URL patterns', 'scriptomatic' ); ?>">
                        <?php foreach ( $values as $pattern ) :
                            $pattern = sanitize_text_field( (string) $pattern );
                            if ( '' === $pattern ) continue;
                        ?>
                        <span class="scriptomatic-chicklet" data-val="<?php echo esc_attr( $pattern ); ?>">
                            <span class="chicklet-label" title="<?php echo esc_attr( $pattern ); ?>"><?php echo esc_html( $pattern ); ?></span>
                            <button type="button" class="scriptomatic-remove-url" aria-label="<?php esc_attr_e( 'Remove pattern', 'scriptomatic' ); ?>">&times;</button>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <div class="sm-cond-add-row">
                        <input type="text" id="<?php echo esc_attr( $pfx ); ?>-url-new" class="regular-text"
                            placeholder="<?php esc_attr_e( '/my-page or /category/name', 'scriptomatic' ); ?>"
                            aria-label="<?php esc_attr_e( 'URL pattern to add', 'scriptomatic' ); ?>">
                        <button type="button" id="<?php echo esc_attr( $pfx ); ?>-url-add" class="button button-secondary"><?php esc_html_e( 'Add Pattern', 'scriptomatic' ); ?></button>
                    </div>
                    <p id="<?php echo esc_attr( $pfx ); ?>-url-error" class="scriptomatic-url-error" style="display:none;"></p>
                </div>
            </div>

            <input type="hidden"
                id="<?php echo esc_attr( $pfx ); ?>-json"
                name="<?php echo esc_attr( $option_key ); ?>"
                value="<?php echo esc_attr( wp_json_encode( array( 'type' => $type, 'values' => $values ) ) ); ?>"
            >
        </div><!-- .scriptomatic-conditions-wrap -->
        <?php
    }

    // =========================================================================
    // RENDER METHODS — ADVANCED SETTINGS
    // =========================================================================

    /**
     * Output the description for the Advanced Settings section.
     *
     * @since  1.1.0
     * @return void
     */
    public function render_advanced_section() {
        echo '<p>';
        esc_html_e( 'Configure history retention and data lifecycle behaviour for this plugin.', 'scriptomatic' );
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
            name="<?php echo esc_attr( SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION ); ?>[max_history]"
            value="<?php echo esc_attr( $max_history ); ?>"
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
                esc_html__( 'Maximum number of script revisions to retain (1\u2013100). Default: %d. Reducing this value will immediately trim the existing history.', 'scriptomatic' ),
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
        $keep     = ! empty( $settings['keep_data_on_uninstall'] );
        ?>
        <label for="scriptomatic_keep_data_on_uninstall">
            <input
                type="checkbox"
                id="scriptomatic_keep_data_on_uninstall"
                name="<?php echo esc_attr( SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION ); ?>[keep_data_on_uninstall]"
                value="1"
                <?php checked( $keep, true ); ?>
                aria-describedby="keep-data-description"
            >
            <?php esc_html_e( 'Preserve all plugin data when Scriptomatic is uninstalled.', 'scriptomatic' ); ?>
        </label>
        <p id="keep-data-description" class="description">
            <?php esc_html_e( 'When unchecked (default), all scripts, history, linked URLs, and settings are permanently deleted on uninstall.', 'scriptomatic' ); ?>
        </p>
        <?php
    }
}
