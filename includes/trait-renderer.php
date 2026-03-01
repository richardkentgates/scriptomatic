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
        $option_key     = ( 'footer' === $location ) ? SCRIPTOMATIC_LOCATION_FOOTER : SCRIPTOMATIC_LOCATION_HEAD;
        $loc_data       = $this->get_location( $location );
        $script_content = $loc_data['script'];
        $char_count     = strlen( $script_content );
        $max_length     = SCRIPTOMATIC_MAX_SCRIPT_LENGTH;
        $textarea_id    = 'scriptomatic-' . $location . '-script';
        $counter_id     = 'scriptomatic-' . $location . '-char-count';
        ?>
        <div class="scriptomatic-code-editor-wrap">
        <textarea
            id="<?php echo esc_attr( $textarea_id ); ?>"
            name="<?php echo esc_attr( $option_key ); ?>[script]"
            rows="20"
            cols="100"
            class="large-text code"
            placeholder="<?php esc_attr_e( 'Enter your JavaScript code here (without <script> tags)', 'scriptomatic' ); ?>"
            aria-describedby="<?php echo esc_attr( $location ); ?>-script-desc <?php echo esc_attr( $location ); ?>-char-count"
        ><?php echo esc_textarea( $script_content ); ?></textarea>
        </div>

        <p id="<?php echo esc_attr( $location ); ?>-char-count" class="description">
            <?php
            printf(
                /* translators: 1: current character count as a formatted number, 2: maximum character limit as a formatted number */
                esc_html__( 'Character count: %1$s / %2$s', 'scriptomatic' ),
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
        $option_key = ( 'footer' === $location ) ? SCRIPTOMATIC_LOCATION_FOOTER : SCRIPTOMATIC_LOCATION_HEAD;
        $entries    = $this->get_location( $location )['urls'];
        if ( ! is_array( $entries ) ) {
            $entries = array();
        }

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
                                  : array( 'logic' => 'and', 'rules' => array() );
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
                name="<?php echo esc_attr( $option_key ); ?>[urls]"
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
                    array( 'logic' => 'and', 'rules' => array() ),
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
     * @param  array      $conditions  Decoded `{logic, rules}` conditions array.
     * @param  object[]   $post_types  Public post-type objects from get_post_types().
     * @param  bool       $is_template When true renders an inert template for JS cloning.
     * @return string HTML string.
     */
    private function render_url_entry_html( $location, $idx, $url, array $conditions, $post_types, $is_template = false ) {
        $pfx   = 'sm-url-' . $location . '-cond-' . $idx;
        $eid   = 'sm-url-' . $location . '-entry-' . $idx;
        $logic = ( isset( $conditions['logic'] ) && 'or' === $conditions['logic'] ) ? 'or' : 'and';
        $rules = $is_template ? array() : ( isset( $conditions['rules'] ) && is_array( $conditions['rules'] ) ? $conditions['rules'] : array() );

        ob_start();
        ?>
        <div class="sm-url-entry" id="<?php echo esc_attr( $eid ); ?>" data-index="<?php echo esc_attr( (string) $idx ); ?>" data-url="<?php echo $is_template ? '' : esc_attr( $url ); ?>">
            <div class="sm-url-entry__row">
                <span class="sm-url-entry__label" title="<?php echo $is_template ? '' : esc_attr( $url ); ?>"><?php echo $is_template ? '' : esc_html( $url ); ?></span>
                <button type="button" class="sm-url-entry__remove" aria-label="<?php esc_attr_e( 'Remove URL', 'scriptomatic' ); ?>">&times; <?php esc_html_e( 'Remove', 'scriptomatic' ); ?></button>
            </div>
            <div class="sm-url-entry__conditions">
                <?php if ( scriptomatic_is_premium() ) : ?>
                <span class="sm-url-entry__cond-label"><?php esc_html_e( 'Load conditions:', 'scriptomatic' ); ?></span>
                <?php $this->render_conditions_stack_ui( $pfx, $logic, $rules, $post_types, '', true ); ?>
                <?php else : ?>
                <?php /* Preserve any existing conditions data so it survives a free → Pro upgrade. */ ?>
                <input type="hidden"
                       id="<?php echo esc_attr( $pfx ); ?>-json"
                       data-entry-cond-json="true"
                       value="<?php echo esc_attr( wp_json_encode( array( 'logic' => $logic, 'rules' => $rules ) ) ); ?>">
                <span class="dashicons dashicons-lock" style="font-size:.875rem;width:.875rem;height:.875rem;vertical-align:middle;color:#999;margin-right:4px;"></span>
                <span style="color:#999;font-size:.8125rem;"><?php esc_html_e( 'Per-URL load conditions require Scriptomatic Pro.', 'scriptomatic' ); ?></span>
                <?php endif; ?>
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
     * @param  array $conditions Decoded `{logic, rules}` conditions array.
     * @return bool  True when the script should be output, false otherwise.
     */
    private function evaluate_conditions_object( array $conditions ) {
        $logic = ( isset( $conditions['logic'] ) && 'or' === $conditions['logic'] ) ? 'or' : 'and';
        $rules = ( isset( $conditions['rules'] ) && is_array( $conditions['rules'] ) ) ? $conditions['rules'] : array();

        if ( empty( $rules ) ) {
            return true; // No conditions — load everywhere.
        }

        foreach ( $rules as $rule ) {
            $result = $this->evaluate_single_rule( $rule );
            if ( 'or' === $logic && $result ) {
                return true;  // Any-match: at least one passed.
            }
            if ( 'and' === $logic && ! $result ) {
                return false; // All-match: one failed.
            }
        }

        return 'and' === $logic; // AND: all passed; OR: none matched.
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
        $conditions = $this->get_location( $location )['conditions'];
        if ( ! is_array( $conditions ) ) {
            return true;
        }
        return $this->evaluate_conditions_object( $conditions );
    }

    /**
     * Evaluate a single condition rule array against the current request.
     *
     * @since  1.0.0
     * @access private
     * @param  array $rule Decoded `{type, values}` rule array.
     * @return bool
     */
    private function evaluate_single_rule( array $rule ) {
        $type   = isset( $rule['type'] ) ? (string) $rule['type'] : '';
        $values = ( isset( $rule['values'] ) && is_array( $rule['values'] ) ) ? $rule['values'] : array();

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

            case 'by_date':
                if ( empty( $values ) || empty( $values[0] ) ) {
                    return false;
                }
                $today = current_time( 'Y-m-d' );
                $from  = sanitize_text_field( $values[0] );
                $to    = ! empty( $values[1] ) ? sanitize_text_field( $values[1] ) : $from;
                return $today >= $from && $today <= $to;

            case 'by_datetime':
                if ( empty( $values ) || empty( $values[0] ) ) {
                    return false;
                }
                $now  = current_time( 'Y-m-d\TH:i' );
                $from = sanitize_text_field( $values[0] );
                $to   = ! empty( $values[1] ) ? sanitize_text_field( $values[1] ) : $from;
                return $now >= $from && $now <= $to;

            case 'week_number':
                if ( empty( $values ) ) {
                    return false;
                }
                return in_array( (int) current_time( 'W' ), array_map( 'intval', $values ), true );

            case 'by_month':
                if ( empty( $values ) ) {
                    return false;
                }
                return in_array( (int) current_time( 'n' ), array_map( 'intval', $values ), true );

            default:
                return false;
        }
    }

    /**
     * Render HTML for a single condition rule card.
     *
     * @since  1.11.0
     * @access private
     * @param  string $cond_pfx   Conditions area prefix (e.g. 'scriptomatic-head-cond').
     * @param  mixed  $ridx       Rule index (integer or '__RIDX__' for JS template).
     * @param  array  $rule       Decoded `{type, values}` rule (empty array for template).
     * @param  array  $post_types Public post-type objects.
     * @param  bool   $is_template Whether this is a JS-cloning template (suppress values).
     * @return string
     */
    private function render_condition_rule_card_html( $cond_pfx, $ridx, array $rule, $post_types, $is_template = false ) {
        $rpfx   = $cond_pfx . '-rule-' . $ridx;
        $type   = ( ! $is_template && isset( $rule['type'] ) ) ? $rule['type'] : '';
        $values = ( ! $is_template && isset( $rule['values'] ) && is_array( $rule['values'] ) ) ? $rule['values'] : array();

        $condition_labels = array(
            'front_page'   => __( 'Front page only', 'scriptomatic' ),
            'singular'     => __( 'Any single post or page', 'scriptomatic' ),
            'post_type'    => __( 'Specific post types', 'scriptomatic' ),
            'page_id'      => __( 'Specific pages / posts by ID', 'scriptomatic' ),
            'url_contains' => __( 'URL contains (any match)', 'scriptomatic' ),
            'logged_in'    => __( 'Logged-in users only', 'scriptomatic' ),
            'logged_out'   => __( 'Logged-out visitors only', 'scriptomatic' ),
            'by_date'      => __( 'Date range', 'scriptomatic' ),
            'by_datetime'  => __( 'Date & time range', 'scriptomatic' ),
            'week_number'  => __( 'Specific week numbers', 'scriptomatic' ),
            'by_month'     => __( 'Specific months', 'scriptomatic' ),
        );

        ob_start();
        ?>
        <div class="sm-rule-card" data-ridx="<?php echo esc_attr( (string) $ridx ); ?>">
            <div class="sm-rule-card__header">
                <span class="sm-rule-card__label"><?php esc_html_e( 'Rule', 'scriptomatic' ); ?> <span class="sm-rule-num"></span></span>
                <button type="button" class="sm-rule-remove button-link" aria-label="<?php esc_attr_e( 'Remove rule', 'scriptomatic' ); ?>">&times; <?php esc_html_e( 'Remove', 'scriptomatic' ); ?></button>
            </div>
            <div class="sm-rule-card__body">
                <select id="<?php echo esc_attr( $rpfx ); ?>-type"
                        class="scriptomatic-condition-type"
                        style="min-width:240px;"
                        aria-label="<?php esc_attr_e( 'Condition type', 'scriptomatic' ); ?>">
                    <option value=""><?php esc_html_e( '— choose —', 'scriptomatic' ); ?></option>
                    <?php foreach ( $condition_labels as $val => $label ) : ?>
                    <option value="<?php echo esc_attr( $val ); ?>"<?php if ( ! $is_template ) { selected( $type, $val ); } ?>><?php echo esc_html( $label ); ?></option>
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
                                    data-prefix="<?php echo esc_attr( $rpfx ); ?>"
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
                    <div class="sm-chicklet-row" id="<?php echo esc_attr( $rpfx ); ?>-id-chicklets">
                        <?php if ( ! $is_template ) : foreach ( $values as $v ) : ?>
                        <span class="sm-chicklet" data-value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $v ); ?> <button type="button" class="sm-chicklet-remove" aria-label="<?php esc_attr_e( 'Remove', 'scriptomatic' ); ?>">&times;</button></span>
                        <?php endforeach; endif; ?>
                    </div>
                    <div class="sm-chicklet-add-row">
                        <input type="number" class="sm-chicklet-input" id="<?php echo esc_attr( $rpfx ); ?>-id-input" min="1" step="1" placeholder="<?php esc_attr_e( 'Page / post ID', 'scriptomatic' ); ?>">
                        <button type="button" class="sm-chicklet-add button-secondary" data-target="<?php echo esc_attr( $rpfx ); ?>-id-chicklets"><?php esc_html_e( '+ Add ID', 'scriptomatic' ); ?></button>
                    </div>
                </div>

                <?php /* --- Panel: url_contains --- */ ?>
                <div class="sm-cond-panel" data-panel="url_contains"<?php echo ( $is_template || 'url_contains' !== $type ) ? ' hidden' : ''; ?>>
                    <div class="sm-chicklet-row" id="<?php echo esc_attr( $rpfx ); ?>-url-chicklets">
                        <?php if ( ! $is_template ) : foreach ( $values as $v ) : ?>
                        <span class="sm-chicklet" data-value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $v ); ?> <button type="button" class="sm-chicklet-remove" aria-label="<?php esc_attr_e( 'Remove', 'scriptomatic' ); ?>">&times;</button></span>
                        <?php endforeach; endif; ?>
                    </div>
                    <div class="sm-chicklet-add-row">
                        <input type="text" class="sm-chicklet-input" id="<?php echo esc_attr( $rpfx ); ?>-url-input" placeholder="<?php esc_attr_e( '/path/fragment', 'scriptomatic' ); ?>">
                        <button type="button" class="sm-chicklet-add button-secondary" data-target="<?php echo esc_attr( $rpfx ); ?>-url-chicklets"><?php esc_html_e( '+ Add pattern', 'scriptomatic' ); ?></button>
                    </div>
                </div>

                <?php /* --- Panel: by_date --- */ ?>
                <div class="sm-cond-panel" data-panel="by_date"<?php echo ( $is_template || 'by_date' !== $type ) ? ' hidden' : ''; ?>>
                    <div class="sm-date-range-row">
                        <label>
                            <?php esc_html_e( 'From:', 'scriptomatic' ); ?>
                            <input type="date" class="sm-date-from" id="<?php echo esc_attr( $rpfx ); ?>-date-from"
                                   value="<?php echo ! $is_template && ! empty( $values[0] ) ? esc_attr( $values[0] ) : ''; ?>">
                        </label>
                        <label>
                            <?php esc_html_e( 'To:', 'scriptomatic' ); ?>
                            <input type="date" class="sm-date-to" id="<?php echo esc_attr( $rpfx ); ?>-date-to"
                                   value="<?php echo ! $is_template && ! empty( $values[1] ) ? esc_attr( $values[1] ) : ''; ?>">
                        </label>
                    </div>
                </div>

                <?php /* --- Panel: by_datetime --- */ ?>
                <div class="sm-cond-panel" data-panel="by_datetime"<?php echo ( $is_template || 'by_datetime' !== $type ) ? ' hidden' : ''; ?>>
                    <div class="sm-date-range-row">
                        <label>
                            <?php esc_html_e( 'From:', 'scriptomatic' ); ?>
                            <input type="datetime-local" class="sm-datetime-from" id="<?php echo esc_attr( $rpfx ); ?>-datetime-from"
                                   value="<?php echo ! $is_template && ! empty( $values[0] ) ? esc_attr( $values[0] ) : ''; ?>">
                        </label>
                        <label>
                            <?php esc_html_e( 'To:', 'scriptomatic' ); ?>
                            <input type="datetime-local" class="sm-datetime-to" id="<?php echo esc_attr( $rpfx ); ?>-datetime-to"
                                   value="<?php echo ! $is_template && ! empty( $values[1] ) ? esc_attr( $values[1] ) : ''; ?>">
                        </label>
                    </div>
                </div>

                <?php /* --- Panel: week_number --- */ ?>
                <div class="sm-cond-panel" data-panel="week_number"<?php echo ( $is_template || 'week_number' !== $type ) ? ' hidden' : ''; ?>>
                    <div class="sm-chicklet-row" id="<?php echo esc_attr( $rpfx ); ?>-week-chicklets">
                        <?php if ( ! $is_template ) : foreach ( $values as $v ) : ?>
                        <span class="sm-chicklet" data-value="<?php echo esc_attr( $v ); ?>">W<?php echo esc_html( $v ); ?> <button type="button" class="sm-chicklet-remove" aria-label="<?php esc_attr_e( 'Remove', 'scriptomatic' ); ?>">&times;</button></span>
                        <?php endforeach; endif; ?>
                    </div>
                    <div class="sm-chicklet-add-row">
                        <input type="number" class="sm-chicklet-input" id="<?php echo esc_attr( $rpfx ); ?>-week-input" min="1" max="53" step="1" placeholder="<?php esc_attr_e( 'Week number (1–53)', 'scriptomatic' ); ?>">
                        <button type="button" class="sm-chicklet-add button-secondary" data-target="<?php echo esc_attr( $rpfx ); ?>-week-chicklets"><?php esc_html_e( '+ Add week', 'scriptomatic' ); ?></button>
                    </div>
                </div>

                <?php /* --- Panel: by_month --- */ ?>
                <div class="sm-cond-panel" data-panel="by_month"<?php echo ( $is_template || 'by_month' !== $type ) ? ' hidden' : ''; ?>>
                    <fieldset class="sm-cond-fieldset">
                        <legend><?php esc_html_e( 'Load during these months:', 'scriptomatic' ); ?></legend>
                        <div class="sm-month-grid">
                        <?php for ( $m = 1; $m <= 12; $m++ ) :
                            $chk = ! $is_template && in_array( $m, $values, true ); ?>
                            <label class="sm-month-label">
                                <input type="checkbox" class="sm-month-checkbox"
                                    value="<?php echo esc_attr( $m ); ?>"
                                    <?php if ( ! $is_template ) { checked( $chk ); } ?>>
                                <?php echo esc_html( date_i18n( 'M', mktime( 0, 0, 0, $m, 1 ) ) ); ?>
                            </label>
                        <?php endfor; ?>
                        </div>
                    </fieldset>
                </div>
            </div><!-- .sm-rule-card__body -->
        </div><!-- .sm-rule-card -->
        <?php
        return ob_get_clean();
    }

    /**
     * Render a full stacked conditions UI (logic radio + rule cards + add button + template).
     *
     * @since  1.11.0
     * @access private
     * @param  string $pfx          HTML ID prefix for this conditions area.
     * @param  string $logic        'and' or 'or'.
     * @param  array  $rules        Array of `{type, values}` rule arrays.
     * @param  array  $post_types   Public post-type objects.
     * @param  string $input_name   Name attribute for the hidden JSON input (empty = skip).
     * @param  bool   $is_url_entry When true wraps with sm-url-conditions-wrap class and uses data-entry-cond-json attr.
     * @return void
     */
    private function render_conditions_stack_ui( $pfx, $logic, array $rules, $post_types, $input_name, $is_url_entry = false ) {
        $tpl_ridx   = '__RIDX__';
        $wrap_class = 'scriptomatic-conditions-wrap' . ( $is_url_entry ? ' sm-url-conditions-wrap' : '' );
        ?>
        <div class="<?php echo esc_attr( $wrap_class ); ?>"
             data-prefix="<?php echo esc_attr( $pfx ); ?>">

            <?php /* AND / OR logic row — hidden until there are 2+ rules */ ?>
            <div class="sm-logic-row"<?php echo count( $rules ) < 2 ? ' hidden' : ''; ?>>
                <span><?php esc_html_e( 'Match:', 'scriptomatic' ); ?></span>
                <label>
                    <input type="radio" class="sm-logic-radio" name="<?php echo esc_attr( $pfx ); ?>-logic"
                           value="and"<?php checked( $logic, 'and' ); ?>>
                    <?php esc_html_e( 'All rules (AND)', 'scriptomatic' ); ?>
                </label>
                <label>
                    <input type="radio" class="sm-logic-radio" name="<?php echo esc_attr( $pfx ); ?>-logic"
                           value="or"<?php checked( $logic, 'or' ); ?>>
                    <?php esc_html_e( 'Any rule (OR)', 'scriptomatic' ); ?>
                </label>
            </div>

            <div class="sm-rules-list" id="<?php echo esc_attr( $pfx ); ?>-rules">
                <?php foreach ( $rules as $ridx => $rule ) :
                    echo $this->render_condition_rule_card_html( $pfx, $ridx, $rule, $post_types, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                endforeach; ?>
            </div><!-- .sm-rules-list -->

            <?php if ( empty( $rules ) ) : ?>
            <p class="sm-no-conditions-msg"><?php esc_html_e( 'Load on all pages (no conditions set).', 'scriptomatic' ); ?></p>
            <?php endif; ?>

            <button type="button" class="sm-add-rule button button-secondary">
                <?php esc_html_e( '+ Add Condition', 'scriptomatic' ); ?>
            </button>

            <?php if ( $is_url_entry ) : ?>
            <input type="hidden"
                   id="<?php echo esc_attr( $pfx ); ?>-json"
                   data-entry-cond-json="true"
                   value="<?php echo esc_attr( wp_json_encode( array( 'logic' => $logic, 'rules' => $rules ) ) ); ?>">
            <?php elseif ( '' !== $input_name ) : ?>
            <input type="hidden"
                   id="<?php echo esc_attr( $pfx ); ?>-json"
                   name="<?php echo esc_attr( $input_name ); ?>"
                   value="<?php echo esc_attr( wp_json_encode( array( 'logic' => $logic, 'rules' => $rules ) ) ); ?>">
            <?php endif; ?>

            <template id="<?php echo esc_attr( $pfx ); ?>-rule-tpl">
                <?php echo $this->render_condition_rule_card_html( $pfx, $tpl_ridx, array(), $post_types, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </template>

        </div><!-- .scriptomatic-conditions-wrap -->
        <?php
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
        if ( ! scriptomatic_is_premium() ) {
            $this->render_pro_upgrade_notice(
                __( 'Load Conditions is a Pro feature', 'scriptomatic' ),
                __( 'Restrict when this script loads: by page, post type, URL pattern, login state, date, or date/time window.', 'scriptomatic' )
            );
            return;
        }
        $this->render_conditions_field_for( 'head' );
    }

    /**
     * Output the Load Conditions field for the footer location.
     *
     * @since  1.3.0
     * @return void
     */
    public function render_footer_conditions_field() {
        if ( ! scriptomatic_is_premium() ) {
            $this->render_pro_upgrade_notice(
                __( 'Load Conditions is a Pro feature', 'scriptomatic' ),
                __( 'Restrict when this script loads: by page, post type, URL pattern, login state, date, or date/time window.', 'scriptomatic' )
            );
            return;
        }
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
        $option_key = ( 'footer' === $location ) ? SCRIPTOMATIC_LOCATION_FOOTER : SCRIPTOMATIC_LOCATION_HEAD;
        $pfx        = 'scriptomatic-' . $location . '-cond';
        $post_types = get_post_types( array( 'public' => true ), 'objects' );
        $decoded    = $this->get_location( $location )['conditions'];

        if ( ! is_array( $decoded ) ) {
            $decoded = array( 'logic' => 'and', 'rules' => array() );
        }

        $logic = isset( $decoded['logic'] ) ? $decoded['logic'] : 'and';
        $rules = ( isset( $decoded['rules'] ) && is_array( $decoded['rules'] ) ) ? $decoded['rules'] : array();

        $this->render_conditions_stack_ui( $pfx, $logic, $rules, $post_types, $option_key . '[conditions]', false );
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
        // Fields are self-describing; no section description needed.
    }

    /**
     * Render the activity log limit number input field.
     *
     * @since  1.7.0
     * @since  1.9.0 Updated to describe the unified activity log.
     * @return void
     */
    public function render_max_log_field() {
        $settings  = $this->get_plugin_settings();
        $max_log   = (int) $settings['max_log_entries'];
        ?>
        <input
            type="number"
            id="scriptomatic_max_log_entries"
            name="<?php echo esc_attr( SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION ); ?>[max_log_entries]"
            value="<?php echo esc_attr( $max_log ); ?>"
            min="3"
            max="1000"
            step="1"
            class="small-text"
            aria-describedby="max-log-description"
        >
        <p id="max-log-description" class="description">
            <?php
            printf(
                /* translators: %d: default max activity log entries */
                esc_html__( 'How many activity log entries to keep per location (3–1000). Covers script saves, rollbacks, URL additions/removals, and JS file changes. Older entries are discarded automatically once the limit is reached. Default: %d.', 'scriptomatic' ),
                SCRIPTOMATIC_MAX_LOG_ENTRIES
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

    /**
     * Render the "API Allowed IPs" textarea field for the Advanced Settings section.
     *
     * Accepts one IPv4 address, IPv6 address, or IPv4 CIDR range per line.
     * Leave empty to allow REST API requests from any IP address.
     *
     * @since  2.7.0
     */
    public function render_api_allowed_ips_field() {
        $settings = $this->get_plugin_settings();
        $ips      = isset( $settings['api_allowed_ips'] ) ? $settings['api_allowed_ips'] : '';
        ?>
        <textarea
            id="scriptomatic_api_allowed_ips"
            name="<?php echo esc_attr( SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION ); ?>[api_allowed_ips]"
            rows="5"
            cols="35"
            class="code"
            aria-describedby="api-ips-description"
            placeholder="203.0.113.1&#10;198.51.100.0/24&#10;2001:db8::1"
        ><?php echo esc_textarea( $ips ); ?></textarea>
        <p id="api-ips-description" class="description">
            <?php esc_html_e( 'One IPv4 address, IPv6 address, or IPv4 CIDR range per line. Leave empty to allow REST API requests from any IP address. Does not affect the WordPress admin interface.', 'scriptomatic' ); ?>
        </p>
        <?php
    }

    // =========================================================================
    // FILE CONDITIONS WIDGET
    // =========================================================================

    /**
     * Render a fully self-contained Load Conditions picker for the JS File
     * edit form.
     *
     * Unlike render_conditions_field_for(), this method accepts the data
     * directly (no DB read) and writes to a custom hidden-input name so the
     * file save handler can read it from $_POST.
     *
     * @since  1.8.0
     * @param  string $pfx         Unique HTML ID prefix (e.g. 'sm-file-cond').
     * @param  array  $conditions  Decoded conditions array: `{logic: string, rules: array}`.
     * @return void
     */
    public function render_file_conditions_widget( $pfx, array $conditions ) {
        if ( ! isset( $conditions['logic'] ) ) {
            $conditions = array( 'logic' => 'and', 'rules' => array() );
        }

        $logic      = $conditions['logic'];
        $rules      = ( isset( $conditions['rules'] ) && is_array( $conditions['rules'] ) ) ? $conditions['rules'] : array();
        $post_types = get_post_types( array( 'public' => true ), 'objects' );

        $this->render_conditions_stack_ui( $pfx, $logic, $rules, $post_types, 'sm_file_conditions', false );
    }

    // =========================================================================
    // PRO UPGRADE NOTICE
    // =========================================================================

    /**
     * Render a styled upgrade-to-Pro notice box.
     *
     * Used by conditions fields and any other gated admin UI.
     *
     * @since  3.0.0
     * @param  string $feature_title Short feature name, e.g. 'Load Conditions is a Pro feature'.
     * @param  string $feature_desc  One-sentence description of what the feature does.
     * @return void
     */
    public function render_pro_upgrade_notice( $feature_title, $feature_desc ) {
        $fs          = function_exists( 'scriptomatic_fs' ) ? scriptomatic_fs() : null;
        $upgrade_url = ( $fs && method_exists( $fs, 'get_upgrade_url' ) ) ? esc_url( $fs->get_upgrade_url() ) : '#';
        ?>
        <div class="notice notice-info sm-pro-notice" style="padding:1.25rem 1.5rem;display:flex;gap:1rem;align-items:flex-start;max-width:800px;margin:8px 0;">
            <span class="dashicons dashicons-lock" style="font-size:1.75rem;width:1.75rem;height:1.75rem;flex-shrink:0;color:#2271b1;margin-top:2px;"></span>
            <div>
                <h3 style="margin:0 0 .4rem;font-size:1rem;"><?php echo esc_html( $feature_title ); ?></h3>
                <p style="margin:0 0 .875rem;color:#50575e;"><?php echo esc_html( $feature_desc ); ?></p>
                <a href="<?php echo $upgrade_url; ?>" class="button button-primary"><?php esc_html_e( 'Upgrade to Scriptomatic Pro', 'scriptomatic' ); ?></a>
                <a href="<?php echo $upgrade_url; ?>" style="margin-left:.75rem;color:#2271b1;"><?php esc_html_e( 'Start free 14-day trial', 'scriptomatic' ); ?></a>
            </div>
        </div>
        <?php
    }
}
