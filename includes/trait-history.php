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
    private function push_history( $content, $location = 'head' ) {
        $option  = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_HISTORY : SCRIPTOMATIC_HEAD_HISTORY;
        $history = $this->get_history( $location );
        $max     = $this->get_max_history();
        $user    = wp_get_current_user();

        if ( ! empty( $history ) && isset( $history[0]['content'] ) && $history[0]['content'] === $content ) {
            return;
        }

        array_unshift( $history, array(
            'content'    => $content,
            'timestamp'  => time(),
            'user_login' => $user->user_login,
            'user_id'    => (int) $user->ID,
            'length'     => strlen( $content ),
        ) );

        if ( count( $history ) > $max ) {
            $history = array_slice( $history, 0, $max );
        }

        update_option( $option, $history );
    }

    /**
     * Retrieve the stored revision history for the given location.
     *
     * @since  1.2.0
     * @access private
     * @param  string $location `'head'` or `'footer'`.
     * @return array
     */
    private function get_history( $location = 'head' ) {
        $option  = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_HISTORY : SCRIPTOMATIC_HEAD_HISTORY;
        $history = get_option( $option, array() );
        return is_array( $history ) ? $history : array();
    }

    /**
     * Return the configured maximum number of history entries to retain.
     *
     * @since  1.1.0
     * @access private
     * @return int
     */
    private function get_max_history() {
        $settings = $this->get_plugin_settings();
        return isset( $settings['max_history'] ) ? (int) $settings['max_history'] : SCRIPTOMATIC_DEFAULT_MAX_HISTORY;
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

        update_option( $option_key, $content );
        $this->push_history( $content, $location );

        $user = wp_get_current_user();
        error_log( sprintf(
            'Scriptomatic: %s script rolled back to revision from %s by user %s (ID: %d)',
            ucfirst( $location ),
            gmdate( 'Y-m-d H:i:s', $entry['timestamp'] ),
            $user->user_login,
            $user->ID
        ) );

        wp_send_json_success( array(
            'content'  => $content,
            'length'   => strlen( $content ),
            'location' => $location,
            'message'  => __( 'Script restored successfully.', 'scriptomatic' ),
        ) );
    }
}
