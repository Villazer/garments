# WMSU Garment Shop: Online Uniform and Toga Ordering and Reserving System

Plain PHP 8 + MySQL (PDO) + HTML/CSS/JavaScript. No framework, no Composer, no CDN.
Runs on XAMPP (Windows) at `http://localhost/garments`.

This is the Session 1 skeleton: shared layout, design system, helpers and database.
No real pages exist yet (catalog, ordering and staff pages come in later sessions).

## 1. Set up on XAMPP

1. Open the **XAMPP Control Panel** and click **Start** next to **Apache** and **MySQL**.
2. Unzip this project over `C:\xampp\htdocs\garments` so that `C:\xampp\htdocs\garments\index.php` exists.
3. Open **http://localhost/phpmyadmin**.
4. Click **Import** (top menu, with no database selected) and choose `database\schema.sql`, then **Import**.
   This creates the database `wmsu_garment_shop`, its tables, stored procedures and scheduled events.
5. Click `wmsu_garment_shop` in the left list, click **Import**, choose `database\seed.sql`, then **Import**.
   This loads the SAMPLE catalog, settings and the sample admin account.
6. Open `config\config.php` in a text editor and check the `db` block. XAMPP defaults
   (`root`, empty password, host `127.0.0.1`) work unchanged. If you put the site somewhere other
   than `/garments`, change `base_url`.
7. Open **http://localhost/garments/setup_check.php**. Everything should read Pass, apart from
   the review items listed below.
8. Open **http://localhost/garments/** for the home page and
   **http://localhost/garments/styleguide.php** for the design approval page.

If the setup check shows **Fail** rows, read the Details column, fix that item and reload the page.

## 2. Sample admin login (CHANGE THIS)

| Username | Password |
|---|---|
| `admin` | `ChangeMe#2026` |

**Warning:** this password is public in this file. Change it before anyone else can reach the site.
The staff sign-in page arrives in Session 2. Until then, you can generate a new hash with
`php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT);"` and update `users.password_hash` in phpMyAdmin.

## 3. Scheduled jobs (event scheduler)

The schema expires unpaid reservations, flags unclaimed orders, purges the recycle bin and marks
overdue togas with MySQL events. XAMPP starts with the scheduler **off**. To turn it on, run this in phpMyAdmin
(SQL tab):

    SET GLOBAL event_scheduler = ON;

To keep it on after a restart, add `event_scheduler=ON` under `[mysqld]` in `C:\xampp\mysql\bin\my.ini`.
Alternatively, Session 9 provides PHP scripts in `cron/` for Windows Task Scheduler.

## 4. Sample data

`database/seed.sql` is marked `[SAMPLE-SEED]`. All names, prices, sizes and stock figures are samples.
Session 10 removes or replaces them (removal notes are at the bottom of that file).

## 5. Project layout

    index.php, setup_check.php, styleguide.php   pages (setup_check and styleguide open only when debug = true)
    admin/              staff pages (Session 2 onward)
    assets/css          app.css (design system), print.css, styleguide.css
    assets/js           app.js (menus, tabs, alerts, confirm prompts)
    config/             config.php (local settings), config.sample.php (no secrets)
    includes/           init.php (bootstrap), db.php, helpers.php, csrf.php, validation.php, layout partials
    database/           schema.sql, seed.sql, migrations/
    cron/               scheduled scripts (Session 9)
    uploads/            user-uploaded files (scripts cannot run here)

## 6. Rules for every page

* Start with `require __DIR__ . '/includes/init.php';` (admin pages: `'/../includes/init.php'`).
* SQL: prepared statements only, through `db_run()`, `db_all()`, `db_one()`, `db_value()`.
* Print user data with `e()`. Every POST form contains `<?= csrf_field() ?>`, and its handler starts with `csrf_require();`.
* Multi-step stock changes: `db_transaction()` with `SELECT ... FOR UPDATE`. Call the schema's stored procedures outside a transaction.
* Do not rename tables or columns. Changes go in `database/migrations/00X_name.sql`.

## 7. Before going live

Set `'debug' => false` in `config/config.php`, delete `setup_check.php` and `styleguide.php`, change or delete the sample admin, and remove the sample data.
