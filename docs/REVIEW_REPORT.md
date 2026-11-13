# Session 10 review report

## Scope and limits (read first)
- Code reviewed line by line: everything in the files supplied for Sessions 1-7 (helpers, db, csrf, validation, auth,
  all public pages, reservation, tracking, toga request, waitlist, restock, order processing `orders.php`, inventory,
  users, staff pages, CSS and JS).
- **Not reviewed:** the Session 8 and 9 PHP files (`rentals.php`, `walkin.php`, `customer_lookup.php`, `shop_settings.php`,
  `settings_lib.php`, `expiry.php`, `recycle.php`, `dashboard.php`, `reports.php`, `activity.php` and their `admin/` pages,
  `cron/expire_orders.php`). I only had their handoff notes. The copies of `admin/_init.php`, `session_boot.php`,
  `staff_nav.php`, `helpers.php`, `public_header.php`, `track_lib.php`, `admin/index.php` and `staff_header.php` I was given
  are the older (pre-Session 8/9) versions, so I did **not** overwrite them. Every fix to a file that Session 8 or 9 may have
  changed is a find-and-replace done by `tools/apply_session10_patches.php`, which reports anything it cannot match.
- No PHP/MySQL stack was available against your real tree. I linted my new PHP, ran the patcher on test copies of the target
  code, and tested the release builder. Everything that needs a browser or database is listed in `TEST_PLAN.md`.

## Security review
| Area | Finding | Result / fix |
|---|---|---|
| SQL injection | All queries use prepared statements. Interpolated values (`LIMIT`, `OFFSET`, `INTERVAL`, hold hours) are cast to int first. LIKE input is escaped. Column names in `inventory_catalog.php` come from `information_schema`, not users. | OK, no change |
| XSS | All user data is printed through `e()`; `old()` values are escaped at the point of printing; `nl2br(e())` for descriptions; CSV export neutralises `= + - @`. | OK. Optional CSP added (`'csp' => true`) |
| CSRF | Every POST form reviewed (public and Sessions 2-7 staff pages) has `csrf_field()` and the handler calls `csrf_require()` first. Logout is POST only. No GET changes data. | OK |
| Session fixation / cookies | `session_regenerate_id(true)` on login and password change; HttpOnly, SameSite=Lax, Secure on HTTPS. | **Fixed:** strict mode + cookies-only for public sessions (init.php); session lifetime 2 h so a 30 min idle timeout is not cut short by PHP's 24 min cleanup |
| Access control | Each Session 2-7 staff page calls `require_can()` / `require_role()` before output; POST handlers re-check. Unknown permissions are denied. | OK. Session 8/9 pages: browser test T12 |
| Direct URL access to folders | Nothing blocked the web from `includes/`, `config/`, `database/`, `tests/` (test stubs hold a DB login `t/t`), `tools/`, `docs/`, `.sql`/`.md` files. | **Fixed:** `.htaccess` in each folder and the root |
| Uploads | Upload code is good: `is_uploaded_file`, `getimagesize` + `finfo` MIME match, size and dimension limits, random server-side name, never the client name. But files are not re-encoded and nothing stopped a script in `uploads/` from running. | **Fixed:** `uploads/.htaccess` turns the PHP engine off, denies script extensions and serves images only |
| Reference enumeration | `track.php`: one generic error, per-IP and per-reference limits, constant-time compare. But `confirmation.php` / `slip.php` (`res_gate`) limited tries **per session only**, so deleting the cookie reset the counter. | **Fixed:** IP + reference limits in `res_gate` |
| Customer record tampering | Checkout overwrote the e-mail/address of an existing customer row that matched phone and name. | **Fixed:** only blank fields are filled |
| Stock hoarding | No limit on online orders per device; with auto-approved items one person could hold all stock for 7 days. | **Fixed:** 10 orders/hour/device (setting `public_orders_per_ip_hour`); seed sets 3 open requests per contact |
| Rate-limit IP | `REMOTE_ADDR` only (correct), but behind a proxy/Cloudflare every visitor shares one IP. | **Fixed:** `client_ip()` honours `trusted_proxies` only |
| Error display | `init.php` hides errors and shows a generic page when `debug` is false. But the shipped `config.php` had `debug => true`, and `setup_check.php`/`styleguide.php` open when it is. | **Fixed:** sample config defaults to false; both pages denied by `.htaccess`; `check_install.php` fails if debug is on |
| Passwords | bcrypt via `password_hash`, 8-72 chars, lockout, throttled change. The README published `ChangeMe#2026`. | **Fixed:** common-password list (includes that one), digits-only and repeated-character checks; `check_install.php` tests for known passwords; `create_admin.php` replaces the seeded admin |
| Open redirect | `auth_safe_next` whitelist was good, but `$` also matched before a trailing newline. | **Fixed:** `D` modifier |
| Database account | Runs as `root` with no password. | Documented least-privilege user (README 4); `check_install.php` fails on root |
| Clickjacking / sniffing | Headers present. | Added Permissions-Policy, optional HSTS |
| Logging | Failed-login rows store the username typed (a mistyped password in that box would be stored). | Accepted; visible to admins only. Do not give the Activity Log to non-admins |

## Logic bugs found and fixed
1. **Waitlist duplicate bug:** a customer whose earlier waitlist entry was *fulfilled* or *cancelled* (and never "contacted") was told
   "already on the list" and the closed row was updated, so nobody would ever be contacted. Only `waiting` entries count now.
2. **Archived items could still be approved**, although the staff screen says they cannot. Approval is now refused with a clear message.
3. Duplicate `<main id="main">` on 10 public pages broke the "Skip to content" link (see usability).
4. Customer e-mail overwrite and unlimited online orders (above).

Checked and found correct: slip numbering, payment-in-full rule, claim/revert stock maths, one active toga per student per period
(row lock + unique index), snapshotted prices (`order_items`, slip and tracking read the snapshot), public restock page hides cancelled
entries, tracking timelines, lock ordering (ascending variant id) against deadlocks.

## Performance and data quality
- Migration `008_indexes_and_hardening.sql` adds: `orders(deleted_at,status,created_at)` for the staff list, `inventory_movements(created_at)` and
  `(movement_type,created_at)`, `audit_logs(action,created_at)`, `(ip_address,created_at)`, `(entity_type,entity_id,action,created_at)` for the
  login lock and rate limits, `waitlist(variant_id,status)`, `customers(contact_no,full_name)`. It skips an index that exists or whose columns are missing.
- `cron/maintenance.php` prunes throttle rows (they grew by one row per tracking lookup) and runs a stock-consistency check.
- Remaining slow-by-design queries: `LIKE '%text%'` searches on names and references. Fine for a shop-sized database (thousands of rows).
- Money: all money output goes through `money()` (`₱1,234.50`); all stored amounts are `DECIMAL(10,2)` and computed in integer cents. Dates: `format_date()`/`format_datetime()`.
- Time zone: PHP is `Asia/Manila` and `db()` sets the MySQL session to the same offset, so `NOW()` agrees. The **MySQL event scheduler uses the
  server's own zone**; keep Windows set to Manila. `tools/check_install.php` compares the two clocks. Offline server: check the clock monthly (no internet time sync).

## Usability and accessibility
- Fixed: nested `<main>` / duplicate id on public pages; field errors now carry `aria-invalid`/`aria-describedby` and focus moves to the first error (public and staff JS).
- Already good: labels on all fields, visible focus ring, 44 px touch targets, tables that stack on phones, empty states, print CSS that shows only the slip.
- Not changed (needs your eyes): colour contrast of badges on tinted rows, report print layouts (Session 9), screen-reader pass with NVDA.
