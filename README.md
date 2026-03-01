# Scriptomatic

<p align="center">
  <img src="docs/scriptomatic-logo.png" alt="Scriptomatic" width="180" />
</p>

[![WordPress Plugin](https://img.shields.io/badge/WordPress-5.3%2B-blue)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-7.2%2B-purple)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-green)](LICENSE)
[![Freemium](https://img.shields.io/badge/Model-Free%20%2B%20Pro-orange)]()
[![Maintained](https://img.shields.io/badge/Maintained-Yes-brightgreen)]()

A secure and production-ready WordPress plugin for injecting custom JavaScript into the `<head>` and footer of your WordPress site. Available as a **free plugin** with core features, and a **Pro upgrade** that unlocks conditional loading, managed JS files, REST API, WP-CLI, and more.

## 🚀 Features

### Free (included)

- **🔒 Security First**: Comprehensive input validation, sanitization, secondary nonce system, rate limiting, and audit logging
- **👤 Capability Checks**: Only administrators with `manage_options` can modify scripts
- **📝 Inline Script Editor**: Full CodeMirror JavaScript editor with line numbers, bracket matching, and WordPress/jQuery-specific Ctrl-Space autocomplete. Falls back to a plain textarea if syntax highlighting is disabled in the user profile.
- **🔗 External Script URLs**: Manage multiple remote `<script src>` URLs per location with a chicklet UI; loaded before the inline block
- **🔁 Revision History & Rollback**: Every save stores a timestamped revision; restore any prior version in one AJAX click with no page reload
- **📋 Activity Log**: All script saves, rollbacks, and JS file events are recorded in a persistent **Activity Log** embedded at the bottom of each admin page. Inline script + conditions changes and external URL changes are recorded as **separate independent entries**, each with its own **View** and **Restore** buttons — the two never interfere with each other. Log limit is configurable in Preferences (3–1000, default 200).
- **📚 Contextual Help**: Built-in help tabs with detailed documentation on every admin page
- **♿ Accessibility**: ARIA labels, `aria-describedby`, and semantic fieldsets throughout
- **🌐 Multisite Compatible**: All script management is per-site; install/activate/deactivate network-wide; uninstall iterates every sub-site
- **🧹 Configurable Uninstall**: Optionally retains or removes all data on deletion; fully multisite-aware

### Pro (requires licence)

- **🎯 Conditional Loading**: Restrict injection to specific pages, post types, URL patterns, user login state, date ranges, date/time windows, ISO week numbers, or months — per inline script and per external URL (11 condition types, AND/OR stacked rules)
- **🗂️ Managed JS Files**: Create, edit, upload, and delete standalone `.js` files stored in `wp-content/uploads/scriptomatic/`; each file has its own Head/Footer selector, load conditions, and CodeMirror editor; files survive plugin updates
- **🔌 REST API**: Full `scriptomatic/v1` REST API (all POST, WordPress Application Passwords). Thirteen endpoints cover inline scripts, external URL lists, and managed JS files — including a multipart file upload endpoint
- **🛡️ API IP Allowlist**: Restrict REST API access to specific IPv4/IPv6 addresses or CIDR ranges from the Preferences page
- **💻 WP-CLI**: `wp scriptomatic` command group with subcommands for inline scripts, external URLs, managed JS files (including `files upload`), and history. All commands share the same service layer as the REST API.
- **📤 JS File Upload**: Upload a local `.js` file from the **JS Files list page**, via `POST /wp-json/scriptomatic/v1/files/upload`, or with `wp scriptomatic files upload --path=<file>`

> 💡 A **14-day free trial** (no payment required) is available for all Pro features.

## 📋 Requirements

- **WordPress**: 5.3 or higher
- **PHP**: 7.2 or higher
- **User Role**: Administrator (manage_options capability)

## 🏗️ Architecture

The plugin uses a modular PHP-trait structure:

```
scriptomatic/
├── scriptomatic.php              # Entry point: header, constants, require_once, bootstrap
├── uninstall.php                 # Multisite-aware cleanup; honours keep_data setting
├── assets/
│   ├── admin.css                 # Admin stylesheet (enqueued via wp_enqueue_style)
│   └── admin.js                  # Admin JS (enqueued via wp_enqueue_script + wp_localize_script)
└── includes/
    ├── class-scriptomatic.php    # Singleton class — uses all nine traits, registers hooks
    ├── class-scriptomatic-cli.php# WP-CLI command class (loaded only when WP_CLI is defined)
    ├── trait-menus.php           # Admin menu & submenu registration; help-tab hooks
    ├── trait-sanitizer.php       # Input validation and sanitisation
    ├── trait-history.php         # Revision history storage and AJAX rollback
    ├── trait-settings.php        # Settings API wiring and plugin-settings CRUD
    ├── trait-renderer.php        # Settings-field callbacks; load-condition evaluator
    ├── trait-pages.php           # Page renderers, Activity Log, JS Files pages, help tabs, action links
    ├── trait-enqueue.php         # Admin-asset enqueuing
    ├── trait-injector.php        # Front-end HTML injection
    ├── trait-files.php           # Managed JS files: CRUD, disk I/O, save + delete handlers
    └── trait-api.php             # REST API route registration, permission callbacks, service layer
```

All traits are `use`d by `class Scriptomatic`, so cross-trait `$this->method()` calls work correctly.

## 📥 Installation

### Method 1: Upload via WordPress Admin

1. Download the latest release from [GitHub](https://github.com/richardkentgates/scriptomatic/releases)
2. Navigate to **Plugins → Add New** in your WordPress admin
3. Click **Upload Plugin** and select the downloaded ZIP file
4. Click **Install Now** and then **Activate**

### Method 2: Manual Installation

1. Download and extract the plugin files
2. Upload the `scriptomatic` folder to `/wp-content/plugins/`
3. Navigate to **Plugins** in WordPress admin
4. Find **Scriptomatic** and click **Activate**

### Method 3: Git Clone (for developers)

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/richardkentgates/scriptomatic.git
```

Then activate via WordPress admin.

## 🎯 Usage

### Basic Setup

1. Navigate to **Scriptomatic → Head Scripts** (or **Footer Scripts**) in your WordPress admin
2. Enter your inline JavaScript in the textarea **or** add external script URLs via the URL manager
3. Optionally configure **Load Conditions** to restrict the script to specific pages, post types, URL patterns, user login state, date ranges, datetime windows, week numbers, or months
4. Click **Save Head Scripts** (or **Save Footer Scripts**)
5. Your code will be automatically injected into the `<head>` or just before `</body>` depending on which page you used

### Admin Pages

| Page | Path | Purpose |
|------|------|---------|
| Head Scripts | Scriptomatic → Head Scripts | Inline JS + external URLs injected in `<head>`; includes Activity Log |
| Footer Scripts | Scriptomatic → Footer Scripts | Inline JS + external URLs injected before `</body>`; includes Activity Log |
| JS Files | Scriptomatic → JS Files | Create, edit, and delete managed `.js` files; each file has its own Head/Footer toggle, load conditions, and CodeMirror editor; list view and edit view each include an Activity Log panel |
| Preferences | Scriptomatic → Preferences | Activity log limit (3–1000), uninstall data retention, API Allowed IPs (IPv4/IPv6/CIDR allowlist for REST API) |

### Important Notes

- **Do NOT include** `<script>` tags — they are added automatically for inline code
- Scripts are injected on pages matching the configured **Load Conditions** (defaults to all pages)
- External script URLs are loaded via `<script src="...">` tags; inline code is wrapped in `<script>` tags
- Revision history is maintained per location (head and footer independently)
- **Test thoroughly** before deploying to production
- Use the **Help** tab in the admin for detailed guidance

### Example: Google Analytics

```javascript
// Google Analytics tracking code
(function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
(i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
})(window,document,'script','https://www.google-analytics.com/analytics.js','ga');

ga('create', 'UA-XXXXX-Y', 'auto');
ga('send', 'pageview');
```

### Example: Facebook Pixel

```javascript
// Facebook Pixel
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');

fbq('init', 'YOUR_PIXEL_ID');
fbq('track', 'PageView');
```

### Example: Custom jQuery Function

```javascript
// Custom initialization
jQuery(document).ready(function($) {
    console.log('Scriptomatic loaded successfully!');

    // Your custom code here
    $('.my-element').on('click', function() {
        alert('Element clicked!');
    });
});
```

## � REST API Reference

All endpoints are `POST`. Authentication uses **WordPress Application Passwords**:
`Authorization: Basic base64(username:application-password)`

**Base URL:** `/wp-json/scriptomatic/v1/`

An optional **API Allowed IPs** allowlist in **Preferences** restricts REST access to specific IPv4 addresses, IPv6 addresses, or IPv4 CIDR ranges (one per line). Leave empty to allow all IPs. Blocked requests receive `403 rest_ip_forbidden`.

| Endpoint | Required params | Optional params | Description |
|---|---|---|---|
| `/script` | `location` | — | Get current inline script |
| `/script/set` | `location`, `content` | `conditions` (JSON) | Save inline script |
| `/script/rollback` | `location`, `id` (DB row ID) | — | Restore script snapshot |
| `/history` | `location` | — | List inline script history |
| `/urls` | `location` | — | Get external URL list |
| `/urls/set` | `location`, `urls` (JSON array) | — | Replace external URL list |
| `/urls/rollback` | `location`, `id` (DB row ID) | — | Restore URL snapshot |
| `/urls/history` | `location` | — | List URL history |
| `/files` | — | — | List all managed JS files |
| `/files/get` | `file_id` | — | Get file content + metadata |
| `/files/set` | `label`, `content` | `file_id`, `filename`, `location`, `conditions` | Create or update a file |
| `/files/delete` | `file_id` | — | Delete a managed JS file |
| `/files/upload` | multipart `file` field | `label`, `file_id`, `location`, `conditions` | Upload a `.js` file |

`location` is `"head"` or `"footer"`. `id` is the DB row primary key of the snapshot to restore — obtain IDs from the history endpoints. All write operations share the same service layer as the admin UI — identical validation, rate-limiting, and activity logging apply.

```bash
# Example: get current head script
curl -X POST https://example.com/wp-json/scriptomatic/v1/script \
  -H "Authorization: Basic $(echo -n 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' | base64)" \
  -H "Content-Type: application/json" \
  -d '{"location":"head"}'

# Example: upload a .js file
curl -X POST https://example.com/wp-json/scriptomatic/v1/files/upload \
  -H "Authorization: Basic $(echo -n 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' | base64)" \
  -F "file=@/path/to/tracker.js" \
  -F "label=My Tracker" \
  -F "location=head"
```

---

## 💻 WP-CLI Reference

All commands are in the `wp scriptomatic` group. Write commands share the same service layer as the REST API and admin UI.

### Inline Script

```bash
# Get current inline script
wp scriptomatic script get --location=<head|footer>

# Set inline script (from string or file)
wp scriptomatic script set --location=<head|footer> [--content=<js>] [--file=<path>] [--conditions=<json>]

# Rollback to a snapshot (use `wp scriptomatic history` to get the ID)
wp scriptomatic script rollback --location=<head|footer> --id=<id>

# List inline script history
wp scriptomatic history --location=<head|footer> [--format=<table|json|csv|yaml|count>]
```

### External URLs

```bash
# Get current external URL list
wp scriptomatic urls get --location=<head|footer> [--format=<format>]

# Replace external URL list (JSON array of {url, conditions} objects)
wp scriptomatic urls set --location=<head|footer> (--urls=<json> | --file=<path>)

# Rollback URL list to a snapshot (use `wp scriptomatic urls history` to get the ID)
wp scriptomatic urls rollback --location=<head|footer> --id=<id>

# List URL history
wp scriptomatic urls history --location=<head|footer> [--format=<format>]
```

### Managed JS Files

```bash
# List all managed JS files
wp scriptomatic files list [--format=<table|json|csv|yaml|count>]

# Get file content + metadata
wp scriptomatic files get --id=<file-id> [--format=<table|json>]

# Create or update a file
wp scriptomatic files set --label=<label> (--content=<js> | --file=<path>) \
  [--id=<file-id>] [--filename=<fn>] [--location=<head|footer>] [--conditions=<json>]

# Upload a local .js file
wp scriptomatic files upload --path=<local-path> \
  [--label=<label>] [--id=<file-id>] [--location=<head|footer>] [--conditions=<json>]

# Delete a managed JS file
wp scriptomatic files delete --id=<file-id> [--yes]
```

`--conditions` accepts a JSON string: `'{"logic":"and","rules":[{"type":"front_page","values":[]}]}'`
`--format` defaults to `table`. Use the history commands to look up `--id` values for rollback.

---

## �🔒 Security Features

Scriptomatic is built with security as a top priority:

### Input Validation
- Maximum content length enforced (100KB)
- Automatic removal of `<script>` tags to prevent double-wrapping
- Detection of potentially dangerous HTML tags (iframe, object, embed, link, style, meta)
- Invalid UTF-8 and control characters are rejected
- Input sanitization using WordPress core functions
- Admin notices for failed validation checks and automatic cleanup

### Access Control
- Restricts access to users with `manage_options` capability (Administrators)
- Nonce verification on all form submissions — both the WordPress Settings API nonce **and** a secondary location-specific nonce
- Capability checks on every admin page load

### Rate Limiting
- A transient-based per-user, per-location cooldown (10 seconds) prevents rapid repeated saves
- Saves submitted within the cooldown window are rejected with an admin notice

### Activity Logging
- All saves, AJAX rollbacks, and JS file events are recorded in the persistent **Activity Log** embedded on each admin page; each page shows only its own location's entries
- Inline script + conditions changes and external URL changes are written as **separate entries** — each with its own View/Restore buttons and rollback path; restoring one never touches the other
- Each entry captures: timestamp, username, user ID, action (`save`, `url_save`, `rollback`, `url_rollback`, `file_save`, `file_rollback`, `file_delete`, `file_restored`), and a human-readable summary of what changed
- The Restore button is disabled on the most recent entry of each dataset (it already reflects the live state)
- Entries with content snapshots expose **View** and **Restore** buttons directly in the table
- No IP addresses collected (intentional privacy decision)
- Log limit is configurable (3–1000, default 200 entries); oldest entries are discarded automatically once the cap is reached
- Helps track changes and detect unauthorised modification

### Output Security
- Proper escaping of all admin interface text
- Content validated before injection
- Scripts only injected on front-end (not in admin)

### Data Protection
- Uninstall behaviour is controlled by the **Keep data on uninstall** setting in Preferences; data is removed by default unless the setting is enabled
- On multisite, uninstall iterates every sub-site and removes per-site option data, then cleans network-level options
- Multisite-aware data handling
- No external dependencies or API calls
- No raw SQL from user input — options API and `$wpdb` prepared statements only

## 🛠️ Best Practices

### Before You Add Code

1. **Verify the Source**: Only use code from trusted sources
2. **Test in Staging**: Always test in a staging environment first
3. **Keep Backups**: Save a copy of your script before making changes
4. **Document Your Code**: Add comments explaining what the script does

### Code Quality

```javascript
// ✅ Good: Well-documented, clean code
// Google Analytics - Added 2026-02-26 by Admin
(function() {
    'use strict';
    // Your code here
})();

// ❌ Bad: Undocumented, messy code
eval(someUntrustedString); // Never use eval!
```

### Performance Tips

1. **Use Async Loading**: Load external scripts asynchronously when possible
2. **Minimize Code**: Remove unnecessary whitespace and comments for production
3. **Monitor Impact**: Use browser dev tools to check performance impact
4. **Conditional Loading**: Use the built-in **Load Conditions** feature to restrict scripts to only the pages that need them — head and footer each have independent conditions

### Security Tips

1. **Never Use `eval()`**: Avoid eval() and similar dangerous functions
2. **Validate URLs**: Ensure external script URLs use HTTPS
3. **Review Regularly**: Audit your scripts periodically
4. **Keep Updated**: Stay informed about security best practices

## 🐛 Troubleshooting

### Script Not Appearing

**Problem**: Code doesn't show in page source

**Solutions**:
- Verify you clicked **Save Head Scripts** or **Save Footer Scripts**
- Clear WordPress and browser cache
- Check if theme calls `wp_head()` properly
- Disable other plugins to check for conflicts

### JavaScript Errors

**Problem**: Console shows errors

**Solutions**:
- Check browser console for specific error messages
- Validate JavaScript syntax using a linter
- Ensure external resources are loading (check Network tab)
- Test with simple `console.log('test')` first

### Cannot Save Changes

**Problem**: Save button doesn't work or changes appear to be ignored

**Solutions**:
- Verify you have administrator privileges
- Check if the inline script exceeds the 100 KB limit (JS files are limited by the server's upload setting, not this plugin)
- Remove any HTML tags (JavaScript only)
- Check browser console for JavaScript errors
- If you saved very recently, the **rate limiter** (10-second cooldown per user/location) may have rejected the save — wait a moment and try again

### Restoring a Previous Version

**Problem**: A script change introduced an error and you need to roll back

**Solutions**:
- Scroll to the **Activity Log** panel at the bottom of the Head Scripts, Footer Scripts, or JS Files edit page
- Click **Restore** next to any saved revision to instantly roll back via AJAX — no further Save needed
- For inline script entries, Restore writes the script content and load conditions back; for URL entries, Restore writes back the external URLs as they were — each is restored independently
- For JS files, Restore writes the snapshot directly to disk
- The Restore button is disabled on the most recent entry of each dataset (already the current state)

### Performance Issues

**Problem**: Site is slower after adding script

**Solutions**:
- Use async attributes for external scripts
- Minimize script size
- Consider conditional loading
- Use performance monitoring tools

## 📚 Documentation

- **[Changelog](CHANGELOG.md)**: Version history and changes
- **[Architecture](ARCHITECTURE.md)**: Internal structure reference for developers extending the plugin
- **[Security Policy](SECURITY.md)**: Security guidelines and reporting
- **[License](LICENSE)**: GPL v2 license details

## 📜 License

### Source Code — GPL v2+

The plugin source code is licensed under the GNU General Public License v2 or later.

```
Copyright (C) 2026 Richard Kent Gates

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

See [LICENSE](LICENSE) file for full license text.

### Pro Features — Commercial Licence

Pro features (conditional loading, managed JS files, REST API, WP-CLI, IP allowlist) require a separate commercial licence purchased through [Freemius](https://freemius.com). The commercial licence grants access to Pro functionality, automatic updates, and support. It does not alter the GPL v2+ terms that apply to the source code.

## 👨‍💻 Author

**Richard Kent Gates**

- Website: [richardkentgates.com](https://richardkentgates.com)
- GitHub: [@richardkentgates](https://github.com/richardkentgates)

## 🙏 Support

If you find this plugin helpful, please:

- ⭐ Star this repository
- 🐛 Report bugs via [GitHub Issues](https://github.com/richardkentgates/scriptomatic/issues)
- 💡 Suggest features or improvements
-  Share with others who might benefit

## 🔗 Links

- **Plugin Homepage**: [https://github.com/richardkentgates/scriptomatic](https://github.com/richardkentgates/scriptomatic)
- **Issue Tracker**: [https://github.com/richardkentgates/scriptomatic/issues](https://github.com/richardkentgates/scriptomatic/issues)
- **Documentation**: [README.md](README.md)

## 📊 Changelog

See [CHANGELOG.md](CHANGELOG.md) for a detailed version history.

## ⚠️ Disclaimer

This plugin allows you to inject arbitrary JavaScript code into your website. While we provide security measures, it is your responsibility to ensure that any code you add is safe, secure, and does not violate any terms of service or laws. Always test thoroughly and only use code from trusted sources.

---

Made with ❤️ by [Richard Kent Gates](https://github.com/richardkentgates)
