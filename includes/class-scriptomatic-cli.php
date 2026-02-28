<?php
/**
 * WP-CLI command class for Scriptomatic.
 *
 * This file is only loaded when the WP_CLI constant is defined (i.e. when a
 * request is initiated through the WP-CLI binary). It must not be required
 * directly — see the conditional require in class-scriptomatic.php.
 *
 * Usage overview
 * --------------
 *
 *   # Inline script
 *   wp scriptomatic script get --location=<head|footer>
 *   wp scriptomatic script set --location=<head|footer> [--content=<js>] [--file=<path>] [--conditions=<json>]
 *   wp scriptomatic script rollback --location=<head|footer> --index=<n>
 *
 *   # Inline script history
 *   wp scriptomatic history --location=<head|footer>
 *
 *   # External URL lists
 *   wp scriptomatic urls get --location=<head|footer>
 *   wp scriptomatic urls set --location=<head|footer> (--urls=<json> | --file=<path>)
 *   wp scriptomatic urls rollback --location=<head|footer> --index=<n>
 *   wp scriptomatic urls history --location=<head|footer>
 *
 *   # Managed JS files
 *   wp scriptomatic files list
 *   wp scriptomatic files get --id=<file-id>
 *   wp scriptomatic files set --label=<label> (--content=<js> | --file=<path>) [--id=<id>] [--filename=<fn>] [--location=<head|footer>] [--conditions=<json>]
 *   wp scriptomatic files upload --path=<path> [--label=<label>] [--id=<id>] [--location=<head|footer>] [--conditions=<json>]
 *   wp scriptomatic files delete --id=<file-id> [--yes]
 *
 * All write operations delegate to the service_*() methods on the
 * Scriptomatic singleton so no logic is duplicated between REST and CLI.
 *
 * @package  Scriptomatic
 * @since    2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manage Scriptomatic inline scripts, external URL lists, and managed JS files.
 *
 * @since 2.6.0
 */
class Scriptomatic_CLI_Commands extends WP_CLI_Command {

    /**
     * Scriptomatic singleton instance.
     *
     * @since  2.6.0
     * @access private
     * @var    Scriptomatic
     */
    private $plugin;

    /**
     * Constructor.
     *
     * @since 2.6.0
     */
    public function __construct() {
        $this->plugin = Scriptomatic::get_instance();
    }

    // =========================================================================
    // INLINE SCRIPT
    // =========================================================================

    /**
     * Get or set the inline script for a location, or roll it back to a
     * previous snapshot.
     *
     * ## SUBCOMMANDS
     *
     *   get      Display the current inline script for a location.
     *   set      Save a new inline script (with optional load conditions).
     *   rollback Restore the inline script to a previous snapshot.
     *
     * @since 2.6.0
     *
     * @subcommand script
     * @when       after_wp_load
     */

    /**
     * Get the current inline script for a location.
     *
     * ## OPTIONS
     *
     * [--location=<location>]
     * : The injection location. Accepts 'head' or 'footer'. Default: head.
     *
     * ## EXAMPLES
     *
     *   wp scriptomatic script get --location=head
     *   wp scriptomatic script get --location=footer
     *
     * @since  2.6.0
     * @param  array $args        Positional arguments (unused).
     * @param  array $assoc_args  Named arguments.
     */
    public function script_get( $args, $assoc_args ) {
        $location = isset( $assoc_args['location'] ) ? $assoc_args['location'] : 'head';
        $location = $this->validate_location( $location );

        $opt_s    = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_SCRIPT    : SCRIPTOMATIC_HEAD_SCRIPT;
        $opt_c    = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_CONDITIONS : SCRIPTOMATIC_HEAD_CONDITIONS;
        $content  = (string) get_option( $opt_s, '' );
        $cond_raw = (string) get_option( $opt_c, '' );

        WP_CLI::line( '' );
        WP_CLI::line( '=== Inline script (' . $location . ') ===' );
        WP_CLI::line( '' );

        if ( '' === $content ) {
            WP_CLI::line( '(empty)' );
        } else {
            WP_CLI::line( $content );
        }

        WP_CLI::line( '' );
        WP_CLI::line( sprintf( '%d characters', strlen( $content ) ) );

        if ( '' !== $cond_raw ) {
            WP_CLI::line( '' );
            WP_CLI::line( '--- Load conditions ---' );
            WP_CLI::line( $cond_raw );
        }
        WP_CLI::line( '' );
    }

    /**
     * Set the inline script for a location.
     *
     * JavaScript content may be provided inline with --content or from a
     * local file path with --file. The --conditions argument accepts a JSON
     * string in the same format as the UI. Omit --conditions to leave the
     * existing load conditions unchanged.
     *
     * ## OPTIONS
     *
     * [--location=<location>]
     * : The injection location. Accepts 'head' or 'footer'. Default: head.
     *
     * [--content=<js>]
     * : JavaScript content (without script tags).
     *
     * [--file=<path>]
     * : Path to a local .js file. Mutually exclusive with --content.
     *
     * [--conditions=<json>]
     * : JSON-encoded load conditions: '{"logic":"and","rules":[]}'.
     *
     * ## EXAMPLES
     *
     *   wp scriptomatic script set --location=head --content="console.log('hello');"
     *   wp scriptomatic script set --location=footer --file=/path/to/script.js
     *   wp scriptomatic script set --location=head --content="var x=1;" --conditions='{"logic":"and","rules":[]}'
     *
     * @since  2.6.0
     * @param  array $args
     * @param  array $assoc_args
     */
    public function script_set( $args, $assoc_args ) {
        $location  = isset( $assoc_args['location'] ) ? $assoc_args['location'] : 'head';
        $location  = $this->validate_location( $location );
        $content   = $this->read_content( $assoc_args );
        $cond_json = isset( $assoc_args['conditions'] ) ? (string) $assoc_args['conditions'] : null;

        $result = $this->plugin->service_set_script( $location, $content, $cond_json );
        $this->handle_result( $result );
    }

    /**
     * Roll back the inline script to a previous snapshot.
     *
     * Index 1 is the most recent snapshot. Index 0 is the current live state
     * and cannot be rolled back to.
     *
     * ## OPTIONS
     *
     * [--location=<location>]
     * : The injection location. Accepts 'head' or 'footer'. Default: head.
     *
     * --index=<n>
     * : Snapshot index to restore (1-based).
     *
     * ## EXAMPLES
     *
     *   wp scriptomatic script rollback --location=head --index=1
     *
     * @since  2.6.0
     * @param  array $args
     * @param  array $assoc_args
     */
    public function script_rollback( $args, $assoc_args ) {
        $location = isset( $assoc_args['location'] ) ? $assoc_args['location'] : 'head';
        $location = $this->validate_location( $location );
        $index    = isset( $assoc_args['index'] ) ? (int) $assoc_args['index'] : 0;

        if ( $index < 1 ) {
            WP_CLI::error( 'The --index must be 1 or higher. Index 0 is the current live state and cannot be restored.' );
        }

        $result = $this->plugin->service_rollback_script( $location, $index );
        $this->handle_result( $result );
    }

    // =========================================================================
    // INLINE SCRIPT HISTORY
    // =========================================================================

    /**
     * List inline script save/rollback history for a location.
     *
     * ## OPTIONS
     *
     * [--location=<location>]
     * : The injection location. Accepts 'head' or 'footer'. Default: head.
     *
     * [--format=<format>]
     * : Output format. Accepts 'table' (default), 'json', 'csv', 'yaml', 'count'.
     *
     * ## EXAMPLES
     *
     *   wp scriptomatic history --location=head
     *   wp scriptomatic history --location=footer --format=json
     *
     * @since  2.6.0
     * @param  array $args
     * @param  array $assoc_args
     */
    public function history( $args, $assoc_args ) {
        $location = isset( $assoc_args['location'] ) ? $assoc_args['location'] : 'head';
        $location = $this->validate_location( $location );
        $format   = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

        $data = $this->plugin->service_get_history( $location );

        if ( empty( $data['entries'] ) ) {
            WP_CLI::line( 'No history found for location: ' . $location );
            return;
        }

        $rows = array();
        foreach ( $data['entries'] as $entry ) {
            $rows[] = array(
                'Index'      => $entry['index'],
                'Action'     => $entry['action'],
                'Date'       => $entry['timestamp'] > 0 ? date( 'Y-m-d H:i:s', $entry['timestamp'] ) : '—',
                'User'       => $entry['user'],
                'Chars'      => number_format( $entry['chars'] ),
                'Detail'     => $entry['detail'],
            );
        }

        WP_CLI\Utils\format_items( $format, $rows, array( 'Index', 'Action', 'Date', 'User', 'Chars', 'Detail' ) );
    }

    // =========================================================================
    // EXTERNAL URL LISTS
    // =========================================================================

    /**
     * Manage external JavaScript URL lists.
     *
     * ## SUBCOMMANDS
     *
     *   get      Display the current URL list for a location.
     *   set      Replace the URL list for a location (JSON array or file).
     *   rollback Restore a URL list to a previous snapshot.
     *   history  List URL list change history for a location.
     *
     * @since 2.6.0
     * @subcommand urls
     */

    /**
     * Get the current external URL list for a location.
     *
     * ## OPTIONS
     *
     * [--location=<location>]
     * : The injection location. Accepts 'head' or 'footer'. Default: head.
     *
     * [--format=<format>]
     * : Output format. Accepts 'table' (default), 'json', 'csv', 'yaml', 'count'.
     *
     * ## EXAMPLES
     *
     *   wp scriptomatic urls get --location=head
     *   wp scriptomatic urls get --location=footer --format=json
     *
     * @since  2.6.0
     * @param  array $args
     * @param  array $assoc_args
     */
    public function urls_get( $args, $assoc_args ) {
        $location = isset( $assoc_args['location'] ) ? $assoc_args['location'] : 'head';
        $location = $this->validate_location( $location );
        $format   = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

        $opt  = ( 'footer' === $location ) ? SCRIPTOMATIC_FOOTER_LINKED : SCRIPTOMATIC_HEAD_LINKED;
        $raw  = get_option( $opt, '[]' );
        $list = json_decode( $raw, true );

        if ( ! is_array( $list ) || empty( $list ) ) {
            WP_CLI::line( 'No external URLs configured for location: ' . $location );
            return;
        }

        $rows = array();
        foreach ( $list as $i => $item ) {
            $rows[] = array(
                '#'          => $i + 1,
                'URL'        => isset( $item['url'] ) ? $item['url'] : '',
                'Conditions' => isset( $item['conditions'] ) ? wp_json_encode( $item['conditions'] ) : '{}',
            );
        }

        WP_CLI\Utils\format_items( $format, $rows, array( '#', 'URL', 'Conditions' ) );
    }

    /**
     * Replace the external URL list for a location.
     *
     * Provide JSON directly with --urls or from a local file with --file.
     * The expected format is an array of {url, conditions} objects. See
     * the REST API documentation for field details.
     *
     * ## OPTIONS
     *
     * [--location=<location>]
     * : The injection location. Accepts 'head' or 'footer'. Default: head.
     *
     * [--urls=<json>]
     * : JSON-encoded array of {url, conditions} objects.
     *
     * [--file=<path>]
     * : Path to a local JSON file. Mutually exclusive with --urls.
     *
     * ## EXAMPLES
     *
     *   wp scriptomatic urls set --location=head --urls='[{"url":"https://example.com/a.js","conditions":{"logic":"and","rules":[]}}]'
     *   wp scriptomatic urls set --location=footer --file=/tmp/urls.json
     *
     * @since  2.6.0
     * @param  array $args
     * @param  array $assoc_args
     */
    public function urls_set( $args, $assoc_args ) {
        $location = isset( $assoc_args['location'] ) ? $assoc_args['location'] : 'head';
        $location = $this->validate_location( $location );

        if ( isset( $assoc_args['file'] ) ) {
            $path = (string) $assoc_args['file'];
            if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
                WP_CLI::error( sprintf( 'Cannot read file: %s', $path ) );
            }
            $urls_json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        } elseif ( isset( $assoc_args['urls'] ) ) {
            $urls_json = (string) $assoc_args['urls'];
        } else {
            WP_CLI::error( 'Provide --urls=<json> or --file=<path>.' );
        }

        $result = $this->plugin->service_set_urls( $location, $urls_json );
        $this->handle_result( $result );
    }

    /**
     * Roll back the external URL list to a previous snapshot.
     *
     * ## OPTIONS
     *
     * [--location=<location>]
     * : The injection location. Accepts 'head' or 'footer'. Default: head.
     *
     * --index=<n>
     * : Snapshot index to restore (1-based).
     *
     * ## EXAMPLES
     *
     *   wp scriptomatic urls rollback --location=head --index=1
     *
     * @since  2.6.0
     * @param  array $args
     * @param  array $assoc_args
     */
    public function urls_rollback( $args, $assoc_args ) {
        $location = isset( $assoc_args['location'] ) ? $assoc_args['location'] : 'head';
        $location = $this->validate_location( $location );
        $index    = isset( $assoc_args['index'] ) ? (int) $assoc_args['index'] : 0;

        if ( $index < 1 ) {
            WP_CLI::error( 'The --index must be 1 or higher.' );
        }

        $result = $this->plugin->service_rollback_urls( $location, $index );
        $this->handle_result( $result );
    }

    /**
     * List URL list change history for a location.
     *
     * ## OPTIONS
     *
     * [--location=<location>]
     * : The injection location. Accepts 'head' or 'footer'. Default: head.
     *
     * [--format=<format>]
     * : Output format. Accepts 'table' (default), 'json', 'csv', 'yaml', 'count'.
     *
     * ## EXAMPLES
     *
     *   wp scriptomatic urls history --location=head
     *
     * @since  2.6.0
     * @param  array $args
     * @param  array $assoc_args
     */
    public function urls_history( $args, $assoc_args ) {
        $location = isset( $assoc_args['location'] ) ? $assoc_args['location'] : 'head';
        $location = $this->validate_location( $location );
        $format   = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

        $data = $this->plugin->service_get_url_history( $location );

        if ( empty( $data['entries'] ) ) {
            WP_CLI::line( 'No URL history found for location: ' . $location );
            return;
        }

        $rows = array();
        foreach ( $data['entries'] as $entry ) {
            $rows[] = array(
                'Index'   => $entry['index'],
                'Action'  => $entry['action'],
                'Date'    => $entry['timestamp'] > 0 ? date( 'Y-m-d H:i:s', $entry['timestamp'] ) : '—',
                'User'    => $entry['user'],
                'URLs'    => $entry['url_count'],
                'Detail'  => $entry['detail'],
            );
        }

        WP_CLI\Utils\format_items( $format, $rows, array( 'Index', 'Action', 'Date', 'User', 'URLs', 'Detail' ) );
    }

    // =========================================================================
    // MANAGED JS FILES
    // =========================================================================

    /**
     * List all managed JS files.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Accepts 'table' (default), 'json', 'csv', 'yaml', 'count'.
     *
     * ## EXAMPLES
     *
     *   wp scriptomatic files list
     *   wp scriptomatic files list --format=json
     *
     * @since  2.6.0
     * @param  array $args
     * @param  array $assoc_args
     */
    public function files_list( $args, $assoc_args ) {
        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
        $files  = $this->plugin->get_js_files_meta();

        if ( empty( $files ) ) {
            WP_CLI::line( 'No managed JS files found.' );
            return;
        }

        $rows = array();
        foreach ( $files as $f ) {
            $rows[] = array(
                'ID'       => $f['id'],
                'Label'    => $f['label'],
                'File'     => $f['filename'],
                'Location' => isset( $f['location'] ) ? $f['location'] : 'head',
            );
        }

        WP_CLI\Utils\format_items( $format, $rows, array( 'ID', 'Label', 'File', 'Location' ) );
    }

    /**
     * Get the content and metadata of a managed JS file.
     *
     * ## OPTIONS
     *
     * --id=<file-id>
     * : The managed file ID.
     *
     * [--format=<format>]
     * : Output format. Accepts 'table' (default), 'json'.
     *
     * ## EXAMPLES
     *
     *   wp scriptomatic files get --id=my-script
     *
     * @since  2.6.0
     * @param  array $args
     * @param  array $assoc_args
     */
    public function files_get( $args, $assoc_args ) {
        if ( ! isset( $assoc_args['id'] ) || '' === $assoc_args['id'] ) {
            WP_CLI::error( '--id is required.' );
        }
        $result = $this->plugin->service_get_file( (string) $assoc_args['id'] );
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        WP_CLI::line( '' );
        WP_CLI::line( '=== ' . $result['label'] . ' (' . $result['file_id'] . ') ===' );
        WP_CLI::line( 'Location : ' . $result['location'] );
        WP_CLI::line( 'Filename : ' . $result['filename'] );
        WP_CLI::line( 'Chars    : ' . number_format( $result['chars'] ) );
        if ( ! empty( $result['conditions']['rules'] ) ) {
            WP_CLI::line( 'Conditions: ' . wp_json_encode( $result['conditions'] ) );
        }
        WP_CLI::line( '' );
        WP_CLI::line( '--- Content ---' );
        WP_CLI::line( $result['content'] );
        WP_CLI::line( '' );
    }

    /**
     * Create or update a managed JS file.
     *
     * Provide JavaScript content with --content or --file. Use --id to update
     * an existing file; omit to create a new one.
     *
     * ## OPTIONS
     *
     * --label=<label>
     * : Human-readable label shown in the file list.
     *
     * [--content=<js>]
     * : JavaScript content (without script tags).
     *
     * [--file=<path>]
     * : Path to a local .js file. Mutually exclusive with --content.
     *
     * [--id=<file-id>]
     * : Existing file ID to update. Omit to create a new file.
     *
     * [--filename=<filename>]
     * : Filename for disk storage (e.g. my-script.js). Auto-generated from
     *   --label when omitted.
     *
     * [--location=<location>]
     * : Injection location: 'head' or 'footer'. Default: head.
     *
     * [--conditions=<json>]
     * : JSON-encoded load conditions: '{"logic":"and","rules":[]}'.
     *
     * ## EXAMPLES
     *
     *   wp scriptomatic files set --label="My Script" --content="console.log(1);" --location=head
     *   wp scriptomatic files set --id=my-script --label="My Script" --file=/path/to/script.js
     *
     * @since  2.6.0
     * @param  array $args
     * @param  array $assoc_args
     */
    public function files_set( $args, $assoc_args ) {
        if ( ! isset( $assoc_args['label'] ) || '' === trim( $assoc_args['label'] ) ) {
            WP_CLI::error( '--label is required.' );
        }

        $content = $this->read_content( $assoc_args );
        $result  = $this->plugin->service_set_file( array(
            'file_id'    => isset( $assoc_args['id'] )         ? (string) $assoc_args['id']         : '',
            'label'      => (string) $assoc_args['label'],
            'content'    => $content,
            'filename'   => isset( $assoc_args['filename'] )   ? (string) $assoc_args['filename']   : '',
            'location'   => isset( $assoc_args['location'] )   ? (string) $assoc_args['location']   : 'head',
            'conditions' => isset( $assoc_args['conditions'] ) ? (string) $assoc_args['conditions'] : '',
        ) );
        $this->handle_result( $result );
    }

    /**
     * Delete a managed JS file.
     *
     * ## OPTIONS
     *
     * --id=<file-id>
     * : The managed file ID.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *   wp scriptomatic files delete --id=my-script
     *   wp scriptomatic files delete --id=my-script --yes
     *
     * @since  2.6.0
     * @param  array $args
     * @param  array $assoc_args
     */
    public function files_delete( $args, $assoc_args ) {
        if ( ! isset( $assoc_args['id'] ) || '' === $assoc_args['id'] ) {
            WP_CLI::error( '--id is required.' );
        }
        $file_id = (string) $assoc_args['id'];

        WP_CLI::confirm(
            sprintf( 'Are you sure you want to delete the file "%s"?', $file_id ),
            $assoc_args
        );

        $result = $this->plugin->service_delete_file( $file_id );
        $this->handle_result( $result );
    }

    /**
     * Upload a local .js file to the managed JS files library.
     *
     * Reads the file from disk, validates the extension and content via the
     * same pipeline used by the REST API and admin UI, then saves it through
     * service_upload_file(). The file name is derived from --path unless
     * --id is supplied (in which case the existing entry is overwritten).
     *
     * ## OPTIONS
     *
     * --path=<path>
     * : Absolute or relative path to the local .js file to upload.
     *
     * [--label=<label>]
     * : Human-readable label for the file. Defaults to the filename (without
     * : the .js extension).
     *
     * [--id=<id>]
     * : Existing file ID to overwrite. Omit to create a new entry.
     *
     * [--location=<location>]
     * : Where to inject the file: head or footer. Default: head.
     *
     * [--conditions=<json>]
     * : JSON-encoded load conditions object {logic, rules}. Omit for all pages.
     *
     * ## EXAMPLES
     *
     *   wp scriptomatic files upload --path=/tmp/analytics.js
     *   wp scriptomatic files upload --path=./my-script.js --label="My Script" --location=footer
     *   wp scriptomatic files upload --path=/tmp/updated.js --id=my-script
     *
     * @since  2.7.0
     * @param  array $args
     * @param  array $assoc_args
     */
    public function files_upload( $args, $assoc_args ) {
        if ( ! isset( $assoc_args['path'] ) || '' === trim( (string) $assoc_args['path'] ) ) {
            WP_CLI::error( '--path is required.' );
        }

        $path = (string) $assoc_args['path'];
        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            WP_CLI::error( sprintf( 'Cannot read file: %s', $path ) );
        }
        if ( ! preg_match( '/\.js$/i', basename( $path ) ) ) {
            WP_CLI::error( 'Only .js files are accepted.' );
        }

        // Build a synthetic $_FILES-style array for validate_js_upload() / service_upload_file().
        $file_data = array(
            'name'     => basename( $path ),
            'tmp_name' => $path,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize( $path ),
            'type'     => 'text/javascript',
            '_cli'     => true, // signal to validate_js_upload() to skip is_uploaded_file().
        );

        $location = isset( $assoc_args['location'] ) ? $this->validate_location( $assoc_args['location'] ) : 'head';

        $result = $this->plugin->service_upload_file( $file_data, array(
            'file_id'    => isset( $assoc_args['id'] )         ? (string) $assoc_args['id']         : '',
            'label'      => isset( $assoc_args['label'] )      ? (string) $assoc_args['label']      : '',
            'location'   => $location,
            'conditions' => isset( $assoc_args['conditions'] ) ? (string) $assoc_args['conditions'] : '',
        ) );
        $this->handle_result( $result );
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================
    private function validate_location( $location ) {
        $location = sanitize_key( (string) $location );
        if ( ! in_array( $location, array( 'head', 'footer' ), true ) ) {
            WP_CLI::error( 'Invalid --location. Accepted values: head, footer.' );
        }
        return $location;
    }

    /**
     * Read JavaScript / JSON content from --content or --file.
     *
     * @since  2.6.0
     * @access private
     * @param  array $assoc_args
     * @return string
     */
    private function read_content( array $assoc_args ) {
        if ( isset( $assoc_args['file'] ) ) {
            $path = (string) $assoc_args['file'];
            if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
                WP_CLI::error( sprintf( 'Cannot read file: %s', $path ) );
            }
            return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        if ( isset( $assoc_args['content'] ) ) {
            return (string) $assoc_args['content'];
        }
        WP_CLI::error( 'Provide --content=<js> or --file=<path>.' );
    }

    /**
     * Handle a service layer result: print success message or error and exit.
     *
     * @since  2.6.0
     * @access private
     * @param  array|WP_Error $result
     * @return void
     */
    private function handle_result( $result ) {
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }
        $message = isset( $result['message'] ) ? $result['message'] : 'Done.';
        unset( $result['message'] );
        foreach ( $result as $key => $value ) {
            if ( is_scalar( $value ) ) {
                WP_CLI::line( $key . ': ' . $value );
            }
        }
        WP_CLI::success( $message );
    }
}
