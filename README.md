# Semtree Zaplecze

Web application for managing WordPress satellite sites - monitoring their status, post counts, and bulk-changing admin passwords.

## Requirements

- PHP 8.0+ with SQLite3 and cURL extensions
- Apache with mod_rewrite (or nginx equivalent)
- WordPress sites with Application Passwords enabled (WP 5.6+, HTTPS)

## Setup

1. Upload files to your web server
2. Ensure `data/` directory is writable by PHP
3. Open in browser - default login: `admin` / `admin`
4. Change the default password after first login

## Features

- **Dashboard** - list of all satellite sites with HTTP status and post count
- **CSV import/export** - bulk add sites from CSV file (separator: `;`)
- **Bulk password change** (admin only) - change WP login password on all sites at once
- **User management** (admin only) - create worker accounts with read-only access

## CSV Format

```
name;url;username;app_password
MySite;https://example.com;admin;XXXX XXXX XXXX XXXX XXXX XXXX
```

## Roles

| Feature | Admin | Worker |
|---|---|---|
| View sites & statuses | Yes | Yes |
| Add/edit/remove sites | Yes | No |
| Import/export CSV | Yes | No |
| Change WP passwords | Yes | No |
| Manage users | Yes | No |
