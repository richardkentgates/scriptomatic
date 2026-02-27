# Scriptomatic

[![WordPress Plugin](https://img.shields.io/badge/WordPress-5.0%2B-blue)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-7.2%2B-purple)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-green)](LICENSE)
[![Maintained](https://img.shields.io/badge/Maintained-Yes-brightgreen)]()

A secure and production-ready WordPress plugin for injecting custom JavaScript code into the `<head>` and footer of your WordPress site, with external script URL management, revision history with rollback, and multisite support.

## 🚀 Features

- **🔒 Security First**: Comprehensive input validation, sanitization, and audit logging
- **👤 Capability Checks**: Only administrators with `manage_options` can modify scripts
- **📝 Rich Admin Interface**: Clean, intuitive settings page with character counter
- **📚 Contextual Help**: Built-in help tabs with detailed documentation
- **🔍 Audit Logging**: All changes logged with user information (username and user ID)
- **⚡ Performance Optimized**: Minimal overhead, script only loaded on front-end
- **🌐 Multisite Compatible**: Works seamlessly with WordPress multisite
- **♿ Accessibility**: ARIA labels and semantic HTML for screen readers
- **🎨 WordPress Standards**: Follows WordPress coding standards and best practices
- **🧹 Configurable Uninstall**: Optionally retains or removes all data on deletion, controlled via General Settings; fully multisite-aware

## 📋 Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.2 or higher
- **User Role**: Administrator (manage_options capability)

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

1. Navigate to **Scripts → Head Scripts** (or **Footer Scripts**) in your WordPress admin
2. Enter your inline JavaScript in the textarea **or** add external script URLs via the URL manager
3. Click **Save Changes**
4. Your code will be automatically injected into the `<head>` or just before `</body>` depending on which page you used

### Admin Pages

| Page | Path | Purpose |
|------|------|---------|
| Head Scripts | Scripts → Head Scripts | Inline JS + external URLs injected in `<head>` |
| Footer Scripts | Scripts → Footer Scripts | Inline JS + external URLs injected before `</body>` |
| General Settings | Scripts → General Settings | History limit, uninstall data retention |

### Important Notes

- **Do NOT include** `<script>` tags — they are added automatically for inline code
- The code executes on **every page** of your site
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

## 🔒 Security Features

Scriptomatic is built with security as a top priority:

### Input Validation
- Maximum content length enforced (100KB)
- Automatic removal of `<script>` tags to prevent double-wrapping
- Detection of potentially dangerous HTML tags (iframe, object, embed, link, style, meta)
- Invalid UTF-8 and control characters are rejected
- Input sanitization using WordPress core functions
- Admin notices for failed validation checks and automatic cleanup

### Access Control
- Restricts access to users with `manage_options` capability only
- Nonce verification on all form submissions
- Capability checks on every admin page load

### Audit Logging
- All script changes logged to the WordPress error log
- Logs include: user ID, username, timestamp
- Helps track unauthorized changes

### Output Security
- Proper escaping of all admin interface text
- Content validated before injection
- Scripts only injected on front-end (not in admin)

### Data Protection
- Uninstall behaviour is controlled by the **Keep data on uninstall** setting in General Settings; data is removed by default unless the setting is enabled
- On multisite, uninstall iterates every sub-site and removes per-site option data, then cleans network-level options
- Multisite-aware data handling
- No external dependencies or API calls
- No raw SQL from user input (options API only)

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
4. **Conditional Loading**: Consider if script is needed on every page

### Security Tips

1. **Never Use `eval()`**: Avoid eval() and similar dangerous functions
2. **Validate URLs**: Ensure external script URLs use HTTPS
3. **Review Regularly**: Audit your scripts periodically
4. **Keep Updated**: Stay informed about security best practices

## 🐛 Troubleshooting

### Script Not Appearing

**Problem**: Code doesn't show in page source

**Solutions**:
- Verify you clicked "Save Script"
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

**Problem**: Save button doesn't work

**Solutions**:
- Verify you have administrator privileges
- Check if script exceeds 100KB limit
- Remove any HTML tags (JavaScript only)
- Check browser console for JavaScript errors

### Performance Issues

**Problem**: Site is slower after adding script

**Solutions**:
- Use async attributes for external scripts
- Minimize script size
- Consider conditional loading
- Use performance monitoring tools

## 📚 Documentation

- **[Changelog](CHANGELOG.md)**: Version history and changes
- **[Security Policy](SECURITY.md)**: Security guidelines and reporting
- **[Contributing](CONTRIBUTING.md)**: How to contribute to the project
- **[Code of Conduct](CODE_OF_CONDUCT.md)**: Community guidelines
- **[License](LICENSE)**: GPL v2 license details

## 🤝 Contributing

We welcome contributions! Please see our [Contributing Guidelines](CONTRIBUTING.md) for details on:

- Reporting bugs
- Suggesting features
- Submitting pull requests
- Development setup
- Coding standards

## 📜 License

This plugin is licensed under the GNU General Public License v2 or later.

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

## 👨‍💻 Author

**Richard Kent Gates**

- GitHub: [@richardkentgates](https://github.com/richardkentgates)

## 🙏 Support

If you find this plugin helpful, please:

- ⭐ Star this repository
- 🐛 Report bugs via [GitHub Issues](https://github.com/richardkentgates/scriptomatic/issues)
- 💡 Suggest features or improvements
- 📖 Contribute to documentation
- 🔄 Share with others who might benefit

## 🔗 Links

- **Plugin Homepage**: [https://github.com/richardkentgates/scriptomatic](https://github.com/richardkentgates/scriptomatic)
- **Issue Tracker**: [https://github.com/richardkentgates/scriptomatic/issues](https://github.com/richardkentgates/scriptomatic/issues)
- **Documentation**: [https://github.com/richardkentgates/scriptomatic/wiki](https://github.com/richardkentgates/scriptomatic/wiki)

## 📊 Changelog

See [CHANGELOG.md](CHANGELOG.md) for a detailed version history.

## ⚠️ Disclaimer

This plugin allows you to inject arbitrary JavaScript code into your website. While we provide security measures, it is your responsibility to ensure that any code you add is safe, secure, and does not violate any terms of service or laws. Always test thoroughly and only use code from trusted sources.

---

Made with ❤️ by [Richard Kent Gates](https://github.com/richardkentgates)
