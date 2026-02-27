<?php
/**
 * Trait: Managed JS File operations for Scriptomatic.
 *
 * Handles creation, editing, deletion, and disk I/O for JS files stored in
 * the WordPress uploads directory under `uploads/scriptomatic/`.
 *
 * Metadata for all managed files is stored as a JSON array in the
 * SCRIPTOMATIC_JS_FILES_OPTION database option. Each entry:
 *
 *   {
 *     "id":         "my-tracker",
 *     "label":      "My Tracker",
 *     "filename":   "my-tracker.js",
 *     "location":   "head",
 *     "conditions": { "type": "all", "values": [] }
 *   }
 *
 * @package  Scriptomatic
 * @since    1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Managed JS File CRUD and admin-post / AJAX handlers.
 */
trait Scriptomatic_Files {

    // =========================================================================
    // UPLOADS DIRECTORY
    // =========================================================================

    /**
     * Return the absolute path to the scriptomatic uploads directory.
     *
     * Creates the directory and drops an index.php access guard on first call.
     *
     * @since  1.8.0
     * @return string  Absolute path with trailing slash.
     */
    private function get_js_files_dir() {
        $upload = wp_upload_dir();
        $dir    = trailingslashit( $upload['basedir'] ) . 'scriptomatic/';

        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        // Drop an index.php guard to block directory listing.
        $guard = $dir . 'index.php';
        if ( ! file_exists( $guard ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents(
                $guard,
                "<?php\n// Silence is golden.\nheader( 'HTTP/1.1 403 Forbidden' );\nexit;\n"
            );
        }

        return $dir;
    }

    /**
     * Return the public URL to the scriptomatic uploads directory.
     *
     * @since  1.8.0
     * @return string  URL with trailing slash.
     */
    private function get_js_files_url() {
        $upload = wp_upload_dir();
        return trailingslashit( $upload['baseurl'] ) . 'scriptomatic/';
    }

    // =========================================================================
    // METADATA CRUD
    // =========================================================================

    /**
     * Return the current JS files metadata array from the database.
     *
     * @since  1.8.0
     * @return array[]  Each element is an associative array with keys:
     *                  id, label, filename, location, conditions.
     */
    public function get_js_files_meta() {
        $raw = get_option( SCRIPTOMATIC_JS_FILES_OPTION, '[]' );
        $arr = json_decode( $raw, true );
        return is_array( $arr ) ? $arr : array();
    }

    /**
     * Persist the JS files metadata array to the database.
     *
     * @since  1.8.0
     * @param  array[] $files  Metadata array.
     * @return void
     */
    private function save_js_files_meta( array $files ) {
        update_option( SCRIPTOMATIC_JS_FILES_OPTION, wp_json_encode( array_values( $files ) ) );
    }

    // =========================================================================
    // ADMIN-POST SAVE HANDLER
    // =========================================================================

    /**
     * Handle form POSTs from the JS File edit view (both new and edit).
     *
     * Registered on `admin_post_scriptomatic_save_js_file`.
     * Validates capability + nonce, writes file to disk, updates metadata,
     * then redirects.
     *
     * @since  1.8.0
     * @return void
     */
    public function handle_save_js_file() {
        // Gate 0: capability.
        if ( ! current_user_can( $this->get_required_cap() ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'scriptomatic' ) );
        }

        // Gate 1: nonce.
        $nonce = isset( $_POST['_sm_files_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['_sm_files_nonce'] ) )
            : '';
        if ( ! wp_verify_nonce( $nonce, SCRIPTOMATIC_FILES_NONCE ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'scriptomatic' ) );
        }

        // Read and sanitise POST fields.
        $original_id = isset( $_POST['_sm_file_original_id'] )
            ? sanitize_key( wp_unslash( $_POST['_sm_file_original_id'] ) )
            : '';
        $label       = isset( $_POST['sm_file_label'] )
            ? sanitize_text_field( wp_unslash( $_POST['sm_file_label'] ) )
            : '';
        $filename    = isset( $_POST['sm_file_name'] )
            ? sanitize_file_name( wp_unslash( $_POST['sm_file_name'] ) )
            : '';
        $location    = ( isset( $_POST['sm_file_location'] ) && 'footer' === $_POST['sm_file_location'] )
            ? 'footer'
            : 'head';
        $content     = isset( $_POST['sm_file_content'] )
            ? wp_unslash( $_POST['sm_file_content'] )
            : '';
        $cond_raw    = isset( $_POST['sm_file_conditions'] )
            ? wp_unslash( $_POST['sm_file_conditions'] )
            : '{"type":"all","values":[]}';

        // Validate: label is required.
        if ( '' === $label ) {
            $this->redirect_file_edit( $original_id, 'missing_label' );
            return;
        }

        // Auto-slug the filename from the label if not supplied.
        if ( '' === $filename ) {
            $filename = sanitize_title( $label ) . '.js';
        }

        // Enforce .js extension.
        if ( ! preg_match( '/\.js$/i', $filename ) ) {
            $filename .= '.js';
        }

        // Strip unsafe characters: allow alphanum, dash, underscore, dot only.
        $filename = preg_replace( '/[^a-zA-Z0-9_\-.]/', '-', $filename );
        $filename = ltrim( $filename, '.' ); // No leading dot (hidden files).
        $filename = preg_replace( '/-+/', '-', $filename ); // Collapse dashes.

        if ( '' === $filename || '.' === $filename ) {
            $this->redirect_file_edit( $original_id, 'invalid_filename' );
            return;
        }

        // Enforce max file size: honour the site's upload limit.
        $max_bytes = wp_max_upload_size();
        if ( strlen( $content ) > $max_bytes ) {
            $this->redirect_file_edit( $original_id, 'too_large' );
            return;
        }

        // Parse conditions JSON.
        $conditions = json_decode( $cond_raw, true );
        if ( ! is_array( $conditions ) ) {
            $conditions = array( 'type' => 'all', 'values' => array() );
        }
        $cond_type   = ( isset( $conditions['type'] ) && is_string( $conditions['type'] ) )
            ? sanitize_key( $conditions['type'] )
            : 'all';
        $cond_values = ( isset( $conditions['values'] ) && is_array( $conditions['values'] ) )
            ? array_map( 'sanitize_text_field', $conditions['values'] )
            : array();
        $conditions  = array( 'type' => $cond_type, 'values' => $cond_values );

        $dir   = $this->get_js_files_dir();
        $files = $this->get_js_files_meta();

        // Derive a stable ID from the (possibly sanitised) filename.
        $new_id = sanitize_key( preg_replace( '/\.js$/i', '', $filename ) );
        if ( '' === $new_id ) {
            $new_id = 'file';
        }

        // Detect filename rename on edit: remove the old file from disk.
        if ( '' !== $original_id ) {
            foreach ( $files as $f ) {
                if ( $f['id'] === $original_id && $f['filename'] !== $filename ) {
                    $old_path = $dir . $f['filename'];
                    if ( file_exists( $old_path ) ) {
                        @unlink( $old_path ); // phpcs:ignore
                    }
                    break;
                }
            }
        }

        // New file: ensure the filename is unique on disk.
        if ( '' === $original_id ) {
            $base   = preg_replace( '/\.js$/i', '', $filename );
            $suffix = 1;
            while ( file_exists( $dir . $filename ) ) {
                $filename = $base . '-' . $suffix . '.js';
                $suffix++;
            }
            $new_id = sanitize_key( preg_replace( '/\.js$/i', '', $filename ) );
        }

        // Write the file to disk.
        $file_path = $dir . $filename;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $result = file_put_contents( $file_path, $content );

        if ( false === $result ) {
            $this->redirect_file_edit( $original_id, 'write_failed' );
            return;
        }

        // Build the new metadata entry.
        $entry = array(
            'id'         => $new_id,
            'label'      => $label,
            'filename'   => $filename,
            'location'   => $location,
            'conditions' => $conditions,
        );

        if ( '' !== $original_id ) {
            // Replace existing entry.
            $replaced = false;
            foreach ( $files as $i => $f ) {
                if ( $f['id'] === $original_id ) {
                    $files[ $i ] = $entry;
                    $replaced    = true;
                    break;
                }
            }
            if ( ! $replaced ) {
                $files[] = $entry; // Safety net: add if not found.
            }
        } else {
            $files[] = $entry;
        }

        $this->save_js_files_meta( $files );

        // Activity log — record the save with a full content snapshot.
        $this->write_activity_entry( array(
            'action'   => 'file_save',
            'location' => 'file',
            'file_id'  => $new_id,
            'content'  => $content,
            'chars'    => strlen( $content ),
            'detail'   => $label,
        ) );

        wp_safe_redirect(
            add_query_arg(
                array( 'page' => 'scriptomatic-files', 'saved' => '1' ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Redirect back to the file edit view with an error code.
     *
     * @since  1.8.0
     * @param  string $id          File ID (empty string for a new file).
     * @param  string $error_code  Short slug describing the error.
     * @return void
     */
    private function redirect_file_edit( $id, $error_code ) {
        $args = array(
            'page'   => 'scriptomatic-files',
            'action' => 'edit',
            'error'  => $error_code,
        );
        if ( '' !== $id ) {
            $args['file'] = $id;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    // =========================================================================
    // AJAX DELETE HANDLER
    // =========================================================================

    /**
     * Handle AJAX deletion of a single JS file.
     *
     * Removes the file from disk and from the metadata array.
     *
     * @since  1.8.0
     * @return void
     */
    public function ajax_delete_js_file() {
        // Gate 0: capability.
        if ( ! current_user_can( $this->get_required_cap() ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'scriptomatic' ) ) );
        }

        // Gate 1: nonce.
        $nonce = isset( $_POST['nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) )
            : '';
        if ( ! wp_verify_nonce( $nonce, SCRIPTOMATIC_FILES_NONCE ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'scriptomatic' ) ) );
        }

        $id    = isset( $_POST['file_id'] ) ? sanitize_key( wp_unslash( $_POST['file_id'] ) ) : '';
        $files = $this->get_js_files_meta();
        $found = null;

        foreach ( $files as $i => $f ) {
            if ( $f['id'] === $id ) {
                $found = $f;
                array_splice( $files, $i, 1 );
                break;
            }
        }

        if ( ! $found ) {
            wp_send_json_error( array( 'message' => __( 'File not found.', 'scriptomatic' ) ) );
        }

        // Remove from disk.
        $dir  = $this->get_js_files_dir();
        $path = $dir . $found['filename'];
        if ( file_exists( $path ) ) {
            @unlink( $path ); // phpcs:ignore
        }

        $this->save_js_files_meta( $files );
        $this->write_activity_entry( array(
            'action'   => 'file_delete',
            'location' => 'file',
            'file_id'  => $id,
            'detail'   => $found['label'],
        ) );

        wp_send_json_success( array( 'message' => __( 'File deleted.', 'scriptomatic' ) ) );
    }

}
