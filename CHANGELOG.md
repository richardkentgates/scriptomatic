# Changelog

All notable changes to Scriptomatic will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-02-26

### Added
- Initial release of Scriptomatic
- Settings page for managing JavaScript injection
- Single textarea interface for entering custom JavaScript code
- Automatic script injection before closing `</head>` tag
- Security features:
  - Capability checks (manage_options required)
  - Input validation and sanitization
  - Maximum content length enforcement (100KB)
  - Detection of potentially dangerous HTML tags
  - Audit logging of all changes with user info and IP address
- Admin interface enhancements:
  - Character counter with visual feedback
  - Security notice panel
  - Quick Start Guide section
  - Common use cases documentation
- Comprehensive help system:
  - Overview tab explaining plugin functionality
  - Usage tab with step-by-step instructions
  - Security tab detailing security features
  - Best Practices tab with recommendations
  - Troubleshooting tab for common issues
  - Help sidebar with external resources
- Settings link in plugin action links
- Proper WordPress coding standards compliance
- Multisite compatibility
- Clean uninstall with data removal
- OOP architecture with singleton pattern
- Constants for version and configuration
- Accessibility features (ARIA labels)
- Front-end only script injection (excludes admin)
- HTML comment markers for easy identification in source

### Documentation
- Comprehensive README.md with installation and usage instructions
- CHANGELOG.md for tracking version history
- SECURITY.md for security policies and vulnerability reporting
- CONTRIBUTING.md for contribution guidelines
- CODE_OF_CONDUCT.md for community guidelines
- LICENSE file with GPL v2 license text
- Inline code documentation with PHPDoc blocks

### Technical Details
- Minimum WordPress version: 5.0
- Minimum PHP version: 7.2
- License: GPL v2 or later
- Text Domain: scriptomatic
- Author: Richard Kent Gates
- Plugin URI: https://github.com/richardkentgates/scriptomatic

---

## [Unreleased]

### Planned Features
- WordPress.org repository submission
- Translation support (i18n) for multiple languages
- Export/Import configuration
- Script versioning and revision history
- Conditional loading based on post types or pages
- Multiple script slots (header, footer, before closing body)
- Script validation with syntax checking
- Performance metrics and monitoring
- Script library with common snippets
- Role-based access control beyond manage_options

---

## Version History

- **1.0.0** (2026-02-26) - Initial Release

---

## Upgrade Notices

### 1.0.0
Initial release. No upgrade required.

---

[1.0.0]: https://github.com/richardkentgates/scriptomatic/releases/tag/v1.0.0
[Unreleased]: https://github.com/richardkentgates/scriptomatic/compare/v1.0.0...HEAD
