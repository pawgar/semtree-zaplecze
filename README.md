# WordPress Password Manager

Desktop app to change WordPress admin login passwords across multiple sites at once.

## Requirements

- Python 3.8+
- WordPress sites with Application Passwords enabled (WP 5.6+, HTTPS required)

## Setup

```bash
pip install -r requirements.txt
python main.py
```

## Usage

1. **Add sites** - click "Dodaj" and enter site name, URL, admin username, and Application Password
2. **Test connection** - select sites and click "Testuj" to verify credentials
3. **Change password** - enter new password, select sites (or click "WSZYSTKIE"), and confirm

## Notes

- Changes the **login password** (wp-login.php), NOT Application Passwords
- Application Passwords used for API access remain unchanged
- Credentials stored in `config.json` (gitignored) - keep this file secure
- Requires admin-level Application Password for each site
