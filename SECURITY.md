# Security Configuration Guide

## Important Security Notes

### 1. Environment Variables
Currently, sensitive credentials are set directly in `includes/functions.php` using `putenv()`. This is **NOT recommended for production**.

**For Production:**
- Never commit credentials to version control
- Use server environment variables (via PHP-FPM, Apache, or .env files)
- Consider using a secrets management service
- Set up proper file permissions

### 2. Gmail App Password
The SMTP password in `includes/functions.php` should be changed immediately:

**To generate a Gmail App Password:**
1. Go to your Google Account settings
2. Enable 2-Step Verification
3. Navigate to Security > 2-Step Verification > App passwords
4. Generate a new app password for "Mail"
5. Update the `SMTP_PASSWORD` environment variable

### 3. Database Security
- Change the default database password
- Use a non-root database user for the application
- Restrict database access to localhost only
- Keep database credentials in environment variables

### 4. File Permissions
Ensure proper file permissions on production:
```bash
# Make config files readable only by web server
chmod 640 config/db.php
chmod 640 includes/functions.php

# Ensure upload directory is writable but not executable
chmod 755 images/fabrics/
```

### 5. HTTPS
Always use HTTPS in production to protect:
- Login credentials
- Session cookies
- Sensitive data transmission

### 6. Admin Account
- Admin access is OTP-only; confirm the admin email inbox can receive login OTPs
- Keep SMTP credentials strong and rotated so admin OTP delivery remains protected
- Remove unused or stale admin accounts promptly

### 7. Content Security Policy
The application includes CSP headers. Review and adjust them in `includes/init.php` based on your CDN and resource requirements.

## Recommended Production Setup

1. **Use a .env file** (not committed to Git):
```env
DB_HOST=localhost
DB_USER=fabric_app_user
DB_PASSWORD=strong_random_password_here
SMTP_PASSWORD=your_gmail_app_password
ADMIN_NOTIFICATION_EMAIL=admin@yourdomain.com
```

2. **Load environment variables** from .env file:
Install `vlucas/phpdotenv` via Composer and load variables in `config/db.php`

3. **Add .env to .gitignore**:
```
.env
config/*.local.php
```

4. **Regular security audits**:
- Keep dependencies updated (run `composer update` regularly)
- Monitor PHP security advisories
- Review access logs for suspicious activity
- Enable error logging but disable error display in production

## Questions?
Review PHPMailer documentation: https://github.com/PHPMailer/PHPMailer
MySQL security best practices: https://dev.mysql.com/doc/refman/8.0/en/security.html
