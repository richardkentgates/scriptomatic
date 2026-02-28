=== Scriptomatic ===
Contributors: richardkentgates
Tags: javascript, script injection, code manager, head scripts, footer scripts, conditional loading, js files, activity log
Requires at least: 5.3
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 2.5.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inject custom JavaScript into your WordPress head and footer with conditional loading, managed JS files, CodeMirror editor, and full activity logging.

== Description ==

Scriptomatic is a secure, production-ready WordPress plugin for injecting custom JavaScript into the `<head>` and footer of your site.

= Core Features =

* **Inline Script Editor** — full CodeMirror JavaScript editor with line numbers, bracket matching, and WordPress/jQuery-specific Ctrl-Space autocomplete. Falls back gracefully to a plain textarea.
* **External Script URLs** — manage multiple remote `<script src>` URLs per location with a chicklet UI; each URL has its own independent load conditions.
* **Managed JS Files** — create, edit, and delete standalone `.js` files stored in `wp-content/uploads/scriptomatic/`. Each file has its own Head/Footer selector, load conditions, and CodeMirror editor. Files survive plugin updates.
* **Conditional Loading** — 11 condition types per location: Front Page, Singular, Post Type, Page ID, URL Contains, Logged In, Logged Out, Date Range, Date &amp; Time Range, ISO Week Number, and Month. AND/OR stacked rules with short-circuit evaluation.
* **Revision History &amp; Rollback** — every save writes a complete snapshot (inline script + URL list + load conditions). Click Restore to bring all three back simultaneously via AJAX — no further Save needed.
* **Activity Log** — all saves, rollbacks, and JS file events are recorded in a persistent log embedded at the bottom of each admin page. Configurable limit of 3–1,000 entries (default 200).
* **Security First** — dual nonce verification, `manage_options` capability gate, transient-based rate limiting (10-second cooldown per user per location), UTF-8 and control-character rejection, 100 KB content cap for inline scripts (JS files are limited by the server's upload setting), PHP-tag detection, and dangerous-HTML-tag warning.

= Architecture =

Nine PHP traits in separate files, a singleton class, and static `assets/admin.css` / `assets/admin.js`. No external dependencies, no autoloader, no REST API routes, no external API calls.

= Multisite =

Fully multisite compatible. Install, activate, and deactivate network-wide. Uninstall iterates every sub-site. All script management is strictly per-site.

== Installation ==

= Via WordPress Admin =

1. Navigate to **Plugins → Add New** in your WordPress admin.
2. Search for **Scriptomatic** or click **Upload Plugin** and select the downloaded ZIP.
3. Click **Install Now**, then **Activate**.

= Via FTP / cPanel =

1. Download and extract the plugin ZIP.
2. Upload the `scriptomatic` folder to `/wp-content/plugins/`.
3. Navigate to **Plugins** in WordPress admin and click **Activate**.

= Via Git =

`cd /path/to/wordpress/wp-content/plugins/ && git clone https://github.com/richardkentgates/scriptomatic.git`

Then activate via WordPress admin.

== Frequently Asked Questions ==

= Do I include `<script>` tags in the editor? =

No. Scriptomatic wraps inline code in `<script>` tags automatically. If you paste code that already has `<script>` tags they will be stripped (with an admin notice) to prevent double-wrapping.

= What is the maximum script size? =

100 KB per location for inline scripts. Managed JS files are capped at the site's `wp_max_upload_size()`.

= Can I restrict a script to specific pages? =

Yes. Use the **Load Conditions** section on each page. Choose from 11 condition types and build AND/OR stacked rules. An empty rule stack means "load on all pages".

= What happens to my data if I deactivate the plugin? =

Deactivating does not remove data. Data is only removed when you **delete** the plugin — and only if the **Keep data on uninstall** setting in Preferences is disabled (the default).

= Is it multisite compatible? =

Yes. All data is stored per-site via `get_option()` / `update_option()`. Uninstall iterates every sub-site. Network-level plugin activation and deactivation work through standard WordPress behaviour.

= Why is the Restore button greyed out on some entries? =

File deletion entries (`file_delete`) are informational only — there is no script content to restore in-place. To recover a deleted JS file, use the **Re-create** button or find the most recent `file_save` entry and restore from there.

= Can I use the plugin with a page builder or theme that doesn't call wp_head() / wp_footer()? =

Scriptomatic hooks at priority 999 on `wp_head` and `wp_footer`. If your theme or a page builder bypasses these standard WordPress hooks, scripts will not be injected. Check with your theme's documentation.

== Screenshots ==

1. Head Scripts page — inline script editor with live character counter, load conditions, and external URL manager.
2. Activity Log panel — complete snapshot entries with View and Restore buttons.
3. JS Files list view — managed JavaScript files with conditions summary.
4. JS Files edit view — CodeMirror editor, Head/Footer selector, load conditions, and per-file Activity Log.
5. Preferences page — activity log limit and uninstall data retention.
6. Contextual help tabs — Overview, Usage, Security, Best Practices, and Troubleshooting.

== Changelog ==

= 2.5.0 =
* **Changed**: Activity Log now writes one combined snapshot entry per save. Previously a single Save could produce up to three separate partial entries. Now every entry contains the full state — inline script, URL list, and load conditions — and a single Restore brings everything back simultaneously.
* **Changed**: Restore now writes the script, URL list, and conditions via direct `$wpdb` writes together, bypassing the sanitize callbacks exactly as the previous script-only rollback did.
* **Changed**: Activity Log table "Size / Detail" column renamed to "Changes"; now shows a human-readable summary (e.g. `Script: 245 chars · 1 URL added · Conditions: Front page only`).
* **Removed**: Separate `url_added`, `url_removed`, `conditions_save`, `url_list_restored`, and `conditions_restored` event types and their individual AJAX endpoints.

= 2.4.0 =
* **Fixed**: File conditions UI now correctly renders the stacked-rule picker. `render_file_conditions_widget()` was still using the legacy single-select UI.
* **Fixed**: Duplicate `conditions_save` Activity Log entries caused by a missing Settings API double-call guard in `sanitize_conditions_for()`.

= 2.3.0 =
* **Changed**: Each log action now records only its own data — no cross-data bundling.
* **Changed**: Restore button disabled (with tooltip) on `url_removed`, empty-content saves, and `file_delete` entries; View remains active for audit reference.

= 2.2.0 =
* **Fixed**: Inline-script conditions UI; sub-panels now appear when a rule type is chosen.
* **Fixed**: Ambiguous "Load Conditions" headings in the Activity Log lightbox; inline-script and file conditions are now labelled distinctly.

= 2.1.0 =
* **Added**: Save confirmation notices on Head Scripts, Footer Scripts, and Preferences pages.
* **Added**: Empty-file auto-delete — saving a JS file with empty content removes the file rather than writing a blank `.js`.
* **Fixed**: Plugin header `Version` tag corrected to match `SCRIPTOMATIC_VERSION`.

= 2.0.0 =
* First public distribution release.
* All internal development backward-compatibility code removed.

== Upgrade Notice ==

= 2.5.0 =
Activity Log entries are now combined snapshots. Restore brings back the script, URL list, and conditions simultaneously — no further Save needed after a Restore. No database migration required; existing log entries continue to display and function correctly.
