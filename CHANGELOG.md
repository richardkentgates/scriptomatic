# Changelog

All notable changes to **Scriptomatic** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

Nothing pending.

---

## [1.5.0] – 2026-02-26

### Added
- **Audit Log admin page (per-site and network).** Script saves and rollbacks are now recorded in a persistent, WordPress-native log stored via `wp_options` / `wp_site_option` rather than the PHP error log. The log is accessible from the new **Audit Log** submenu in both the per-site and network admin menus. Each entry captures the timestamp, acting user, action (`save` or `rollback`), script location (`head` or `footer`), and character count of the content involved. The log is capped at 200 entries (oldest entries are discarded once the cap is exceeded). A **Clear Audit Log** button — guarded by a nonce and a capability check — lets admins wipe the log at any time.
- Three new constants: `SCRIPTOMATIC_AUDIT_LOG_OPTION`, `SCRIPTOMATIC_MAX_LOG_ENTRIES`, `SCRIPTOMATIC_CLEAR_LOG_NONCE`.
- New private methods `write_audit_log_entry()` and `get_audit_log()` in `trait-settings.php`.
- New public methods `render_audit_log_page()`, `render_network_audit_log_page()`, and `maybe_clear_audit_log()` in `trait-pages.php`.

### Changed
- `log_change()` in `trait-settings.php` now calls `write_audit_log_entry()` instead of `error_log()`.
- `ajax_rollback()` in `trait-history.php` now calls `write_audit_log_entry()` instead of `error_log()`.
- `uninstall.php` now deletes the `scriptomatic_audit_log` option on uninstall.

---

## [1.4.4] – 2026-02-26

### Fixed
- **`esc_attr()` used in HTML comment context.** `inject_scripts_for()` escaped the version string with `esc_attr()` inside an HTML comment (`<!-- Scriptomatic v... -->`). The correct function for HTML content context is `esc_html()`. Changed accordingly.

### Documentation
- **SECURITY.md**: Removed false claim that IP addresses are audit-logged. IP collection was removed in v1.2.1 for privacy; the policy still listed it as a logged field.
- **SECURITY.md**: Supported Versions table updated from `1.0.x` to `1.4.x`; version footer updated from `1.0.0` to `1.4.3`.
- **SECURITY.md**: "Type Safety: Strict parameter and return type declarations" replaced with an accurate description: "Defensive Type Checks: All input types verified at runtime via `is_string()`, `is_array()`, `absint()`, and similar guards."
- **README.md**: Replaced dead `/wiki` link in the Links section with a link to `README.md`.
- **CONTRIBUTING.md**: Prerequisites WordPress minimum corrected from 5.0 to 5.3 (matching the actual plugin header).
- **CONTRIBUTING.md**: Bug-report navigation example corrected from `Settings → Scriptomatic` to `Scriptomatic → Head Scripts` (the plugin has a top-level menu, not a Settings sub-item).
- **CONTRIBUTING.md**: Code Organization directory tree completely rewritten to reflect the actual v1.4.4 file structure (trait-based architecture, real `assets/` and `languages/` contents; removed "(planned)" labels for things that already exist).
- **`trait-menus.php`**: Fixed comment on menu position 82 — described it as "between Comments (60) and Appearance (60+)", but WordPress default positions are Comments=25, Appearance=60, Settings=80; position 82 is just after Settings.

---

## [1.4.3] – 2026-02-26

### Removed
- **All backward-compatibility aliases deleted.** Because this is an unreleased plugin with no existing integrations, there is nothing to stay compatible with. The following were dead weight:
  - **Constants** in `scriptomatic.php`: `SCRIPTOMATIC_OPTION_NAME`, `SCRIPTOMATIC_HISTORY_OPTION`, `SCRIPTOMATIC_LINKED_SCRIPTS_OPTION`, `SCRIPTOMATIC_NONCE_ACTION`.
  - **Methods** in `trait-renderer.php`: `render_section_description()`, `render_script_field()`, `render_linked_scripts_section()`, `render_linked_scripts_field()`.
  - **Methods** in `trait-pages.php`: `render_settings_page()`.
  - **Methods** in `trait-injector.php`: `inject_script()`.
  - **Method** in `trait-sanitizer.php`: `sanitize_linked_scripts()`.
- Removed stale `// backward-compat key` inline comments from the `SCRIPTOMATIC_HEAD_*` constant definitions in `scriptomatic.php`.
- Removed `“Backward-compatibility aliases for pre-1.2.0 hook callbacks”` note from `trait-renderer.php` file docblock.
- Removed `“**🔄 Backward Compatible**: Legacy constant and method aliases retained for pre-1.2.0 integrations”` bullet from README features list.

---

## [1.4.2] – 2026-02-26

### Fixed
- **`wp_redirect()` missing `esc_url_raw()` on error path.** In `handle_network_settings_save()`, the failed-nonce redirect used  `add_query_arg( 'error', '1', wp_get_referer() )` without wrapping it in `esc_url_raw()`. The success-path redirect one line below already used `esc_url_raw()`. Both redirects are now consistent.
- **Redundant capability check in `sanitize_script_for()`.** A second `if ( current_user_can( $this->get_required_cap() ) )` guard wrapped the `log_change()` and `push_history()` calls at the end of the method. Gate 0 at the top of the same function — `if ( ! current_user_can(...) ) return` — ensures the inner check is always true, making it dead conditional logic. The redundant wrapper has been removed; `log_change()` and `push_history()` are now called unconditionally at that point.

### Changed
- **`enqueue_admin_scripts()` `@since` tag corrected.** The method docblock said `@since 1.0.0`; the containing trait (`Scriptomatic_Enqueue`) was introduced in v1.4.0 along with this specific implementation (static file enqueuing via `wp_enqueue_script` / `wp_localize_script`). Tag updated to `@since 1.4.0`.
- **Dead hook entry removed from `$head_hooks`.** The array in `enqueue_admin_scripts()` included `'scriptomatic_page_scriptomatic'`. When a top-level menu page slug and its first submenu slug are identical, WordPress generates only `toplevel_page_scriptomatic` — the `scriptomatic_page_scriptomatic` hook is never fired. The dead entry has been removed.
- **Network admin page header now shows version/author/docs.** `render_network_page_header()` previously rendered only the page `<h1>` title, unlike the per-site `render_page_header()` which appends a version/author/documentation line. The network header now includes the same `<p class="description">` block for consistency.

### Documentation
- **`inject_head_scripts()` docblock simplified.** The PHPDoc listed "Handles two sources: Linked URLs / Inline content" — a description that belongs on `inject_scripts_for()` (the method that implements the logic, where the same text also appears). The `inject_head_scripts()` docblock now accurately describes what the method actually does: guard against admin context and delegate.

---

## [1.4.1] – 2026-02-26

### Fixed
- **Network admin: fields displayed wrong values.** `render_script_field_for()`, `render_linked_field_for()`, and `render_conditions_field_for()` all called `get_option()` when rendering network-admin pages. Because network-admin values are stored via `update_site_option()`, the script textarea, linked-URL chicklets, and load-condition selector always appeared empty (or showed per-site values) on the network admin screens. All three helpers now call `get_site_option()` when `is_network_admin()` is true.
- **Network admin: validation fallback returned wrong value.** `validate_inline_script()`, `sanitize_linked_for()`, and `sanitize_conditions_for()` each used `get_option()` as their fallback return on parse/decode failure. When called from `handle_network_settings_save()`, a failed validation would return the per-site option value (possibly empty) instead of the stored site option. All three now check `is_network_admin()` to choose the correct storage API.
- **`get_plugin_settings()` not network-aware.** The method always read from `get_option()`. On the network-admin General Settings page the History Limit and Keep Data fields showed per-site values instead of the network-level settings. The method now reads `get_site_option()` on network-admin and falls back to it on multisite front-end/per-site requests (mirroring `get_front_end_option()`).
- **`log_change()` always logged on network-admin saves.** The old-value comparison used `get_option()`, but network scripts are stored under `get_site_option()`. Content that was unchanged would still be logged as a change because the per-site option was (typically) empty. The method now reads the correct API based on `is_network_admin()`.
- **Network admin script saves were not audit-logged.** `handle_network_settings_save()` called `validate_inline_script()` (which has no logging) and immediately passed the result to `update_site_option()`. Script changes via network admin were therefore never written to the error log. The handler now captures the validated value, calls `log_change()`, and then persists it.

### Changed
- Moved `.scriptomatic-security-notice` inline styles from `render_script_field_for()` into `assets/admin.css`.

---

## [1.4.0] – 2026-02-26

### Changed
- Refactored the monolithic `scriptomatic.php` (2 696 lines) into a clean multi-file structure:
  - `scriptomatic.php` — lean entry point: plugin header, constants, `require_once`, and bootstrap.
  - `includes/class-scriptomatic.php` — singleton class that `use`s all eight traits.
  - `includes/trait-menus.php` — `Scriptomatic_Menus`: admin menu registrations.
  - `includes/trait-sanitizer.php` — `Scriptomatic_Sanitizer`: all input validation and sanitisation.
  - `includes/trait-history.php` — `Scriptomatic_History`: revision history and AJAX rollback.
  - `includes/trait-settings.php` — `Scriptomatic_Settings`: Settings API wiring and plugin-settings CRUD.
  - `includes/trait-renderer.php` — `Scriptomatic_Renderer`: settings-field callbacks and load-condition evaluator.
  - `includes/trait-pages.php` — `Scriptomatic_Pages`: page renderers, network pages, help tabs, action links.
  - `includes/trait-enqueue.php` — `Scriptomatic_Enqueue`: admin-asset enqueuing.
  - `includes/trait-injector.php` — `Scriptomatic_Injector`: front-end HTML injection.
- Extracted inline CSS and JS from `get_admin_css()` / `get_admin_js()` PHP methods into standalone static files:
  - `assets/admin.css` — enqueued via `wp_enqueue_style`.
  - `assets/admin.js` — enqueued via `wp_enqueue_script` with `wp_localize_script` for PHP→JS data.
- Added `network_admin_enqueue_scripts` hook so assets load correctly on multisite network-admin pages.
- `wp_localize_script` now targets `scriptomatic-admin-js` (the real enqueued handle) instead of `jquery`.
- Renamed inner JS `makeChicklet()` in the URL manager section to `makeUrlChicklet()` to avoid shadowing the identically-named inner function inside `initConditions`.
- Conditions-section JS variables `$urlInput` / `$urlError` renamed `$urlPatInput` / `$urlPatError` (and added `$urlPatList` / `$urlPatAdd`) to eliminate scope conflicts with the URL manager at the outer `document.ready` level.

### Removed
- `get_admin_css()` and `get_admin_js()` PHP methods removed; replaced by real asset files.

---

## [1.3.1] – 2026-02-26

### Fixed
- Removed dead-code methods `sanitize_script_content()` and `log_script_change()` that were never called.
- Added missing `// PLUGIN SETTINGS` section-banner comment that was dropped during a prior refactor.
- Removed duplicate `$raw_cond` assignment in `handle_network_settings_save()`.
- Corrected misleading `/* end document.ready */` JS comment placed on the history-restore handler's closing brace instead of the actual `jQuery(document).ready` closure.

### Changed
- `render_head_code_section()` and `render_footer_code_section()` description strings updated to reference **Load Conditions** instead of incorrectly claiming the script loads on every page.
- Help tab **Overview** updated to mention Load Conditions and remove the false "every page" claim.
- Help tab **Usage** gains a new step for configuring Load Conditions before saving.
- Help tab **Troubleshooting** gains an entry directing users to check Load Conditions when a script does not appear on expected pages.
- JS conditions-UI error strings (invalid ID, duplicate ID, empty pattern, duplicate pattern) now sourced from `scriptomaticData.i18n` via `wp_localize_script`, making them translatable.

### Documentation
- README: WordPress badge and Requirements section corrected from 5.0 to **5.3**.
- README: Short description updated to mention conditional loading.
- README: Features bullet list expanded to include **Conditional Loading**, **Revision History & Rollback**, and **External Script URLs**.
- README: "The code executes on every page" claim replaced with accurate conditional-loading language.
- README: Basic Setup steps updated to reflect actual save-button labels and to mention the Load Conditions step.
- README: Troubleshooting save-button reference corrected from generic "Save Script" to "Save Head Scripts" / "Save Footer Scripts".
- README: Performance Tips Conditional Loading bullet points users to the built-in feature.
- CHANGELOG: Fully rewritten — all prior versions (1.0.0 through 1.3.0) are now documented.

---

## [1.3.0] – 2026-02-26

### Added
- **Conditional loading** — scripts can now be restricted to specific pages rather than being injected everywhere.
  - Eight condition types: `all` (default), `front_page`, `singular`, `post_type`, `page_id`, `url_contains`, `logged_in`, `logged_out`.
  - Independent conditions for head and footer (`SCRIPTOMATIC_HEAD_CONDITIONS` / `SCRIPTOMATIC_FOOTER_CONDITIONS` option keys).
  - `check_load_conditions($location)` evaluates the stored condition on every front-end page load; returns `false` to suppress output with no markup written.
  - `sanitize_conditions_for($raw, $location)` validates and stores condition data; `post_type` values checked via `post_type_exists()`.
  - `render_conditions_field_for($location)` renders the full conditions UI: type `<select>`, post-type checkbox grid, page-ID chicklet manager, and URL-pattern chicklet manager.
  - Load Conditions section added to head-scripts page, footer-scripts page, and both network-admin equivalents.
  - Network admin save handler extended to persist condition data via `update_site_option()`.
- Two new option keys added to `uninstall.php` for cleanup on deletion.
- Conditions CSS (panel show/hide) and JS (`syncJson`, ID and URL-pattern chicklet managers) added to the admin enqueue path.

---

## [1.2.1] – 2026-02-26

### Fixed
- `get_front_end_option()` used `get_option()` on multisite instead of `get_site_option()`; the method now correctly reads network-level options on network-active installs.
- `wp_date()` replaced with `date_i18n()` in the history panel; `wp_date()` requires WordPress 5.3, which was already the minimum but was not declared. "Requires at least" header bumped to 5.3.
- Network admin save handlers bypassed `validate_inline_script()` full validation (length, control chars, PHP tags, dangerous HTML); the same gates now apply on all save paths.
- General settings nonce (`SCRIPTOMATIC_GENERAL_NONCE`) was registered and emitted but never verified; `sanitize_plugin_settings()` now verifies it.
- Removed `type="text/javascript"` from injected `<script>` tags; the attribute is redundant in HTML5.

### Security
- Removed IP address collection from audit logging. `get_client_ip()` helper deleted; all history entries and `log_change()` now record only username, user ID, timestamp, and character count.

---

## [1.2.0] – 2026-02-26

### Added
- **Footer Scripts** admin page (Scripts → Footer Scripts) — inline JS and external URLs injected via `wp_footer` before `</body>`.
- **General Settings** admin page (Scripts → General Settings) — history limit and uninstall data-retention settings.
- **Multisite / Network Admin** support — all three pages exposed under the Network Admin menu; saves routed through `network_admin_edit_scriptomatic_network_save` with nonce + capability gates.
- Separate nonce constants: `SCRIPTOMATIC_HEAD_NONCE`, `SCRIPTOMATIC_FOOTER_NONCE`, `SCRIPTOMATIC_GENERAL_NONCE`, `SCRIPTOMATIC_ROLLBACK_NONCE`, `SCRIPTOMATIC_NETWORK_NONCE`.
- Per-location option constants: `SCRIPTOMATIC_HEAD_SCRIPT`, `SCRIPTOMATIC_FOOTER_SCRIPT`, `SCRIPTOMATIC_HEAD_LINKED`, `SCRIPTOMATIC_FOOTER_LINKED`, `SCRIPTOMATIC_HEAD_HISTORY`, `SCRIPTOMATIC_FOOTER_HISTORY`.
- `get_front_end_option()` helper for multisite-aware option reads on the front end.
- Separate history stacks for head and footer; `push_history()`, `get_history()`, and `ajax_rollback()` accept a `$location` parameter.
- Save-rate limiting per location to prevent rapid repeated saves.
- Shared `render_page_header()`, `render_history_panel($location)`, `render_script_field_for($location)`, and `render_linked_field_for($location)` helpers.

### Fixed (post-release patch — commit `36405f8`)
- Settings-section slug mismatch in settings registration.
- Duplicate `sanitize_script_for` callback registration.
- `register_settings()` not hooked to `network_admin_init`.
- `load_textdomain()` was never called; added to `init`.
- External-link HTML missing `target="_blank" rel="noopener noreferrer"`.
- Orphaned PHPDoc blocks removed.

---

## [1.1.0] – 2026-02-26

### Added
- **External Script URLs** — chicklet-based manager for adding remote `<script src="...">` URLs to the head block; URLs validated (`http/https` only), stored as JSON, and output in order before the inline block.
- **Revision History & Rollback** — every save stores a timestamped snapshot (user login, character count); up to `max_history` revisions retained (default 20). AJAX rollback restores any prior version; the current script is pushed to history before overwriting.
- **Advanced Settings** — `max_history` (1–100) and `keep_data_on_uninstall` (checkbox).
- `uninstall.php` — removes all plugin option keys on deletion; respects `keep_data_on_uninstall`; iterates sub-sites on multisite.

---

## [1.0.0] – 2026-02-26

### Added
- Initial plugin release.
- Head script injection via `wp_head` hook; scripts wrapped in a `<script>` block with comment markers.
- Input validation: maximum length (100 KB), UTF-8 / control-character rejection, automatic `<script>` tag stripping, dangerous-HTML-tag detection (`iframe`, `object`, `embed`, `link`, `style`, `meta`).
- Capability gate — only users with `manage_options` can view or save settings.
- Nonce verification on all form submissions.
- Audit logging — all saves logged to the WordPress error log with username, user ID, and timestamp.
- Admin settings page with live character counter and security notice panel.
- Contextual help tabs: Overview, Usage, Security, Best Practices, Troubleshooting; help sidebar with external resource links.
- "Head Scripts" action link prepended on the Plugins screen.
- GPL v2 licence, Code of Conduct, `SECURITY.md`, and GitHub issue/PR templates.
- `.pot` file for i18n; `load_textdomain()` hooked to `init`.
- Singleton bootstrap via `plugins_loaded`.

---

## Version History

| Version | Date       | Summary                                        |
|---------|------------|------------------------------------------------|
| 1.4.4   | 2026-02-26 | Pre-release audit: doc accuracy, esc_html fix       |
| 1.4.3   | 2026-02-26 | Remove all backward-compat aliases and dead constants |
| 1.4.2   | 2026-02-26 | Code-quality fixes, dead-code removal, doc accuracy |
| 1.4.1   | 2026-02-26 | Multisite network-admin bug fixes              |
| 1.4.0   | 2026-02-26 | Trait refactor, static assets, modular architecture |
| 1.3.1   | 2026-02-26 | Dead-code removal, doc accuracy, i18n fix      |
| 1.3.0   | 2026-02-26 | Conditional per-page loading                   |
| 1.2.1   | 2026-02-26 | Multisite bug fixes, security hardening        |
| 1.2.0   | 2026-02-26 | Footer scripts, multisite, multi-page UI       |
| 1.1.0   | 2026-02-26 | History/rollback, external URLs, settings      |
| 1.0.0   | 2026-02-26 | Initial release                                |

---

[Unreleased]: https://github.com/richardkentgates/scriptomatic/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/richardkentgates/scriptomatic/compare/v1.4.4...v1.5.0
[1.4.4]: https://github.com/richardkentgates/scriptomatic/compare/v1.4.3...v1.4.4
[1.4.3]: https://github.com/richardkentgates/scriptomatic/compare/v1.4.2...v1.4.3
[1.4.2]: https://github.com/richardkentgates/scriptomatic/compare/v1.4.1...v1.4.2
[1.4.1]: https://github.com/richardkentgates/scriptomatic/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/richardkentgates/scriptomatic/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/richardkentgates/scriptomatic/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/richardkentgates/scriptomatic/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/richardkentgates/scriptomatic/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/richardkentgates/scriptomatic/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/richardkentgates/scriptomatic/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/richardkentgates/scriptomatic/releases/tag/v1.0.0
