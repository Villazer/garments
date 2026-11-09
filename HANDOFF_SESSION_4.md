HANDOFF NOTE

SESSION NUMBER AND TITLE
Session 4 of 10: Reservation flow (uniforms) and order slip

WHAT WAS BUILT (short)
Session-based reservation list, rule checks on add/update/submit, guest checkout, atomic order creation (SELECT ... FOR UPDATE, ascending variant id, deadlock retry), confirmation page with print option, and a reusable printable Order Slip. Tested on MariaDB 10.11 against the project schema and sample seed: 25 automated checks pass, including a 6-process race for the last item (exactly one winner, reserved never above on-hand).

FILES (path - purpose)
includes/reserve_lib.php - list, rules, res_submit(), view-access helpers
includes/order_slip.php - render_order_slip()
reserve.php - the list page (update, remove, empty)
reserve_add.php - REPLACES the Session 3 stub
checkout.php - guest form and submit
confirmation.php - reference number, summary, next steps
slip.php - slip page (?print=1 opens the print dialog)
assets/css/reserve.css, assets/js/reserve.js
tests/session4/ - CLI tests (php tests/session4/run_tests.php); they use their own DB stub, not the app

DECISIONS YOU SHOULD KNOW ABOUT
1. Stock is held only when an order is approved. If every line is reservation_mode = auto, the order is created as approved with stock held, and expires_at is set from hold_unpaid_hours. If any line needs approval, the whole order is created as requested with NO stock held and expires_at NULL. This matches sp_approve_order, which holds stock when staff approve and would double-reserve if we held it earlier. Availability and caps are still checked at submit.
2. orders.order_no is NOT NULL UNIQUE, so it is filled at creation (OS-YYYY-000123, from the order_no_prefix setting and the order id). Public pages and the slip show "To be issued" until status is slip_issued, paid, claimed or unclaimed.
3. hold_unpaid_hours is in hours (the schema's unit). Session 1's seed sets 168 (7 days).
4. Public reference format GRM-YYYY-XXXXXX, random from 32 unambiguous characters (fits orders.order_ref VARCHAR(16)).
5. Confirmation and slip are visible to the browser that placed the order, to signed-in staff with orders.view, or after entering the matching contact number or email (5 wrong tries, then a 10 minute lock, kept in the session).
6. An existing students row (same student_no) is never overwritten from the guest form; only blank fields are filled.
7. Made-to-order and alteration items can be added (no stock check, approval needed). Measurements are taken by staff later.
8. List limit: 12 lines (RES_MAX_LINES), so the slip fits one page.

FUNCTIONS AND HELPERS ADDED
res_list(), res_save(list), res_count(), res_remove(variant_id), res_empty(): the session list [variant_id => qty].
res_fetch_variants(ids, lock=false): variant + item rows; lock=true adds FOR UPDATE.
res_evaluate(list, rows): returns lines (each with error and kind: gone, toga, walkin, limit, stock), ok, approval_needed, total_cents.
res_evaluate_current(): same for the session list with fresh data.
res_add(variant_id, qty): ['ok','message','kind']. res_update_quantities(posted): array of error messages.
res_submit(list, customer_data): ['ok','order_id','ref','status','expires_at'] or ['ok'=>false,'problems'=>[variant_id=>message],'error'].
res_generate_ref(), res_clean_ref(ref), res_find_order_by_ref(ref).
res_grant_view(ref), res_can_view(ref), res_verify_contact(ref, contact), res_gate(ref, self_path): call before output; returns the order row or shows the verify form and exits.
res_q(sql, params): PDOStatement helper used by these files.
render_order_slip(order_id, opts): HTML string. Wrap it in <div class="slip-print"> for the print CSS. Session 6 reuses it as is.

PAGES / URLS (all public)
reserve.php, reserve_add.php (POST; accepts variant_id or variant, and qty or quantity), checkout.php, confirmation.php?ref=, slip.php?ref=[&print=1]

DATABASE CHANGES
None. Uses audit_logs action order_created_online (user_id NULL).

THINGS TO VERIFY WHEN YOU INSTALL
- The Session 3 "Add to reservation list" form posts to reserve_add.php with CSRF; check its field names (both common variants are accepted).
- The public header must call flash_show(); the new pages rely on it for flash messages. The failed-lines banner on reserve.php does not depend on it.
- These pages were written against the handoff notes (helper names and constants such as CATALOG_HEADER); I did not have the Session 1-3 code to run them end to end. Library and slip were tested against the real schema; page rendering was only syntax-checked.
- Session cookies: use session.cookie_httponly and SameSite in php.ini or Session 1 bootstrap if not already set.

MANUAL TEST
Set one variant's quantity_on_hand to 1 (auto item, e.g. PRI-BOY-POLO-6), add it in two browsers, submit in both: one gets a reference number, the other returns to reserve.php with the line named as failed and the list kept.
