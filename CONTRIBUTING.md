# Contributing to Scriptomatic

First off, thank you for considering contributing to Scriptomatic! It's people like you that make this plugin better for everyone.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How Can I Contribute?](#how-can-i-contribute)
  - [Reporting Bugs](#reporting-bugs)
  - [Suggesting Enhancements](#suggesting-enhancements)
  - [Pull Requests](#pull-requests)
- [Development Setup](#development-setup)
- [Coding Standards](#coding-standards)
- [Testing Guidelines](#testing-guidelines)
- [Documentation](#documentation)
- [Community](#community)

## Code of Conduct

This project and everyone participating in it is governed by our [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code. Please report unacceptable behavior to mail@richardkentgates.com.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the [existing issues](https://github.com/richardkentgates/scriptomatic/issues) to avoid duplicates.

#### How to Submit a Good Bug Report

**Use the bug report template** and include:

- **Clear, descriptive title**
- **Exact steps to reproduce**
- **Expected vs. actual behavior**
- **Screenshots** (if applicable)
- **Environment details**:
  - WordPress version
  - PHP version
  - Theme name and version
  - Other active plugins
  - Browser (if front-end issue)
- **Error messages** from:
  - WordPress debug.log
  - Browser console
  - PHP error logs

**Example:**

```
Title: Character counter doesn't update when pasting content

Environment:
- WordPress 6.4.2
- PHP 8.1
- Theme: Twenty Twenty-Four
- Browser: Chrome 121

Steps to Reproduce:
1. Go to Scriptomatic → Head Scripts
2. Copy text from external source
3. Paste into textarea
4. Observe character counter

Expected: Counter updates immediately
Actual: Counter doesn't update until typing

Error: No errors in console
```

### Suggesting Enhancements

Enhancement suggestions are tracked as [GitHub issues](https://github.com/richardkentgates/scriptomatic/issues).

#### How to Submit a Good Enhancement Suggestion

- **Use a clear, descriptive title**
- **Provide detailed description** of the suggested enhancement
- **Explain why this would be useful** to most users
- **List examples** of how the feature would be used
- **Include mockups or examples** if applicable

**Example:**

```
Title: Add script templates library with common snippets

Description:
Provide a library of pre-built, tested script templates for common use cases.

Use Cases:
- Google Analytics
- Facebook Pixel
- Hotjar
- Custom font loading
- Performance monitoring

Benefits:
- Faster setup for common scenarios
- Reduced errors from copying code
- Educational for beginners

Implementation Ideas:
- Dropdown selector with categories
- "Load Template" button
- Template preview before insertion
- Allow custom template saving
```

### Pull Requests

Pull requests are the best way to propose changes to the codebase.

#### Pull Request Process

1. **Fork the repository** and create your branch from `main`
2. **Make your changes** following our coding standards
3. **Test thoroughly** in a WordPress environment
4. **Update documentation** if needed
5. **Add tests** if applicable
6. **Commit with clear messages**
7. **Push to your fork**
8. **Open a pull request**

#### PR Guidelines

- **One feature per PR**: Keep pull requests focused
- **Reference issues**: Link related issues in description
- **Describe changes**: Explain what and why, not just how
- **Follow conventions**: Match existing code style
- **Update CHANGELOG.md**: Add entry under Unreleased section
- **Be responsive**: Respond to review feedback promptly

**Good PR Title Examples:**
- `feat: Add script template library`
- `fix: Character counter not updating on paste`
- `docs: Improve installation instructions`
- `refactor: Extract sanitization logic to separate method`

**Commit Message Format:**

```
type(scope): Brief description

Longer explanation if needed. Wrap at 72 characters.

- Bullet points for multiple changes
- Reference issues with #123

Fixes #123
```

**Types:**
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation only
- `style`: Formatting, missing semicolons, etc.
- `refactor`: Code restructuring without changing behavior
- `test`: Adding or updating tests
- `chore`: Maintenance tasks

## Development Setup

### Prerequisites

- Git
- Local WordPress development environment:
  - [Local by Flywheel](https://localwp.com/)
  - [XAMPP](https://www.apachefriends.org/)
  - [Docker](https://github.com/docker/docker.github.io)
  - [VVV](https://varyingvagrantvagrants.org/)
- PHP 7.2 or higher
- WordPress 5.3 or higher
- Text editor or IDE (VS Code, PHPStorm, etc.)

### Setup Steps

1. **Clone the repository**
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   git clone https://github.com/richardkentgates/scriptomatic.git
   cd scriptomatic
   ```

2. **Activate the plugin**
   - Navigate to WordPress admin
   - Go to Plugins → Installed Plugins
   - Find Scriptomatic and click Activate

3. **Enable debugging**

   Add to `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   define('SCRIPT_DEBUG', true);
   ```

4. **Create a development branch**
   ```bash
   git checkout -b feature/your-feature-name
   ```

### Development Workflow

1. Make your changes
2. Test in WordPress admin
3. Check for PHP/JavaScript errors
4. Verify on front-end
5. Review error logs
6. Commit changes
7. Push to your fork
8. Open pull request

## Coding Standards

### PHP Standards

Follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).

**Key Points:**

- **Indentation**: Tabs, not spaces
- **Naming**:
  - Functions: `scriptomatic_function_name()`
  - Classes: `class Scriptomatic_Class_Name`
  - Methods: `public function method_name()`
  - Variables: `$variable_name`
  - Constants: `SCRIPTOMATIC_CONSTANT_NAME`
- **Bracing**: Opening brace on same line
- **Documentation**: PHPDoc blocks for all functions/methods
- **Security**: Always escape, sanitize, and validate
- **Internationalization**: Use `__()`, `_e()`, `esc_html__()`, etc.

**Example:**

```php
/**
 * Calculate the character count
 *
 * @since 1.0.0
 * @param string $content The content to count
 * @return int Character count
 */
function scriptomatic_count_characters($content) {
    if (!is_string($content)) {
        return 0;
    }

    return strlen(trim($content));
}
```

### JavaScript Standards

Follow [WordPress JavaScript Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/javascript/).

**Key Points:**

- **Indentation**: Tabs
- **Semicolons**: Always use
- **Quotes**: Single quotes for strings
- **jQuery**: Use `jQuery` not `$` in WordPress context
- **Strict Mode**: Use `'use strict';`

**Example:**

```javascript
(function($) {
    'use strict';

    $(document).ready(function() {
        // Your code here
    });
})(jQuery);
```

### CSS Standards

Follow [WordPress CSS Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/css/).

### Code Organization

```
scriptomatic/
├── scriptomatic.php              # Entry point: header, constants, require_once, bootstrap
├── uninstall.php                 # Multisite-aware cleanup; honours keep_data setting
├── README.md                     # Main documentation
├── CHANGELOG.md                  # Version history
├── SECURITY.md                   # Security policy
├── CONTRIBUTING.md               # This file
├── CODE_OF_CONDUCT.md            # Community guidelines
├── LICENSE                       # GPL v2 license
├── .gitignore                    # Git ignore rules
├── assets/
│   ├── admin.css                 # Admin stylesheet (enqueued via wp_enqueue_style)
│   └── admin.js                  # Admin JS (enqueued via wp_enqueue_script + wp_localize_script)
├── languages/
│   └── scriptomatic.pot          # Translation template
└── includes/
    ├── class-scriptomatic.php    # Singleton class — uses all eight traits, registers hooks
    ├── trait-menus.php           # Admin menu & submenu registration; help-tab hooks
    ├── trait-sanitizer.php       # Input validation and sanitisation
    ├── trait-history.php         # Revision history storage and AJAX rollback
    ├── trait-settings.php        # Settings API wiring and plugin-settings CRUD
    ├── trait-renderer.php        # Settings-field callbacks; load-condition evaluator
    ├── trait-pages.php           # Page renderers, network save handler, help tabs, action links
    ├── trait-enqueue.php         # Admin-asset enqueuing
    └── trait-injector.php        # Front-end HTML injection
```

## Testing Guidelines

### Manual Testing

Before submitting a PR, test:

1. **Settings Page**
   - Loads without errors
   - Form submits successfully
   - Validation works correctly
   - Help tabs display properly
   - Character counter updates

2. **Script Injection**
   - Appears in page source
   - Located before `</head>`
   - Executes correctly
   - Doesn't break site

3. **Security**
   - Non-admins can't access
   - Length limits enforced
   - Dangerous content detected
   - Changes logged

4. **Multisite**
   - Works on network admin
   - Works on individual sites
   - Uninstall properly

5. **Compatibility**
   - Different WordPress versions
   - Different PHP versions
   - Common themes
   - Common plugins

### Test Scenarios

Create a checklist for your changes:

- [ ] Fresh install
- [ ] Update from previous version
- [ ] With empty content
- [ ] With maximum length content
- [ ] With special characters
- [ ] With malicious content attempts
- [ ] On different user roles
- [ ] With WP_DEBUG enabled
- [ ] In different browsers
- [ ] On mobile devices

### Automated Testing (Planned)

Future additions:
- Unit tests with PHPUnit
- Integration tests
- End-to-end tests with Playwright
- Code coverage reports

## Documentation

### Code Documentation

- **PHPDoc blocks** for all classes, methods, and functions
- **Inline comments** for complex logic
- **Parameter types** and return types
- **Since tags** with version numbers
- **Example usage** when helpful

### User Documentation

- Update **README.md** for user-facing changes
- Update **help tabs** in admin interface
- Add **examples** when introducing new features
- Keep **CHANGELOG.md** updated

### Commit Messages

Write clear, descriptive commit messages:

```
Good:
- "Add character counter with visual feedback"
- "Fix issue where script tag wasn't properly escaped"
- "Update README with new installation methods"

Bad:
- "Update"
- "Fix bug"
- "Changes"
```

## Community

### Where to Get Help

- **GitHub Issues**: For bugs and feature requests
- **GitHub Discussions**: For questions and community discussion (coming soon)
- **Email**: mail@richardkentgates.com

### Recognition

Contributors will be:
- Listed in release notes
- Mentioned in CHANGELOG.md
- Credited in documentation (if significant contribution)
- Given our sincere thanks!

### First-Time Contributors

Look for issues labeled:
- `good first issue`: Perfect for newcomers
- `help wanted`: We need assistance
- `documentation`: Improve docs
- `beginner friendly`: Easy fixes

Don't be intimidated! Everyone was a beginner once.

## Release Process

(For maintainers)

1. Update version in `scriptomatic.php`
2. Update CHANGELOG.md with release date
3. Create release on GitHub
4. Tag with version number
5. Publish to WordPress.org (when ready)

## Questions?

Don't hesitate to ask! Contact:
- GitHub Issues: https://github.com/richardkentgates/scriptomatic/issues
- Email: mail@richardkentgates.com

## Thank You!

Your contributions, whether big or small, make Scriptomatic better for everyone. We appreciate your time and effort! 🎉

---

**Remember**: The best way to contribute is the one that works for you. Whether it's fixing typos, reporting bugs, or adding features - all contributions are valued!
