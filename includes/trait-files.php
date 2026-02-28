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
 *     "conditions": { "logic": "and", "rules": [] }
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
    // UPLOAD VALIDATION
    // =========================================================================

    /**
     * Validate a .js file submitted via an HTTP file-upload field (from
     * $_FILES or the REST API multipart body).
     *
     * Checks the upload error code, file extension, file size, and MIME type,
     * then returns the raw file contents on success.
     *
     * @since  2.7.0
     * @access private
     * @param  array $file  A single entry from $_FILES.
     * @return string|WP_Error  File contents on success; WP_Error on failure.
     */
    private function validate_js_upload( array $file ) {
        $error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

        if ( UPLOAD_ERR_NO_FILE === $error ) {
            return new WP_Error( 'upload_no_file', __( 'No file was received.', 'scriptomatic' ), array( 'status' => 400 ) );
        }

        if ( UPLOAD_ERR_OK !== $error ) {
            return new WP_Error(
                'upload_error',
                sprintf(
                    /* translators: %d: PHP upload error code */
                    __( 'Upload failed (PHP error %d). Please try again.', 'scriptomatic' ),
                    $error
                ),
                array( 'status' => 400 )
            );
        }

        $original_name = isset( $file['name'] )     ? trim( (string) $file['name'] )     : '';
        $tmp_name      = isset( $file['tmp_name'] ) ? trim( (string) $file['tmp_name'] ) : '';
        $file_size     = isset( $file['size'] )     ? (int) $file['size']                : 0;

        // Must originate from a real HTTP upload (prevents path-traversal attacks).
        // CLI callers pass _cli => true and supply a fully-qualified local path,
        // so the is_uploaded_file() check is intentionally skipped for that path.
        $is_cli = ! empty( $file['_cli'] );
        if ( '' === $tmp_name || ( ! $is_cli && ! @is_uploaded_file( $tmp_name ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            return new WP_Error(
                'upload_error',
                __( 'Invalid upload — did not originate from an HTTP form upload.', 'scriptomatic' ),
                array( 'status' => 400 )
            );
        }

        // Must end in .js.
        if ( ! preg_match( '/\.js$/i', $original_name ) ) {
            return new WP_Error(
                'invalid_type',
                __( 'Only .js files may be uploaded.', 'scriptomatic' ),
                array( 'status' => 400 )
            );
        }

        // Must not exceed the server upload limit.
        $max_bytes = wp_max_upload_size();
        if ( $file_size > $max_bytes || @filesize( $tmp_name ) > $max_bytes ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            return new WP_Error(
                'upload_too_large',
                sprintf(
                    /* translators: %s: human-readable maximum upload size (e.g. "8 MB") */
                    __( 'File exceeds the maximum upload size of %s.', 'scriptomatic' ),
                    size_format( $max_bytes )
                ),
                array( 'status' => 400 )
            );
        }

        // MIME-type check: only JavaScript (and plain text, which some systems
        // report for .js files) is permitted.
        $allowed_types = array(
            'text/javascript',
            'application/javascript',
            'text/x-javascript',
            'application/x-javascript',
            'text/plain', // Reported by some servers for .js files.
        );

        // Prefer finfo for real MIME detection; fall back to browser-supplied type.
        $real_type = '';
        if ( function_exists( 'finfo_open' ) ) {
            $finfo     = finfo_open( FILEINFO_MIME_TYPE );
            $real_type = $finfo ? strtolower( (string) finfo_file( $finfo, $tmp_name ) ) : '';
            if ( $finfo ) { finfo_close( $finfo ); }
        } elseif ( function_exists( 'mime_content_type' ) ) {
            $real_type = strtolower( (string) mime_content_type( $tmp_name ) );
        }

        $browser_type  = isset( $file['type'] ) ? strtolower( trim( preg_replace( '/;.*$/', '', (string) $file['type'] ) ) ) : '';
        $type_to_check = '' !== $real_type ? $real_type : $browser_type;

        if ( '' !== $type_to_check && ! in_array( $type_to_check, $allowed_types, true ) ) {
            return new WP_Error(
                'invalid_type',
                sprintf(
                    /* translators: %s: detected MIME type string */
                    __( 'File type not permitted (%s). Only JavaScript (.js) files are accepted.', 'scriptomatic' ),
                    esc_html( $type_to_check )
                ),
                array( 'status' => 400 )
            );
        }

        // Read and return the file contents.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = file_get_contents( $tmp_name );
        if ( false === $content ) {
            return new WP_Error(
                'upload_error',
                __( 'Could not read the uploaded file.', 'scriptomatic' ),
                array( 'status' => 500 )
            );
        }

        return $content;
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
        $original_id   = isset( $_POST['_sm_file_original_id'] )
            ? sanitize_key( wp_unslash( $_POST['_sm_file_original_id'] ) )
            : '';
        $upload_source = isset( $_POST['_sm_upload_source'] )
            ? sanitize_key( wp_unslash( $_POST['_sm_upload_source'] ) )
            : '';
        $label         = isset( $_POST['sm_file_label'] )
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
            : '{"logic":"and","rules":[]}';

        // If a .js file was uploaded, validate it and use its content.
        // This path serves both as a no-JS fallback and as a pure file-import
        // operation. Network upload validation is enforced by validate_js_upload().
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! empty( $_FILES['sm_file_upload'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES['sm_file_upload']['error'] ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $upload_result = $this->validate_js_upload( $_FILES['sm_file_upload'] );
            if ( is_wp_error( $upload_result ) ) {
                if ( 'list' === $upload_source ) {
                    wp_safe_redirect(
                        add_query_arg(
                            array( 'page' => 'scriptomatic-files', 'upload_error' => $upload_result->get_error_code() ),
                            admin_url( 'admin.php' )
                        )
                    );
                    exit;
                }
                $this->redirect_file_edit( $original_id, $upload_result->get_error_code() );
                return;
            }
            $content = $upload_result;
            // Auto-fill filename from the uploaded file's name if not manually supplied.
            if ( '' === $filename ) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $filename = sanitize_file_name( (string) $_FILES['sm_file_upload']['name'] );
            }
            // Auto-fill label from the filename (without extension) if not supplied.
            if ( '' === $label ) {
                $label = sanitize_text_field( preg_replace( '/\.js$/i', '', basename( $filename ) ) );
            }
        }

        // Empty content: delete an existing file or reject a new-file attempt.
        if ( '' === trim( $content ) ) {
            if ( '' !== $original_id ) {
                $files_meta = $this->get_js_files_meta();
                $found      = null;
                foreach ( $files_meta as $i => $f ) {
                    if ( $f['id'] === $original_id ) {
                        $found = $f;
                        array_splice( $files_meta, $i, 1 );
                        break;
                    }
                }
                if ( $found ) {
                    $dir          = $this->get_js_files_dir();
                    $path         = $dir . $found['filename'];
                    $file_content = '';
                    if ( file_exists( $path ) ) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                        $file_content = (string) file_get_contents( $path );
                        @unlink( $path ); // phpcs:ignore
                    }
                    $this->save_js_files_meta( $files_meta );
                    $this->write_activity_entry( array(
                        'action'     => 'file_delete',
                        'location'   => 'file',
                        'file_id'    => $original_id,
                        'detail'     => $found['label'],
                        'content'    => $file_content,
                        'conditions' => isset( $found['conditions'] ) ? $found['conditions'] : array(),
                        'meta'       => array(
                            'label'    => $found['label'],
                            'filename' => $found['filename'],
                            'location' => isset( $found['location'] ) ? $found['location'] : 'head',
                            'reason'   => 'empty_save',
                        ),
                    ) );
                }
                wp_safe_redirect(
                    add_query_arg(
                        array( 'page' => 'scriptomatic-files', 'deleted' => '1' ),
                        admin_url( 'admin.php' )
                    )
                );
                exit;
            }
            // New file with no content — nothing to save.
            $this->redirect_file_edit( '', 'empty_content' );
            return;
        }

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

        // Parse and sanitise conditions JSON ({logic,rules} stacked format).
        $conditions_raw = json_decode( $cond_raw, true );
        if ( ! is_array( $conditions_raw ) ) {
            $conditions_raw = array( 'logic' => 'and', 'rules' => array() );
        }
        $conditions = $this->sanitize_conditions_array( $conditions_raw );

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

        // Activity log — record the save with a full content + conditions snapshot.
        $this->write_activity_entry( array(
            'action'     => 'file_save',
            'location'   => 'file',
            'file_id'    => $new_id,
            'content'    => $content,
            'chars'      => strlen( $content ),
            'detail'     => $label,
            'conditions' => $conditions,
            'meta'       => array(
                'label'    => $label,
                'filename' => $filename,
                'location' => $location,
            ),
        ) );

        // When the save was triggered from the list-page upload form, redirect
        // to the edit view for the new file so the user can review the code
        // and configure the label, inject location, and load conditions.
        if ( 'list' === $upload_source && '' === $original_id ) {
            wp_safe_redirect(
                add_query_arg(
                    array( 'page' => 'scriptomatic-files', 'action' => 'edit', 'file' => $new_id ),
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

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

        // Remove from disk — capture content first so the entry can be restored.
        $dir          = $this->get_js_files_dir();
        $path         = $dir . $found['filename'];
        $file_content = '';
        if ( file_exists( $path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $file_content = (string) file_get_contents( $path );
            @unlink( $path ); // phpcs:ignore
        }

        $this->save_js_files_meta( $files );
        $this->write_activity_entry( array(
            'action'     => 'file_delete',
            'location'   => 'file',
            'file_id'    => $id,
            'detail'     => $found['label'],
            'content'    => $file_content,
            'conditions' => isset( $found['conditions'] ) ? $found['conditions'] : array(),
            'meta'       => array(
                'label'    => $found['label'],
                'filename' => $found['filename'],
                'location' => isset( $found['location'] ) ? $found['location'] : 'head',
            ),
        ) );

        wp_send_json_success( array( 'message' => __( 'File deleted.', 'scriptomatic' ) ) );
    }

}
