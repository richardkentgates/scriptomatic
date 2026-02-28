<?php
/**
 * Trait: Revision history management for Scriptomatic.
 *
 * Provides push/get/rollback of per-location script revision history,
 * stored as serialised arrays in `wp_options`.
 *
 * @package  Scriptomatic
 * @since    1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * History storage and AJAX rollback handler.
 */
trait Scriptomatic_History {

    /**
     * Push a script snapshot onto the revision history for a location.
     *
     * Deduplicates sequential identical entries and caps the history to the
     * configured maximum via {@see get_max_history()}.
     *
     * @since  1.2.0
     * @access private
     * @param  string $content  Script content to record.
     * @param  string $location `'head'` or `'footer'`.
     * @return void
     */
    /**
     * Retrieve content-bearing revision entries for the given location.
     *
     * Filters the unified activity log to entries that carry a content snapshot
     * (action in 'save'|'rollback') for the specified location.
     *
     * @since  1.2.0
     * @since  1.9.0 Now reads from the unified activity log instead of a
     *               per-location wp_options entry.
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return array  Re-indexed array; each element has at least 'content', 'timestamp', 'user_login'.
     */
    private function get_history( $location = 'head' ) {
        $log = $this->get_activity_log();
        return array_values(
            array_filter( $log, function ( $e ) use ( $location ) {
                return isset( $e['location'] ) && $e['location'] === $location
                    && array_key_exists( 'content', $e )
                    && isset( $e['action'] ) && in_array( $e['action'], array( 'save', 'rollback' ), true );
            } )
        );
    }

    /**
     * Handle the AJAX rollback request for either head or footer scripts.
     *
     * In v2.5.0 the restore scope was expanded: when the activity-log entry
     * carries `urls_snapshot` and/or `conditions_snapshot` (all combined save
     * entries do), those fields are also restored atomically in the same
     * request. Pre-v2.5.0 entries that carry only `content` are restored as
     * before, script-content only.
     *
     * Expects POST fields: `nonce`, `index` (int), `location` ('head'|'footer').
     *
     * @since  1.2.0
     * @since  2.5.0 Restores URLs and conditions snapshots when present.
     * @return void  Sends a JSON response and exits.
     */
    public function ajax_rollback() {
        check_ajax_referer( SCRIPTOMATIC_ROLLBACK_NONCE, 'nonce' );

        if ( ! current_user_can( $this->get_required_cap() ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'scriptomatic' ) ) );
        }

        $location   = isset( $_POST['location'] ) && 'footer' === $_POST['location'] ? 'footer' : 'head';
        $option_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_SCRIPT : SCRIPTOMATIC_HEAD_SCRIPT;
        $index      = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : PHP_INT_MAX;
        $history    = $this->get_history( $location );

        if ( ! array_key_exists( $index, $history ) ) {
            wp_send_json_error( array( 'message' => __( 'History entry not found.', 'scriptomatic' ) ) );
        }

        $entry   = $history[ $index ];
        $content = $entry['content'];

        // Write directly using $wpdb to bypass the registered sanitize_callback.
        // update_option() runs our sanitize_head/footer_script callback which
        // performs nonce + capability checks that have no $‌_POST data in this
        // AJAX context and would silently return the old (empty) value.
        // The content is already validated — it came from our own stored history.
        global $wpdb;
        $wpdb->update(
            $wpdb->options,
            array( 'option_value' => $content ),
            array( 'option_name'  => $option_key ),
            array( '%s' ),
            array( '%s' )
        );
        wp_cache_delete( $option_key, 'options' );

        // Restore the URL list snapshot if the entry carries one (combined save
        // entries from v2.5.0+ always include all three field snapshots).
        if ( array_key_exists( 'urls_snapshot', $entry ) && null !== $entry['urls_snapshot'] ) {
            $linked_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_LINKED : SCRIPTOMATIC_HEAD_LINKED;
            $wpdb->update(
                $wpdb->options,
                array( 'option_value' => $entry['urls_snapshot'] ),
                array( 'option_name'  => $linked_key ),
                array( '%s' ),
                array( '%s' )
            );
            wp_cache_delete( $linked_key, 'options' );
        }

        // Restore the conditions snapshot if the entry carries one.
        if ( array_key_exists( 'conditions_snapshot', $entry ) && null !== $entry['conditions_snapshot'] ) {
            $cond_key = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_CONDITIONS : SCRIPTOMATIC_HEAD_CONDITIONS;
            $wpdb->update(
                $wpdb->options,
                array( 'option_value' => $entry['conditions_snapshot'] ),
                array( 'option_name'  => $cond_key ),
                array( '%s' ),
                array( '%s' )
            );
            wp_cache_delete( $cond_key, 'options' );
        }

        $rollback_entry = array(
            'action'   => 'rollback',
            'location' => $location,
            'content'  => $content,
            'chars'    => strlen( $content ),
            'detail'   => __( 'Restored from snapshot', 'scriptomatic' ),
        );
        if ( array_key_exists( 'urls_snapshot', $entry ) ) {
            $rollback_entry['urls_snapshot'] = $entry['urls_snapshot'];
        }
        if ( array_key_exists( 'conditions_snapshot', $entry ) ) {
            $rollback_entry['conditions_snapshot'] = $entry['conditions_snapshot'];
        }
        $this->write_activity_entry( $rollback_entry );

        wp_send_json_success( array(
            'content'  => $content,
            'length'   => strlen( $content ),
            'location' => $location,
            'message'  => __( 'Script restored successfully.', 'scriptomatic' ),
        ) );
    }

    /**
     * AJAX handler — return the raw content of a single history entry.
     *
     * Expects POST fields: `nonce`, `index` (int), `location` ('head'|'footer').
     *
     * @since  1.7.1
     * @return void  Sends a JSON response and exits.
     */
    public function ajax_get_history_content() {
        check_ajax_referer( SCRIPTOMATIC_ROLLBACK_NONCE, 'nonce' );

        if ( ! current_user_can( $this->get_required_cap() ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'scriptomatic' ) ) );
        }

        $location = isset( $_POST['location'] ) && 'footer' === $_POST['location'] ? 'footer' : 'head';
        $index    = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : PHP_INT_MAX;
        $history  = $this->get_history( $location );

        if ( ! array_key_exists( $index, $history ) ) {
            wp_send_json_error( array( 'message' => __( 'History entry not found.', 'scriptomatic' ) ) );
        }

        $entry = $history[ $index ];

        wp_send_json_success( array(
            'content' => $entry['content'],
            'display' => $this->format_entry_display( $entry ),
        ) );
    }

    /**
     * AJAX handler — restore a JS file from a saved activity-log snapshot.
     *
     * Expects POST fields: `nonce`, `file_id`, `index` (0-based position within
     * the file's content-bearing history entries).
     *
     * @since  1.9.0
     * @return void  Sends a JSON response and exits.
     */
    public function ajax_rollback_js_file() {
        check_ajax_referer( SCRIPTOMATIC_FILES_NONCE, 'nonce' );

        if ( ! current_user_can( $this->get_required_cap() ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'scriptomatic' ) ) );
        }

        $file_id = isset( $_POST['file_id'] ) ? sanitize_key( wp_unslash( $_POST['file_id'] ) ) : '';
        $index   = isset( $_POST['index'] )   ? absint( $_POST['index'] )                        : PHP_INT_MAX;

        // Build content-bearing file history.
        $file_history = array_values(
            array_filter( $this->get_activity_log(), function ( $e ) use ( $file_id ) {
                return isset( $e['file_id'] ) && $e['file_id'] === $file_id
                    && array_key_exists( 'content', $e )
                    && isset( $e['action'] ) && in_array( $e['action'], array( 'file_save', 'file_rollback' ), true );
            } )
        );

        if ( ! array_key_exists( $index, $file_history ) ) {
            wp_send_json_error( array( 'message' => __( 'History entry not found.', 'scriptomatic' ) ) );
        }

        $entry   = $file_history[ $index ];
        $content = $entry['content'];

        // Look up the file metadata.
        $file_meta = null;
        foreach ( $this->get_js_files_meta() as $f ) {
            if ( $f['id'] === $file_id ) {
                $file_meta = $f;
                break;
            }
        }

        if ( ! $file_meta ) {
            wp_send_json_error( array( 'message' => __( 'File not found.', 'scriptomatic' ) ) );
        }

        $path = $this->get_js_files_dir() . $file_meta['filename'];
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( false === file_put_contents( $path, $content ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not write file.', 'scriptomatic' ) ) );
        }

        // Also restore the conditions that were saved with this snapshot.
        if ( isset( $entry['conditions'] ) && is_array( $entry['conditions'] ) ) {
            $updated_meta = $file_meta;
            $updated_meta['conditions'] = $entry['conditions'];
            $all_files = $this->get_js_files_meta();
            foreach ( $all_files as $fi => $fm ) {
                if ( $fm['id'] === $file_id ) {
                    $all_files[ $fi ] = $updated_meta;
                    break;
                }
            }
            $this->save_js_files_meta( $all_files );
        }

        $this->write_activity_entry( array(
            'action'     => 'file_rollback',
            'location'   => 'file',
            'file_id'    => $file_id,
            'content'    => $content,
            'chars'      => strlen( $content ),
            'detail'     => $file_meta['label'],
            'conditions' => isset( $entry['conditions'] ) ? $entry['conditions'] : null,
            'meta'       => isset( $entry['meta'] )       ? $entry['meta']       : null,
        ) );

        wp_send_json_success( array(
            'content' => $content,
            'message' => __( 'File restored successfully.', 'scriptomatic' ),
        ) );
    }

    /**
     * AJAX handler — return the raw content of a JS-file activity-log entry.
     *
     * Expects POST fields: `nonce`, `file_id`, `index`.
     *
     * @since  1.9.0
     * @return void  Sends a JSON response and exits.
     */
    public function ajax_get_file_activity_content() {
        check_ajax_referer( SCRIPTOMATIC_FILES_NONCE, 'nonce' );

        if ( ! current_user_can( $this->get_required_cap() ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'scriptomatic' ) ) );
        }

        $file_id   = isset( $_POST['file_id'] )   ? sanitize_key( wp_unslash( $_POST['file_id'] ) ) : '';
        $index     = isset( $_POST['index'] )       ? absint( $_POST['index'] )                        : PHP_INT_MAX;
        $is_delete = ! empty( $_POST['is_delete'] );

        $filter_actions = $is_delete ? array( 'file_delete' ) : array( 'file_save', 'file_rollback' );

        $file_history = array_values(
            array_filter( $this->get_activity_log(), function ( $e ) use ( $file_id, $filter_actions ) {
                return isset( $e['file_id'] ) && $e['file_id'] === $file_id
                    && array_key_exists( 'content', $e )
                    && isset( $e['action'] ) && in_array( $e['action'], $filter_actions, true );
            } )
        );

        if ( ! array_key_exists( $index, $file_history ) ) {
            wp_send_json_error( array( 'message' => __( 'History entry not found.', 'scriptomatic' ) ) );
        }

        $entry = $file_history[ $index ];

        wp_send_json_success( array(
            'content' => $entry['content'],
            'display' => $this->format_entry_display( $entry ),
        ) );
    }

    // =========================================================================
    // DISPLAY HELPERS
    // =========================================================================

    /**
     * Build a human-readable plaintext string for a conditions array.
     *
     * Accepts a {logic,rules} format conditions array.
     *
     * @since  1.0.0
     * @access private
     * @param  array|string $cond Decoded conditions array or JSON string.
     * @return string
     */
    private function format_conditions_display( $cond ) {
        if ( is_string( $cond ) ) {
            $cond = json_decode( $cond, true );
        }
        if ( ! is_array( $cond ) ) {
            return 'All pages (no conditions)';
        }

        $type_labels = array(
            'front_page'   => 'Front page only',
            'singular'     => 'Any singular post/page',
            'post_type'    => 'Specific post types',
            'page_id'      => 'Specific page IDs',
            'url_contains' => 'URL contains',
            'logged_in'    => 'Logged-in users only',
            'logged_out'   => 'Logged-out visitors only',
            'by_date'      => 'Date range',
            'by_datetime'  => 'Date & time range',
            'week_number'  => 'Specific week numbers',
            'by_month'     => 'Specific months',
        );

        if ( isset( $cond['rules'] ) && is_array( $cond['rules'] ) ) {
            $rules = $cond['rules'];
            $logic = ( isset( $cond['logic'] ) && 'or' === $cond['logic'] ) ? 'OR' : 'AND';
        } else {
            return 'All pages (no conditions)';
        }

        if ( empty( $rules ) ) {
            return 'All pages (no conditions)';
        }

        $lines = array( 'Match: ' . $logic . ' of:' );
        foreach ( $rules as $idx => $rule ) {
            $t    = isset( $rule['type'] ) ? $rule['type'] : '';
            $lbl  = isset( $type_labels[ $t ] ) ? $type_labels[ $t ] : $t;
            $vals = isset( $rule['values'] ) && is_array( $rule['values'] ) ? $rule['values'] : array();
            $vstr = ! empty( $vals ) ? ' → ' . implode( ', ', array_map( 'strval', $vals ) ) : '';
            $lines[] = '  Rule ' . ( $idx + 1 ) . ': ' . $lbl . $vstr;
        }
        return implode( "
", $lines );
    }

    /**
     * Build a human-readable plaintext string for a URL list snapshot.
     *
     * @since  1.12.0
     * @access private
     * @param  string|array $urls_json JSON string or already-decoded array.
     * @return string
     */
    private function format_url_list_display( $urls_json ) {
        $list = is_array( $urls_json ) ? $urls_json : json_decode( $urls_json, true );
        if ( ! is_array( $list ) || empty( $list ) ) {
            return '(no URLs)';
        }
        $lines = array();
        foreach ( $list as $i => $entry ) {
            $url  = is_array( $entry ) && isset( $entry['url'] ) ? $entry['url'] : '';
            $cond = is_array( $entry ) && isset( $entry['conditions'] ) && is_array( $entry['conditions'] ) ? $entry['conditions'] : array();
            $lines[] = ( $i + 1 ) . '. ' . $url;
            $lines[] = '   Conditions: ' . $this->format_conditions_display( $cond );
        }
        return implode( "
", $lines );
    }

    /**
     * Compose a complete lightbox display string for any activity-log entry.
     *
     * Includes code, URL list, conditions, and file metadata depending on
     * what keys are present in the entry.
     *
     * @since  1.12.0
     * @access private
     * @param  array $entry Activity log entry.
     * @return string
     */
    private function format_entry_display( array $entry ) {
        $parts  = array();
        $action = isset( $entry['action'] ) ? $entry['action'] : '';

        // === File Info ===
        if ( array_key_exists( 'meta', $entry ) && is_array( $entry['meta'] ) ) {
            $meta  = $entry['meta'];
            $lines = array( '=== File Info ===' );
            if ( ! empty( $meta['label'] ) )    { $lines[] = 'Label:    ' . $meta['label']; }
            if ( ! empty( $meta['filename'] ) ) { $lines[] = 'Filename: ' . $meta['filename']; }
            if ( ! empty( $meta['location'] ) ) { $lines[] = 'Location: ' . ucfirst( $meta['location'] ); }
            $parts[] = implode( "
", $lines );
        }

        // === Script / File Content ===
        if ( array_key_exists( 'content', $entry ) ) {
            $code_title = in_array( $action, array( 'file_save', 'file_rollback', 'file_delete' ), true )
                ? '=== File Content ===' : '=== Script Code ===';
            $code_body  = '' !== $entry['content'] ? $entry['content'] : '(empty)';
            $parts[]    = $code_title . "
" . $code_body;
        }

        // === External Script URLs ===
        if ( array_key_exists( 'urls_snapshot', $entry ) && null !== $entry['urls_snapshot'] ) {
            $parts[] = '=== External Script URLs ===' . "
"
                . $this->format_url_list_display( $entry['urls_snapshot'] );
        }

        // === Inline Script / File Load Conditions ===
        if ( array_key_exists( 'conditions_snapshot', $entry ) && null !== $entry['conditions_snapshot'] ) {
            $parts[] = '=== Inline Script Load Conditions ===' . "
"
                . $this->format_conditions_display( $entry['conditions_snapshot'] );
        } elseif ( array_key_exists( 'conditions', $entry ) && is_array( $entry['conditions'] ) ) {
            $parts[] = '=== File Load Conditions ===' . "
"
                . $this->format_conditions_display( $entry['conditions'] );
        }

        return implode( "

", $parts );
    }

    /**
     * Filter activity log to file_delete entries that have a content snapshot.
     *
     * @since  1.12.0
     * @access private
     * @return array
     */
    private function get_file_delete_entries() {
        return array_values( array_filter( $this->get_activity_log(), function ( $e ) {
            return 'file' === ( isset( $e['location'] ) ? $e['location'] : '' )
                && 'file_delete' === ( isset( $e['action'] ) ? $e['action'] : '' )
                && array_key_exists( 'content', $e );
        } ) );
    }

    // =========================================================================
    // FILE RESTORE AJAX
    // =========================================================================

    /**
     * AJAX handler — re-create a JS file from a file_delete activity-log entry.
     *
     * Writes the file back to disk and re-inserts the metadata stored in the
     * snapshot taken at the time of deletion.
     *
     * Expects POST fields: `nonce`, `index` (int).
     *
     * @since  1.12.0
     * @return void
     */
    public function ajax_restore_deleted_file() {
        check_ajax_referer( SCRIPTOMATIC_FILES_NONCE, 'nonce' );

        if ( ! current_user_can( $this->get_required_cap() ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'scriptomatic' ) ) );
        }

        $index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : PHP_INT_MAX;
        $entries = $this->get_file_delete_entries();

        if ( ! array_key_exists( $index, $entries ) ) {
            wp_send_json_error( array( 'message' => __( 'Entry not found.', 'scriptomatic' ) ) );
        }

        $entry    = $entries[ $index ];
        $content  = isset( $entry['content'] )    ? (string) $entry['content']    : '';
        $cond     = isset( $entry['conditions'] ) && is_array( $entry['conditions'] ) ? $entry['conditions'] : array();
        $meta     = isset( $entry['meta'] )       && is_array( $entry['meta'] )       ? $entry['meta']       : array();
        $file_id  = isset( $entry['file_id'] )    ? (string) $entry['file_id']    : '';

        $label    = isset( $meta['label'] )    ? (string) $meta['label']    : $file_id;
        $filename = isset( $meta['filename'] ) ? (string) $meta['filename'] : $file_id . '.js';
        $location = isset( $meta['location'] ) ? (string) $meta['location'] : 'head';

        if ( '' === $filename || '' === $file_id ) {
            wp_send_json_error( array( 'message' => __( 'Snapshot is missing file metadata.', 'scriptomatic' ) ) );
        }

        $dir  = $this->get_js_files_dir();
        $path = $dir . $filename;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( false === file_put_contents( $path, $content ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not write file to disk.', 'scriptomatic' ) ) );
        }

        // Re-insert metadata if the file ID is not already present.
        $files  = $this->get_js_files_meta();
        $exists = false;
        foreach ( $files as $f ) {
            if ( $f['id'] === $file_id ) { $exists = true; break; }
        }

        if ( ! $exists ) {
            $files[] = array(
                'id'         => $file_id,
                'label'      => $label,
                'filename'   => $filename,
                'location'   => $location,
                'conditions' => $cond,
            );
            $this->save_js_files_meta( $files );
        }

        $this->write_activity_entry( array(
            'action'   => 'file_restored',
            'location' => 'file',
            'file_id'  => $file_id,
            'detail'   => $label,
        ) );

        wp_send_json_success( array(
            'message'  => __( 'File restored successfully.', 'scriptomatic' ),
            'filename' => $filename,
            'label'    => $label,
        ) );
    }
}
