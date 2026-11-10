# HANDOFF NOTE

## SESSION NUMBER AND TITLE
Session 8 of 10: Toga rentals and walk-in orders

## WHAT WAS BUILT (short)
- Toga rentals list with status and academic-period filters, student search and an overdue banner, count and badge.
- One page per toga to approve or decline a request, issue the toga, and record its return.
- Walk-in orders: student lookup that prefills from past orders, items with live availability, counter override of the online cap, slip printing, optional "paid" and "paid and claimed" in the same step, togas supported.
- Customer history by student no. or name (orders, toga rentals, people who ordered).
- Six fixes to earlier sessions (see OVERWRITES).
- "Issue a toga" is the claim step of a PAID toga order. It runs Session 6's claim (stock out, history) and then records due date, deposit and issuing staff. A toga cannot be issued before payment.

## FILES (path - purpose)
NEW
- database/migrations/006_toga_rentals.sql - rentals.issued_by, rentals.received_by, setting toga_rental_days (safe to run twice)
- includes/rentals.php - list queries, effective status, approve/decline wrapper, rent_issue, rent_return
- includes/walkin.php - walk-in validation and atomic creation
- includes/customer_lookup.php - student search, prefill, history queries
- admin/toga_rentals.php - toga list (filters, search, overdue)
- admin/toga_rental_view.php?id=<order_id> - approve, decline, issue, return
- admin/walkin.php - walk-in order form
- admin/walkin_api.php - JSON live availability (GET ?do=stock&ids=1,2,3)
- admin/customer_history.php - student history
- assets/css/rentals.css, assets/js/walkin.js
- tests/session8/run_tests.php, tests/session8/stub.php - CLI tests (111 checks)
- docs/HANDOFF_SESSION_8.md - this note

OVERWRITES (replace the existing file; each is the old file with ONLY the change listed)
- admin/_init.php - required includes/bootstrap.php, which does not exist, so every staff page fataled. Now uses includes/init.php (or bootstrap.php if you ever create one) and loads helpers first.
- includes/session_boot.php - staff sessions now use the configured session name (garments_sid), so staff and public pages share one session.
- includes/staff_nav.php - Toga Rentals now points to admin/toga_rentals.php; Customer History link added.
- includes/helpers.php - public menu "Toga Rental" linked to toga.php (never existed); now toga_request.php.
- includes/public_header.php - menu highlight never worked because pages set $nav_active but the header read $active_nav; it now accepts both.
- includes/track_lib.php - tracking page now shows the reason for a cancelled order; a customer cancel stores "Cancelled by the customer" as its reason.

## FUNCTIONS AND HELPERS ADDED (name, parameters, what it returns)
includes/rentals.php (prefix rent_)
- rent_statuses(), rent_condition_labels(): label arrays. rent_status_badge(eff): badge HTML.
- rent_migration_problem(): message or null. rent_migration_block(): prints it, true = stop the page.
- rent_actor(user_id, permission): active user row or DomainException. rent_tx(callable): transaction with deadlock retry.
- rent_default_due(issue_date, period_end|null): Y-m-d or null (uses setting toga_rental_days; 0 = period end).
- rent_union_sql(): the toga list SQL (rentals rows plus toga orders without a rentals row). rent_read_filters(get), rent_filter_query(f), rent_where, rent_count(f), rent_rows(f, offset, per_page), rent_status_counts(f), rent_overdue_count(), rent_overdue_rows(limit), rent_periods(), rent_for_student(student_id).
- rent_is_toga_order(order_id), rent_for_order(order_id) (needs migration 006), rent_effective_status(order, rental|null), rent_student_has_active(student_id, period_id, exclude_order_id=0), rent_next_hint(eff, order).
- rent_decide(order_id, 'approved'|'declined', user_id, reason): transition_order() result.
- rent_issue_locked(order_id, user_id, data): runs inside a transaction, throws DomainException. rent_issue(...): ['ok','message',...].
  data: issue_date, due_date, deposit_amount, issued_by, received_by (required), checked_by, oic_manager_id.
- rent_return_locked(rental_id, user_id, data) / rent_return(...): data: return_date, condition (required), penalty_amount, deposit_returned ('', '1', '0'), received_by, remarks.
includes/walkin.php (prefix walk_)
- walk_after_options(), walk_relationships(), walk_catalog(), walk_stock(ids), walk_read_lines(post), walk_validate(post): [clean, errors], walk_new_ref(), walk_create_locked(clean, user_id), walk_create(clean, user_id): ['ok','order_id','ref','order_no','status','steps'] or ['ok'=>false,'error'].
includes/customer_lookup.php (prefix cust_)
- cust_find_student(no), cust_search_students(q, limit), cust_last_customer(student_id), cust_orders, cust_contacts, cust_history(student_id).

## PAGES / URLS (path - who can open it)
- admin/toga_rentals.php - rentals.view (filters: status, period, q, page)
- admin/toga_rental_view.php?id=<order id> - view: rentals.view. Approve, decline, issue: orders.process. Return: rentals.process.
- admin/walkin.php - walkin.create (?q= prefills from a student no. or name)
- admin/walkin_api.php - walkin.create (JSON; 401 when signed out)
- admin/customer_history.php?q= - orders.view
Walk-in save redirects to admin/order_slip.php?id=...&print=1 (Session 6).

## DATABASE CHANGES
Migration 006_toga_rentals.sql: rentals.issued_by and rentals.received_by (nullable, FK to users) and setting toga_rental_days (0 = due at the end of the academic period). New audit actions, shown in the order history: toga_issued, toga_returned. A walk-in above the online cap also adds an order_note_added row.

## HOW TO TEST
1. Unzip over C:\xampp\htdocs\garments. Import database/migrations/006_toga_rentals.sql in phpMyAdmin. Sign in as staff01 or admin.
2. Sidebar > Toga Rentals: the seed shows Grace Lim's toga as Issued. Try the status tabs, the period filter and the search (2021-00555).
3. Open the public Toga Rental page, submit a request for a student with no toga, then in Toga Rentals open it: Approve. Issue is refused until payment. Open the order page, issue the slip, record the payment (for example 850 with a deposit of 500). Back on the toga page, Issue the toga (due date defaults to the end of the period). In the list the toga is Issued; in Inventory the toga size dropped by 1.
4. On the toga page choose Record the return, pick a condition and leave penalty and deposit blank. The toga is Returned and back in stock when the condition is Good or Needs cleaning. Damaged or Lost is not restocked.
5. Overdue: in phpMyAdmin set a released rental's due_date to a past date. The list shows an Overdue badge, a red banner and days late, and Return still works.
6. Sidebar > Walk-in: look up 2022-01001 to prefill, add Tertiary Polo M x 5 (above the online limit of 3; allowed). Save with "Issue the order slip only": the slip opens for printing. Repeat with a toga and "hand over the items now" to see it Issued at once.
7. Customer History: search 2021-00555 for orders, rentals and contacts.
8. CLI tests (scratch DB only): load schema, seed and migration 006 into a database named wmsu_s8_test, create MySQL user t/t on 127.0.0.1, run `php tests/session8/run_tests.php`. Reload the scratch database before each run.

## KNOWN ISSUES AND ASSUMPTIONS TO CONFIRM WITH THE SHOP
- The shop must confirm: toga rental length (set toga_rental_days; 0 = end of the period), deposit amount and refund rule, and penalties. All are optional in the screens and nothing is calculated automatically.
- "Issue" is the claim of a paid order. Claiming a toga order from the generic order page still works but does not record the due date, deposit or issuing staff. Use the toga page to issue.
- The Session 4 label "Deposit" on the slip depends on render_order_slip() (Session 4); I did not change it.
- Staff of every role may exceed the online quantity cap at the counter, as in your brief. Session 2's comment says orders.override covers "exceed the online cap" but nothing enforces that, so I did not gate it.
- A walk-in order is limited to 12 lines and one toga. A toga walk-in for a student who already has a toga this period is refused.
- The MySQL events and tog_create_request still change status or stock without transition_order(), as noted in Sessions 6 and 7 (no history row for events). A PHP cron calling transition_order() would close it; that fits Session 9.
- Overdue is computed from the due date on every page load, so it is right even when the event scheduler is off; the scheduled job still changes rentals.status to overdue, which the screens treat the same.
- Tests ran on MariaDB 10.11 against a copy of Session 6's write functions, because that is what I could reproduce here. The pages were smoke-tested through PHP's built-in server with a stand-in for Session 2 login. Please run the CLI tests once against your real includes/orders.php.
- admin/order_slip.php opens with print=1 after a walk-in save; if the browser blocks the print dialog, use the Print button on that page.
