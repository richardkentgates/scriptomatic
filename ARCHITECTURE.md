# Scriptomatic — Architecture Reference

This document describes the internal structure of Scriptomatic for developers who want to understand, extend, or build on top of the plugin. It covers the file layout, the trait system, all constants and option keys, the security model, data structures, and how the component boundaries are drawn.

---

## Table of Contents

1. [Overview](#1-overview)
2. [File Structure](#2-file-structure)
3. [Bootstrap & Entry Point](#3-bootstrap--entry-point)
4. [Constants](#4-constants)
5. [The Singleton Class](#5-the-singleton-class)
6. [Trait Inventory](#6-trait-inventory)
   - [trait-menus.php](#trait-menusphp)
   - [trait-sanitizer.php](#trait-sanitizerphp)
   - [trait-history.php](#trait-historyphp)
   - [trait-settings.php](#trait-settingsphp)
   - [trait-renderer.php](#trait-rendererphp)
   - [trait-pages.php](#trait-pagesphp)
   - [trait-enqueue.php](#trait-enqueuephp)
   - [trait-injector.php](#trait-injectorphp)
   - [trait-files.php](#trait-filesphp)
7. [WordPress Hook Map](#7-wordpress-hook-map)
8. [Settings API Groups](#8-settings-api-groups)
9. [Option Keys & Data Structures](#9-option-keys--data-structures)
10. [Security Model](#10-security-model)
11. [Load Condition System](#11-load-condition-system)
12. [AJAX Endpoints](#12-ajax-endpoints)
13. [Activity Log](#13-activity-log)
14. [Revision History](#14-revision-history)
15. [Front-End Injection Pipeline](#15-front-end-injection-pipeline)
16. [Admin Assets](#16-admin-assets)
17. [Uninstall](#17-uninstall)
18. [Extension Points](#18-extension-points)

---

## 1. Overview

Scriptomatic is a single-file-bootstrapped, trait-based WordPress plugin. The class `Scriptomatic` is a protected singleton that `use`s nine PHP traits — one per logical concern. Because all traits are mixed into the same class, any method on any trait can call `$this->method()` to reach any other trait's methods without indirection.

There are no abstract base classes, no dependency injection containers, and no autoloaders. The load order is: `scriptomatic.php` → `class-scriptomatic.php` → nine trait files (via `require_once`).

---

## 2. File Structure

```
scriptomatic/
├── scriptomatic.php              # Entry point: header, constants, require_once, bootstrap hook
├── uninstall.php                 # Runs on plugin deletion; honours keep_data_on_uninstall
├── index.php                     # Returns HTTP 403 — blocks direct directory access
├── .gitattributes                # Marks docs/ as export-ignore (excluded from zip downloads)
│
├── assets/
│   ├── admin.css                 # Admin-only stylesheet
│   └── admin.js                  # Admin-only JavaScript (URL manager, history panel, lightbox)
│
├── docs/                         # GitHub Pages site (not included in plugin zip downloads)
│   ├── index.html
│   └── CNAME
│
├── languages/                    # .pot file and compiled .mo/.po translations
│   └── scriptomatic.pot
│
└── includes/
    ├── index.php                 # Returns HTTP 403 — blocks direct directory access
    ├── class-scriptomatic.php    # Singleton; requires all traits; registers hooks
    ├── trait-menus.php           # Admin menu and sub-menu registration
    ├── trait-sanitizer.php       # Input validation, sanitisation, rate limiter
    ├── trait-history.php         # Revision history storage and AJAX rollback
    ├── trait-settings.php        # Settings API wiring, activity log write/read, settings CRUD
    ├── trait-renderer.php        # Settings-field callbacks, load-condition evaluator
    ├── trait-pages.php           # Page renderers, Activity Log, JS Files pages, help tabs, action links
    ├── trait-enqueue.php         # Admin asset enqueueing
    ├── trait-injector.php        # Front-end HTML output
    └── trait-files.php           # Managed JS files: disk I/O, save/delete handlers
```

---

## 3. Bootstrap & Entry Point

`scriptomatic.php` does three things:

1. Defines all plugin-wide constants (see §4).
2. `require_once`s `includes/class-scriptomatic.php`, which in turn `require_once`s all nine traits.
3. Registers `scriptomatic_init()` on the `plugins_loaded` action to call `Scriptomatic::get_instance()`.

Nothing runs before `plugins_loaded`. That ensures all WordPress APIs are available when the plugin first executes.

```php
function scriptomatic_init() {
    return Scriptomatic::get_instance();
}
add_action( 'plugins_loaded', 'scriptomatic_init' );
```

---

## 4. Constants

All constants are defined in `scriptomatic.php` before the class is loaded.

### Core

| Constant | Value / Description |
|---|---|
| `SCRIPTOMATIC_VERSION` | `'2.0.0'` |
| `SCRIPTOMATIC_PLUGIN_FILE` | Absolute path to `scriptomatic.php` |
| `SCRIPTOMATIC_PLUGIN_DIR` | Absolute path to the plugin directory (trailing slash) |
| `SCRIPTOMATIC_PLUGIN_URL` | URL to the plugin directory (trailing slash) |

### Option Keys — Head Scripts

| Constant | Option Name |
|---|---|
| `SCRIPTOMATIC_HEAD_SCRIPT` | `scriptomatic_script_content` |
| `SCRIPTOMATIC_HEAD_LINKED` | `scriptomatic_linked_scripts` |
| `SCRIPTOMATIC_HEAD_CONDITIONS` | `scriptomatic_head_conditions` |

### Option Keys — Footer Scripts

| Constant | Option Name |
|---|---|
| `SCRIPTOMATIC_FOOTER_SCRIPT` | `scriptomatic_footer_script` |
| `SCRIPTOMATIC_FOOTER_LINKED` | `scriptomatic_footer_linked` |
| `SCRIPTOMATIC_FOOTER_CONDITIONS` | `scriptomatic_footer_conditions` |

### Option Keys — Plugin Settings

| Constant | Option Name |
|---|---|
| `SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION` | `scriptomatic_plugin_settings` |
| `SCRIPTOMATIC_ACTIVITY_LOG_OPTION` | `scriptomatic_activity_log` |

### Option Keys — Managed JS Files

| Constant | Option Name |
|---|---|
| `SCRIPTOMATIC_JS_FILES_OPTION` | `scriptomatic_js_files` |

### Limits & Timing

| Constant | Value | Description |
|---|---|---|
| `SCRIPTOMATIC_MAX_SCRIPT_LENGTH` | `100000` | Hard cap on inline script bytes |
| `SCRIPTOMATIC_RATE_LIMIT_SECONDS` | `10` | Cooldown between saves (per user, per location) |
| `SCRIPTOMATIC_MAX_LOG_ENTRIES` | `200` | Default activity log entry cap |

### Nonces

| Constant | Nonce Action String | Used For |
|---|---|---|
| `SCRIPTOMATIC_HEAD_NONCE` | `scriptomatic_save_head` | Head script / linked URLs / conditions form |
| `SCRIPTOMATIC_FOOTER_NONCE` | `scriptomatic_save_footer` | Footer script / linked URLs / conditions form |
| `SCRIPTOMATIC_GENERAL_NONCE` | `scriptomatic_save_general` | Preferences form |
| `SCRIPTOMATIC_ROLLBACK_NONCE` | `scriptomatic_rollback` | AJAX rollback + AJAX get-history-content |
| `SCRIPTOMATIC_FILES_NONCE` | `scriptomatic_save_js_file` | JS file edit form (admin-post) + AJAX delete |

---

## 5. The Singleton Class

`class Scriptomatic` lives in `includes/class-scriptomatic.php`. Its responsibilities are minimal:

- Owns the `static $instance` property and the `get_instance()` / `__construct()` pattern.
- Calls `init_hooks()` from `__construct()`.
- Declares `private function get_required_cap()` returning `'manage_options'` — the single source of truth for the required capability throughout all traits.
- Implements `private __clone()` and `public __wakeup()` to prevent singleton bypass via object cloning or PHP `unserialize()`.
- Loads the text domain via the `init` action hook.

All business logic lives in the traits.

---

## 6. Trait Inventory

### `trait-menus.php`

**Trait name:** `Scriptomatic_Menus`

Registers the top-level `Scriptomatic` menu (position 82, `dashicons-editor-code`) and three sub-pages:

| Sub-page slug | Page title | Callback |
|---|---|---|
| `scriptomatic` | Head Scripts — Scriptomatic | `render_head_page()` |
| `scriptomatic-footer` | Footer Scripts — Scriptomatic | `render_footer_page()` |
| `scriptomatic-files` | JS Files — Scriptomatic | `render_js_files_page()` |
| `scriptomatic-settings` | Preferences — Scriptomatic | `render_general_settings_page()` |

Help tabs are attached to each sub-page hook via `load-{hook}` actions, which all call `add_help_tab()` from `trait-pages.php`.

---

### `trait-sanitizer.php`

**Trait name:** `Scriptomatic_Sanitizer`

All input validation and sanitisation lives here. Publicly callable sanitise callbacks are registered with the Settings API (see §8); private helpers are called internally.

**Public callbacks (registered with Settings API):**

| Method | Validates |
|---|---|
| `sanitize_head_script( $input )` | Inline head script |
| `sanitize_footer_script( $input )` | Inline footer script |
| `sanitize_head_linked( $input )` | Head external URL list (JSON) |
| `sanitize_footer_linked( $input )` | Footer external URL list (JSON) |
| `sanitize_head_conditions( $input )` | Head load conditions (JSON) |
| `sanitize_footer_conditions( $input )` | Footer load conditions (JSON) |

**Core shared methods (private):**

- `sanitize_script_for( $input, $location )` — runs all security gates followed by content gates (see §10). Called by both public script callbacks.
- `sanitize_linked_for( $input, $location )` — validates URL list JSON; diffs old/new URLs and writes activity log entries for each addition/removal.
- `sanitize_conditions_for( $input, $location )` — validates conditions JSON for the inline script.
- `sanitize_conditions_array( array $raw )` — validates a `{logic, rules}` stacked conditions object; calls `sanitize_single_rule()` for each rule entry.
- `sanitize_single_rule( array $raw )` — validates a single rule object (`{type, values}`) within a stack; clamps values per condition type.

**Rate limiter (private):**

- `is_rate_limited( $location )` — checks for a transient keyed `scriptomatic_save_{location}_{user_id}`.
- `record_save_timestamp( $location )` — sets that transient for `SCRIPTOMATIC_RATE_LIMIT_SECONDS`.

**Double-call guard:**

The WordPress Settings API invokes each sanitise callback twice per POST request. Both `sanitize_script_for()` and `sanitize_linked_for()` use a `static $processed_this_request` array to detect the second invocation and skip rate-limiting, history recording, and activity logging on the second call.

---

### `trait-history.php`

**Trait name:** `Scriptomatic_History`

Manages per-location revision stacks stored in `wp_options`.

**Private methods:**

- `get_history( $location )` — filters the unified activity log to entries carrying a content snapshot (`action` in `save`|`rollback`) for the given location; returns a re-indexed array.

**Public AJAX handlers:**

- `ajax_rollback()` — verifies the `SCRIPTOMATIC_ROLLBACK_NONCE` nonce, validates `$_POST['index']` and `$_POST['location']`, then writes the content **directly via `$wpdb->update()`** (bypassing `update_option()` and its registered sanitise callbacks, which would fail in an AJAX context with no `$_POST` nonce fields). Clears the options cache with `wp_cache_delete()`. Also calls `write_activity_entry()`.
- `ajax_get_history_content()` — same nonce check, returns the entry's `content` as JSON without modifying data.
- `ajax_rollback_js_file()` — restores a managed JS file from an activity log snapshot; writes content to disk and updates metadata.
- `ajax_get_file_activity_content()` — returns a formatted lightbox display string for a JS file activity log entry.
- `ajax_get_url_list_content()` — returns a formatted display string for a URL-list snapshot in an activity log entry.
- `ajax_restore_url_list()` — restores the external URL list from an activity log snapshot.
- `ajax_get_conditions_content()` — returns a formatted display string for a conditions snapshot in an activity log entry.
- `ajax_restore_conditions()` — restores the load conditions from an activity log snapshot.
- `ajax_restore_deleted_file()` — re-creates a deleted JS file from its `file_delete` log entry snapshot (writes content to disk and re-inserts metadata).

**History entry structure:**

Revision history is stored inside the unified activity log (`SCRIPTOMATIC_ACTIVITY_LOG_OPTION`). Entries with action `save` or `rollback` carry a `content` key and are returned by `get_history()`:

```php
array(
    'content'    => string,   // Raw script content
    'timestamp'  => int,      // Unix timestamp
    'user_login' => string,   // wp_get_current_user()->user_login
    'user_id'    => int,      // wp_get_current_user()->ID
    'action'     => string,   // 'save' or 'rollback'
    'location'   => string,   // 'head' or 'footer'
    'chars'      => int,      // strlen( $content )
)
```

---

### `trait-settings.php`

**Trait name:** `Scriptomatic_Settings`

**Settings API wiring:**

`register_settings()` (hooked to `admin_init`) registers three settings groups:

| Group name | Page slug | Options registered |
|---|---|---|
| `scriptomatic_head_group` | `scriptomatic_head_page` | `HEAD_SCRIPT`, `HEAD_LINKED`, `HEAD_CONDITIONS` |
| `scriptomatic_footer_group` | `scriptomatic_footer_page` | `FOOTER_SCRIPT`, `FOOTER_LINKED`, `FOOTER_CONDITIONS` |
| `scriptomatic_general_group` | `scriptomatic_general_page` | `SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION` |

**Plugin settings CRUD:**

- `get_plugin_settings()` — returns the stored settings merged with defaults via `wp_parse_args()`. Safe to call anywhere.
- `sanitize_plugin_settings( $input )` — validates and clamps `max_log_entries` (3–1000). When the limit is reduced, immediately trims the existing activity log via `update_option()`.
- `get_max_log_entries()` — convenience getter for `max_log_entries`.

**Plugin settings data structure:**

```php
array(
    'max_log_entries'        => int,   // 3–1000, default 200
    'keep_data_on_uninstall' => bool,  // default false
)
```

**Activity log (write/read):**

- `write_activity_entry( array $data )` — merges `$data` with `timestamp`, `user_login`, `user_id`; unshifts onto the log array; caps to `get_max_log_entries()`; calls `update_option( SCRIPTOMATIC_ACTIVITY_LOG_OPTION, $log )`.
- `get_activity_log()` — reads and returns the log array from options.
- `log_change( $new_content, $option_key, $location )` — called by `sanitize_script_for()` before a save; only writes an activity entry if the content has actually changed.

---

### `trait-renderer.php`

**Trait name:** `Scriptomatic_Renderer`

All Settings API field and section callback methods. Also owns `check_load_conditions()` and `evaluate_conditions_object()`.

**Public section callbacks:**

`render_head_code_section()`, `render_head_links_section()`, `render_head_conditions_section()`, `render_footer_code_section()`, `render_footer_links_section()`, `render_footer_conditions_section()`, `render_advanced_section()`

**Public field callbacks:**

`render_head_script_field()`, `render_head_linked_field()`, `render_head_conditions_field()`, `render_footer_script_field()`, `render_footer_linked_field()`, `render_footer_conditions_field()`, `render_max_log_field()`, `render_keep_data_field()`

**Private shared implementations:**

- `render_script_field_for( $location )` — outputs the `<textarea>`, live character counter span, and security notice block.
- `render_linked_field_for( $location )` — outputs the chicklet URL manager (reads stored JSON, renders each entry as a `.sm-url-entry` block with its own conditions sub-panel). Outputs a hidden `<input name="{option_key}">` containing JSON, updated by `admin.js` before form submit.
- `render_conditions_field_for( $location )` — outputs the stacked conditions widget for the inline-script location by calling `render_conditions_stack_ui()`.

**Load condition evaluation (used by `trait-injector.php`):**

- `check_load_conditions( $location )` — reads the location-level conditions option and delegates to `evaluate_conditions_object()`.
- `evaluate_conditions_object( array $conditions )` — evaluates a `{logic, rules}` stacked conditions object against the current WordPress page context. Loops over all rules via `evaluate_single_rule()`; applies AND or OR logic with short-circuit. Called once for the inline script and once per external URL entry.
- `evaluate_single_rule( array $rule )` — evaluates a single `{type, values}` rule object; contains all 11 condition-type `switch` cases.
- `render_condition_rule_card_html( $cond_pfx, $ridx, array $rule, $post_types )` — renders a single rule card (type select + sub-panels + remove button).
- `render_conditions_stack_ui( $pfx, $logic, array $rules, $post_types, $input_name )` — renders the full stacked conditions widget (logic toggle, rules list, add button, hidden JSON input, and `<template>` clone).
- `render_file_conditions_widget( $pfx, array $conditions )` — public entry point used by the JS Files edit page to emit the conditions widget.

---

### `trait-pages.php`

**Trait name:** `Scriptomatic_Pages`

Full-page renderers, the Activity Log table, JS Files pages, contextual help, and the plugins-page action link.

**Public page renderers:**

- `render_head_page()` — outputs the Head Scripts settings form (wraps with `settings_fields()` / `do_settings_sections()` for group `scriptomatic_head_group` / page `scriptomatic_head_page`). Also renders the secondary nonce field and calls `render_activity_log( 'head' )`.
- `render_footer_page()` — same for the footer location.
- `render_js_files_page()` — dispatches to `render_js_file_list_view()` (no `id` parameter) or `render_js_file_edit_view()` (when `id` query parameter is present).
- `render_general_settings_page()` — Preferences form, group `scriptomatic_general_group` / page `scriptomatic_general_page`.

**Private view helpers:**

- `render_js_file_list_view()` — outputs the managed JS files table (label, filename, location, conditions summary) with Add New and Edit links; includes an all-files activity log panel at the bottom.
- `render_js_file_edit_view()` — outputs the JS file edit form (label, filename, Head/Footer selector, CodeMirror editor, conditions widget) and a per-file activity log panel.
- `render_activity_log( $location, $file_id )` — queries the unified activity log, filters by location/file_id, and renders the table with View and Restore buttons where applicable.

**Public contextual help:**

- `add_help_tab()` — hooked via `load-{page_hook}` for each sub-page. Registers five tabs on `get_current_screen()`:
  - Overview
  - Usage
  - Security
  - Best Practices
  - Troubleshooting

**Plugins-page action link:**

- `add_action_links( $links )` — prepends a "Preferences" link pointing to `admin.php?page=scriptomatic-settings` to the plugin row in `wp-admin/plugins.php`.

---

### `trait-enqueue.php`

**Trait name:** `Scriptomatic_Enqueue`

- `enqueue_admin_scripts( $hook )` — hooked to `admin_enqueue_scripts`. Bails early if `$hook` is not a Scriptomatic page. Enqueues:
  - `scriptomatic-admin` → `assets/admin.css`
  - `scriptomatic-admin-js` → `assets/admin.js`
- On Head Scripts, Footer Scripts, and JS Files pages, calls `wp_enqueue_code_editor( ['type' => 'text/javascript'] )` to activate the built-in WordPress CodeMirror editor. Returns `false` (and skips the editor) when the user has disabled syntax highlighting in their profile.
- Calls `wp_localize_script()` with a `scriptomaticData` object containing: `ajaxUrl`, `rollbackNonce`, `filesNonce`, `maxLength`, `maxUploadSize`, `location`, `codeEditorSettings`, `i18n`.

---

### `trait-injector.php`

**Trait name:** `Scriptomatic_Injector`

- `inject_head_scripts()` / `inject_footer_scripts()` — public methods hooked at priority 999 on `wp_head` / `wp_footer`. Both guard against `is_admin()` and delegate to `inject_scripts_for()`.
- `inject_scripts_for( $location )` — reads the inline script content and the linked URL list. For each URL entry, calls `evaluate_conditions_object()` to decide whether to include it. Also iterates `get_js_files_meta()` and emits a `<script src>` tag for each managed file targeting this location that passes its own conditions. For the inline script, calls `check_load_conditions()`. Collects passing items into `$output_parts`, then emits the Scriptomatic comment block + output only if at least one item passed.

Output format:
```html
<!-- Scriptomatic v2.0.0 (head) -->
<script src="https://example.com/script.js"></script>
<script src="/wp-content/uploads/scriptomatic/my-tracker.js"></script>
<script>
/* inline content — unescaped; validated at write-time */
</script>
<!-- /Scriptomatic (head) -->
```

---

### `trait-files.php`

**Trait name:** `Scriptomatic_Files`

**Uploads directory helpers:**
- `get_js_files_dir()` — returns / creates `wp-content/uploads/scriptomatic/` and drops an `index.php` 403 guard on first call.
- `get_js_files_url()` — returns the corresponding public URL.

**Metadata CRUD:**
- `get_js_files_meta()` — reads the `SCRIPTOMATIC_JS_FILES_OPTION` option and returns a decoded array. Each element: `{ id, label, filename, location, conditions }`.
- `save_js_files_meta( $files )` — JSON-encodes and persists the array.

**`admin_post` save handler:**
- `handle_save_js_file()` — registered on `admin_post_scriptomatic_save_js_file`. Validates capability (Gate 0) + nonce `SCRIPTOMATIC_FILES_NONCE` (Gate 1). Auto-slugs filename from label if blank; enforces `.js` extension and safe character set; enforces `wp_max_upload_size()`. Writes file bytes with `file_put_contents()`. On rename, removes the old file. Updates metadata array; writes activity log entry.

**AJAX delete handler:**
- `ajax_delete_js_file()` — registered on `wp_ajax_scriptomatic_delete_js_file`. Same two security gates. Removes file from disk and from the metadata array. Writes activity log entry. Returns JSON success/error.

---

## 7. WordPress Hook Map

| Hook | Priority | Method | Trait |
|---|---|---|---|
| `plugins_loaded` | default | `scriptomatic_init()` | _(function in scriptomatic.php)_ |
| `init` | default | `load_textdomain()` | class |
| `admin_menu` | default | `add_admin_menus()` | menus |
| `admin_init` | default | `register_settings()` | settings |
| `admin_enqueue_scripts` | default | `enqueue_admin_scripts()` | enqueue |
| `wp_head` | **999** | `inject_head_scripts()` | injector |
| `wp_footer` | **999** | `inject_footer_scripts()` | injector |
| `plugin_action_links_{basename}` | default | `add_action_links()` | pages |
| `wp_ajax_scriptomatic_rollback` | default | `ajax_rollback()` | history |
| `wp_ajax_scriptomatic_get_history_content` | default | `ajax_get_history_content()` | history |
| `wp_ajax_scriptomatic_rollback_js_file` | default | `ajax_rollback_js_file()` | history |
| `wp_ajax_scriptomatic_get_file_activity_content` | default | `ajax_get_file_activity_content()` | history |
| `wp_ajax_scriptomatic_get_url_list_content` | default | `ajax_get_url_list_content()` | history |
| `wp_ajax_scriptomatic_restore_url_list` | default | `ajax_restore_url_list()` | history |
| `wp_ajax_scriptomatic_get_conditions_content` | default | `ajax_get_conditions_content()` | history |
| `wp_ajax_scriptomatic_restore_conditions` | default | `ajax_restore_conditions()` | history |
| `wp_ajax_scriptomatic_restore_deleted_file` | default | `ajax_restore_deleted_file()` | history |
| `wp_ajax_scriptomatic_delete_js_file` | default | `ajax_delete_js_file()` | files |
| `admin_post_scriptomatic_save_js_file` | default | `handle_save_js_file()` | files |
| `load-{head_hook}` | default | `add_help_tab()` | pages |
| `load-{footer_hook}` | default | `add_help_tab()` | pages |
| `load-{files_hook}` | default | `add_help_tab()` | pages |
| `load-{general_hook}` | default | `add_help_tab()` | pages |

---

## 8. Settings API Groups

Each registered `option_key` maps to one sanitise callback. The groups are used in `settings_fields()` and `do_settings_sections()` calls in the page renderers.

| Group | Option registered | Sanitise callback |
|---|---|---|
| `scriptomatic_head_group` | `SCRIPTOMATIC_HEAD_SCRIPT` | `sanitize_head_script()` |
| `scriptomatic_head_group` | `SCRIPTOMATIC_HEAD_LINKED` | `sanitize_head_linked()` |
| `scriptomatic_head_group` | `SCRIPTOMATIC_HEAD_CONDITIONS` | `sanitize_head_conditions()` |
| `scriptomatic_footer_group` | `SCRIPTOMATIC_FOOTER_SCRIPT` | `sanitize_footer_script()` |
| `scriptomatic_footer_group` | `SCRIPTOMATIC_FOOTER_LINKED` | `sanitize_footer_linked()` |
| `scriptomatic_footer_group` | `SCRIPTOMATIC_FOOTER_CONDITIONS` | `sanitize_footer_conditions()` |
| `scriptomatic_general_group` | `SCRIPTOMATIC_PLUGIN_SETTINGS_OPTION` | `sanitize_plugin_settings()` |

---

## 9. Option Keys & Data Structures

### Inline script content
`string` — raw JavaScript, no `<script>` tags. Empty string when nothing has been saved.

### Linked URLs
`string` — JSON-encoded array of entry objects:

```json
[
  {
    "url": "https://example.com/script.js",
    "conditions": { "logic": "and", "rules": [] }
  }
]
```

### Load conditions (inline script)
`string` — JSON-encoded stacked conditions object:

```json
{ "logic": "and", "rules": [ { "type": "post_type", "values": ["post", "page"] } ] }
```

An empty `rules` array means “load on all pages”.

### Revision history
Revision history is stored within the unified activity log (see §13 and §14). Use `get_history( $location )` in `trait-history.php` to fetch content-bearing entries.

### Activity log
`array` — serialised by `update_option()`. Stored in `SCRIPTOMATIC_ACTIVITY_LOG_OPTION` (`scriptomatic_activity_log`). Entries are prepended (newest first). Example structure:

```php
array(
    'timestamp'            => int,     // Unix timestamp
    'user_login'           => string,
    'user_id'              => int,
    'action'               => string,  // see action table in §13
    'location'             => string,  // 'head' | 'footer' | '' for cross-location events
    'chars'                => int,     // present for content-bearing events
    'detail'               => string,  // URL or file label for informational events
    'content'              => string,  // snapshot for save/rollback/file events
    'urls_snapshot'        => array,   // full URL list at time of change
    'conditions_snapshot'  => array,   // full conditions at time of change
    'meta'                 => array,   // file metadata for JS file events
)
```

---

## 10. Security Model

Every save path enforces the same three gates in order:

1. **Gate 0 — Capability**: `current_user_can( 'manage_options' )`. Returns stored value immediately on failure.
2. **Gate 1 — Secondary nonce**: A short-lived nonce distinct from the WordPress Settings API nonce. Keyed to `SCRIPTOMATIC_{HEAD|FOOTER|GENERAL}_NONCE`. Present on all Settings API form submissions; conditionally checked (only if the nonce field is present in `$_POST`) for callbacks that may also be invoked in non-form contexts.
3. **Gate 2 — Rate limiter** *(script saves only)*: Transient-based per-user, per-location 10-second cooldown. Skipped on the second invocation within the same request (double-call guard).

**Content validation** (applied after all gates pass, script saves only):
- Type check: must be `string`
- `wp_kses_no_null()` + `wp_check_invalid_utf8()`
- Control character rejection (`\x00–\x08`, `\x0B`, `\x0C`, `\x0E–\x1F`, `\x7F`)
- PHP tag rejection (`<?php`, `<?=`, `<?`)
- `<script>` tag stripping (with admin warning)
- Length cap: `SCRIPTOMATIC_MAX_SCRIPT_LENGTH` (100 KB)
- Dangerous HTML tag detection: `<iframe>`, `<object>`, `<embed>`, `<link>`, `<style>`, `<meta>` (warning, not rejection)

**AJAX security**: All AJAX endpoints use `check_ajax_referer()` with the appropriate nonce constant and then `current_user_can()`. The `wp_ajax_` prefix ensures only logged-in users can trigger them.

**Rollback write path**: Uses `$wpdb->update()` + `wp_cache_delete()` directly, bypassing `update_option()` which would invoke the registered sanitise callback. The content is already validated — it came from our own stored history.

**Singleton guards**: `private __clone()` prevents object cloning; `public __wakeup()` calls `_doing_it_wrong()` to prevent deserialization.

---

## 11. Load Condition System

Conditions are stored per-location for the inline script, and per-entry for each external URL. The format is always `{logic, rules}` — an empty `rules` array means "load on all pages".

**Allowed rule `type` values:**

| Type | Values array | Evaluation |
|---|---|---|
| `front_page` | empty | `is_front_page()` |
| `singular` | empty | `is_singular()` |
| `post_type` | `['post', 'page', …]` | `is_singular( $values )` |
| `page_id` | `[42, 7, …]` | `is_page( $values )` or `get_queried_object_id()` check |
| `url_contains` | `['/shop/', …]` | `strpos()` on `$_SERVER['REQUEST_URI']` |
| `logged_in` | empty | `is_user_logged_in()` |
| `logged_out` | empty | `! is_user_logged_in()` |
| `by_date` | `['YYYY-MM-DD', 'YYYY-MM-DD']` | `current_time('Y-m-d')` between From–To (To optional) |
| `by_datetime` | `['YYYY-MM-DDTHH:MM', '…']` | `current_time('Y-m-d\TH:i')` within window, site timezone |
| `week_number` | `[5, 22, …]` | `current_time('W')` in values array |
| `by_month` | `[1, 3, 12, …]` | `current_time('n')` in values array |

Evaluation methods are in `trait-renderer.php`:
- `check_load_conditions( $location )` — reads the location-level option and delegates.
- `evaluate_conditions_object( array $conditions )` — evaluates a `{logic, rules}` stacked object; loops over each rule via `evaluate_single_rule()`, applying AND or OR logic with short-circuit. Called by the injector for each item.

---

## 12. AJAX Endpoints

All endpoints are registered on `wp_ajax_{action}` (logged-in users only). History/restore endpoints use `SCRIPTOMATIC_ROLLBACK_NONCE`; file-management endpoints use `SCRIPTOMATIC_FILES_NONCE`.

| Action | Nonce | Method | Description |
|---|---|---|---|
| `scriptomatic_rollback` | ROLLBACK | `ajax_rollback()` | Restore inline script from activity log snapshot |
| `scriptomatic_get_history_content` | ROLLBACK | `ajax_get_history_content()` | Return content for lightbox display |
| `scriptomatic_rollback_js_file` | ROLLBACK | `ajax_rollback_js_file()` | Restore a managed JS file from snapshot |
| `scriptomatic_get_file_activity_content` | ROLLBACK | `ajax_get_file_activity_content()` | Return JS file entry display for lightbox |
| `scriptomatic_get_url_list_content` | ROLLBACK | `ajax_get_url_list_content()` | Return URL-list snapshot display for lightbox |
| `scriptomatic_restore_url_list` | ROLLBACK | `ajax_restore_url_list()` | Restore external URL list from snapshot |
| `scriptomatic_get_conditions_content` | ROLLBACK | `ajax_get_conditions_content()` | Return conditions snapshot display for lightbox |
| `scriptomatic_restore_conditions` | ROLLBACK | `ajax_restore_conditions()` | Restore load conditions from snapshot |
| `scriptomatic_restore_deleted_file` | ROLLBACK | `ajax_restore_deleted_file()` | Re-create a deleted JS file from file_delete snapshot |
| `scriptomatic_delete_js_file` | FILES | `ajax_delete_js_file()` | Delete a managed JS file from disk and metadata |

**Standard success response shape:**
```json
{ "success": true, "data": { ... } }
```

---

## 13. Activity Log

The activity log is a single `wp_options` row (`scriptomatic_activity_log`, constant `SCRIPTOMATIC_ACTIVITY_LOG_OPTION`) storing a serialised PHP array. Entries are prepended (newest first). When the array length exceeds `get_max_log_entries()`, the oldest entries are sliced off. Written via `write_activity_entry()` in `trait-settings.php`.

**Events logged:**

| Action | Trigger | Notable keys |
|---|---|---|
| `save` | Inline script content changed | `content`, `chars`, `urls_snapshot`, `conditions_snapshot` |
| `rollback` | AJAX inline rollback | `content`, `chars` |
| `url_added` | URL added to external list | `detail` (URL), `urls_snapshot` |
| `url_removed` | URL removed from external list | `detail` (URL), `urls_snapshot` |
| `conditions_save` | Load conditions changed | `detail` (summary), `conditions_snapshot` |
| `url_list_restored` | External URL list restored via AJAX | `urls_snapshot` |
| `conditions_restored` | Load conditions restored via AJAX | `conditions_snapshot` |
| `file_save` | Managed JS file saved | `content`, `chars`, `meta`, `conditions` |
| `file_rollback` | Managed JS file rolled back via AJAX | `content`, `chars`, `meta` |
| `file_delete` | Managed JS file deleted | `content`, `meta`, `conditions` |
| `file_restored` | Deleted JS file re-created via AJAX | `meta` |

The log is per-site (always uses `get_option` / `update_option`). On multisite, each site has its own log.

---

## 14. Revision History

Revision history is stored **inside the unified activity log** (`SCRIPTOMATIC_ACTIVITY_LOG_OPTION`). There are no separate per-location history option keys.

`get_history( $location )` in `trait-history.php` filters the activity log to entries with action `save` or `rollback` for the given location. Because every save already records a `content` snapshot in the log, the history is always available without any additional storage.

History depth is effectively bounded by the activity log limit (configurable in Preferences, 3–1000, default 200).

---

## 15. Front-End Injection Pipeline

On each front-end page load, two actions fire:

1. `wp_head` at priority 999 → `inject_head_scripts()` → `inject_scripts_for( 'head' )`
2. `wp_footer` at priority 999 → `inject_footer_scripts()` → `inject_scripts_for( 'footer' )`

`inject_scripts_for( $location )`:

1. Reads `$script_content` from the script option and `$linked_entries` from the linked URL option.
2. For each linked entry, calls `evaluate_conditions_object( $entry['conditions'] )`. If it passes, appends a `<script src="…">` tag.
3. Reads the location-level conditions option and calls `check_load_conditions( $location )`. If the inline script is non-empty and conditions pass, appends the `<script>` block.
4. If `$output_parts` is empty, returns without any output.
5. Otherwise, emits the comment wrapper and all output parts.

The inline content is emitted unescaped — it was validated at write-time and stored as-is. External URL attributes are escaped with `esc_url()`.

---

## 16. Admin Assets

Both assets are enqueued only on Scriptomatic admin pages via `enqueue_admin_scripts( $hook )`.

### `assets/admin.css`
Styles the admin UI: chicklet URL entries, conditions sub-panels, revision history table, lightbox overlay, character counter colour states (neutral → amber at 75% → red at 90%).

### `assets/admin.js`
Localized via `wp_localize_script()` as `scriptomaticData`:

```js
{
  ajaxUrl:            "...",
  rollbackNonce:      "...",   // SCRIPTOMATIC_ROLLBACK_NONCE
  filesNonce:         "...",   // SCRIPTOMATIC_FILES_NONCE
  maxLength:          100000,
  maxUploadSize:      ...,
  location:           "head",  // or "footer" or "files"
  codeEditorSettings: { ... }, // wp_enqueue_code_editor() result, or false
  i18n:               { ... }  // translatable strings
}
```

Responsibilities:
- Live character counter: updates on `input` events, changes CSS class at 75% and 90% thresholds.
- URL manager: add/remove URL chicklet entries; per-entry conditions sub-panel; encode the full entry list as JSON into the hidden `<input>` before form submit.
- Stacked conditions widget: `initConditions()` manages rule-card add/remove, logic toggle, and JSON sync (`syncJson()`).
- Activity log: View button fires a `get_*_content` AJAX call and opens a lightbox. Restore button fires the corresponding `restore_*` or `rollback` AJAX call and updates the editor/form in place.

---

## 17. Uninstall

`uninstall.php` is executed by WordPress when the plugin is deleted (`WP_UNINSTALL_PLUGIN` is defined). It:

1. Reads `scriptomatic_plugin_settings` to check `keep_data_on_uninstall`.
2. If `true`, returns immediately (data preserved).
3. Otherwise, calls `delete_option()` for every option key on the current site.
4. On multisite, additionally iterates every blog via `switch_to_blog()` / `restore_current_blog()` and calls `delete_site_option()` for completeness.

All option keys explicitly cleaned up:

```
scriptomatic_script_content    scriptomatic_footer_script
scriptomatic_script_history    scriptomatic_footer_history
scriptomatic_linked_scripts    scriptomatic_footer_linked
scriptomatic_head_conditions   scriptomatic_footer_conditions
scriptomatic_js_files          scriptomatic_plugin_settings
scriptomatic_activity_log      scriptomatic_audit_log
```

`scriptomatic_script_history`, `scriptomatic_footer_history`, and `scriptomatic_audit_log` are legacy option names cleaned up for complete removal; they are no longer written by the current codebase. The uploads directory (`wp-content/uploads/scriptomatic/`) is also deleted via `scriptomatic_delete_uploads_dir()`.

---

## 18. Extension Points

Scriptomatic does not expose WordPress filters or actions for extension. The following are the practical patterns for extending the plugin cleanly.

### Reading stored data from outside the plugin

All data is in standard `wp_options`. You can read any value at any time:

```php
// Current head inline script
$head_script = get_option( 'scriptomatic_script_content', '' );

// Current footer inline script
$footer_script = get_option( 'scriptomatic_footer_script', '' );

// External URL list (JSON — decode before use)
$head_urls = json_decode( get_option( 'scriptomatic_linked_scripts', '[]' ), true );

// Head load conditions
$head_conditions = json_decode( get_option( 'scriptomatic_head_conditions', '{"logic":"and","rules":[]}' ), true );

// Plugin settings
$settings = get_option( 'scriptomatic_plugin_settings', array() );

// Activity log (newest first)
$log = get_option( 'scriptomatic_activity_log', array() );
```

### Hooking into injection output

Since injection fires at priority 999 on `wp_head` / `wp_footer`, you can insert output at any priority below 999 to run before Scriptomatic, or priority 1000+ to run after.

### Suppressing injection for specific requests

Hook before priority 999 and use `remove_action()`:

```php
add_action( 'wp_head', function() {
    if ( some_condition() ) {
        $instance = scriptomatic_init();
        remove_action( 'wp_head',   array( $instance, 'inject_head_scripts' ),   999 );
        remove_action( 'wp_footer', array( $instance, 'inject_footer_scripts' ), 999 );
    }
}, 1 );
```

### Adding a new condition type

1. Add the new type string to the `$allowed_types` array in `sanitize_conditions_array()` in `trait-sanitizer.php`.
2. Add a `case` to `sanitize_single_rule()` in `trait-sanitizer.php` if the type requires value sanitisation.
3. Add a `case` to `evaluate_single_rule()` in `trait-renderer.php` that returns `true` or `false`.
4. Add the UI sub-panel to `render_condition_rule_card_html()` in `trait-renderer.php` and the corresponding JS branch in `admin.js`.

### Adding a third injection location

The codebase uses `'head'` and `'footer'` as string identifiers throughout. To add a third location (e.g. `'after_header'`):

1. Define new `SCRIPTOMATIC_{LOCATION}_*` constants in `scriptomatic.php`.
2. Add the new option to the cleanup list in `uninstall.php`.
3. Add public sanitise callbacks + `register_setting()` calls in `trait-sanitizer.php` and `trait-settings.php`.
4. Add a sub-page in `trait-menus.php` and a page renderer in `trait-pages.php`.
5. Add a new action hook and injection method to `trait-injector.php`.
6. Update the `enqueue_admin_scripts()` hook detection in `trait-enqueue.php`.

### Writing to the activity log from external code

`write_activity_entry()` is `private` on the trait, but you can write directly to the option it uses:

```php
$log   = (array) get_option( 'scriptomatic_activity_log', array() );
$entry = array(
    'timestamp'  => time(),
    'user_login' => wp_get_current_user()->user_login,
    'user_id'    => get_current_user_id(),
    'action'     => 'my_custom_action',
    'location'   => 'head',
    'detail'     => 'optional context string',
);
array_unshift( $log, $entry );
update_option( 'scriptomatic_activity_log', array_slice( $log, 0, 200 ) );
```

---

*Document version: 2.0.0 — reflects the codebase as of February 2026.*
