<?php
/**
 * Trait: Email notifications and per-profile opt-in for Scriptomatic.
 *
 * - Renders and saves a "Notify me" checkbox on admin user profile pages
 *   (only shown when the profile belongs to a user with manage_options).
 * - Dispatches plain-text notification emails to the acting admin and the
 *   site admin when content is changed, subject to each recipient's opt-in.
 * - Handles the AJAX endpoint and server-side rendering for the paginated
 *   read-only Preferences Action History section.
 *
 * @package  Scriptomatic
 * @since    3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Email notifications, per-admin opt-in, and Preferences action history.
 */
trait Scriptomatic_Notifications {

    // =========================================================================
    // PROFILE FIELD — NOTIFICATION OPT-IN
    // =========================================================================

    /**
     * Render the Scriptomatic notification checkbox on a user-profile page.
     *
     * Only rendered when the profile being viewed belongs to a user who holds
     * the manage_options capability (administrators only).
     *
     * @since  3.1.0
     * @param  WP_User $user  The user whose profile is being displayed.
     * @return void
     */
    public function render_notification_profile_field( WP_User $user ) {
        if ( ! user_can( $user, 'manage_options' ) ) {
            return;
        }

        $checked = ( '1' === (string) get_user_meta( $user->ID, 'scriptomatic_notifications', true ) );
        wp_nonce_field( 'scriptomatic_save_notification_pref_' . $user->ID, '_scriptomatic_notif_nonce' );
        ?>
        <h3><?php esc_html_e( 'Scriptomatic Notifications', 'scriptomatic' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="scriptomatic_notifications">
                        <?php esc_html_e( 'Content Change Alerts', 'scriptomatic' ); ?>
                    </label>
                </th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            id="scriptomatic_notifications"
                            name="scriptomatic_notifications"
                            value="1"
                            <?php checked( $checked, true ); ?>
                        >
                        <?php esc_html_e( 'Email me when any Scriptomatic script, URL, or JS file is added, removed, or modified.', 'scriptomatic' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Applies to head/footer inline scripts, external URL lists, and managed JS files. Emails are sent immediately after each change.', 'scriptomatic' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save the notification opt-in preference when a user profile is saved.
     *
     * Runs on `personal_options_update` and `edit_user_profile_update`.
     * Silently bails when our nonce field is absent or when the target user
     * is not an administrator.
     *
     * @since  3.1.0
     * @param  int $user_id  ID of the user being saved.
     * @return void
     */
    public function save_notification_profile_field( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        // Only admins get this field; silently skip non-admin profiles.
        $target = get_userdata( $user_id );
        if ( ! $target || ! user_can( $target, 'manage_options' ) ) {
            return;
        }

        // Bail if our nonce field is absent (saves not triggered by our profile form).
        if ( ! isset( $_POST['_scriptomatic_notif_nonce'] ) ) {
            return;
        }

        $nonce = sanitize_text_field( wp_unslash( $_POST['_scriptomatic_notif_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'scriptomatic_save_notification_pref_' . $user_id ) ) {
            return;
        }

        $enabled = ! empty( $_POST['scriptomatic_notifications'] ) ? '1' : '0';
        update_user_meta( $user_id, 'scriptomatic_notifications', $enabled );
    }

    // =========================================================================
    // NOTIFICATION DISPATCH
    // =========================================================================

    /**
     * Send notification emails to opted-in administrators when content changes.
     *
     * Two potential recipients:
     *   1. The acting user (currently logged in, who made the change).
     *   2. The site admin resolved from get_option('admin_email').
     *
     * Each is emailed only when their `scriptomatic_notifications` user meta
     * is '1'. When both resolve to the same email address, a single email is sent.
     *
     * @since  3.1.0
     * @access private
     * @param  array $event {
     *     @type string $action   Verb describing the event, e.g. 'Script saved'.
     *     @type string $location Location or resource label, e.g. 'Head', 'Footer', filename.
     *     @type string $detail   Optional extra context (char count, filename, URL count…).
     * }
     * @return void
     */
    private function maybe_send_notifications( array $event ) {
        $action   = isset( $event['action'] )   ? (string) $event['action']   : '';
        $location = isset( $event['location'] ) ? (string) $event['location'] : '';
        $detail   = isset( $event['detail'] )   ? (string) $event['detail']   : '';

        $actor = wp_get_current_user();

        /**
         * Collect recipients keyed by email to deduplicate.
         * Value is the display name (unused in body but available for future personalisation).
         */
        $recipients = array();

        // Acting user.
        if ( $actor && $actor->ID > 0
            && is_email( $actor->user_email )
            && '1' === (string) get_user_meta( $actor->ID, 'scriptomatic_notifications', true )
        ) {
            $recipients[ $actor->user_email ] = $actor->display_name ?: $actor->user_login;
        }

        // Site admin (only if distinct address from the actor).
        $admin_email = (string) get_option( 'admin_email', '' );
        if ( is_email( $admin_email ) && ! isset( $recipients[ $admin_email ] ) ) {
            $admin_user = get_user_by( 'email', $admin_email );
            if ( $admin_user
                && '1' === (string) get_user_meta( $admin_user->ID, 'scriptomatic_notifications', true )
            ) {
                $recipients[ $admin_email ] = $admin_user->display_name ?: $admin_user->user_login;
            }
        }

        if ( empty( $recipients ) ) {
            return;
        }

        $site_name   = get_bloginfo( 'name' );
        $actor_login = ( $actor && $actor->ID > 0 ) ? $actor->user_login : __( '(unknown)', 'scriptomatic' );
        $ts          = date_i18n(
            get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
            time()
        );

        /* translators: 1: action label 2: site name */
        $subject = sprintf( __( '[Scriptomatic] %1$s on %2$s', 'scriptomatic' ), $action, $site_name );

        /* Compose plain-text body. */
        $body  = sprintf(
            /* translators: %s: site name */
            __( 'A Scriptomatic content change was made on %s.', 'scriptomatic' ),
            $site_name
        ) . "\n\n";
        /* translators: %s: action label */
        $body .= sprintf( __( 'Action:   %s', 'scriptomatic' ), $action )   . "\n";
        /* translators: %s: location identifier */
        $body .= sprintf( __( 'Location: %s', 'scriptomatic' ), $location ) . "\n";
        if ( '' !== $detail ) {
            /* translators: %s: detail string */
            $body .= sprintf( __( 'Details:  %s', 'scriptomatic' ), $detail ) . "\n";
        }
        /* translators: %s: username */
        $body .= sprintf( __( 'User:     %s', 'scriptomatic' ), $actor_login ) . "\n";
        /* translators: %s: formatted date/time */
        $body .= sprintf( __( 'Time:     %s', 'scriptomatic' ), $ts ) . "\n\n";
        // Resolve a location-aware admin URL so the link goes straight to the
        // relevant editor page rather than always the Head page.
        $loc_lower  = strtolower( $location );
        $manage_url = admin_url( 'admin.php?page=scriptomatic' );
        if ( 'footer' === $loc_lower ) {
            $manage_url = admin_url( 'admin.php?page=scriptomatic-footer' );
        } elseif ( 'head' !== $loc_lower ) {
            $manage_url = admin_url( 'admin.php?page=scriptomatic-files' );
        }
        if ( ! empty( $event['page_url'] ) ) {
            $manage_url = esc_url_raw( (string) $event['page_url'] );
        }
        /* translators: %s: admin URL */
        $body .= sprintf( __( 'Manage scripts: %s', 'scriptomatic' ), $manage_url ) . "\n\n";
        /* translators: %s: profile page URL */
        $body .= sprintf( __( 'To stop receiving these emails, visit your profile: %s', 'scriptomatic' ), admin_url( 'profile.php' ) ) . "\n";

        foreach ( $recipients as $email => $name ) {
            wp_mail( $email, $subject, $body );
        }
    }

    // =========================================================================
    // PREFERENCES ACTION HISTORY — RENDER + AJAX
    // =========================================================================

    /**
     * Render the read-only Preferences Action History section.
     *
     * Reads from `wp_scriptomatic_prefs_log` — records of changes made to the
     * Preferences page only, not script or file changes. Hard-capped at the
     * 100 most recent rows; paginated 20 per page.
     *
     * @since  3.1.0
     * @since  3.2.0 Reads from dedicated prefs log table.
     * @return void
     */
    public function render_pref_history_section() {
        $per_page = 20;
        $cap      = 100;
        $log      = $this->get_prefs_log( $per_page, 0 );

        global $wpdb;
        $table       = $wpdb->prefix . SCRIPTOMATIC_PREFS_LOG_TABLE;
        $total_count = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ( SELECT id FROM %i ORDER BY id DESC LIMIT %d ) AS t',
            $table,
            $cap
        ) );
        $total_pages = max( 1, (int) ceil( $total_count / $per_page ) );
        ?>
        <div id="sm-pref-history-section" class="sm-pref-history-section" data-total-pages="<?php echo absint( $total_pages ); ?>">
        <hr style="margin:30px 0;">
        <h2 style="margin-top:12px;">
            <span class="dashicons dashicons-list-view" style="font-size:24px;width:24px;height:24px;margin-right:4px;vertical-align:middle;"></span>
            <?php esc_html_e( 'Preferences Change History', 'scriptomatic' ); ?>
        </h2>
        <p class="description">
            <?php
            printf(
                /* translators: %d: maximum preferences history entries retained */
                esc_html__( 'A read-only record of the last %d changes made to this Preferences page, newest first. Paginated 20 per page. This log cannot be cleared manually — oldest entries are pruned automatically when the 100-entry cap is reached.', 'scriptomatic' ),
                absint( $cap )
            );
            ?>
        </p>

        <table class="widefat scriptomatic-history-table sm-pref-history-table" style="max-width:960px;margin-top:8px;">
            <thead>
                <tr>
                    <th style="width:18%;"><?php esc_html_e( 'Date / Time', 'scriptomatic' ); ?></th>
                    <th style="width:14%;"><?php esc_html_e( 'User', 'scriptomatic' ); ?></th>
                    <th><?php esc_html_e( 'Changes', 'scriptomatic' ); ?></th>
                </tr>
            </thead>
            <tbody id="sm-pref-history-tbody">
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $this->render_pref_history_rows( $log );
                ?>
            </tbody>
        </table>

        <div class="sm-pref-history-pagination" style="margin-top:12px;display:flex;align-items:center;gap:12px;<?php echo $total_pages <= 1 ? 'visibility:hidden;' : ''; ?>">
            <button type="button" class="button sm-pref-history-prev" disabled>
                <?php esc_html_e( '&laquo; Previous', 'scriptomatic' ); ?>
            </button>
            <span class="sm-pref-history-page-info description">
                <?php
                printf(
                    /* translators: 1: current page number markup, 2: total pages markup */
                    esc_html__( 'Page %1$s of %2$s', 'scriptomatic' ),
                    '<span class="sm-pref-history-current-page">1</span>',
                    '<span class="sm-pref-history-total-pages">' . absint( $total_pages ) . '</span>'
                );
                ?>
            </span>
            <button type="button" class="button sm-pref-history-next"
                <?php disabled( $total_pages <= 1 ); ?>>
                <?php esc_html_e( 'Next &raquo;', 'scriptomatic' ); ?>
            </button>
            <span class="sm-pref-history-loading description" style="display:none;">
                <?php esc_html_e( 'Loading&hellip;', 'scriptomatic' ); ?>
            </span>
        </div>
        <input type="hidden" id="sm-pref-history-nonce"
            value="<?php echo esc_attr( wp_create_nonce( SCRIPTOMATIC_GENERAL_NONCE ) ); ?>">
        </div><!-- #sm-pref-history-section -->
        <?php
    }

    /**
     * Build the <tr> rows HTML string for one page of Preferences history entries.
     *
     * Columns: Date/Time · User · Changes.
     * Each change is listed as "Setting: old → new". When the full detail string
     * is too wide to display inline the raw string is shown in a title attribute.
     *
     * All output is safe to echo directly.
     *
     * @since  3.1.0
     * @since  3.2.0 Updated for dedicated prefs log schema.
     * @access private
     * @param  array $log  Row arrays from get_prefs_log().
     * @return string  HTML ready for echo.
     */
    private function render_pref_history_rows( array $log ) {
        if ( empty( $log ) ) {
            return '<tr><td colspan="3">' . esc_html__( 'No preferences changes recorded yet.', 'scriptomatic' ) . '</td></tr>';
        }

        $date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        $html     = '';

        foreach ( $log as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $ts     = isset( $entry['timestamp'] )  ? (int) $entry['timestamp']     : 0;
            $ulogin = isset( $entry['user_login'] ) ? (string) $entry['user_login'] : '';
            $uid    = isset( $entry['user_id'] )    ? (int) $entry['user_id']        : 0;
            $detail = isset( $entry['detail'] )     ? (string) $entry['detail']      : '';

            // Build individual change pills from the structured changes array.
            $changes      = isset( $entry['changes'] ) && is_array( $entry['changes'] ) ? $entry['changes'] : array();
            $change_parts = array();
            foreach ( $changes as $field => $diff ) {
                if ( ! is_array( $diff ) || ! isset( $diff['old'], $diff['new'] ) ) {
                    continue;
                }
                /* translators: 1: setting label, 2: old value, 3: new value */
                $change_parts[] = sprintf(
                    __( '%1$s: %2$s &rarr; %3$s', 'scriptomatic' ),
                    esc_html( ucwords( str_replace( '_', ' ', $field ) ) ),
                    '<strong>' . esc_html( $diff['old'] ) . '</strong>',
                    '<strong>' . esc_html( $diff['new'] ) . '</strong>'
                );
            }

            $html .= '<tr>';
            $html .= '<td>' . esc_html( $ts ? date_i18n( $date_fmt, $ts ) : '—' ) . '</td>';
            $html .= '<td>' . esc_html( $ulogin ) . ( $uid ? ' <span class="description">(ID:&nbsp;' . absint( $uid ) . ')</span>' : '' ) . '</td>';
            $html .= '<td>';
            if ( ! empty( $change_parts ) ) {
                $html .= implode( '<br>', $change_parts );
            } elseif ( '' !== $detail ) {
                // Fallback: plain detail string with tooltip for long values.
                $display = strlen( $detail ) > 80
                    ? substr( $detail, 0, 77 ) . "\xe2\x80\xa6"
                    : $detail;
                $html .= '<span title="' . esc_attr( $detail ) . '">' . esc_html( $display ) . '</span>';
            } else {
                $html .= '&mdash;';
            }
            $html .= '</td>';
            $html .= '</tr>';
        }

        return $html;
    }

    /**
     * AJAX handler — return one page of Preferences change history rows as HTML.
     *
     * Expects POST fields: `nonce` (SCRIPTOMATIC_GENERAL_NONCE), `page` (1-based int).
     *
     * @since  3.1.0
     * @since  3.2.0 Reads from dedicated prefs log table.
     * @return void  Sends a JSON response and exits.
     */
    public function ajax_pref_history() {
        check_ajax_referer( SCRIPTOMATIC_GENERAL_NONCE, 'nonce' );

        if ( ! current_user_can( $this->get_required_cap() ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'scriptomatic' ) ) );
        }

        $per_page = 20;
        $cap      = 100;
        $page     = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
        $offset   = ( $page - 1 ) * $per_page;

        // Clamp offset to cap so we never read beyond row 100.
        if ( $offset >= $cap ) {
            wp_send_json_success( array(
                'rows'        => '',
                'total_pages' => (int) ceil( $cap / $per_page ),
                'page'        => $page,
            ) );
            return;
        }

        $limit = min( $per_page, $cap - $offset );
        $log   = $this->get_prefs_log( $limit, $offset );

        global $wpdb;
        $table       = $wpdb->prefix . SCRIPTOMATIC_PREFS_LOG_TABLE;
        $total_count = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ( SELECT id FROM %i ORDER BY id DESC LIMIT %d ) AS t',
            $table,
            $cap
        ) );
        $total_pages = max( 1, (int) ceil( $total_count / $per_page ) );

        wp_send_json_success( array(
            'rows'        => $this->render_pref_history_rows( $log ),
            'total_pages' => $total_pages,
            'page'        => $page,
        ) );
    }
}
