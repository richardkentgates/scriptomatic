<?php
/**
 * Trait: Admin page renderers for Scriptomatic.
 *
 * Covers all per-site and network-admin page rendering, the network save
 * handler, contextual help tabs, and plugins-page action links.
 *
 * @package  Scriptomatic
 * @since    1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin page builders, network save handler, help tabs, and action links.
 */
trait Scriptomatic_Pages {

    // =========================================================================
    // PAGE RENDERERS — PER-SITE ADMIN
    // =========================================================================

    /**
     * Shared page header for all Scriptomatic admin pages.
     *
     * Calls wp_die() with a 403 response if the current user lacks the
     * required capability.
     *
     * @since  1.2.0
     * @access private
     * @param  string $error_slug Settings-errors slug to display (or '' to skip).
     * @return void
     */
    private function render_page_header( $error_slug = '' ) {
        if ( ! current_user_can( $this->get_required_cap() ) ) {
            wp_die(
                esc_html__( 'You do not have sufficient permissions to access this page.', 'scriptomatic' ),
                esc_html__( 'Permission Denied', 'scriptomatic' ),
                array( 'response' => 403 )
            );
        }
        ?>
        <div class="wrap" id="scriptomatic-settings">
        <h1>
            <span class="dashicons dashicons-editor-code" style="font-size:32px;width:32px;height:32px;"></span>
            <?php echo esc_html( get_admin_page_title() ); ?>
        </h1>
        <p class="description" style="font-size:14px;margin-bottom:20px;">
            <?php esc_html_e( 'Version', 'scriptomatic' ); ?>: <?php echo esc_html( SCRIPTOMATIC_VERSION ); ?> |
            <?php esc_html_e( 'Author', 'scriptomatic' ); ?>: <a href="https://github.com/richardkentgates" target="_blank" rel="noopener noreferrer">Richard Kent Gates</a> |
            <a href="https://github.com/richardkentgates/scriptomatic" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'scriptomatic' ); ?></a>
        </p>
        <?php if ( $error_slug ) { settings_errors( $error_slug ); } ?>
        <?php
    }

    /**
     * Output the revision history panel for a given location.
     *
     * Renders nothing when the history array is empty.
     *
     * @since  1.2.0
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return void
     */
    private function render_history_panel( $location ) {
        $history = $this->get_history( $location );
        if ( empty( $history ) ) {
            return;
        }
        ?>
        <hr style="margin:30px 0;">
        <div class="scriptomatic-history-section">
            <h2>
                <span class="dashicons dashicons-backup" style="font-size:24px;width:24px;height:24px;margin-right:4px;vertical-align:middle;"></span>
                <?php
                printf(
                    /* translators: %s: 'Head' or 'Footer' */
                    esc_html__( '%s Script History', 'scriptomatic' ),
                    esc_html( ucfirst( $location ) )
                );
                ?>
            </h2>
            <p class="description">
                <?php
                printf(
                    /* translators: %d: number of stored revisions */
                    esc_html( _n(
                        'Showing %d saved revision. Click Restore to roll back to a previous version.',
                        'Showing %d saved revisions. Click Restore to roll back to a previous version.',
                        count( $history ),
                        'scriptomatic'
                    ) ),
                    count( $history )
                );
                ?>
            </p>
            <table class="widefat scriptomatic-history-table" style="max-width:900px;">
                <thead>
                    <tr>
                        <th style="width:40px;"><?php esc_html_e( '#', 'scriptomatic' ); ?></th>
                        <th><?php esc_html_e( 'Saved', 'scriptomatic' ); ?></th>
                        <th><?php esc_html_e( 'By', 'scriptomatic' ); ?></th>
                        <th style="width:100px;"><?php esc_html_e( 'Characters', 'scriptomatic' ); ?></th>
                        <th style="width:110px;"><?php esc_html_e( 'Action', 'scriptomatic' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $history as $index => $entry ) : ?>
                    <tr>
                        <td><?php echo esc_html( $index + 1 ); ?></td>
                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['timestamp'] ) ); ?></td>
                        <td><?php echo esc_html( $entry['user_login'] ); ?></td>
                        <td><?php echo esc_html( number_format( $entry['length'] ) ); ?></td>
                        <td>
                            <button
                                type="button"
                                class="button button-small scriptomatic-history-restore"
                                data-index="<?php echo esc_attr( $index ); ?>"
                                data-location="<?php echo esc_attr( $location ); ?>"
                                data-original-text="<?php esc_attr_e( 'Restore', 'scriptomatic' ); ?>"
                            >
                                <?php esc_html_e( 'Restore', 'scriptomatic' ); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render the Head Scripts admin page.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_head_page() {
        $this->render_page_header( 'scriptomatic_head_script' );
        ?>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'scriptomatic_head_group' );
            wp_nonce_field( SCRIPTOMATIC_HEAD_NONCE, 'scriptomatic_save_nonce' );
            do_settings_sections( 'scriptomatic_head_page' );
            submit_button( __( 'Save Head Scripts', 'scriptomatic' ), 'primary large' );
            ?>
        </form>
        <?php
        $this->render_history_panel( 'head' );
        echo '</div>'; // .wrap
    }

    /**
     * Render the Footer Scripts admin page.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_footer_page() {
        $this->render_page_header( 'scriptomatic_footer_script' );
        ?>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'scriptomatic_footer_group' );
            wp_nonce_field( SCRIPTOMATIC_FOOTER_NONCE, 'scriptomatic_footer_nonce' );
            do_settings_sections( 'scriptomatic_footer_page' );
            submit_button( __( 'Save Footer Scripts', 'scriptomatic' ), 'primary large' );
            ?>
        </form>
        <?php
        $this->render_history_panel( 'footer' );
        echo '</div>'; // .wrap
    }

    /**
     * Render the General Settings admin page.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_general_settings_page() {
        $this->render_page_header( 'scriptomatic_plugin_settings' );
        ?>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'scriptomatic_general_group' );
            wp_nonce_field( SCRIPTOMATIC_GENERAL_NONCE, 'scriptomatic_general_nonce' );
            do_settings_sections( 'scriptomatic_general_page' );
            submit_button( __( 'Save Settings', 'scriptomatic' ), 'primary large' );
            ?>
        </form>
        </div><!-- .wrap -->
        <?php
    }

    /**
     * Backward-compat alias — renders head page (was render_settings_page).
     *
     * @since  1.0.0
     * @return void
     */
    public function render_settings_page() {
        $this->render_head_page();
    }

    // =========================================================================
    // PAGE RENDERERS — NETWORK ADMIN
    // =========================================================================

    /**
     * Shared header for network-admin Scriptomatic pages.
     *
     * @since  1.2.0
     * @access private
     * @return void
     */
    private function render_network_page_header() {
        if ( ! current_user_can( $this->get_network_cap() ) ) {
            wp_die(
                esc_html__( 'You do not have sufficient permissions to access this page.', 'scriptomatic' ),
                esc_html__( 'Permission Denied', 'scriptomatic' ),
                array( 'response' => 403 )
            );
        }

        if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'scriptomatic' ); ?></p></div>
            <?php
        }
        if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Security check failed. Settings not saved.', 'scriptomatic' ); ?></p></div>
            <?php
        }
        ?>
        <div class="wrap" id="scriptomatic-settings">
        <h1>
            <span class="dashicons dashicons-editor-code" style="font-size:32px;width:32px;height:32px;"></span>
            <?php echo esc_html( get_admin_page_title() ); ?>
        </h1>
        <p class="description" style="font-size:14px;margin-bottom:20px;">
            <?php esc_html_e( 'Version', 'scriptomatic' ); ?>: <?php echo esc_html( SCRIPTOMATIC_VERSION ); ?> |
            <?php esc_html_e( 'Author', 'scriptomatic' ); ?>: <a href="https://github.com/richardkentgates" target="_blank" rel="noopener noreferrer">Richard Kent Gates</a> |
            <a href="https://github.com/richardkentgates/scriptomatic" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'scriptomatic' ); ?></a>
        </p>
        <?php
    }

    /**
     * Render the Network Admin — Head Scripts page.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_network_head_page() {
        $this->render_network_page_header();
        ?>
        <form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=scriptomatic_network_save' ) ); ?>">
            <?php
            wp_nonce_field( SCRIPTOMATIC_NETWORK_NONCE, 'scriptomatic_network_nonce' );
            echo '<input type="hidden" name="scriptomatic_network_location" value="head">';
            do_settings_sections( 'scriptomatic_head_page' );
            submit_button( __( 'Save Network Head Scripts', 'scriptomatic' ), 'primary large' );
            ?>
        </form>
        <?php $this->render_history_panel( 'head' ); ?>
        </div><!-- .wrap -->
        <?php
    }

    /**
     * Render the Network Admin — Footer Scripts page.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_network_footer_page() {
        $this->render_network_page_header();
        ?>
        <form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=scriptomatic_network_save' ) ); ?>">
            <?php
            wp_nonce_field( SCRIPTOMATIC_NETWORK_NONCE, 'scriptomatic_network_nonce' );
            echo '<input type="hidden" name="scriptomatic_network_location" value="footer">';
            do_settings_sections( 'scriptomatic_footer_page' );
            submit_button( __( 'Save Network Footer Scripts', 'scriptomatic' ), 'primary large' );
            ?>
        </form>
        <?php $this->render_history_panel( 'footer' ); ?>
        </div><!-- .wrap -->
        <?php
    }

    /**
     * Render the Network Admin — General Settings page.
     *
     * @since  1.2.0
     * @return void
     */
    public function render_network_general_page() {
        $this->render_network_page_header();
        ?>
        <form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=scriptomatic_network_save' ) ); ?>">
            <?php
            wp_nonce_field( SCRIPTOMATIC_NETWORK_NONCE, 'scriptomatic_network_nonce' );
            echo '<input type="hidden" name="scriptomatic_network_location" value="general">';
            do_settings_sections( 'scriptomatic_general_page' );
            submit_button( __( 'Save Network Settings', 'scriptomatic' ), 'primary large' );
            ?>
        </form>
        </div><!-- .wrap -->
        <?php
    }

    // =========================================================================
    // NETWORK ADMIN SAVE HANDLER
    // =========================================================================

    /**
     * Process the custom POST from all three network-admin forms.
     *
     * WordPress network admin does not route through `options.php`, so saves
     * are handled via a `network_admin_edit_` action hook.  The hidden field
     * `scriptomatic_network_location` distinguishes head / footer / general.
     *
     * Security gates: nonce verification + `manage_network_options` capability.
     *
     * @since  1.2.0
     * @return void  Redirects on completion (or terminates on error).
     */
    public function handle_network_settings_save() {
        if ( ! isset( $_POST['scriptomatic_network_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scriptomatic_network_nonce'] ) ), SCRIPTOMATIC_NETWORK_NONCE ) ) {
            wp_redirect( esc_url_raw( add_query_arg( 'error', '1', wp_get_referer() ) ) );
            exit;
        }

        if ( ! current_user_can( $this->get_network_cap() ) ) {
            wp_die( esc_html__( 'Permission denied.', 'scriptomatic' ), 403 );
        }

        $location = isset( $_POST['scriptomatic_network_location'] )
            ? sanitize_key( $_POST['scriptomatic_network_location'] )
            : '';

        if ( 'head' === $location || 'footer' === $location ) {
            $script_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_SCRIPT     : SCRIPTOMATIC_HEAD_SCRIPT;
            $linked_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_LINKED     : SCRIPTOMATIC_HEAD_LINKED;
            $cond_key   = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_CONDITIONS : SCRIPTOMATIC_HEAD_CONDITIONS;

            $raw_script = isset( $_POST[ $script_key ] ) ? (string) wp_unslash( $_POST[ $script_key ] ) : '';
            $raw_linked = isset( $_POST[ $linked_key ] ) ? (string) wp_unslash( $_POST[ $linked_key ] ) : '[]';
            $raw_cond   = isset( $_POST[ $cond_key ] )   ? (string) wp_unslash( $_POST[ $cond_key ] )   : '';

            $sanitised_script = $this->validate_inline_script( $raw_script, $location );
            $this->log_change( $sanitised_script, $script_key, $location );
            update_site_option( $script_key, $sanitised_script );
            update_site_option( $linked_key, $this->sanitize_linked_for( $raw_linked, $location ) );
            update_site_option( $cond_key,   $this->sanitize_conditions_for( $raw_cond, $location ) );

        } elseif ( 'general' === $location ) {
            $raw = isset( $_POST[ SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION ] )
                ? (array) wp_unslash( $_POST[ SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION ] )
                : array();
            update_site_option( SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION, $this->sanitize_plugin_settings( $raw ) );
        }

        wp_redirect( esc_url_raw( add_query_arg( 'updated', '1', wp_get_referer() ) ) );
        exit;
    }

    // =========================================================================
    // CONTEXTUAL HELP TABS
    // =========================================================================

    /**
     * Attach contextual help tabs to the Scriptomatic settings screen.
     *
     * Registers five tabs (Overview, Usage, Security, Best Practices,
     * Troubleshooting) and a sidebar with external resource links.
     *
     * @since  1.0.0
     * @return void
     */
    public function add_help_tab() {
        $screen = get_current_screen();

        $screen->add_help_tab( array(
            'id'      => 'scriptomatic_overview',
            'title'   => __( 'Overview', 'scriptomatic' ),
            'content' =>
                '<h3>' . __( 'Scriptomatic Overview', 'scriptomatic' ) . '</h3>' .
                '<p>' . __( 'Scriptomatic safely injects custom JavaScript into the <strong>head</strong> (before &lt;/head&gt;) and the <strong>footer</strong> (before &lt;/body&gt;) of your WordPress site. Use the <strong>Load Conditions</strong> setting on each page to control exactly which pages, post types, or user states receive the script.', 'scriptomatic' ) . '</p>' .
                '<p>' . __( 'Use the <strong>Head Scripts</strong> page for analytics tags, pixel codes, and scripts that must load early. Use the <strong>Footer Scripts</strong> page for scripts that should run after page content has loaded.', 'scriptomatic' ) . '</p>' .
                '<p>' . __( 'Each location has its own <strong>External Script URLs</strong> section for loading remote <code>&lt;script src&gt;</code> files, and a <strong>Revision History</strong> panel so you can restore any previous version in one click.', 'scriptomatic' ) . '</p>' .
                '<p>' . __( 'This plugin is designed with security and performance in mind, providing input validation, sanitisation, secondary nonce verification, per-user rate limiting, revision history, conditional loading, and audit logging.', 'scriptomatic' ) . '</p>',
        ) );

        $screen->add_help_tab( array(
            'id'      => 'scriptomatic_usage',
            'title'   => __( 'Usage', 'scriptomatic' ),
            'content' =>
                '<h3>' . __( 'How to Use', 'scriptomatic' ) . '</h3>' .
                '<ol>' .
                '<li><strong>' . __( 'Choose a location:', 'scriptomatic' ) . '</strong> ' . __( 'Use <em>Head Scripts</em> for early-loading code (analytics, pixels) or <em>Footer Scripts</em> for deferred code.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Add Your Code:', 'scriptomatic' ) . '</strong> ' . __( 'Paste your JavaScript code into the textarea. Do not include &lt;script&gt; tags — they are added automatically.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Add external URLs (optional):', 'scriptomatic' ) . '</strong> ' . __( 'Enter remote script URLs in the External Script URLs section. They load before the inline block.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Set Load Conditions (optional):', 'scriptomatic' ) . '</strong> ' . __( 'Use the Load Conditions drop-down to restrict injection to specific pages, post types, URL patterns, or user login state. Defaults to all pages.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Save Changes:', 'scriptomatic' ) . '</strong> ' . __( 'Click the Save button at the bottom of the page.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Verify:', 'scriptomatic' ) . '</strong> ' . __( 'View your page source to confirm the script is injected in the correct location.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Test:', 'scriptomatic' ) . '</strong> ' . __( 'Thoroughly test your site to ensure the script functions correctly.', 'scriptomatic' ) . '</li>' .
                '</ol>' .
                '<p><strong>' . __( 'Example:', 'scriptomatic' ) . '</strong></p>' .
                '<pre>console.log("Hello from Scriptomatic!");\n' .
                'var myCustomVar = "Hello World";</pre>',
        ) );

        $screen->add_help_tab( array(
            'id'      => 'scriptomatic_security',
            'title'   => __( 'Security', 'scriptomatic' ),
            'content' =>
                '<h3>' . __( 'Security Features', 'scriptomatic' ) . '</h3>' .
                '<ul>' .
                '<li><strong>' . __( 'Capability Check:', 'scriptomatic' ) . '</strong> ' . __( 'Only users with &ldquo;manage_options&rdquo; capability (administrators) can modify per-site scripts. Network admin pages require &ldquo;manage_network_options&rdquo;.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Dual Nonce Verification:', 'scriptomatic' ) . '</strong> ' . __( 'Each form carries both the WordPress Settings API nonce and a secondary location-specific nonce, verified on every save.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Rate Limiting:', 'scriptomatic' ) . '</strong> ' . __( 'A transient-based 10-second cooldown per user per location prevents rapid repeated saves.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Input Validation:', 'scriptomatic' ) . '</strong> ' . __( 'All input is validated: UTF-8 check, control-character rejection, 100 KB length cap, PHP-tag detection, and dangerous-HTML-tag warning (iframe, object, embed, link, style, meta).', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Sanitization:', 'scriptomatic' ) . '</strong> ' . __( '&lt;script&gt; tags are automatically stripped to prevent double-wrapping.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Audit Logging:', 'scriptomatic' ) . '</strong> ' . __( 'All saves and AJAX rollbacks are written to the WordPress error log with username, user ID, and timestamp.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Output Escaping:', 'scriptomatic' ) . '</strong> ' . __( 'Content is properly escaped when displayed in the admin interface.', 'scriptomatic' ) . '</li>' .
                '</ul>' .
                '<p class="description">' . __( 'Note: Always verify code from external sources before adding it to your site. Malicious JavaScript can compromise your website and user data.', 'scriptomatic' ) . '</p>',
        ) );

        $screen->add_help_tab( array(
            'id'      => 'scriptomatic_best_practices',
            'title'   => __( 'Best Practices', 'scriptomatic' ),
            'content' =>
                '<h3>' . __( 'Best Practices', 'scriptomatic' ) . '</h3>' .
                '<ul>' .
                '<li><strong>' . __( 'Test First:', 'scriptomatic' ) . '</strong> ' . __( 'Always test scripts in a staging environment before deploying to production.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Use Comments:', 'scriptomatic' ) . '</strong> ' . __( 'Add comments to your code to document what it does and where it came from.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Keep It Clean:', 'scriptomatic' ) . '</strong> ' . __( 'Remove unused or outdated scripts regularly.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Verify Sources:', 'scriptomatic' ) . '</strong> ' . __( 'Only use code from trusted sources. Review all third-party scripts.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Monitor Performance:', 'scriptomatic' ) . '</strong> ' . __( 'Heavy scripts can slow down your site. Use browser dev tools to monitor impact.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Backup:', 'scriptomatic' ) . '</strong> ' . __( 'Use the built-in <em>Revision History</em> panel to restore previous versions. Click <em>Restore</em> next to any entry to roll back instantly without losing subsequent revisions.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Async/Defer:', 'scriptomatic' ) . '</strong> ' . __( 'Consider using async or defer attributes for external scripts to improve page load times.', 'scriptomatic' ) . '</li>' .
                '</ul>',
        ) );

        $screen->add_help_tab( array(
            'id'      => 'scriptomatic_troubleshooting',
            'title'   => __( 'Troubleshooting', 'scriptomatic' ),
            'content' =>
                '<h3>' . __( 'Troubleshooting', 'scriptomatic' ) . '</h3>' .
                '<h4>' . __( 'Script not appearing:', 'scriptomatic' ) . '</h4>' .
                '<ul>' .
                '<li>' . __( 'Check that you clicked the Save button after entering your code.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'Clear your site cache and browser cache.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'View page source to verify the script tag is present in the expected location (head or footer).', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'Check the <strong>Load Conditions</strong> setting — if it is set to anything other than &ldquo;All pages&rdquo;, the script is intentionally suppressed on pages that do not match the condition.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'Check if another plugin or theme is preventing wp_head() or wp_footer() from running.', 'scriptomatic' ) . '</li>' .
                '</ul>' .
                '<h4>' . __( 'Script causing errors:', 'scriptomatic' ) . '</h4>' .
                '<ul>' .
                '<li>' . __( 'Check the browser console for JavaScript errors.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'Verify syntax errors in your JavaScript code.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'Ensure external resources are loading properly (check network tab).', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'Test with a simple console.log() first to verify injection is working.', 'scriptomatic' ) . '</li>' .
                '</ul>' .
                '<h4>' . __( 'Cannot save / changes ignored:', 'scriptomatic' ) . '</h4>' .
                '<ul>' .
                '<li>' . __( 'Verify you have administrator privileges.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'Check if script exceeds the 100 KB maximum length.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'Remove any HTML tags (only JavaScript is allowed).', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'The <strong>rate limiter</strong> enforces a 10-second cooldown per user per location. If you saved very recently, wait a moment and try again.', 'scriptomatic' ) . '</li>' .
                '</ul>' .
                '<h4>' . __( 'Restore a previous version:', 'scriptomatic' ) . '</h4>' .
                '<ul>' .
                '<li>' . __( 'Scroll to the <strong>Revision History</strong> panel at the bottom of the page.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'Click <strong>Restore</strong> next to the desired revision \u2014 the textarea updates instantly via AJAX.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'Click the Save button to persist the restored content.', 'scriptomatic' ) . '</li>' .
                '</ul>',
        ) );

        $screen->set_help_sidebar(
            '<p><strong>' . __( 'For more information:', 'scriptomatic' ) . '</strong></p>' .
            '<p><a href="https://github.com/richardkentgates/scriptomatic" target="_blank" rel="noopener noreferrer">' . __( 'Plugin Documentation', 'scriptomatic' ) . '</a></p>' .
            '<p><a href="https://github.com/richardkentgates/scriptomatic/issues" target="_blank" rel="noopener noreferrer">' . __( 'Report Issues', 'scriptomatic' ) . '</a></p>' .
            '<p><a href="https://github.com/richardkentgates" target="_blank" rel="noopener noreferrer">' . __( 'Developer Profile', 'scriptomatic' ) . '</a></p>' .
            '<p><a href="https://developer.wordpress.org/reference/hooks/wp_head/" target="_blank" rel="noopener noreferrer">' . __( 'WordPress wp_head Documentation', 'scriptomatic' ) . '</a></p>'
        );
    }

    // =========================================================================
    // PLUGINS-PAGE ACTION LINKS
    // =========================================================================

    /**
     * Prepend a Settings link to the plugin's action links on the Plugins screen.
     *
     * @since  1.0.0
     * @param  string[] $links Existing action-link HTML strings.
     * @return string[] Modified array with the Head Scripts link at index 0.
     */
    public function add_action_links( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'admin.php?page=scriptomatic' ),
            __( 'Head Scripts', 'scriptomatic' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Prepend a Settings link on the Network Plugins screen.
     *
     * @since  1.2.0
     * @param  string[] $links Existing action-link HTML strings.
     * @return string[] Modified array with the Network Head Scripts link prepended.
     */
    public function add_network_action_links( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            network_admin_url( 'admin.php?page=scriptomatic-network' ),
            __( 'Head Scripts', 'scriptomatic' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }
}
