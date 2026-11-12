# HANDOFF NOTE

## SESSION NUMBER AND TITLE
Session 9 of 10: Admin settings, auto-expiry, recycle bin, dashboard, reports, activity log

## WHAT WAS BUILT (short)
- Settings page (admin) with validation and one audit row per changed setting; academic periods (make current, add).
- Auto-expiry job that runs from the command line, from a Run now button, and lazily on staff page loads. Safe if runs overlap.
- Recycle bin: restore (re-reserves stock in a transaction, refuses if short) and admin-only purge with typed-reference confirmation.
- Role-aware dashboard (awaiting approval, expiring soon, slips waiting for payment, low/out of stock, upcoming restocks, overdue togas).
- Reports (manager/admin): orders by period and status, claimed by item and size, most requested sizes, toga rentals per period, stock movement summary; date and period filters, print view, CSV.
- Activity log viewer (admin only): logins, setting changes, status reverts, stock adjustments, purges, user changes, expiry runs.

## FILES (path - purpose)
NEW
- database/migrations/007_admin_settings.sql - settings rows; drops the old MySQL event ev_expire_unpaid_orders
- includes/shop_settings.php - shop_info(), reservations switch, quantity ceiling, public order guard (loads on every page)
- includes/settings_lib.php - settings defaults, validation, save with audit, academic periods
- includes/expiry.php - expiry_run(), expiry_lazy_run(), expiry_claim_slot(), expiry_status()
- includes/recycle.php - bin list, restore, purge
- includes/dashboard.php - dashboard queries
- includes/reports.php - report queries, HTML renderer, CSV export
- includes/activity.php - activity feed queries and labels
- admin/settings.php, admin/run_expiry.php, admin/recycle_bin.php, admin/reports.php, admin/activity_log.php
- cron/expire_orders.php, cron/README.txt (replaces the Session 1 placeholder text)
- assets/css/admin9.css
- docs/session9_edits.md - four small manual edits (see below), docs/HANDOFF_SESSION_9.md - this note
OVERWRITES (replace the file; the only change is listed)
- admin/index.php - the dashboard (the old file only showed shortcuts; they are kept at the bottom)
- includes/staff_header.php - calls expiry_lazy_run() after sign-in is confirmed
- includes/staff_nav.php - adds Activity Log. I rebuilt it from the Session 2 file plus the Session 8 changes described in the Session 8 note (Toga Rentals -> admin/toga_rentals.php, Customer History with key customers / admin/customer_history.php / orders.view). Compare it with your Session 8 copy.
- includes/catalog_bootstrap.php - loads shop_settings.php and calls shop_guard_public_posts()
- includes/public_footer.php - shop name, address, hours, phone and email come from Settings (config.php is the fallback)

## FUNCTIONS AND HELPERS ADDED
shop_settings.php: shop_truthy(v), shop_info(key: name|address|hours|phone|email), shop_reservations_open(), shop_closed_message(), shop_qty_limit(), shop_guard_public_posts().
settings_lib.php (cfg_): cfg_current(), cfg_form_values(), cfg_validate(post) -> [clean, errors], cfg_save(clean, user_id) -> changed keys, cfg_check_slip_format(format, prefix) -> error|null, cfg_periods(), cfg_period_make_current(id, user_id), cfg_period_validate(post), cfg_period_add(clean, user_id), cfg_audit(...).
expiry.php: expiry_run(source, user_id|null) -> ['ok','skipped','expired','failed','already','ids','message']; expiry_lazy_run(); expiry_claim_slot(minutes); expiry_due_ids(limit); expiry_due_count(); expiry_status().
recycle.php (rb_): rb_filters, rb_count, rb_rows, rb_find, rb_restore_problems(order), rb_restore(order_id, user_id) -> ['ok','message'], rb_purge(order_id, user_id, typed_ref) -> ['ok','message'].
dashboard.php (dash_): dash_awaiting_approval, dash_expiring, dash_slips_waiting, dash_stock_attention, dash_restocks, dash_overdue_togas, dash_recycle_count, dash_relative.
reports.php (rep_): rep_types, rep_filters, rep_run(filters) -> report, rep_render_html(report), rep_export_csv(report, filters), rep_csv_cell, rep_periods, rep_period_label.
activity.php (act_): act_categories, act_filters, act_count, act_rows, act_users, act_label, act_detail, act_entity_link.

## PAGES / URLS (path - who can open it)
- admin/settings.php - admin (settings.manage)
- admin/run_expiry.php - admin, POST only (Run now buttons on Settings, Dashboard and Recycle bin)
- admin/recycle_bin.php[?purge=ID] - view: manager and admin (recycle.view). Restore: recycle.restore. Purge: admin only
- admin/reports.php[?type=orders|claimed|sizes|rentals|movements&from=&to=&period=&print=1|&export=csv] - manager and admin (reports.view)
- admin/activity_log.php - admin only
- admin/index.php - every signed-in role, showing only what the role can act on
- CLI: php cron/expire_orders.php (404 if opened in a browser)

## DATABASE CHANGES
Migration 007: settings rows only (reservations_open, hold_unpaid_hours, default_max_qty_per_request, max_qty_per_request_limit, low_stock_default, toga_rental_days, recycle_purge_days, expiring_soon_hours, auto_expiry_enabled, auto_expiry_interval_minutes, auto_expiry_last_run, auto_expiry_last_result, announcement_*, order_slip_format, order_no_prefix). Shop details are stored on first save. INSERT IGNORE never overwrites a value you already set. It also runs DROP EVENT IF EXISTS ev_expire_unpaid_orders (the PHP job replaces it; the other three events stay).
No table or column was added or renamed. New audit_logs actions: setting_changed, academic_period_changed, academic_period_added, auto_expiry_run, order_purged. restore_order is reused (ord_history already labels it).

## HOW TO TEST
1. Unzip over C:\xampp\htdocs\garments. Import database/migrations/007_admin_settings.sql in phpMyAdmin. Make the four edits in docs/session9_edits.md. Sign in as admin.
2. Settings: set the hold to 7 days, save; the activity log (Activity Log, category Setting changes) shows hold_unpaid_hours 48 -> 168 or similar. Try bad input (hold 0, default above maximum, slip format without {seq}) and see the field errors. Change the shop name and check the public footer. Add a period and make it current.
3. Reservations switch: untick, save, open the catalog: a yellow notice shows; Add to reservation list, checkout and toga requests are refused. Tick it again.
4. Expiry (three ways). In phpMyAdmin set orders.expires_at to a past time for an approved, unpaid order. (a) Dashboard > Run expiry now. (b) Set it again, then just open any staff page and wait; it runs within the interval (check Settings > Automatic expiry status). (c) Command line: C:\xampp\php\php.exe C:\xampp\htdocs\garments\cron\expire_orders.php; then schedule it every 5 minutes (cron/README.txt). Check: order shows Expired, the size's Reserved dropped, Movements has a Released row, the order history has the step, the order is in the Recycle bin.
5. Recycle bin: restore that order: it becomes Approved with stock held again. Set the size's on-hand equal to reserved and expire another order: its Restore is disabled and says what is short. Purge as admin: type the reference to confirm; as manager there is no Purge button.
6. Dashboard: sign in as staff, manager and admin; check each sees the right blocks (no recycle tile or expiry card for staff).
7. Reports: pick each tab, set dates, use Printable view (opens the print dialog) and Export CSV (opens in Excel).
8. Activity log: filter by category and user; revert an order as manager, adjust stock, then find both.
Overlap check: run the cron command in two command windows at the same moment; one prints "Another expiry run is already in progress".
Automated: I ran the new SQL and logic against your schema and seed on MariaDB 10.11 (45 checks: validation, save and audit, expiry loop, overlapping-run skip, lock release, throttle slot, bin list, purge guard, dashboard, all five reports, CSV, every activity category). I used a small stand-in for includes/orders.php, so transition_order(), ord_reserve_stock() and the restore transaction have NOT been run against your real Session 6 code. Please do steps 4 and 5 once.

## KNOWN ISSUES AND ASSUMPTIONS TO CONFIRM WITH THE SHOP
- Four edits are manual (docs/session9_edits.md) because I cannot see those files in full. Until done: the maximum quantity in Settings is not applied to the reservation list, the closed notice does not show on the home page (the order pages are still blocked by catalog_bootstrap.php), and new items do not start at the default limit.
- Please compare staff_nav.php with your Session 8 copy (see OVERWRITES).
- Auto-expiry only touches Approved and Slip issued orders with no payment record and an expires_at in the past. Paid orders are never expired here (the shop must confirm whether unclaimed paid orders should expire; the schema keeps claim_period_days separate).
- Expiry runs as "System" in the order history; who pressed Run now is in the activity log (Auto-expiry runs).
- Restore returns an expired order to Approved (not Requested, unlike the old sp_restore_order) with a fresh hold, and refuses if any line is short or the student already has an active toga this period. If the order had a slip number it keeps it when the slip is re-issued.
- The unclaimed flag, recycle purge and overdue-rental MySQL events still change data without history rows (as noted in Sessions 6 to 8). Purge after the recycle days is still done by the daily MySQL event; admins can purge earlier here.
- The activity log shows manual stock receipts and adjustments from inventory_movements, because those never wrote an audit row. Cancels and status moves done by customers appear as System / customer.
- The shop name setting changes the public footer only; the page header and order slip still use config app_name and the fixed "WMSU Garment Shop / Resource Generation Office" text.
- A blank shop address, hours, phone or email falls back to config/config.php, so a field cannot be left truly empty.
- Hold time can be entered as 0.5 to 90 days (stored in hours). If the stored value is not a whole number of days the form shows the decimal.
- Reports include expired orders in the recycle bin. Toga deposit and penalty columns show whatever staff entered; the shop has not confirmed those rules.
- Dashboard and expiry comparisons use PHP time against MySQL timestamps; keep both timezones the same (Asia/Manila), as in Sessions 6 and 7.
