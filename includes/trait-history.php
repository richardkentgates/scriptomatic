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
     * Expects POST fields: `nonce`, `index` (int), `location` ('head'|'footer').
     *
     * @since  1.2.0
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
        $this->write_activity_entry( array(
            'action'   => 'rollback',
            'location' => $location,
            'content'  => $content,
            'chars'    => strlen( $content ),
        ) );

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

        wp_send_json_success( array(
            'content' => $history[ $index ]['content'],
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

        $this->write_activity_entry( array(
            'action'   => 'file_rollback',
            'location' => 'file',
            'file_id'  => $file_id,
            'content'  => $content,
            'chars'    => strlen( $content ),
            'detail'   => $file_meta['label'],
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

        $file_id = isset( $_POST['file_id'] ) ? sanitize_key( wp_unslash( $_POST['file_id'] ) ) : '';
        $index   = isset( $_POST['index'] )   ? absint( $_POST['index'] )                        : PHP_INT_MAX;

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

        wp_send_json_success( array(
            'content' => $file_history[ $index ]['content'],
        ) );
    }
}
