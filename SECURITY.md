# Security Policy

## Our Commitment to Security

Security is a top priority for Scriptomatic. We take the protection of our users' websites seriously and have implemented multiple layers of security throughout the plugin.

## Supported Versions

We provide security updates for the following versions:

| Version | Supported          | Status        |
| ------- | ------------------ | ------------- |
| 1.4.x   | :white_check_mark: | Active Support |
| < 1.4   | :x:                | Unsupported    |

## Security Features

### Access Control

- **Capability Checks**: Only users with `manage_options` capability (typically administrators) can access and modify plugin settings
- **WordPress Nonce Verification**: All form submissions are protected with nonce tokens
- **Direct Access Protection**: All PHP files check for `ABSPATH` constant to prevent direct access

### Input Validation & Sanitization

- **Length Limits**: Maximum script content length enforced (100KB)
- **Tag Stripping**: Automatic removal of `<script>` tags to prevent double-wrapping
- **Dangerous Content Detection**: Scanning for potentially harmful HTML tags (iframe, object, embed, link, style, meta)
- **Encoding Validation**: Invalid UTF-8 is rejected to avoid malformed content
- **Control Character Blocking**: Disallowed control characters are rejected
- **WordPress Sanitization**: Using `esc_textarea()`, `esc_html()`, `esc_attr()`, and other WordPress core functions
- **Type Validation**: Strict typing in function parameters and return values

### Audit & Monitoring

- **Change Logging**: All script modifications logged with:
  - User ID and username
  - Timestamp
  - Action performed

  Note: IP addresses are intentionally not logged to protect user privacy.
- **Audit Log**: Script save and rollback events recorded in the persistent Audit Log, embedded at the bottom of the Head Scripts and Footer Scripts pages; entries capture timestamp, user, action, and character count
- **Settings Errors**: User-facing error messages for validation failures

### Output Escaping

- **Admin Interface**: All dynamic content properly escaped
- **HTML Attributes**: Using `esc_attr()` for all HTML attributes
- **URLs**: Using `esc_url()` for all URL outputs
- **Internationalization**: Using `esc_html__()` and `esc_html_e()` for translatable strings

### Data Protection

- **No External Calls**: Plugin makes no external API calls
- **No Tracking**: No analytics or telemetry collected
- **Clean Uninstall**: All data removed upon plugin deletion
- **Multisite Safe**: Proper handling of multisite installations

### Code Quality

- **WordPress Coding Standards**: Follows official WordPress PHP coding standards
- **OOP Architecture**: Clean object-oriented design with singleton pattern
- **No Deprecated Functions**: Uses current WordPress APIs only
- **SQL Injection Protection**: No raw SQL from user input; data access is via WordPress options APIs
- **Defensive Type Checks**: All input types verified at runtime via `is_string()`, `is_array()`, `absint()`, and similar guards throughout all sanitisation methods

## Known Limitations

### Inherent Risks

By its nature, Scriptomatic allows injection of arbitrary JavaScript code. While we provide security measures, administrators must understand:

1. **JavaScript Execution**: Any code entered will execute on your website
2. **Administrator Trust**: Plugin assumes administrators are trustworthy
3. **Code Review**: No automated JavaScript syntax validation (planned for future)
4. **XSS Risk**: Malicious JavaScript could lead to XSS attacks if entered by compromised admin account

### Responsibility

Website administrators are responsible for:
- Verifying code sources before adding scripts
- Testing scripts in staging environments
- Regularly auditing active scripts
- Maintaining secure administrator accounts
- Following WordPress security best practices

## Reporting a Vulnerability

We take security vulnerabilities seriously. If you discover a security issue, please follow these steps:

### DO

1. **Email**: Send details to mail@richardkentgates.com
2. **Include**:
   - Description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if available)
   - Your contact information
3. **Wait**: Allow up to 48 hours for initial response
4. **Cooperate**: Work with us on verification and resolution

### DON'T

1. **Don't** publicly disclose the vulnerability before we've had a chance to address it
2. **Don't** exploit the vulnerability beyond proof-of-concept testing
3. **Don't** access other users' data or disrupt services
4. **Don't** demand compensation (though we appreciate responsible disclosure)

### Response Timeline

- **Initial Response**: Within 48 hours
- **Validation**: Within 1 week
- **Fix Development**: Within 2 weeks (depending on severity)
- **Public Disclosure**: After fix is released and users have time to update (typically 30 days)

### Severity Levels

We classify vulnerabilities using the following severity levels:

#### Critical
- Remote code execution
- SQL injection
- Authentication bypass
- Arbitrary file upload

**Response Time**: Immediate (24-48 hours)

#### High
- Cross-site scripting (XSS)
- Privilege escalation
- Information disclosure of sensitive data
- CSRF on critical functions

**Response Time**: 3-5 days

#### Medium
- Reflected XSS with limited impact
- Information disclosure of non-sensitive data
- Denial of service

**Response Time**: 1-2 weeks

#### Low
- Non-security bugs
- Minor information leaks
- Best practice improvements

**Response Time**: As time permits

## Security Best Practices for Users

### For Administrators

1. **Strong Passwords**: Use strong, unique passwords for admin accounts
2. **Two-Factor Authentication**: Enable 2FA on all admin accounts
3. **Limited Access**: Only give `manage_options` capability to trusted users
4. **Regular Audits**: Review scripts regularly for unauthorized changes
5. **Monitoring**: Review the Audit Log (bottom of the Head Scripts and Footer Scripts pages) for unexpected script changes; check server error logs for other security events
6. **Updates**: Keep WordPress, themes, and plugins updated
7. **Backups**: Maintain regular backups before making script changes

### For Script Content

1. **Source Verification**: Only use scripts from trusted sources
2. **HTTPS Only**: Ensure external scripts load via HTTPS
3. **Staging Tests**: Test all scripts in staging environment first
4. **Code Review**: Review third-party code before adding
5. **Comments**: Document script sources and purposes
6. **Minimalism**: Only add scripts that are necessary
7. **Performance**: Monitor impact on page load times

### For Hosting

1. **Secure Server**: Use reputable hosting with security features
2. **SSL Certificate**: Enforce HTTPS on your site
3. **File Permissions**: Set proper file and directory permissions
4. **PHP Version**: Use supported PHP versions (7.2+)
5. **Server Hardening**: Follow hosting provider security guidelines

## Security Checklist

Before adding any script, ask yourself:

- [ ] Do I trust the source of this code?
- [ ] Have I reviewed the code for malicious content?
- [ ] Have I tested this in a staging environment?
- [ ] Do I understand what this code does?
- [ ] Is this script necessary for my site?
- [ ] Does this script load external resources securely (HTTPS)?
- [ ] Have I documented where this script came from?
- [ ] Have I backed up my site before making this change?

## Hall of Fame

We appreciate responsible security researchers who help keep Scriptomatic secure. Confirmed vulnerability reporters will be listed here (with permission):

*No vulnerabilities reported yet*

## Additional Resources

- [WordPress Security Whitepaper](https://wordpress.org/about/security/)
- [OWASP Top Ten](https://owasp.org/www-project-top-ten/)
- [WordPress Plugin Security](https://developer.wordpress.org/plugins/security/)
- [Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP)

## Contact

- **Security Issues**: mail@richardkentgates.com
- **General Support**: https://github.com/richardkentgates/scriptomatic/issues
- **Website**: https://github.com/richardkentgates

---

**Last Updated**: February 26, 2026
**Version**: 1.4.3

Thank you for helping keep Scriptomatic secure!
