# WMSU Garment Shop: Online Uniform and Toga Ordering and Reserving System

Plain PHP 8 + MySQL/MariaDB (PDO) + HTML/CSS/JavaScript. No framework, no Composer, no CDN: it works on a network with no internet.
Guests reserve without accounts; staff, managers and administrators sign in. Payment is made at the cashier; the system only records the OR number.

## 1. Requirements
PHP 8.0+ (extensions pdo_mysql, mbstring, json, fileinfo; zip for the release builder), Apache 2.4 with `AllowOverride All`, MySQL 5.7+/MariaDB 10.4+ with
permission to create stored procedures and events. XAMPP for Windows has all of this.

## 2. First install (new server)
1. Unzip so that `index.php` is at `C:\xampp\htdocs\garments\index.php` (or your web root; set `base_url` accordingly).
2. phpMyAdmin > Import, in this order: `database/schema.sql`, `database/migrations/001` ... `008` (each file once, in number order), then `database/seed_minimal.sql`.
   Do **not** import `seed.sql` or `wmsu_garment_shop.sql` on a real server (they hold sample orders and a known admin password).
3. Copy `config/config.sample.php` to `config/config.php` and edit: `base_url`, database user and password. Keep `'debug' => false`.
4. Create the database user (phpMyAdmin > SQL):
   ```sql
   CREATE USER 'garments_app'@'localhost' IDENTIFIED BY 'a-long-random-password';
   GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE, CREATE TEMPORARY TABLES, LOCK TABLES ON wmsu_garment_shop.* TO 'garments_app'@'localhost';
   FLUSH PRIVILEGES;
   ```
   Use a separate administrative account for imports and upgrades. Never use `root` in `config.php` on the real server.
5. Create the first administrator: `C:\xampp\php\php.exe tools\create_admin.php admin "Your Name"` (you are asked for the password).
   Create a **second** administrator the same way so one forgotten password cannot lock the shop out.
6. Run `C:\xampp\php\php.exe tools\check_install.php`. Fix every FAIL.
7. Sign in at `/garments/admin/login.php`. Settings: shop details, hold days, academic period, announcement. Inventory > Items: add the real items, sizes, prices and stock (stock counts via Adjust > "Receive").
8. Apply the four manual edits in `docs/session9_edits.md` if not yet done.

## 3. Upgrading an existing development copy (Sessions 1-9 installed)
1. **Back up** the database (`tools/backup.bat`) and the whole folder.
2. Unzip the Session 10 files over the project.
3. `C:\xampp\php\php.exe tools\apply_session10_patches.php --dry-run`, then without `--dry-run`. Originals are kept in `backup/s10/`. A line reading `NOT FOUND` means that file differs from what the patcher expected: apply that fix by hand from `docs/REVIEW_REPORT.md`.
4. phpMyAdmin > Import `database/migrations/008_indexes_and_hardening.sql`.
5. Set `'debug' => false` (and add `trusted_proxies`, `hsts`, `csp` keys if you want them, see `config/config.sample.php`).
6. `php tools\check_install.php`.

## 4. Before opening to the public
- Empty the sample data: back up, then import `database/clear_sample_data.sql` (destructive; read its header). Delete uploaded sample photos in `uploads/items/`.
- Change the admin password: `php tools\set_password.php admin`. Delete the sample staff accounts in Users.
- Delete `setup_check.php`, `styleguide.php`, `tests/` from the server (`php tools\make_release.php` builds a package without them).
- Confirm the open questions in `docs/HANDOFF_SESSION_10.md` with the garment shop.

## 5. Configuration
`config/config.php` holds technical settings (database, base URL, security). Everything the shop changes day to day is in **Settings** (admin): hold days,
quantity limits, reservations open/closed, low-stock level, academic periods, toga rental days, announcement, slip number format, shop details.
Hidden setting `public_orders_per_ip_hour` (default 10) and `max_active_requests_per_contact` limit abuse; change them in the `settings` table if needed.

## 6. Scheduled jobs
| Job | Command | How often |
|---|---|---|
| Expire unpaid reservations | `php cron\expire_orders.php` | every 5 minutes (also runs lazily when staff pages load) |
| Daily maintenance | `php cron\maintenance.php` | daily, e.g. 06:00 |
| Backup | `tools\backup.bat` | daily, after closing |
Windows Task Scheduler > Create Basic Task > Action "Start a program": `C:\xampp\php\php.exe`, arguments `C:\xampp\htdocs\garments\cron\maintenance.php`.
Also turn the MySQL event scheduler on permanently (`event_scheduler=ON` under `[mysqld]` in `my.ini`).

## 7. Backup and restore
Backup: `tools\backup.bat` (needs `tools\backup.cnf`:
```
[client]
user=garments_app
password=YOUR-PASSWORD
```
Keep that file out of any shared folder; it is denied to the web by `.htaccess`). It writes a dated SQL file and copies `uploads\items`. Keep copies on another disk or USB, and test a restore every month.
Restore into an empty database: phpMyAdmin > create database `wmsu_garment_shop` (utf8mb4_unicode_ci) > Import the `.sql` file (it contains tables, procedures and events). Or:
`C:\xampp\mysql\bin\mysql.exe -u root -p wmsu_garment_shop < wmsu_garment_shop-YYYYMMDD-HHMM.sql`. Copy `uploads-items` back to `uploads\items`. Then `php tools\check_install.php`.

## 8. Project layout
`admin/` staff pages | `includes/` libraries (blocked from the web) | `assets/` css, js | `config/` settings (blocked) | `database/` SQL and `migrations/` (blocked) |
`cron/` scheduled scripts (CLI only) | `tools/` command-line tools (blocked) | `uploads/items/` photos (scripts disabled) | `docs/` handoffs, test plan, guides (blocked).

## 9. Rules for developers
Prepared statements only; print with `e()`; every POST form has `csrf_field()` and its handler `csrf_require()`; staff pages start with `require_can()`;
stock changes go through `transition_order()` / `inv_adjust_stock()` inside transactions; schema changes only as numbered migrations.

More: `docs/DEPLOYMENT.md` (local server vs web hosting), `docs/STAFF_GUIDE.md`, `docs/TEST_PLAN.md`, `docs/REVIEW_REPORT.md`.
