# Staff guide: daily tasks

Sign in at `/garments/admin/login.php`. Five wrong tries lock your account for 10 minutes. You are signed out after 30 minutes of inactivity. Change your password under *Change password*.

## Start of day
1. **Dashboard:** look at *Awaiting approval*, *Expiring soon*, *Slips waiting for payment*, *Low or out of stock*, and overdue togas.
2. Open **Orders** > tab *Requested* and process them (below).

## Online reservations
- **Requested** (approval items): open the order > *Approve and reserve stock*. If a size is short the system refuses and reserves nothing: *Decline* with a reason the customer will read, or wait for restock.
- **Approved**: > *Issue order slip* (choose Measure/Cutter, pickup date, OIC-Manager if needed). The slip number is automatic; the slip opens for printing. Give the slip to the customer.
- The customer pays at the **cashier**. When they come back with the official receipt: **Record payment** (OR number, total on the receipt, deposit if any, date). It must cover the order total.
- **Paid**: *Record claim* (received by, checked by) when the items are handed over.
- A mistake? Only a **Manager** can revert one step back or cancel a paid order, and must give a reason.
- Unpaid orders are released automatically after the hold period (7 days unless Settings says otherwise).

## Walk-in customers
Sidebar *Walk-in*: type the student number to pre-fill, add items (you may exceed the online limit if stock is available), choose "Issue the order slip only" or "paid / paid and handed over now" if the receipt is already in hand. Print the slip.

## Toga rentals
*Toga Rentals*: approve a request; once paid, open it > *Issue the toga* (due date fills in from Settings; deposit optional). On return: *Record the return*, choose the condition. Good or needs-cleaning togas go back into stock; damaged or lost ones do not. One toga per student per academic period.

## Stock
- *Inventory > Overview*: red/yellow badges show out and low stock. **Adjust** to receive a delivery, correct a count (type the total you counted), or record damage/loss. A reason is always required, and on hand can never go below reserved.
- *Restock schedule*: add expected deliveries (shown publicly without quantities). When goods arrive press **Receive**, enter quantities per size.
- *Waitlist*: when stock arrives a yellow notice appears. The system sends no messages: call or text the customers, mark them **Contacted**, then **Fulfilled**.
- Item or price changes (Manager/Admin): *Inventory > Items*. A price change affects new orders only. Items with history can only be archived.

## Customer questions
- "Where is my order?": *Orders* search by reference, slip no., name or student no. The customer can also use *Track Order* with the reference and phone or email.
- Student history: *Customer History*.

## End of day
Run `tools\backup.bat` (or confirm the scheduled backup ran). Check *Orders > Open* for anything unusual. Never share your login.

## If something looks wrong
Do not edit the database by hand. Note the order reference and the time, check *Inventory > Movements* for the size, and tell an administrator (Activity Log shows who did what).
