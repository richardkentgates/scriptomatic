# Changelog

All notable changes to **Scriptomatic** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

_Nothing yet._

---

## [3.0.0] – 2026-03-01

### Added
- **Freemius SDK integration (`freemius/` subfolder).**
  `includes/freemius-init.php` bootstraps the SDK via `fs_dynamic_init()` and exposes two
  global helpers: `scriptomatic_fs()` (Freemius singleton) and `scriptomatic_is_premium()`
  (returns `true` when a valid Pro licence or active trial is present, `false` on free).
  Product ID `25187`; `is_live => true` (production).
- **3-day free trial** (no payment required) — configured via the `trial` key in `fs_dynamic_init()`.
- **Freemius `after_uninstall` hook** registered in `freemius-init.php`.
  `scriptomatic_fs_uninstall_cleanup()`, `scriptomatic_drop_log_table()`, and
  `scriptomatic_delete_uploads_dir()` are now called via the Freemius uninstall action,
  which fires *after* opt-out feedback is reported to the Freemius servers.

### Changed
- **Freemium feature split:**
  - **Free** — Inline script editor (head + footer), External URL manager, Activity log
    with rollback. All free features are fully unlimited with no quantity caps.
  - **Pro** — Conditional loading rules (head/footer inline + per-URL conditions),
    Managed JS Files (create/edit/upload/delete), REST API (`scriptomatic/v1`),
    WP-CLI (`wp scriptomatic`), IP allowlist for REST API access.
- **Conditional loading (`Load Conditions`) is now Pro-only.**
  - Head/footer conditions fields render a styled upgrade notice for free users.
  - Per-URL condition pickers in the External URLs editor are replaced by a lock
    indicator for free users; existing conditions data is preserved in a hidden
    input so it survives a free → Pro upgrade without additional saves.
  - Front-end injector always loads scripts on free (no conditions evaluated).
    On Pro, per-URL, per-file, and inline-script conditions are all evaluated.
- **Managed JS Files page is now Pro-only.**
  - Free users see a `render_js_files_upgrade_page()` with an upgrade CTA instead
    of the file list and editor.
  - All JS file AJAX endpoints (`rollback_js_file`, `get_file_activity_content`,
    `delete_js_file`, `restore_deleted_file`) and the `admin_post` save handler are
    only registered when `scriptomatic_is_premium()` returns true.
  - JS file injection block in `inject_scripts_for()` is skipped on free.
- **REST API is now Pro-only.**
  `rest_api_init` hook for `register_rest_routes()` only fires on Pro.
- **WP-CLI is now Pro-only.**
  `WP_CLI::add_command( 'scriptomatic', ... )` only registers on Pro.
- **IP allowlist (API Allowed IPs) is now Pro-only.**
  The settings field is only registered in the Preferences page on Pro.
  `api_permission_check()` skips the IP check on free (REST API not registered anyway).
- **Bug fix in `inject_scripts_for()`:** The local `$conditions` variable was being
  overwritten by each URL entry's conditions inside the loop, causing the inline script
  to be evaluated against the last URL entry's conditions instead of the location-level
  conditions. Renamed to `$entry_conditions` (loop) and `$loc_conditions` (location
  level) to fix the shadow.

### Removed
- **`uninstall.php` deleted.** Static `register_uninstall_hook()` / `uninstall.php`
  patterns conflict with Freemius's uninstall tracking. All cleanup logic has been moved
  into functions inside `includes/freemius-init.php` and hooked to
  `scriptomatic_fs()->add_action('after_uninstall', ...)`.
- **Version bumped to 3.0.0.**

---

## [2.9.0] – 2026-02-28

### Added
- **Custom activity log DB table (`{prefix}scriptomatic_log`).**
  Replaces the previous `wp_options`-based activity log (`scriptomatic_activity_log`).
  Table columns: `id` (PK, auto-increment), `timestamp`, `user_id`, `user_login`,
  `action`, `location`, `file_id`, `detail`, `chars`, `snapshot` (JSON LONGTEXT).
  Indexed on `(location, action)` and `file_id` for efficient filtered reads.
  Created at `admin_init` via `maybe_create_log_table()` which calls `dbDelta()`.
- **`write_activity_entry()` now writes to DB.** Uses `$wpdb->insert()` then prunes
  by finding the Nth most-recent row's ID and issuing `DELETE WHERE id < that_id`,
  avoiding a full-table read on every write.
- **`get_activity_log($limit, $offset, $location, $file_id)` now queries DB.**
  Optional location and file_id filters are pushed into SQL — no PHP-side array
  filtering needed. Zero limit defaults to `get_max_log_entries()`.
- **`get_log_entry_by_id($id)` new method.** Fetches a single log row by primary
  key; returns a decoded entry array or `null` when not found.
- **`decode_log_row(array $row)`** new private helper. Expands the JSON `snapshot`
  column into the flat entry shape expected by AJAX handlers and service methods.
- Activity log and history AJAX endpoints now accept an **`id` parameter** (DB row
  primary key) instead of `index` (array position). JavaScript updated accordingly.
- REST API rollback endpoints (`script/rollback`, `urls/rollback`) now accept an
  `id` parameter instead of `index`. Each validates location and action against the
  fetched DB row to prevent cross-location restores.
- `uninstall.php` now calls `scriptomatic_drop_log_table()` to `DROP TABLE IF EXISTS
  {prefix}scriptomatic_log` for each site on plugin deletion.

### Removed
- **`SCRIPTOMATIC_ACTIVITY_LOG_OPTION` constant.** The legacy `scriptomatic_activity_log`
  wp_option is no longer used; the constant has been removed.
- **`maybe_migrate_log_to_table()` migration helper.** Removed as dead code — this
  is a first release with no existing installs to migrate.
- `scriptomatic_activity_log` removed from `uninstall.php` option cleanup list.

---

## [2.8.0] – 2026-02-28

### Changed
- **Location data consolidated into two unified option keys.**
  Six fragmented wp_options (`scriptomatic_script_content`, `scriptomatic_linked_scripts`,
  `scriptomatic_head_conditions`, `scriptomatic_footer_script`, `scriptomatic_footer_linked`,
  `scriptomatic_footer_conditions`) are replaced by two PHP array options:
  `scriptomatic_head` and `scriptomatic_footer`. Each array stores `{ script, conditions, urls }`.
- **Two new location constants** `SCRIPTOMATIC_LOCATION_HEAD` (`scriptomatic_head`)
  and `SCRIPTOMATIC_LOCATION_FOOTER` (`scriptomatic_footer`) replace the six individual
  option key constants.
- **`get_location($location)` / `save_location($location, $data)` helpers** added to
  `trait-settings.php` as the single read/write interface for location data.
- All sanitizer, history, injector, API, and CLI code updated to use the new helpers.

### Added
- **`maybe_migrate_to_v2_8()` one-time migration** runs at `admin_init` on first load
  after upgrade, reading the six old options and writing the two new ones, then deleting
  the old keys.

### Removed
- Constants: `SCRIPTOMATIC_HEAD_SCRIPT`, `SCRIPTOMATIC_HEAD_LINKED`,
  `SCRIPTOMATIC_HEAD_CONDITIONS`, `SCRIPTOMATIC_FOOTER_SCRIPT`,
  `SCRIPTOMATIC_FOOTER_LINKED`, `SCRIPTOMATIC_FOOTER_CONDITIONS`.

---

## [2.7.0] – 2026-03-07

### Added
- **JS file upload in the admin UI.**
  The **JS Files list page** gains a dedicated **Upload a JS File** card with
  a file picker and an **Upload &amp; Edit** button. Submitting the form
  performs a real server-side POST (`admin_post_scriptomatic_save_js_file`
  with `_sm_upload_source=list`). After a successful upload the handler
  redirects directly to the edit page so you can review the content, set
  load conditions, and save. The file is never read in the browser — there
  is no `FileReader` path. Server-side validation covers PHP upload errors,
  `.js`-only extension, file size against `wp_max_upload_size()`, and MIME
  type via `finfo_open` with a `mime_content_type` fallback (implemented
  in `validate_js_upload()` in `trait-files.php`).
- **REST API IP allowlist.**
  Preferences → Advanced Settings gains an **API Allowed IPs** textarea
  (`api_allowed_ips` setting key, sanitised on save). Accepts one IPv4
  address, IPv6 address, or IPv4 CIDR range per line; empty means allow all.
  Every REST API request is checked against the list before the capability
  gate. Returns a `403 rest_ip_forbidden` error when denied. Does not affect
  the WordPress admin interface. Implemented in `api_check_ip_allowlist()` and
  `api_ip_in_cidr()` private methods in `trait-api.php`.
- **`POST /wp-json/scriptomatic/v1/files/upload` REST endpoint.**
  Upload a `.js` file as `multipart/form-data` (key `file`). Optional
  parameters: `label`, `file_id` (overwrite), `location`, `conditions`.
  Validated through `validate_js_upload()` and persisted through
  `service_set_file()` — identical to the admin UI pipeline.
- **`wp scriptomatic files upload --path=<file>` WP-CLI command.**
  Reads a local `.js` file and saves it through the same service layer as the
  REST API and admin UI. Optional flags: `--label`, `--id`, `--location`,
  `--conditions`.
- **`service_upload_file(array $file_data, array $params)` service-layer method.**
  Public method on the main class shared by both the REST callback and the CLI
  command. Calls `validate_js_upload()` then `service_set_file()`.
- **`validate_js_upload(array $file)` private method in `trait-files.php`.**
  Central validation pipeline for all upload paths. Skips
  `is_uploaded_file()` for CLI callers (signalled by `_cli => true` in the
  file array).

---

## [2.6.0] – 2026-03-07

### Added
- **REST API (`scriptomatic/v1`).**
  All twelve endpoints are POST-only; credentials travel exclusively in the
  `Authorization: Basic` header via WordPress Application Passwords (no custom
  key storage, no credentials in URLs).
  - `POST /wp-json/scriptomatic/v1/script` — read current inline script.
  - `POST /wp-json/scriptomatic/v1/script/set` — save inline script + optional load conditions.
  - `POST /wp-json/scriptomatic/v1/script/rollback` — restore inline script to snapshot (index ≥ 1).
  - `POST /wp-json/scriptomatic/v1/history` — list inline script history.
  - `POST /wp-json/scriptomatic/v1/urls` — read current external URL list.
  - `POST /wp-json/scriptomatic/v1/urls/set` — replace external URL list.
  - `POST /wp-json/scriptomatic/v1/urls/rollback` — restore external URLs to snapshot.
  - `POST /wp-json/scriptomatic/v1/urls/history` — list external URL history.
  - `POST /wp-json/scriptomatic/v1/files` — list managed JS files.
  - `POST /wp-json/scriptomatic/v1/files/get` — read one file's content + metadata.
  - `POST /wp-json/scriptomatic/v1/files/set` — create or update a managed JS file.
  - `POST /wp-json/scriptomatic/v1/files/delete` — delete a managed JS file.
- **WP-CLI command group (`wp scriptomatic`).**
  - `wp scriptomatic script get|set|rollback` — manage inline scripts.
  - `wp scriptomatic history` — list inline script history.
  - `wp scriptomatic urls get|set|rollback|history` — manage external URL lists.
  - `wp scriptomatic files list|get|set|delete` — manage JS files.
  All write commands delegate to the same service layer as the REST API to
  ensure identical validation, rate-limiting, and activity logging.
- **Service layer** — public `service_*()` methods on the main class shared by
  both the REST API callbacks and WP-CLI commands, eliminating code duplication.
- **`includes/trait-api.php`** — new trait housing REST route registration,
  permission callbacks, argument schemas, REST callback methods, the service
  layer, and the `api_validate_script_content()` private helper.
- **`includes/class-scriptomatic-cli.php`** — new class extending
  `WP_CLI_Command`; loaded only when `WP_CLI` is defined.

---

## [2.5.3] – 2026-02-28

### Fixed
- **Restore button now greyed out for entries with an empty external-URL
  snapshot.** A "URL removed" (or "all URLs cleared") log entry carried
  `urls_snapshot = '[]'`; the Restore button was enabled, but clicking it
  would simply wipe the current URL list — no meaningful restore. The button is
  now disabled with the tooltip "No external URLs in this snapshot — nothing to
  restore." (Complements the existing index-0 / current-state disable added in
  v2.5.2.)
- **User-facing "URL list" language replaced with "external URLs" throughout.**
  All Activity Log labels, tooltips, success messages, and help tab copy now
  say "external URLs" instead of "URL list", consistent with the
  "External Script URLs" section heading and the rest of the admin UI.
  Includes two `ajax_rollback_urls()` response strings ("External URLs restored
  from snapshot" / "External URLs restored successfully.") that were missed in
  the initial pass.
- **Stale docblocks corrected.**  `write_activity_entry()` action list now
  includes `url_save`, `url_rollback`, and `file_restored`.  `ajax_rollback()`
  docblock no longer describes the removed v2.5.0 combined-restore behaviour
  (URLs were separated into their own independent restore path in v2.5.2).
  `Scriptomatic_Files` trait added to the class-level trait list in
  `class-scriptomatic.php`.

---

## [2.5.2] – 2026-02-28

### Fixed
- **Activity Log now writes fully independent entries per dataset.** Inline
  script + load-conditions changes produce a `save` entry; external URL list
  changes produce a separate `url_save` entry. The two are never combined.
  Each dataset has its own View and Restore buttons, its own index counter, and
  its own rollback path — restoring one dataset never affects the other.
- **Restore button is now disabled for the current-state (index 0) entry.**
  The Restore button on the most recent inline-script entry and the most recent
  URL-list entry is now greyed out with a tooltip, since those entries already
  reflect the live state and restoring them would have no effect.
- **Conditions-only saves now always produce a complete, restorable log entry.**
  When only load conditions changed (no script edit), the log entry previously
  lacked a `content` key and rendered no View or Restore buttons. The entry
  writer now snapshots the current script from the database when the script
  sanitizer did not contribute to the accumulator, so every inline entry always
  carries a full content + conditions snapshot.

---

## [2.5.1] – 2026-02-28

### Fixed
- **Restore/Load/Delete buttons displayed `\u2026` instead of `…` as a literal
  string in the browser.** The `wp_localize_script` i18n strings for
  `restoring`, `loading`, and `deleting` used PHP double-quoted `"\u2026"`
  escape sequences. PHP does not expand `\u` escapes — the backslash was
  passed verbatim into the JSON payload, so the button text read
  `Restoring\u2026` rather than `Restoring…`. Fixed by using the actual UTF-8
  ellipsis character `…` in single-quoted strings.

---

## [2.5.0] – 2026-03-01

### Changed
- **Activity Log now writes one combined snapshot entry per save click.**
  Previously, a single Head/Footer Save produced up to three separate partial
  entries (`save`, `url_added`, `conditions_save`). Now the WordPress Settings
  API sanitize callbacks each contribute their piece to a shared accumulator
  and a single combined entry is flushed at PHP shutdown. Every entry contains
  the full state at that moment — inline script content, external URL list, and
  load conditions — so a single Restore brings everything back simultaneously.
- **Restore now restores all three fields at once.** When a combined snapshot
  entry (v2.5.0+) is available, clicking Restore writes the script content,
  URL list, and conditions options back together via direct `$wpdb` writes,
  bypassing the sanitize callbacks exactly as the script-only rollback did.
  Rollback log entries also carry all three snapshots for forward reference.
- **Activity Log table "Size / Detail" column renamed to "Changes".** The column
  now shows a human-readable summary built from the combined entry's `detail`
  field (e.g. `Script: 245 chars · 1 URL added · Conditions: Front page only`),
  giving immediate visibility into what changed on each save.
- **Event column now uses explicit human-readable labels.** A label map replaces
  the generic `ucwords(str_replace('_', ' ', $action))` fallback for all known
  action types (Save, Restore, URL Added, URL Removed, Conditions,
  Conditions Restored, URLs Restored, File Save, File Restore, File Deleted).

### Removed
- **`log_change()` private method** in `trait-settings.php`. Logging of script
  content changes is now handled by `contribute_to_pending_save()` in
  `trait-sanitizer.php` as part of the combined-snapshot accumulator.

---

## [2.4.0] – 2026-02-28

### Fixed
- **File conditions UI now uses the stacked-rule picker.** `render_file_conditions_widget()` was still rendering the legacy single-select UI. It now delegates to `render_conditions_stack_ui()`, matching the behaviour of inline-script conditions and URL-entry conditions. Legacy `{type,values}` saved data is auto-migrated to `{logic,rules}` on render.
- **Duplicate `conditions_save` Activity Log entries.** `sanitize_conditions_for()` was missing the `static $logged_this_request` guard that protects the other sanitize callbacks from the WordPress Settings API double-call. This caused two identical `conditions_save` entries to be written on every save. The guard is now in place.

---

## [2.3.0] – 2026-02-28

### Changed
- **Activity Log entries are now strictly single-concern.** Each log entry
  records only its own action's data — no more cross-data bundling:
  - `url_added` / `url_removed` entries carry only `urls_snapshot` (no
    conditions snapshot).
  - `conditions_save` entries carry only `conditions_snapshot` (no URL
    snapshot).
  - `save` / `rollback` entries carry only `content` + `chars` (no URL or
    conditions snapshots).
- **Restore is now greyed out for deliberate removals and empty states.**
  The Restore button is disabled (but View remains active for audit reference)
  when the action is a `url_removed` event, a `save`/`rollback` with empty
  content, or a `file_delete` event. Hovering the button shows a tooltip
  explaining why. To undo these actions, find an earlier log entry with the
  desired state and click Restore there.
- **Cross-restore side-effects removed.** Restoring a URL list snapshot no
  longer silently overwrites conditions; restoring a conditions snapshot no
  longer silently overwrites the URL list; rolling back an inline script no
  longer silently overwrites URLs or conditions. Each Restore is now a
  surgical single-field operation.

---

## [2.2.0] – 2026-03-01

### Fixed
- **Inline script load conditions UI.** The condition type select on the Head
  Scripts and Footer Scripts pages no longer rendered sub-panels when a type
  was chosen. `render_conditions_field_for()` was still using the legacy
  single-select UI while `initConditions()` in admin.js expected the new
  stacked rules UI. The function now delegates to `render_conditions_stack_ui()`
  with automatic migration of any saved legacy `{type, values}` data to the
  `{logic, rules}` stacked format.
- **Ambiguous activity-log condition labels.** The Activity Log previously
  displayed both per-URL conditions and inline-script conditions under the
  identical heading `=== Load Conditions ===`, making the log appear
  contradictory. Inline-script condition snapshots now appear as
  `=== Inline Script Load Conditions ===` and file load conditions appear as
  `=== File Load Conditions ===`.

---

## [2.1.0] – 2026-02-28

### Added
- **Save confirmation notices.** Head Scripts, Footer Scripts, and Preferences
  pages now display a green "Settings saved." notice after a successful save.
  Previously a successful save gave no visual feedback — only errors/warnings
  were surfaced.
- **Empty-file auto-delete.** Saving a JS file with empty content now deleted
  the file rather than writing a blank `.js` to disk:
  - *Existing file* — removed from disk and metadata; a `file_delete` Activity
    Log entry is written with `meta.reason = 'empty_save'` (full content
    snapshot retained for restore); redirects to the JS Files list with a
    warning notice.
  - *New file* — redirects back to the edit form with an "empty content" error.

### Fixed
- Plugin header `Version` tag corrected (`1.12.0` → `2.1.0`); the constant
  `SCRIPTOMATIC_VERSION` has been updated to `'2.1.0'` to match.
- Plugin description corrected: "audit logging" → "activity logging".

---

## [2.0.0] – 2026-02-28

### Changed
- **First public release.** All internal development backward-compatibility code removed; the codebase now reflects a clean, single-version baseline.
- Conditions storage is exclusively the `{logic, rules}` stacked format; all legacy `{type, values}` single-condition auto-migration paths have been removed throughout `trait-sanitizer.php`, `trait-renderer.php`, `trait-injector.php`, `trait-pages.php`, `trait-history.php`, and `trait-files.php`.
- External script URL lists are exclusively the `[{url, conditions}]` array format; legacy plain-string URL migration paths removed from `trait-sanitizer.php`, `trait-renderer.php`, and `trait-injector.php`.
- Activity log is read directly via `get_option( SCRIPTOMATIC_ACTIVITY_LOG_OPTION )` with an empty-array default; the one-time migration from legacy per-location `head_history` / `footer_history` options has been removed from `trait-settings.php`.
- `write_activity_entry()` is now the sole logging method; the `write_audit_log_entry()` backward-compat alias has been removed.
- Removed deprecated `render_audit_log_table()` no-op stub and unused `maybe_clear_audit_log()` method from `trait-pages.php`.
- Removed constants: `SCRIPTOMATIC_HEAD_HISTORY`, `SCRIPTOMATIC_FOOTER_HISTORY`, `SCRIPTOMATIC_DEFAULT_MAX_HISTORY`, `SCRIPTOMATIC_AUDIT_LOG_OPTION`, `SCRIPTOMATIC_CLEAR_LOG_NONCE`.
- Default registered conditions value updated from `{"type":"all","values":[]}` to `{"logic":"and","rules":[]}` in both Settings API registrations.
- Help tab Security section updated to list all current Activity Log action types including `conditions_save`, `url_list_restored`, `conditions_restored`, and `file_restored`.

---

## [1.12.0] – 2026-06-05

### Added
- **Full Activity Log snapshots.** Every log entry now bundles:
  - `urls_snapshot` — the complete URL list at the time of the change.
  - `conditions_snapshot` — the full conditions data at the time of the change.
- **Conditions changes logged.** `sanitize_conditions_for()` now detects when load conditions change and writes a dedicated `conditions_save` event to the Activity Log with a human-readable summary in the Detail column.
- **URL-list snapshot on URL add/remove.** `sanitize_linked_for()` attaches the full new URL list to every `url_added` / `url_removed` entry.
- **File save/delete snapshots.** `handle_save_js_file()` includes `conditions` and file `meta` in `file_save` log entries; `ajax_delete_js_file()` captures `content`, `conditions`, and `meta` in `file_delete` entries to enable full restoration.
- **Rich View lightbox.** The View lightbox now shows a formatted plaintext display (`format_entry_display`) that includes File Info, Script Code, External Script URLs, and Load Conditions — for _all_ entry types.
- **Universal Restore.** New Restore buttons for every Activity Log row type:
  - `url_added` / `url_removed` → "Restore" restores the complete URL list.
  - `conditions_save` → "Restore" re-applies the saved conditions.
  - `file_delete` → "Re-create" writes the deleted JS file back to disk and re-inserts its metadata.
  - Inline script `save` / `rollback` now also restores URL list and conditions alongside the code.
  - Managed JS File `file_save` / `file_rollback` now also restores file conditions alongside the code.
- **Five new AJAX endpoints:** `scriptomatic_get_url_list_content`, `scriptomatic_restore_url_list`, `scriptomatic_get_conditions_content`, `scriptomatic_restore_conditions`, `scriptomatic_restore_deleted_file`.

### Fixed
- `handle_save_js_file()` was parsing the `sm_file_conditions` POST field with a hard-coded legacy `{type, values}` parser introduced before v1.11.0; now correctly delegates to `sanitize_conditions_array()` which handles the current `{logic, rules}` stacked format.

---

## [1.11.0] – 2026-03-04

### Added
- **Stacked load conditions (AND / OR multi-rule).** Every conditions picker now supports building a stack of rules rather than a single condition:
  - **AND mode** ("All rules") — the script loads only when _every_ rule in the stack matches.
  - **OR mode** ("Any rule") — the script loads when _at least one_ rule matches.
  - The AND/OR logic toggle is hidden when there are fewer than two rules and appears automatically once a second rule is added.
  - A "+ Add Condition" button adds new rule cards; each card has its own type select and sub-panel (identical to the old single-condition UI).
  - An empty stack ("no rules") means "load on all pages" — backwards-compatible with the pre-existing default behaviour.
- `migrate_conditions_to_stack()` in `trait-renderer.php` — transparently converts stored legacy `{type, values}` data to the new `{logic, rules}` format so all existing conditions continue to work without a database migration.
- `evaluate_single_rule()` in `trait-renderer.php` — extracted per-rule evaluation with all 11 condition-type cases.
- `render_condition_rule_card_html()` in `trait-renderer.php` — renders a single rule card (type select + 8 sub-panels + remove button) used both for PHP server-render and the JS `<template>` clone.
- `render_conditions_stack_ui()` in `trait-renderer.php` — renders the full stacked widget (logic row, rules list, no-conditions message, add button, hidden JSON input, and `<template>`) used by all three conditions pickers.
- New CSS rules in `admin.css` for `.sm-rule-card`, `.sm-logic-row`, `.sm-rules-list`, `.sm-no-conditions-msg`, `.sm-add-rule`, `.sm-chicklet`/`.sm-chicklet-remove`, `.sm-chicklet-add-row`, `.sm-date-range-row`, `.sm-month-grid`.

### Changed
- **New conditions data format**: `{"logic":"and","rules":[{"type":"logged_in","values":[]},{"type":"by_month","values":[12]}]}`. Old format `{"type":"...","values":[]}` is auto-migrated; `type:"all"` becomes an empty rules array.
- `sanitize_conditions_array()` in `trait-sanitizer.php`: rewritten to accept and validate the new `{logic, rules}` stack format; added `sanitize_single_rule()` helper. Legacy format auto-migrated on save.
- `evaluate_conditions_object()` in `trait-renderer.php`: refactored to delegate to `migrate_conditions_to_stack()` + loop over `evaluate_single_rule()` with AND/OR short-circuit logic.
- `render_conditions_field_for()`, `render_url_entry_html()`, and `render_file_conditions_widget()` in `trait-renderer.php`: all delegate to the new shared `render_conditions_stack_ui()` method; all inline condition panels removed from these callers.
- `initConditions()` in `admin.js`: completely rewritten with rule-card architecture (`syncJson`, `updateUI`, `initRuleCard`, `addRule`). Old single-type approach removed.
- `syncLinked()` default condition in `admin.js`: `{type:'all',values:[]}` → `{logic:'and',rules:[]}`.
- Condition label in the Managed JS Files list view (`trait-pages.php`): 0 rules → "All pages"; 1 rule → the rule type label; 2+ rules → "N rules (AND/OR)".

---

### Added
- **Four new date/time condition types.** Load conditions now support date and time-based targeting in addition to the existing page/user-state types:
  - `by_date` — loads only between a **From** and **To** calendar date (inclusive). Leave _To_ blank for an exact single-day match.
  - `by_datetime` — loads only within a **From** / **To** `datetime-local` window (inclusive), evaluated against the site timezone via `current_time()`.
  - `week_number` — loads when the current ISO week number (1–53) matches any of the listed values; uses a chicklet input identical to the existing Page ID picker.
  - `by_month` — loads during specific months; uses a 12-checkbox grid (January–December) identical to the Post Types picker. Month labels use `date_i18n( 'F' )` for full locale support.
- All four types are available on every conditions picker: inline-script load conditions (head and footer), per-URL entry conditions, and managed JS file conditions.

### Changed
- `sanitize_conditions_array()` in `trait-sanitizer.php`: extended `$allowed_types`; added `by_date`/`by_datetime` (truncate to 2 string values), `week_number` (validated 1–53 integers), and `by_month` (validated 1–12 integers) sanitization cases.
- `evaluate_conditions_object()` in `trait-renderer.php`: added four new `switch` cases using `current_time()` for timezone-correct evaluation.
- All three render methods (`render_conditions_field_for()`, `render_url_entry_html()`, `render_file_conditions_widget()`) updated with new labels and sub-panels for each type.
- `initConditions()` in `admin.js`: `syncJson()` extended with four new branches; delegated `change` handlers added for date/datetime inputs and month checkboxes; week-number chicklet manager (range validation 1–53, duplicate detection, Enter-key support) and extended shared remove handler added.
- `$condition_labels` in `render_js_file_list_view()` (`trait-pages.php`) updated with the four new display labels for the JS Files list view.

---

## [1.9.0] – 2026-02-27

### Added
- **Unified Activity Log.** The separate Revision History panel and Audit Log table on the Head Scripts and Footer Scripts pages have been merged into a single **Activity Log** panel per location. Every event type — script saves, rollbacks, external URL additions/removals, JS file saves, JS file rollbacks, and JS file deletions — appears in one table. Rows that carry a content snapshot (saves and rollbacks) expose **View** and **Restore** buttons; informational rows (URL events, file deletions) are shown without action buttons. The Activity Log panel is also added to the JS Files list view (all file events) and the JS Files edit view (filtered to the current file only).
- **JS Files activity log entries.** `file_save` and `file_rollback` entries carry a full content snapshot, enabling View and Restore on managed JS files. `file_delete` entries are informational only.
- **Per-location Clear Log.** The **Clear Log** button (previously present only on head/footer pages) now appears on all activity log panels and clears only the entries for that location, leaving other locations untouched.
- **`ajax_rollback_js_file()` AJAX handler.** Restores a managed JS file from a content snapshot stored in the activity log; writes a `file_rollback` entry on success.
- **`ajax_get_file_activity_content()` AJAX handler.** Returns the raw content of a JS-file activity log entry for the View lightbox.
- **One-time migration.** On first load after upgrade, `get_activity_log()` automatically migrates existing head/footer revision history (with content snapshots) and legacy URL-event audit log entries into the new unified option (`scriptomatic_activity_log`), then deletes nothing — old options remain until uninstall.

### Changed
- **`SCRIPTOMATIC_ACTIVITY_LOG_OPTION`** (`scriptomatic_activity_log`) replaces the per-location `SCRIPTOMATIC_HEAD_HISTORY`, `SCRIPTOMATIC_FOOTER_HISTORY`, and `SCRIPTOMATIC_AUDIT_LOG_OPTION` options as the live data store. Legacy constants and options are retained for migration reads and uninstall cleanup.
- **`write_activity_entry()`** replaces both `push_history()` and `write_audit_log_entry()`. The old `write_audit_log_entry()` is kept as a pass-through alias so existing callers continue to compile during the transition.
- **`get_history($location)`** now filters the unified activity log instead of reading a per-location option; AJAX handlers `ajax_rollback()` and `ajax_get_history_content()` are unchanged.
- **`max_history` setting removed.** The separate History Limit field on the Preferences page has been removed. A single **Activity Log Limit** (3–1000, default 200) now governs retention across all locations.
- **`maybe_clear_audit_log()` is now fully wired.** The method was defined but never registered on `admin_init`; it is now properly hooked. The `scriptomatic-files` page slug is also accepted, and clearing now targets `SCRIPTOMATIC_ACTIVITY_LOG_OPTION` by location.
- **`SCRIPTOMATIC_CLEAR_LOG_NONCE`** constant was referenced but never defined; it is now declared in `scriptomatic.php`.
- `uninstall.php` updated to delete `scriptomatic_activity_log`; legacy keys (`scriptomatic_script_history`, `scriptomatic_footer_history`, `scriptomatic_audit_log`) are also deleted to clean up migrated data.

### Fixed
- **Broken `log_change()` calls in `trait-files.php`.** Both the save and delete handlers called `$this->log_change('js_file', $label, '', $content)` — four arguments — but `log_change()` only accepts three. JS file events were therefore logged with `action='save'`, `location=''`, and `chars=7` (the byte-count of the literal string `'js_file'`). Both callers now use `write_activity_entry()` with the correct `file_save` / `file_delete` action, proper `file_id`, and — for saves — a full content snapshot.

---

## [1.8.0] – 2026-02-27

### Added
- **Managed JS Files.** New **JS Files** sub-menu (between Footer Scripts and Preferences) lets administrators create, edit, and delete standalone `.js` files stored in `wp-content/uploads/scriptomatic/`. Each file has: a human-readable label; an auto-slugged editable filename with `.js` enforced; a Head / Footer injection selector; the full Load Conditions picker (all 8 condition types); and a CodeMirror JavaScript editor with WP/jQuery-specific autocomplete hints. File size is capped at the site's `wp_max_upload_size()`. The edit form is a full admin page with a size counter showing KB/MB. Files are deleted from disk on uninstall (unless "keep data" is enabled).
- **CodeMirror code editor for inline scripts.** The inline-script `<textarea>` on the Head Scripts and Footer Scripts pages is now upgraded to a full CodeMirror JavaScript editor (line numbers, bracket matching, auto-close brackets, Ctrl-Space autocomplete). The hint function merges CodeMirror's built-in JS completions with a curated list of WordPress/jQuery globals: `jQuery`, `$.ajax/post/get`, `wp.ajax`, `wp.hooks.addFilter/addAction/applyFilters/doAction`, `wp.data`, `wp.i18n.__`, `wp.apiFetch`, `ajaxurl`, `pagenow`, and more. The form `submit` handler syncs the editor value back to the textarea before POST. Rollback `setValue()` updates the live editor on success. Falls back gracefully to the plain textarea when the user has disabled syntax highlighting in their WordPress profile.
- **View button on revision history entries.** Each row in the Inline Script History panel now has a **View** button that opens a full-page glass-effect lightbox showing the revision's script content in a monospace block, with the save date and user as metadata. Closes on `Escape`, clicking outside the card, or the `×` button. New AJAX action `scriptomatic_get_history_content` returns the content for a given index/location, reusing the existing rollback nonce.
- **URL add/remove audit log entries.** `sanitize_linked_for()` now diffs the old and new URL lists on every save and writes a `url_added` or `url_removed` audit log entry for each changed URL, storing the URL in a new `detail` field. A static double-call guard prevents duplicates from the Settings API double-invocation.

### Fixed
- **Rate limiter false positive on first save.** The WordPress Settings API invokes sanitize callbacks twice per POST request. The second call was triggering the 10-second rate limiter even though it was not a genuine second save. Fixed with a `static $processed_this_request` guard that skips the rate check, history push, and audit log write on the second invocation within the same request.
- **AJAX rollback silently cleared script content.** `update_option()` on a Settings-API-registered option re-runs the registered sanitize callback. In an AJAX context there is no `$_POST` nonce field, so the secondary nonce check failed and returned the previously stored (empty) value. Fixed by using `$wpdb->update()` + `wp_cache_delete()` directly, bypassing the sanitize callback entirely.
- **`uninstall.php` null warning.** The plugin options array was defined at file scope and accessed via `global` inside `scriptomatic_uninstall_cleanup()`. WordPress executes uninstall files inside its own `uninstall_plugin()` function, so file-scope variables are not accessible via `global`. Fixed by moving the array definition inside the function.
- **`trait-enqueue.php` orphaned braces.** A previous edit left the `$location` assignment with a missing `elseif`/`else` structure and orphaned closing braces. Corrected to a clean `if / elseif / else` block.

### Changed
- **Audit log filtered by location.** Each scripts page (Head, Footer) now filters the audit log table to show only entries for its own location. The Location column has been removed from the table since it is implicit.
- **Inline Script History heading.** The history panel heading was renamed from "%s Script History" to "Inline Script History" to be unambiguous.
- **History panel description.** The description now reads "Showing N saved revisions of the Head/Footer inline script…" to make the scope explicit.
- **Load Conditions section moved above External Script URLs.** On both the Head Scripts and Footer Scripts pages, the Load Conditions section now appears immediately after the Inline Script section, before External Script URLs.
- **General Settings page renamed to Preferences.** The submenu label, browser tab title, help tab references, and README all updated.

### Security
- **Singleton clone/deserialization guard.** Added `__clone()` (disabled) and `__wakeup()` (calls `_doing_it_wrong()`) to `class-scriptomatic.php` to prevent the singleton being duplicated via object cloning or PHP `unserialize()` deserialization, which could register duplicate hooks.
- **`sanitize_linked_for()` — capability + secondary nonce gates added.** The external URL list save callback now verifies `current_user_can( 'manage_options' )` and, when a secondary nonce field is present, validates it against the per-location nonce action. Matches the existing pattern in `sanitize_script_for()`.
- **`sanitize_conditions_for()` — capability + secondary nonce gates added.** Same two-gate pattern applied to the load-conditions sanitizer callback; it previously had neither check.
- **`sanitize_plugin_settings()` — capability check added.** The Preferences save callback now verifies `current_user_can( 'manage_options' )` before processing input; the secondary nonce check was already in place.
- **Open redirect in `maybe_clear_audit_log()` eliminated.** The post-clear redirect was constructed with `wp_get_referer()`, which reads the HTTP `Referer` header and can be spoofed by an attacker. Replaced with an explicit `admin_url( 'admin.php' )` call using the already-validated `$page` slug, so the destination is always a known-safe admin page.
- **`inject_scripts_for()` — `esc_html()` applied to label in HTML comments.** The location label written into the `<!-- Scriptomatic … -->` page comment is now escaped with `esc_html()` as a defence-in-depth measure (the value is currently constrained to `'head'` or `'footer'` at all call sites, but correct form prevents future exposure if call sites change).

---

## [1.7.0] – 2026-02-27

### Added
- **Configurable audit log limit.** The maximum number of retained audit log entries is now a setting on the General Settings page (3–1000, default 200). Previously the cap was a hard-coded constant.

### Fixed
- **`addUrl()` template clone.** The JS `addUrl()` function was using `$.parseHTML( html.trim() ).filter( '.sm-url-entry' )` to extract the cloned entry from the template, which returned an empty set because `$.parseHTML` produces a flat array whose root element is the entry itself. Changed to `$( '<div>' ).html( html ).children( '.sm-url-entry' )`, which reliably wraps and extracts the element. Fixes: no entry card appearing when clicking **Add URL**.

### Changed
- **Network admin UI removed.** Scriptomatic is now strictly per-site. All network admin menus, page renderers, save handlers, and multisite-branching code have been removed (`add_network_admin_menus()`, `render_network_head_page()`, `render_network_footer_page()`, `render_network_general_page()`, `handle_network_settings_save()`, `add_network_action_links()`, `get_network_cap()`, `is_network_active()`, `get_front_end_option()`). All `is_network_admin()` guards, `get_site_option()` reads, and `update_site_option()` writes replaced with direct `get_option()` / `update_option()` calls. Network-level install, activate, and deactivate hooks remain standard WordPress behaviour and are unaffected.
- **Audit log moved to Head Scripts and Footer Scripts pages.** The audit log table is now embedded at the bottom of each scripts page, below the revision history panel. The separate Audit Log submenu page has been removed.
- **Page header simplified.** The author and documentation info paragraph has been removed from every admin page header. Documentation links remain accessible from the page help tab.
- **`SCRIPTOMATIC_NETWORK_NONCE` constant removed** (no longer needed after network admin removal).

---

## [1.6.0] – 2026-02-27

### Added
- **Per-script load conditions.** Every entry in the External Script URLs list now has its own independent load-condition picker (All Pages, Front Page, Singular, Post Type, Page ID, URL Contains, Logged In, Logged Out). The inline script textarea retains its own load-condition picker, unchanged. This replaces the previous model where a single condition applied to every URL in a location's list.
- New private PHP method `evaluate_conditions_object( array $conditions )` in `trait-renderer.php` — extracted condition-evaluation logic shared by `check_load_conditions()` (inline script) and `inject_scripts_for()` (per-URL checks).
- New private PHP method `render_url_entry_html( $location, $idx, $url, array $conditions, $post_types, $is_template )` in `trait-renderer.php` — renders a single URL entry card with an embedded conditions wrap; used for both existing entries and the JS `<template>` prototype (with `__IDX__` placeholders).
- New private PHP method `sanitize_conditions_array( array $raw )` in `trait-sanitizer.php` — shared sanitisation logic for a `{type, values}` conditions object; called by both `sanitize_conditions_for()` (inline) and `sanitize_linked_for()` (per-URL).
- New JS functions `syncLinked()`, `initEntry()`, and `addUrl()` in `assets/admin.js` replacing the old chicklet-only URL manager (Section 2).

### Changed
- **Data model for `SCRIPTOMATIC_HEAD_LINKED` / `SCRIPTOMATIC_FOOTER_LINKED`**: Stored format changed from `["url1","url2"]` (plain URL strings) to `[{"url":"url1","conditions":{"type":"all","values":[]}}]` (URL + conditions objects). Legacy plain-string values are automatically migrated on read/save without data loss.
- `sanitize_linked_for()` in `trait-sanitizer.php`: rewritten to handle the new `{url, conditions}` entry format; delegates conditions sanitisation to `sanitize_conditions_array()`.
- `sanitize_conditions_for()` in `trait-sanitizer.php`: inner switch factored out to `sanitize_conditions_array()`.
- `render_linked_field_for()` in `trait-renderer.php`: rewritten to render `.sm-url-manager` entry cards with per-URL conditions and a cloneable `<template>` element.
- `check_load_conditions()` in `trait-renderer.php`: now delegates to `evaluate_conditions_object()` and applies only to the inline script textarea.
- `inject_scripts_for()` in `trait-injector.php`: rewrote per-URL loop to evaluate each entry's conditions via `evaluate_conditions_object()` instead of a single global gate; inline script still checked via `check_load_conditions()`.
- `initConditions()` in `assets/admin.js`: accepts an optional `onUpdate` callback (fired after every `syncJson()`); moved before Section 2 so URL-entry cards can call it during initialisation.
- Section 4 (page-level conditions) in `assets/admin.js`: scoped to `.scriptomatic-conditions-wrap:not(.sm-url-conditions-wrap)` to skip per-URL condition wraps already initialised by Section 2.
- Inline-script load-condition section descriptions updated to clarify they apply only to the textarea, not to external URLs.

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
| 2.7.0   | 2026-03-07 | JS file list-page upload; REST API IP allowlist; upload CLI/API endpoints |
| 2.6.0   | 2026-03-07 | REST API (`scriptomatic/v1`) and WP-CLI command group |
| 2.5.3   | 2026-02-28 | External URL Restore polish; docblock audit; language consistency |
| 2.5.2   | 2026-02-28 | Independent inline-script and external-URL Activity Log entries |
| 2.5.1   | 2026-02-28 | Ellipsis display fix; comprehensive code audit |
| 2.5.0   | 2026-03-01 | Combined snapshot save; single-click full restore |
| 2.4.0   | 2026-02-28 | File conditions UI fixed; duplicate log entries fixed |
| 2.3.0   | 2026-02-28 | Single-concern log entries; greyed-out Restore for removals |
| 2.2.0   | 2026-03-01 | Inline-script conditions UI fixed; log label clarity |
| 2.1.0   | 2026-02-28 | Save confirmation notices; empty-file auto-delete |
| 2.0.0   | 2026-02-28 | First public release; all back-compat code removed |
| 1.10.0  | 2026-02-27 | Four new date/time condition types             |
| 1.9.0   | 2026-02-27 | Unified Activity Log, JS file history, bug fixes |
| 1.8.0   | 2026-02-27 | Managed JS Files, CodeMirror editor, security hardening |
| 1.7.0   | 2026-02-27 | Configurable audit log limit, addUrl fix, network UI removed |
| 1.6.0   | 2026-02-27 | Per-URL load conditions                        |
| 1.5.0   | 2026-02-26 | Persistent audit log admin page                |
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

[Unreleased]: https://github.com/richardkentgates/scriptomatic/compare/v2.7.0...HEAD
[2.7.0]: https://github.com/richardkentgates/scriptomatic/compare/v2.6.0...v2.7.0
[2.6.0]: https://github.com/richardkentgates/scriptomatic/compare/v2.5.3...v2.6.0
[2.5.3]: https://github.com/richardkentgates/scriptomatic/compare/v2.5.2...v2.5.3
[2.5.2]: https://github.com/richardkentgates/scriptomatic/compare/v2.5.1...v2.5.2
[2.5.1]: https://github.com/richardkentgates/scriptomatic/compare/v2.5.0...v2.5.1
[2.5.0]: https://github.com/richardkentgates/scriptomatic/compare/v2.4.0...v2.5.0
[2.4.0]: https://github.com/richardkentgates/scriptomatic/compare/v2.3.0...v2.4.0
[2.3.0]: https://github.com/richardkentgates/scriptomatic/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/richardkentgates/scriptomatic/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/richardkentgates/scriptomatic/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/richardkentgates/scriptomatic/compare/v1.12.0...v2.0.0
[1.12.0]: https://github.com/richardkentgates/scriptomatic/compare/v1.11.0...v1.12.0
[1.11.0]: https://github.com/richardkentgates/scriptomatic/compare/v1.10.0...v1.11.0
[1.10.0]: https://github.com/richardkentgates/scriptomatic/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/richardkentgates/scriptomatic/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/richardkentgates/scriptomatic/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/richardkentgates/scriptomatic/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/richardkentgates/scriptomatic/compare/v1.5.0...v1.6.0
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
