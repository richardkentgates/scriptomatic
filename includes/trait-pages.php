<?php
/**
 * Trait: Admin page renderers for Scriptomatic.
 *
 * Covers per-site page rendering (Head Scripts, Footer Scripts, General
 * Settings), the embedded Activity Log table, contextual help tabs, the
 * Plugins-page action links.
 *
 * @package  Scriptomatic
 * @since    1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin page builders, Activity Log renderers, help tabs, and action links.
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
        <?php if ( $error_slug ) { settings_errors( $error_slug ); } ?>
        <?php
    }

    /**
     * Render the unified Activity Log panel for a given location.
     *
     * Replaces the separate Revision History and Audit Log panels.  Every
     * entry type (save, rollback, url_added, url_removed, file_save,
     * file_rollback, file_delete) appears in a single table.  Entries that
     * carry a content snapshot expose View and Restore buttons; purely
     * informational entries (URL events, file_delete) do not.
     *
     * @since  1.9.0
     * @access private
     * @param  string $location `'head'`, `'footer'`, or `'file'`.
     * @param  string $file_id  When non-empty, filters to just this file's entries.
     * @return void
     */
    private function render_activity_log( $location, $file_id = '' ) {
        // Query only the entries relevant for this panel via DB (no post-processing filter needed).
        $log = $this->get_activity_log( $this->get_max_log_entries(), 0, $location, $file_id );

        // Determine the admin page slug (for potential clear-log link).
        if ( 'footer' === $location ) {
            $page_slug = 'scriptomatic-footer';
        } elseif ( 'file' === $location ) {
            $page_slug = 'scriptomatic-files';
        } else {
            $page_slug = 'scriptomatic';
        }

        // Check whether any displayed entry supports View/Restore.
        $has_content_entries = false;
        foreach ( $log as $e ) {
            if ( array_key_exists( 'content', $e )
                || ( 'file' !== $location && array_key_exists( 'urls_snapshot', $e ) )
            ) {
                $has_content_entries = true;
                break;
            }
        }
        ?>
        <hr style="margin:30px 0;">
        <h2 style="margin-top:12px;">
            <span class="dashicons dashicons-backup" style="font-size:24px;width:24px;height:24px;margin-right:4px;vertical-align:middle;"></span>
            <?php esc_html_e( 'Activity Log', 'scriptomatic' ); ?>
        </h2>
        <p class="description">
            <?php
            printf(
                /* translators: %d: maximum number of retained log entries */
                esc_html__( 'All script saves, rollbacks, and file events for this location. The most recent %d entries are retained. Inline script, external URL, and file entries are recorded separately and restored independently.', 'scriptomatic' ),
                $this->get_max_log_entries()
            );
            ?>
        </p>

        <?php if ( ! empty( $log ) ) : ?>
        <table class="widefat scriptomatic-history-table" style="max-width:960px;margin-top:8px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date / Time', 'scriptomatic' ); ?></th>
                    <th><?php esc_html_e( 'User', 'scriptomatic' ); ?></th>
                    <th><?php esc_html_e( 'Event', 'scriptomatic' ); ?></th>
                    <th><?php esc_html_e( 'Changes', 'scriptomatic' ); ?></th>
                    <?php if ( $has_content_entries ) : ?>
                    <th style="width:170px;"><?php esc_html_e( 'Actions', 'scriptomatic' ); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php
            $first_code_seen = false;
            $first_url_seen  = false;
            foreach ( $log as $entry ) :
                if ( ! is_array( $entry ) ) { continue; }
                $entry_id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
                $ts       = isset( $entry['timestamp'] )  ? (int) $entry['timestamp']     : 0;
                $ulogin   = isset( $entry['user_login'] ) ? (string) $entry['user_login'] : '';
                $uid      = isset( $entry['user_id'] )    ? (int) $entry['user_id']        : 0;
                $action   = isset( $entry['action'] )     ? (string) $entry['action']      : '';
                $detail   = isset( $entry['detail'] )     ? (string) $entry['detail']      : '';
                $chars    = isset( $entry['chars'] )      ? (int) $entry['chars']           : 0;
                $file_eid = isset( $entry['file_id'] )    ? (string) $entry['file_id']     : '';

                // Determine whether this row gets View/Restore.
                $has_delete_snap  = ( 'file_delete' === $action )
                    && array_key_exists( 'content', $entry );
                $has_code_content = array_key_exists( 'content', $entry ) && ! $has_delete_snap;
                $has_content      = $has_code_content || $has_delete_snap;

                // URL dataset entries.
                $has_url_entry = 'file' !== $location
                    && in_array( $action, array( 'url_save', 'url_rollback' ), true )
                    && array_key_exists( 'urls_snapshot', $entry );

                // Restore is greyed for rollback actions and for the most recent entry
                // of each dataset (entries are ordered newest-first, so "first seen"
                // == the entry already representing the live state).
                $is_rollback_action = in_array( $action, array( 'rollback', 'file_rollback' ), true );
                $restore_greyed     = $has_code_content && ( $is_rollback_action || ! $first_code_seen );

                if ( $has_code_content && $is_rollback_action ) {
                    $restore_title = __( 'This is a restore entry — nothing to restore from here.', 'scriptomatic' );
                } elseif ( $has_code_content && ! $first_code_seen ) {
                    $restore_title = __( 'This is the current version — nothing to restore.', 'scriptomatic' );
                } else {
                    $restore_title = '';
                }

                // Re-create is greyed only when the delete snapshot has no content
                // to recover. In practice every file_delete entry stores the full
                // pre-deletion content, so this flag is almost always false.
                $reanimate_greyed = $has_delete_snap
                    && '' === (string) ( isset( $entry['content'] ) ? $entry['content'] : '' );

                // URL Restore is greyed when this is the most recent URL entry (current
                // state) or when it is itself a restore action.
                $url_restore_greyed = $has_url_entry
                    && ( 'url_rollback' === $action || ! $first_url_seen );

                if ( $has_url_entry && 'url_rollback' === $action ) {
                    $url_restore_title = __( 'This is a restore entry — nothing to restore from here.', 'scriptomatic' );
                } elseif ( $has_url_entry && ! $first_url_seen ) {
                    $url_restore_title = __( 'Already the current state — nothing to restore.', 'scriptomatic' );
                } else {
                    $url_restore_title = '';
                }

                // Advance first-seen flags after computing greyed state.
                if ( $has_code_content ) { $first_code_seen = true; }
                if ( $has_url_entry )    { $first_url_seen  = true; }

                $is_file_entry = ( 'file' === ( isset( $entry['location'] ) ? $entry['location'] : '' ) );
                // Map action keys to human-readable Event labels.
                $action_label_map = array(
                    'save'                 => __( 'Save', 'scriptomatic' ),
                    'rollback'             => __( 'Restore', 'scriptomatic' ),
                    'url_save'             => __( 'URL Save', 'scriptomatic' ),
                    'url_rollback'         => __( 'URL Restore', 'scriptomatic' ),
                    'url_added'            => __( 'URL Added', 'scriptomatic' ),
                    'url_removed'          => __( 'URL Removed', 'scriptomatic' ),
                    'conditions_save'      => __( 'Conditions', 'scriptomatic' ),
                    'conditions_restored'  => __( 'Conditions Restored', 'scriptomatic' ),
                    'url_list_restored'    => __( 'URLs Restored', 'scriptomatic' ),
                    'file_save'            => __( 'File Save', 'scriptomatic' ),
                    'file_rollback'        => __( 'File Restore', 'scriptomatic' ),
                    'file_delete'          => __( 'File Deleted', 'scriptomatic' ),
                );
                $action_label = isset( $action_label_map[ $action ] )
                    ? $action_label_map[ $action ]
                    : ucwords( str_replace( '_', ' ', $action ) );
                $label_str     = ( $ts ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '' )
                               . ( $ulogin ? ' — ' . $ulogin : '' );
            ?>
                <tr>
                    <td><?php echo esc_html( $ts ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '—' ); ?></td>
                    <td>
                        <?php echo esc_html( $ulogin ); ?>
                        <?php if ( $uid ) : ?>
                        <span class="description">(ID:&nbsp;<?php echo esc_html( $uid ); ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $action_label ); ?></td>
                    <td><?php
                        if ( '' !== $detail ) {
                            echo '<span title="' . esc_attr( $detail ) . '">' . esc_html( strlen( $detail ) > 60 ? substr( $detail, 0, 57 ) . '…' : $detail ) . '</span>';
                        } elseif ( $chars ) {
                            echo esc_html( number_format( $chars ) ) . ' ' . esc_html__( 'chars', 'scriptomatic' );
                        } else {
                            echo '—';
                        }
                    ?></td>
                    <?php if ( $has_content_entries ) : ?>
                    <td>
                        <?php if ( $has_code_content ) : ?>
                            <?php if ( $is_file_entry ) : ?>
                            <button type="button" class="button button-small sm-file-view"
                                data-id="<?php echo esc_attr( $entry_id ); ?>"
                                data-file-id="<?php echo esc_attr( $file_eid ); ?>"
                                data-label="<?php echo esc_attr( $label_str ); ?>"
                            ><?php esc_html_e( 'View', 'scriptomatic' ); ?></button>
                            <button type="button" class="button button-small sm-file-restore"
                                data-id="<?php echo esc_attr( $entry_id ); ?>"
                                data-file-id="<?php echo esc_attr( $file_eid ); ?>"
                                data-original-text="<?php esc_attr_e( 'Restore', 'scriptomatic' ); ?>"
                                <?php if ( $restore_greyed ) : ?>disabled title="<?php echo esc_attr( $restore_title ); ?>"<?php endif; ?>
                            ><?php esc_html_e( 'Restore', 'scriptomatic' ); ?></button>
                            <?php else : ?>
                            <button type="button" class="button button-small scriptomatic-history-view"
                                data-id="<?php echo esc_attr( $entry_id ); ?>"
                                data-location="<?php echo esc_attr( $location ); ?>"
                                data-label="<?php echo esc_attr( $label_str ); ?>"
                            ><?php esc_html_e( 'View', 'scriptomatic' ); ?></button>
                            <button type="button" class="button button-small scriptomatic-history-restore"
                                data-id="<?php echo esc_attr( $entry_id ); ?>"
                                data-location="<?php echo esc_attr( $location ); ?>"
                                data-original-text="<?php esc_attr_e( 'Restore', 'scriptomatic' ); ?>"
                                <?php if ( $restore_greyed ) : ?>disabled title="<?php echo esc_attr( $restore_title ); ?>"<?php endif; ?>
                            ><?php esc_html_e( 'Restore', 'scriptomatic' ); ?></button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ( $has_delete_snap ) : ?>
                            <button type="button" class="button button-small sm-file-view"
                                data-id="<?php echo esc_attr( $entry_id ); ?>"
                                data-file-id="<?php echo esc_attr( $file_eid ); ?>"
                                data-label="<?php echo esc_attr( $label_str ); ?>"
                                data-is-delete="1"
                            ><?php esc_html_e( 'View', 'scriptomatic' ); ?></button>
                            <button type="button" class="button button-small sm-file-reanimate"
                                data-id="<?php echo esc_attr( $entry_id ); ?>"
                                data-original-text="<?php esc_attr_e( 'Re-create', 'scriptomatic' ); ?>"
                                <?php if ( $reanimate_greyed ) : ?>disabled title="<?php esc_attr_e( 'No content snapshot — nothing to restore.', 'scriptomatic' ); ?>"<?php endif; ?>
                            ><?php esc_html_e( 'Re-create', 'scriptomatic' ); ?></button>
                        <?php endif; ?>
                        <?php if ( $has_url_entry ) : ?>
                            <button type="button" class="button button-small sm-url-history-view"
                                data-id="<?php echo esc_attr( $entry_id ); ?>"
                                data-location="<?php echo esc_attr( $location ); ?>"
                                data-label="<?php echo esc_attr( $label_str ); ?>"
                            ><?php esc_html_e( 'View', 'scriptomatic' ); ?></button>
                            <button type="button" class="button button-small sm-url-history-restore"
                                data-id="<?php echo esc_attr( $entry_id ); ?>"
                                data-location="<?php echo esc_attr( $location ); ?>"
                                data-original-text="<?php esc_attr_e( 'Restore', 'scriptomatic' ); ?>"
                                <?php if ( $url_restore_greyed ) : ?>disabled title="<?php echo esc_attr( $url_restore_title ); ?>"<?php endif; ?>
                            ><?php esc_html_e( 'Restore', 'scriptomatic' ); ?></button>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
        <p class="description"><?php esc_html_e( 'No activity yet. Script saves and rollbacks will appear here.', 'scriptomatic' ); ?></p>
        <?php endif;

        // Lightbox — shared by inline View and file View buttons.
        ?>
        <div id="sm-history-lightbox" class="sm-history-lightbox" role="dialog" aria-modal="true" aria-labelledby="sm-lightbox-title">
            <div class="sm-history-lightbox__card">
                <div class="sm-history-lightbox__header">
                    <div>
                        <p id="sm-lightbox-title" class="sm-history-lightbox__title"></p>
                        <p class="sm-history-lightbox__meta"></p>
                    </div>
                    <button type="button" class="sm-history-lightbox__close" aria-label="<?php esc_attr_e( 'Close', 'scriptomatic' ); ?>">&times;</button>
                </div>
                <div class="sm-history-lightbox__body">
                    <pre class="sm-history-lightbox__pre"></pre>
                </div>
            </div>
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
        $this->render_activity_log( 'head' );
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
        $this->render_activity_log( 'footer' );
        echo '</div>'; // .wrap
    }

    /**
     * Render the Preferences admin page.
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

    // =========================================================================
    // JS FILES PAGE
    // =========================================================================

    /**
     * Render the JS Files upgrade notice page shown to free-tier users.
     *
     * @since  3.0.0
     * @return void
     */
    public function render_js_files_upgrade_page() {
        if ( ! current_user_can( $this->get_required_cap() ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'scriptomatic' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'JS Files', 'scriptomatic' ); ?></h1>
            <?php
            $this->render_pro_upgrade_notice(
                __( 'JS Files is a Pro feature', 'scriptomatic' ),
                __( 'Create, upload, and manage standalone .js files stored in wp-content/uploads/scriptomatic/. Each file has its own code editor, Head/Footer selector, and load conditions, and persists across plugin updates.', 'scriptomatic' )
            );
            ?>
        </div><!-- .wrap -->
        <?php
    }

    /**
     * Render the JS Files admin page.
     *
     * Dispatches to the list view or the edit/new-file view based on the
     * `action` query parameter.
     *
     * @since  1.8.0
     * @return void
     */
    public function render_js_files_page() {
        if ( ! current_user_can( $this->get_required_cap() ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'scriptomatic' ) );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

        if ( 'edit' === $action ) {
            $this->render_js_file_edit_view();
        } else {
            $this->render_js_file_list_view();
        }
    }

    /**
     * Render the JS Files list table.
     *
     * @since  1.8.0
     * @access private
     * @return void
     */
    private function render_js_file_list_view() {
        $files = $this->get_js_files_meta();

        $saved_notice = isset( $_GET['saved'] ) && '1' === $_GET['saved'];

        $condition_labels = array(
            'all'          => __( 'All pages', 'scriptomatic' ),
            'front_page'   => __( 'Front page only', 'scriptomatic' ),
            'singular'     => __( 'Any singular', 'scriptomatic' ),
            'post_type'    => __( 'Specific post types', 'scriptomatic' ),
            'page_id'      => __( 'Specific page IDs', 'scriptomatic' ),
            'url_contains' => __( 'URL contains', 'scriptomatic' ),
            'logged_in'    => __( 'Logged-in only', 'scriptomatic' ),
            'logged_out'   => __( 'Logged-out only', 'scriptomatic' ),
            'by_date'      => __( 'Date range', 'scriptomatic' ),
            'by_datetime'  => __( 'Date & time range', 'scriptomatic' ),
            'week_number'  => __( 'Week numbers', 'scriptomatic' ),
            'by_month'     => __( 'Specific months', 'scriptomatic' ),
        );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'JS Files', 'scriptomatic' ); ?></h1>
            <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'scriptomatic-files', 'action' => 'edit' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
                <?php esc_html_e( 'Add New', 'scriptomatic' ); ?>
            </a>
            <hr class="wp-header-end">

            <?php if ( $saved_notice ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'File saved.', 'scriptomatic' ); ?></p></div>
            <?php endif; ?>

            <?php if ( isset( $_GET['deleted'] ) && '1' === $_GET['deleted'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'File was empty and has been deleted.', 'scriptomatic' ); ?></p></div>
            <?php endif; ?>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $upload_error_code = isset( $_GET['upload_error'] ) ? sanitize_key( wp_unslash( $_GET['upload_error'] ) ) : '';
            $upload_error_messages = array(
                'invalid_type'     => __( 'Only .js files may be uploaded. Please select a .js file.', 'scriptomatic' ),
                'upload_too_large' => __( 'The uploaded file exceeds the maximum size allowed by this server.', 'scriptomatic' ),
                'upload_error'     => __( 'File upload failed. Please try again.', 'scriptomatic' ),
                'upload_no_file'   => __( 'No file was received. Please choose a .js file to upload.', 'scriptomatic' ),
            );
            if ( '' !== $upload_error_code && isset( $upload_error_messages[ $upload_error_code ] ) ) :
            ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $upload_error_messages[ $upload_error_code ] ); ?></p></div>
            <?php endif; ?>

            <p class="description" style="margin-top:8px;">
                <?php esc_html_e( 'Manage standalone JavaScript files stored on this server. Each file can be loaded in the head or footer with its own conditions.', 'scriptomatic' ); ?>
            </p>

            <div class="sm-upload-section" style="margin:16px 0;padding:16px 16px 8px;background:#fff;border:1px solid #c3c4c7;max-width:480px;">
                <h3 style="margin-top:0;"><?php esc_html_e( 'Upload a JS File', 'scriptomatic' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="scriptomatic_save_js_file">
                    <input type="hidden" name="_sm_upload_source" value="list">
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( (int) wp_max_upload_size() ); ?>">
                    <?php wp_nonce_field( SCRIPTOMATIC_FILES_NONCE, '_sm_files_nonce' ); ?>
                    <p style="margin-top:0;">
                        <input type="file" name="sm_file_upload" accept=".js,text/javascript,application/javascript">
                    </p>
                    <p class="description" style="margin-bottom:12px;">
                        <?php
                        printf(
                            /* translators: %s: human-readable maximum upload size (e.g. "8 MB") */
                            esc_html__( 'Select a local .js file. Max: %s. You will be taken to the edit page to review the code and set the label, location, and conditions before the file goes live.', 'scriptomatic' ),
                            esc_html( size_format( wp_max_upload_size() ) )
                        );
                        ?>
                    </p>
                    <p>
                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Upload &amp; Edit', 'scriptomatic' ); ?></button>
                    </p>
                </form>
            </div>

            <?php if ( empty( $files ) ) : ?>
            <div class="sm-files-empty">
                <p><?php esc_html_e( 'No JS files yet.', 'scriptomatic' ); ?> <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'scriptomatic-files', 'action' => 'edit' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Add your first file.', 'scriptomatic' ); ?></a></p>
            </div>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped sm-files-table" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th scope="col" class="column-primary"><?php esc_html_e( 'Label', 'scriptomatic' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Filename', 'scriptomatic' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Inject In', 'scriptomatic' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Conditions', 'scriptomatic' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Actions', 'scriptomatic' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $files as $file ) :
                        $fid       = isset( $file['id'] )       ? $file['id']       : '';
                        $flabel    = isset( $file['label'] )    ? $file['label']    : $fid;
                        $fname     = isset( $file['filename'] ) ? $file['filename'] : '';
                        $floc      = ( isset( $file['location'] ) && 'footer' === $file['location'] ) ? 'footer' : 'head';
                        $fcond_raw  = ( isset( $file['conditions'] ) && is_array( $file['conditions'] ) ) ? $file['conditions'] : array();
                        $fcond      = ( is_array( $fcond_raw ) && isset( $fcond_raw['rules'] ) )
                                      ? $fcond_raw
                                      : array( 'logic' => 'and', 'rules' => array() );
                        $fcond_cnt  = count( $fcond['rules'] );
                        if ( 0 === $fcond_cnt ) {
                            $cond_lbl = __( 'All pages', 'scriptomatic' );
                        } elseif ( 1 === $fcond_cnt ) {
                            $fcond_type = isset( $fcond['rules'][0]['type'] ) ? $fcond['rules'][0]['type'] : '';
                            $cond_lbl   = isset( $condition_labels[ $fcond_type ] ) ? $condition_labels[ $fcond_type ] : $fcond_type;
                        } else {
                            $cond_lbl = sprintf(
                                /* translators: 1: number of rules, 2: logic operator (AND/OR) */
                                __( '%1$d rules (%2$s)', 'scriptomatic' ),
                                $fcond_cnt,
                                strtoupper( $fcond['logic'] )
                            );
                        }

                        $edit_url = add_query_arg(
                            array( 'page' => 'scriptomatic-files', 'action' => 'edit', 'file' => $fid ),
                            admin_url( 'admin.php' )
                        );
                    ?>
                    <tr>
                        <td class="column-primary">
                            <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $flabel ); ?></a></strong>
                        </td>
                        <td><code><?php echo esc_html( $fname ); ?></code></td>
                        <td><?php echo 'footer' === $floc ? esc_html__( 'Footer', 'scriptomatic' ) : esc_html__( 'Head', 'scriptomatic' ); ?></td>
                        <td><?php echo esc_html( $cond_lbl ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'scriptomatic' ); ?></a>
                            <button
                                type="button"
                                class="button button-small button-link-delete sm-file-delete"
                                data-file-id="<?php echo esc_attr( $fid ); ?>"
                                data-label="<?php echo esc_attr( $flabel ); ?>"
                            ><?php esc_html_e( 'Delete', 'scriptomatic' ); ?></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div><!-- .wrap -->
        <?php
        $this->render_activity_log( 'file' );
    }

    /**
     * Render the JS File edit / new-file view.
     *
     * @since  1.8.0
     * @access private
     * @return void
     */
    private function render_js_file_edit_view() {
        // Error messages.
        $error_messages = array(
            'missing_label'    => __( 'Please enter a label.', 'scriptomatic' ),
            'invalid_filename' => __( 'The filename is invalid. Use letters, numbers, dashes and underscores only.', 'scriptomatic' ),
            'too_large'        => __( 'The file exceeds the maximum upload size allowed by this server.', 'scriptomatic' ),
            'write_failed'     => __( 'Could not write the file to disk. Please check directory permissions.', 'scriptomatic' ),
            'empty_content'    => __( 'File content cannot be empty. Add some JavaScript or discard the file.', 'scriptomatic' ),
            // Upload-specific codes.
            'upload_error'     => __( 'File upload failed. Please try again.', 'scriptomatic' ),
            'invalid_type'     => __( 'Only .js files may be uploaded. Please select a valid JavaScript file.', 'scriptomatic' ),
            'upload_too_large' => __( 'The uploaded file exceeds the maximum size allowed by this server.', 'scriptomatic' ),
            'upload_no_file'   => __( 'No file was received. Please choose a .js file to upload.', 'scriptomatic' ),
        );

        $error_code = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
        $error_msg  = isset( $error_messages[ $error_code ] ) ? $error_messages[ $error_code ] : '';

        // Determine if editing an existing file.
        $file_id = isset( $_GET['file'] ) ? sanitize_key( wp_unslash( $_GET['file'] ) ) : '';
        $entry   = null;

        if ( '' !== $file_id ) {
            foreach ( $this->get_js_files_meta() as $f ) {
                if ( $f['id'] === $file_id ) {
                    $entry = $f;
                    break;
                }
            }
        }

        // Populate form values.
        $label      = $entry ? $entry['label']    : '';
        $filename   = $entry ? $entry['filename'] : '';
        $location   = ( $entry && 'footer' === $entry['location'] ) ? 'footer' : 'head';
        $conditions = ( $entry && isset( $entry['conditions'] ) && is_array( $entry['conditions'] ) )
            ? $entry['conditions']
            : array( 'logic' => 'and', 'rules' => array() );

        // Read existing file content from disk.
        $content = '';
        if ( $entry && ! empty( $entry['filename'] ) ) {
            $file_path = $this->get_js_files_dir() . $entry['filename'];
            if ( file_exists( $file_path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                $content = file_get_contents( $file_path );
            }
        }

        $is_new        = ( null === $entry );
        $page_title    = $is_new
            ? __( 'Add New JS File', 'scriptomatic' )
            : __( 'Edit JS File', 'scriptomatic' );
        $max_bytes     = wp_max_upload_size();
        $max_bytes_fmt = size_format( $max_bytes );
        $pfx           = 'sm-file-cond';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $page_title ); ?></h1>
            <hr class="wp-header-end">

            <?php if ( '' !== $error_msg ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $error_msg ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="scriptomatic_save_js_file">
                <?php wp_nonce_field( SCRIPTOMATIC_FILES_NONCE, '_sm_files_nonce' ); ?>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( (int) wp_max_upload_size() ); ?>">
                <input type="hidden" name="_sm_file_original_id" value="<?php echo esc_attr( $file_id ); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sm-file-label"><?php esc_html_e( 'Label', 'scriptomatic' ); ?></label></th>
                        <td>
                            <input
                                type="text"
                                id="sm-file-label"
                                name="sm_file_label"
                                value="<?php echo esc_attr( $label ); ?>"
                                class="regular-text"
                                required
                                aria-describedby="sm-file-label-desc"
                            >
                            <p id="sm-file-label-desc" class="description"><?php esc_html_e( 'A human-readable name shown in the file list.', 'scriptomatic' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sm-file-name"><?php esc_html_e( 'Filename', 'scriptomatic' ); ?></label></th>
                        <td>
                            <input
                                type="text"
                                id="sm-file-name"
                                name="sm_file_name"
                                value="<?php echo esc_attr( $filename ); ?>"
                                class="regular-text"
                                placeholder="my-script.js"
                                pattern="[a-zA-Z0-9_\-\.]*\.js"
                                aria-describedby="sm-file-name-desc"
                            >
                            <p id="sm-file-name-desc" class="description">
                                <?php esc_html_e( 'Auto-filled from the label. Must end in .js. Letters, numbers, dashes and underscores only.', 'scriptomatic' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Inject In', 'scriptomatic' ); ?></th>
                        <td>
                            <label style="margin-right:20px;">
                                <input type="radio" name="sm_file_location" value="head" <?php checked( $location, 'head' ); ?>>
                                <?php esc_html_e( 'Head', 'scriptomatic' ); ?>
                            </label>
                            <label>
                                <input type="radio" name="sm_file_location" value="footer" <?php checked( $location, 'footer' ); ?>>
                                <?php esc_html_e( 'Footer', 'scriptomatic' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Choose where in the page this file is injected.', 'scriptomatic' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Load Conditions', 'scriptomatic' ); ?></th>
                        <td><?php $this->render_file_conditions_widget( $pfx, $conditions ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="scriptomatic-files-script"><?php esc_html_e( 'JavaScript', 'scriptomatic' ); ?></label></th>
                        <td>
                            <div class="scriptomatic-code-editor-wrap">
                                <textarea
                                    id="scriptomatic-files-script"
                                    name="sm_file_content"
                                    rows="30"
                                    cols="100"
                                    class="large-text code"
                                    placeholder="<?php esc_attr_e( 'Enter your JavaScript code here (without <script> tags)', 'scriptomatic' ); ?>"
                                    aria-describedby="sm-file-content-desc sm-file-char-ct"
                                ><?php echo esc_textarea( $content ); ?></textarea>
                            </div>
                            <p id="sm-file-char-ct" class="description">
                                <?php
                                printf(
                                    /* translators: 1: current size, 2: max upload size */
                                    esc_html__( 'Size: %1$s / %2$s', 'scriptomatic' ),
                                    '<span id="scriptomatic-files-char-count">' . esc_html( size_format( strlen( $content ) ) ) . '</span>',
                                    esc_html( $max_bytes_fmt )
                                );
                                ?>
                            </p>
                            <p id="sm-file-content-desc" class="description">
                                <strong><?php esc_html_e( 'Important:', 'scriptomatic' ); ?></strong>
                                <?php esc_html_e( 'Do not include <script> tags — they are added automatically on the front end.', 'scriptomatic' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large">
                        <?php esc_html_e( 'Save File', 'scriptomatic' ); ?>
                    </button>
                    <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'scriptomatic-files' ), admin_url( 'admin.php' ) ) ); ?>" class="button button-secondary button-large">
                        <?php esc_html_e( 'Cancel', 'scriptomatic' ); ?>
                    </a>
                </p>
            </form>
        </div><!-- .wrap -->
        <?php
        if ( '' !== $file_id ) {
            $this->render_activity_log( 'file', $file_id );
        }
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
                '<p>' . __( 'Scriptomatic safely injects custom JavaScript into the <strong>head</strong> (before &lt;/head&gt;) and the <strong>footer</strong> (before &lt;/body&gt;) of your WordPress site.', 'scriptomatic' ) . '</p>' .
                '<p>' . __( 'Use the <strong>Head Scripts</strong> page for analytics tags, pixel codes, and scripts that must load early. Use the <strong>Footer Scripts</strong> page for scripts that should run after page content has loaded.', 'scriptomatic' ) . '</p>' .
                '<p>' . __( 'Each location has its own <strong>External Script URLs</strong> section for loading remote <code>&lt;script src&gt;</code> files, and an <strong>Activity Log</strong> below showing all saves, rollbacks, and file events. Inline script changes and external URL changes are recorded as <strong>separate entries</strong>, each with its own View and Restore buttons &mdash; restoring one never affects the other.', 'scriptomatic' ) . '</p>' .
                '<p>' . __( '<strong>Load Conditions</strong> <em>(Pro)</em> — restrict injection to specific pages, post types, URL patterns, user login state, date ranges, date/time windows, ISO week numbers, or months. Available per inline script and per external URL (11 condition types).', 'scriptomatic' ) . '</p>' .
                '<p>' . __( '<strong>JS Files</strong> <em>(Pro)</em> — create, edit, and delete standalone <code>.js</code> files stored in <code>wp-content/uploads/scriptomatic/</code>. Each file has its own Head/Footer selector and Load Conditions, and persists across plugin updates.', 'scriptomatic' ) . '</p>' .
                '<p>' . __( '<strong>REST API</strong> <em>(Pro)</em> — full <code>scriptomatic/v1</code> REST API (WordPress Application Passwords). <strong>WP-CLI</strong> <em>(Pro)</em> — <code>wp scriptomatic</code> command group. Both share the same validation, rate limiting, and activity logging as the admin UI. REST API access can be restricted to specific IP addresses in <em>Preferences</em>.', 'scriptomatic' ) . '</p>' .
                '<p>' . __( 'The inline-script editor and JS Files editor both use <strong>CodeMirror</strong> — a full JavaScript code editor with line numbers, bracket matching, and WordPress/jQuery-specific Ctrl-Space autocomplete. Falls back to a plain textarea when syntax highlighting is disabled in your WordPress profile.', 'scriptomatic' ) . '</p>' .
                '<p>' . __( 'A <strong>3-day free trial</strong> is available for all Pro features (credit card or PayPal required). Visit <em>Scriptomatic &rarr; Account</em> to start a trial or upgrade.', 'scriptomatic' ) . '</p>' .
                '<p>' . __( 'This plugin is designed with security and performance in mind, providing input validation, sanitisation, secondary nonce verification, per-user rate limiting, an activity log with revision rollback, and conditional loading.', 'scriptomatic' ) . '</p>',
        ) );

        $screen->add_help_tab( array(
            'id'      => 'scriptomatic_usage',
            'title'   => __( 'Usage', 'scriptomatic' ),
            'content' =>
                '<h3>' . __( 'How to Use', 'scriptomatic' ) . '</h3>' .
                '<ol>' .
                '<li><strong>' . __( 'Choose a location:', 'scriptomatic' ) . '</strong> ' . __( 'Use <em>Head Scripts</em> for early-loading code (analytics, pixels) or <em>Footer Scripts</em> for deferred code.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Add Your Code:', 'scriptomatic' ) . '</strong> ' . __( 'Write or paste your JavaScript into the <strong>CodeMirror editor</strong>. Do not include &lt;script&gt; tags — they are added automatically. Use Ctrl-Space for WordPress/jQuery autocomplete hints.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Add external URLs (optional):', 'scriptomatic' ) . '</strong> ' . __( 'Enter remote script URLs in the External Script URLs section. They load before the inline block.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Set Load Conditions (optional, Pro):', 'scriptomatic' ) . '</strong> ' . __( 'Use the Load Conditions panel to restrict injection to specific pages, post types, URL patterns, user login state, date ranges, date/time windows, ISO week numbers, or specific months. Supports 11 condition types with AND/OR logic stacking. Defaults to all pages. Requires a Pro licence.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Save Changes:', 'scriptomatic' ) . '</strong> ' . __( 'Click the Save button at the bottom of the page.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Verify &amp; Test:', 'scriptomatic' ) . '</strong> ' . __( 'View your page source to confirm the script is injected in the correct location, then test your site to ensure it behaves as expected.', 'scriptomatic' ) . '</li>' .
                '</ol>' .
                '<p><strong>' . __( 'Managed JS Files (Pro):', 'scriptomatic' ) . '</strong> ' . __( 'Use <em>Scriptomatic &rarr; JS Files</em> to create and manage standalone <code>.js</code> files. Each file has its own label, filename, Head/Footer selector, Load Conditions, and CodeMirror editor. Files are stored in <code>wp-content/uploads/scriptomatic/</code> and survive plugin updates. Requires a Pro licence.', 'scriptomatic' ) . '</p>' .
                '<p><strong>' . __( 'File Upload (Pro):', 'scriptomatic' ) . '</strong> ' . __( 'On the <strong>JS Files</strong> page, use the <strong>Upload a JS File</strong> form to import a local .js file directly from your computer. The REST API (<code>POST /wp-json/scriptomatic/v1/files/upload</code>) and WP-CLI (<code>wp scriptomatic files upload --path=&lt;file&gt;</code>) also support file uploads. Requires a Pro licence.', 'scriptomatic' ) . '</p>' .
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
                '<li><strong>' . __( 'Capability Check:', 'scriptomatic' ) . '</strong> ' . __( 'Only users with &ldquo;manage_options&rdquo; capability (Administrators) can access any Scriptomatic page or modify scripts.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'REST API IP Allowlist (Pro):', 'scriptomatic' ) . '</strong> ' . __( 'The <em>Preferences</em> page lets you restrict REST API access to a specific list of IPv4 addresses, IPv6 addresses, or IPv4 CIDR ranges (one per line). Leave the list empty to allow access from any IP (the default). Requires a Pro licence.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'JS File Upload Validation:', 'scriptomatic' ) . '</strong> ' . __( 'Uploaded files are validated for extension (<code>.js</code> only), MIME type, and file size against the server upload limit. The file must be transmitted as a genuine HTTP file upload; arbitrary binary content is rejected. All uploads are recorded in the Activity Log.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Dual Nonce Verification:', 'scriptomatic' ) . '</strong> ' . __( 'Each form carries both the WordPress Settings API nonce and a secondary location-specific nonce, verified on every save.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Rate Limiting:', 'scriptomatic' ) . '</strong> ' . __( 'A transient-based 10-second cooldown per user per location prevents rapid repeated saves.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Input Validation:', 'scriptomatic' ) . '</strong> ' . __( 'All input is validated: UTF-8 check, control-character rejection, length cap (100&nbsp;KB for inline scripts; JS files are limited by the server&rsquo;s upload setting, not this plugin), PHP-tag detection, and dangerous-HTML-tag warning (iframe, object, embed, link, style, meta).', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Sanitization:', 'scriptomatic' ) . '</strong> ' . __( '&lt;script&gt; tags are automatically stripped to prevent double-wrapping.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Activity Log:', 'scriptomatic' ) . '</strong> ' . __( 'All saves, AJAX rollbacks, and JS file events are recorded in the <strong>Activity Log</strong> at the bottom of each admin page. Inline script + conditions changes and external URL changes are written as <strong>separate independent entries</strong>, each with its own View and Restore buttons &mdash; restoring one never touches the other. <strong>View</strong> shows the full saved state; <strong>Restore</strong> writes the dataset back with no further Save needed. The Restore button is disabled on the most recent entry of each dataset. Actions covered: <code>save</code>, <code>url_save</code>, <code>rollback</code>, <code>url_rollback</code>, <code>file_save</code>, <code>file_rollback</code>, <code>file_delete</code>. The log limit (3&ndash;1000, default 200) is configurable in Preferences; oldest entries are discarded automatically.', 'scriptomatic' ) . '</li>' .
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
                '<li><strong>' . __( 'Backup:', 'scriptomatic' ) . '</strong> ' . __( 'Every save is recorded in the <strong>Activity Log</strong>. Inline script and external URL changes have separate entries with independent Restore buttons &mdash; click <strong>Restore</strong> on any entry to roll back that dataset instantly. No further Save needed.', 'scriptomatic' ) . '</li>' .
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
                '<li>' . __( 'Check the <strong>Load Conditions</strong> setting <em>(Pro)</em> — if it is set to anything other than &ldquo;All pages&rdquo;, the script is intentionally suppressed on pages that do not match the condition. Load Conditions requires a Pro licence; on free, scripts are always injected on all pages.', 'scriptomatic' ) . '</li>' .
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
                '<li>' . __( 'Check if the inline script exceeds the 100&nbsp;KB maximum length. For JS files, the limit is set by the server&rsquo;s upload configuration, not this plugin — the current limit is shown beneath the editor.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'Remove any HTML tags (only JavaScript is allowed).', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'The <strong>rate limiter</strong> enforces a 10-second cooldown per user per location. If you saved very recently, wait a moment and try again.', 'scriptomatic' ) . '</li>' .
                '</ul>' .
                '<h4>' . __( 'Restore a previous version:', 'scriptomatic' ) . '</h4>' .
                '<ul>' .
                '<li>' . __( 'Scroll to the <strong>Activity Log</strong> panel at the bottom of the page.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'Click <strong>Restore</strong> next to the desired entry &mdash; inline script entries restore the script and load conditions; URL entries restore the external URLs. Each is restored independently, no further Save needed.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'For JS files, the restore writes the snapshot directly to disk.', 'scriptomatic' ) . '</li>' .
                '</ul>' .
                '<h4>' . __( 'File upload errors:', 'scriptomatic' ) . '</h4>' .
                '<ul>' .
                '<li><strong>' . __( 'Only .js files accepted', 'scriptomatic' ) . '</strong> &mdash; ' . __( 'The file must have a <code>.js</code> extension and a JavaScript MIME type. Rename the file or select the correct one.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'File too large', 'scriptomatic' ) . '</strong> &mdash; ' . __( 'The upload limit is set by your server\'s PHP configuration, not this plugin. The current limit is shown beneath the upload field.', 'scriptomatic' ) . '</li>' .
                '<li><strong>' . __( 'Upload failed / write failed', 'scriptomatic' ) . '</strong> &mdash; ' . __( 'Check that <code>wp-content/uploads/scriptomatic/</code> exists and is writable by the web server.', 'scriptomatic' ) . '</li>' .
                '</ul>' .
                '<h4>' . __( 'REST API returns 403:', 'scriptomatic' ) . '</h4>' .
                '<ul>' .
                '<li>' . __( 'The REST API requires a <strong>Pro licence</strong>. On free installations the REST API is not registered and will return a 404, not 403.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'If the <strong>API Allowed IPs</strong> list in Preferences is populated, requests from unlisted IP addresses are blocked. Add your IP or clear the list to allow access from any address.', 'scriptomatic' ) . '</li>' .
                '<li>' . __( 'Authenticate using a WordPress <strong>Application Password</strong> (Users &rarr; Profile) passed as <code>Authorization: Basic base64(username:app-password)</code>.', 'scriptomatic' ) . '</li>' .
                '</ul>',
        ) );

        $upgrade_sidebar = '';
        if ( ! scriptomatic_is_premium() ) {
            $fs          = function_exists( 'scriptomatic_fs' ) ? scriptomatic_fs() : null;
            $upgrade_url = ( $fs && method_exists( $fs, 'get_upgrade_url' ) ) ? esc_url( $fs->get_upgrade_url() ) : '#';
            $upgrade_sidebar =
                '<p><strong>' . __( 'Go Pro:', 'scriptomatic' ) . '</strong></p>' .
            '<p><a href="' . $upgrade_url . '" target="_blank" rel="noopener noreferrer">' . __( '⭐ Upgrade to Pro', 'scriptomatic' ) . '</a></p>' .
            '<p class="description">' . __( '3-day free trial available (credit card or PayPal required). Unlock conditional loading, managed JS files, REST API, and WP-CLI.', 'scriptomatic' ) . '</p>';
        }

        $screen->set_help_sidebar(
            '<p><strong>' . __( 'For more information:', 'scriptomatic' ) . '</strong></p>' .
            '<p><a href="https://github.com/richardkentgates/scriptomatic" target="_blank" rel="noopener noreferrer">' . __( 'Plugin Documentation', 'scriptomatic' ) . '</a></p>' .
            '<p><a href="https://github.com/richardkentgates/scriptomatic/issues" target="_blank" rel="noopener noreferrer">' . __( 'Report Issues', 'scriptomatic' ) . '</a></p>' .
            '<p><a href="https://github.com/richardkentgates" target="_blank" rel="noopener noreferrer">' . __( 'Developer Profile', 'scriptomatic' ) . '</a></p>' .
            '<p><a href="https://developer.wordpress.org/reference/hooks/wp_head/" target="_blank" rel="noopener noreferrer">' . __( 'WordPress wp_head Documentation', 'scriptomatic' ) . '</a></p>' .
            $upgrade_sidebar
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
            admin_url( 'admin.php?page=scriptomatic-settings' ),
            __( 'Preferences', 'scriptomatic' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }
}
