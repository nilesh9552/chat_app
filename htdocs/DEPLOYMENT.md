# Production Deployment Checklist

## InfinityFree/ByetHost quick setup
1. Upload all project files, including `gappa.php`, `send.php`, `fetch.php`, `js/`, `css/`, and `uploads/`.
2. Import `database/schema.sql` in phpMyAdmin for your live database.
3. Create `config.credentials.php` from `config.credentials.example.php` and set real DB values.
4. Keep `DB_AUTO_INIT` disabled (`false`) on shared hosting.
5. Keep `APP_CLEAR_ON_LOAD` disabled (`false`) on shared hosting.
6. Open `https://your-domain/gappa.php?name=test` to verify DB connection.

## 1) Environment
Set database values in your web server environment variables:

- `DB_HOST`
- `DB_USER`
- `DB_PASS`
- `DB_NAME`
- `APP_CLEAR_ON_LOAD` (set to `0` in production)
- `ADMIN_CLEAR_TOKEN` (required only if you want to allow `clear.php`)

If your hosting does not support environment variables, use `config.credentials.php` (not committed) with `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME` constants.

Example values:

- `DB_HOST=127.0.0.1`
- `DB_USER=chat_user`
- `DB_PASS=strong_password_here`
- `DB_NAME=chat_app`
- `APP_CLEAR_ON_LOAD=0`
- `ADMIN_CLEAR_TOKEN=very_long_random_secret`

## 2) Database user permissions
Create a dedicated DB user with least privileges for this app DB only.
Do not use MySQL `root` in production.

## 3) Apache hardening (already added)
- Root `.htaccess`:
  - disables directory listing
  - sets common security headers
  - blocks direct access to `config.php`
  - limits upload sizes
- `uploads/.htaccess`:
  - blocks script execution in uploads folder

## 4) App behavior changes applied
- Messages are now persistent across page refresh and browser close.
- Auto-clear endpoint (`clear.php`) is disabled unless `ADMIN_CLEAR_TOKEN` is set.
- Input validation added for username, name, message, and image size.

## 5) Go-live smoke test
1. Open `http://your-domain/chat-app/`.
2. Start two browser sessions with different names.
3. Send text and image messages.
4. Confirm messages do not disappear on refresh.
5. Confirm oversized image uploads are rejected.

## 6) Recommended next step
Move schema creation/migration out of `config.php` into a one-time setup script for cleaner production startup.

## 7) Troubleshooting send message errors
1. If clicking send shows DB error, verify `config.credentials.php` values exactly match hosting panel.
2. Ensure `database/schema.sql` was imported into the same DB selected in config.
3. Confirm `uploads/` directory exists and is writable by PHP.
4. Ensure either `storage/` or `uploads/.storage/` is writable by PHP for fallback mode.
5. Open `send.php` directly in browser; if blank/ok with GET is fine, if server error appears, check hosting error logs.
