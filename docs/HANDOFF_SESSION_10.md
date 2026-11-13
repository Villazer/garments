# HANDOFF NOTE

## SESSION NUMBER AND TITLE
Session 10 of 10: Testing, security, polish and deployment notes

## WHAT WAS BUILT (short)
Security hardening (web access rules, upload lockdown, rate limits, password rules, proxy-aware IP), four logic fixes, indexes, a patcher for earlier files, production tools
(install check, admin creation, backup, maintenance cron, release builder), accessibility fixes, and full documentation (README, test plan, review report, deployment notes, staff guide).

## FILES (path - purpose)
NEW
- `.htaccess`, `includes|config|database|tests|tools|docs|cron/.htaccess` - deny web access; root also blocks sql/md/log/bak files and the dev pages
- `uploads/.htaccess`, `uploads/items/.htaccess` - no scripts, images only, no listing
- `database/migrations/008_indexes_and_hardening.sql` - indexes and setting `public_orders_per_ip_hour`
- `database/seed_minimal.sql` - reference data and settings only (real installs)
- `database/clear_sample_data.sql` - DESTRUCTIVE: empties sample orders, items, customers before go-live
- `tools/apply_session10_patches.php` - applies the fixes to Session 1-9 files (idempotent, `--dry-run`, backups in `backup/s10/`)
- `tools/check_install.php` - production readiness check (CLI)
- `tools/create_admin.php` - create the first admin or any account (CLI)
- `tools/backup.bat` - Windows backup of database and photos (needs `tools/backup.cnf`)
- `tools/make_release.php` - builds the clean install zip
- `cron/maintenance.php` - prune throttle rows, run the three daily jobs, stock consistency check
- `docs/TEST_PLAN.md`, `docs/REVIEW_REPORT.md`, `docs/DEPLOYMENT.md`, `docs/STAFF_GUIDE.md`, `docs/HANDOFF_SESSION_10.md`
OVERWRITES
- `README.md` - final install, upgrade, backup, scheduling
- `config/config.sample.php` - debug false, least-privilege DB sample, `trusted_proxies`, `hsts`, `csp`
- `includes/init.php` - `client_ip()`, session hardening, extra headers, CLI-safe (no session or headers from the command line)
- `assets/js/app.js`, `assets/js/staff.js` - same behaviour plus field-error accessibility
PATCHED BY THE TOOL (not shipped whole)
- `includes/auth.php` (common passwords, `D` modifier, proxy-aware IP), `includes/public5_common.php` (IP), `includes/reserve_lib.php` (verify-form limit, no e-mail overwrite),
  `checkout.php` (per-device limit), `includes/waitlist_lib.php` (duplicate bug), `includes/orders.php` (archived items), `includes/session_boot.php` (gc lifetime),
  `track.php`, `toga_request.php`, `toga_confirmation.php`, `waitlist.php`, `restock.php`, `reserve.php`, `confirmation.php`, `slip.php` (inner `<main>` to `<div>`)

## FUNCTIONS AND HELPERS ADDED
- `client_ip()`: string; REMOTE_ADDR unless the request comes from a `trusted_proxies` address.
- `AUTH_COMMON_PASSWORDS` constant used by `auth_validate_new_password()`.
- No other library functions. Settings: `public_orders_per_ip_hour` (int, 0 = off).

## PAGES / URLS
None new. CLI only: `cron/maintenance.php`, `tools/*.php`.

## DATABASE CHANGES
Migration 008 (indexes only, plus one settings row). No table or column changed.

## HOW TO TEST
`docs/TEST_PLAN.md` (23 cases, each marked as code-checked or browser-only). Then `php tools/check_install.php` on the real server.

## KNOWN ISSUES AND ASSUMPTIONS TO CONFIRM WITH THE SHOP
Remaining technical risks
1. **Sessions 8 and 9 PHP was not reviewed** (only their notes), and the Session 8/9 overwritten files were not visible. Run T5, T8, T12, T23 and the Session 8/9 CLI tests yourself.
2. **The patcher is untested against your real files.** It was run on test copies of the target code. Read its output; any `NOT FOUND` must be done by hand (REVIEW_REPORT).
3. Nothing was run against a real MySQL/Apache here. Migration 008 and the `.htaccess` rules are untested on your server; check T15.
4. MySQL events and `tog_create_request` still change status/stock outside `transition_order()` (no history row for events). Unchanged since Session 6.
5. Rate limits count per IP. Behind a NAT/proxy this can hit many people; use `trusted_proxies` on hosting.
6. No customer notification (SMS/e-mail); the waitlist depends on staff calling.
7. Anyone knowing a student number can make a toga request for that student (staff cancel it at the counter).
8. No UI for courses, education levels or categories (edit in phpMyAdmin). No UI to correct a wrong OR number. Partial payments are not supported.
9. Old customer data (names, phones, student numbers) is never deleted automatically; see the privacy note in DEPLOYMENT.md.
10. `clear_sample_data.sql` and the release package were not run on your database; back up first.
11. Offline server: clock drift changes hold and expiry times.

Questions for the garment shop
- Hold period for unpaid reservations (7 days?) and the claim period after payment (14 days?); do **paid** unclaimed orders ever expire?
- Toga: deposit amount and refund rule, rental length, penalties for late/damaged/lost, fixed set per size?
- Is one open request per phone number (seed: 3) and 10 orders per device per hour acceptable?
- Should every staff role be allowed to adjust stock counts and exceed the online cap at the counter, or only managers?
- Who is the OIC-Manager on slips, and must the paper slip layout match exactly?
- Will customers outside the campus network reserve (decides Option A or B), and who approves the privacy notice (Data Protection Officer)?
- Real item list, sizes, prices, courses and the academic calendar.
