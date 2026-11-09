<?php
/**
 * includes/order_slip.php  (Session 4)
 * render_order_slip($order_id, $opts) -> HTML string mirroring the shop's paper Order Slip.
 * Reused by the staff side in Session 6. Wrap the output in <div class="slip-print"> to get the print CSS.
 *
 * $opts: 'show_ref' (bool, default true)  print the public reference number and expiry in the footer
 */
function render_order_slip(int $order_id, array $opts = []): string
{
    $show_ref = $opts['show_ref'] ?? true;

    $o = res_q('SELECT o.*, cu.full_name AS cust_name, cu.contact_no, cu.address,
                       s.student_no, s.full_name AS stu_name, s.year_level,
                       co.code AS course_code, el.name AS level_name,
                       um.full_name AS measured_name, uc.full_name AS checked_name, uo.full_name AS oic_name
                  FROM orders o
                  JOIN customers cu ON cu.customer_id = o.customer_id
             LEFT JOIN students s ON s.student_id = o.student_id
             LEFT JOIN courses co ON co.course_id = s.course_id
             LEFT JOIN education_levels el ON el.education_level_id = s.education_level_id
             LEFT JOIN users um ON um.user_id = o.measured_by
             LEFT JOIN users uc ON uc.user_id = o.checked_by
             LEFT JOIN users uo ON uo.user_id = o.oic_manager_id
                 WHERE o.order_id = ?', [$order_id])->fetch(PDO::FETCH_ASSOC);
    if (!$o) { return ''; }

    $items = res_q('SELECT item_name, size_label, quantity, unit_price, line_total FROM order_items WHERE order_id = ? ORDER BY order_item_id',
                   [$order_id])->fetchAll(PDO::FETCH_ASSOC);
    $pay = res_q('SELECT GROUP_CONCAT(or_no ORDER BY payment_id SEPARATOR ", ") AS or_nos, SUM(deposit_amount) AS deposit, MAX(paid_at) AS paid_at
                    FROM payments WHERE order_id = ?', [$order_id])->fetch(PDO::FETCH_ASSOC);
    $has_pay = $pay && $pay['or_nos'] !== null;

    // The official slip number is shown once staff have issued the slip.
    $issued = in_array($o['status'], ['slip_issued', 'paid', 'claimed', 'unclaimed'], true);
    $slip_no = $issued ? e($o['order_no']) : '<em class="slip-pending">To be issued</em>';

    $course_year = trim(($o['course_code'] ?: ($o['level_name'] ?: '')) . ($o['year_level'] ? ' - ' . $o['year_level'] : ''), ' -');
    $student_label = $o['student_no'] ?? '';

    $rows = '';
    foreach ($items as $it) {
        $rows .= '<tr><td>' . e($it['item_name']) . ' <span class="slip-size">(' . e($it['size_label']) . ')</span></td>'
               . '<td class="num">' . (int)$it['quantity'] . '</td>'
               . '<td class="num">' . e(money($it['unit_price'])) . '</td>'
               . '<td class="num">' . e(money($it['line_total'])) . '</td></tr>';
    }
    for ($i = count($items); $i < 8; $i++) {
        $rows .= '<tr class="slip-blank"><td>&nbsp;</td><td></td><td></td><td></td></tr>';
    }

    $dash = fn($v) => ($v === null || $v === '') ? '&nbsp;' : e($v);

    ob_start(); ?>
<article class="slip" aria-label="Order slip">
  <header class="slip-head">
    <div class="slip-org">
      <strong>WMSU Garment Shop</strong>
      <span>Resource Generation Office</span>
    </div>
    <h2>ORDER SLIP</h2>
    <dl class="slip-no">
      <dt>Order Slip No.</dt><dd><?= $slip_no ?></dd>
      <dt>Date</dt><dd><?= e(format_date($o['created_at'])) ?></dd>
    </dl>
  </header>

  <section class="slip-grid">
    <div><span class="lbl">Customer</span><span class="val"><?= $dash($o['cust_name']) ?></span></div>
    <div><span class="lbl">Student No.</span><span class="val"><?= $dash($student_label) ?></span></div>
    <div><span class="lbl">Contact No.</span><span class="val"><?= $dash($o['contact_no']) ?></span></div>
    <div><span class="lbl">Course &amp; Year</span><span class="val"><?= $dash($course_year) ?></span></div>
    <div class="wide"><span class="lbl">Address</span><span class="val"><?= $dash($o['address']) ?></span></div>
    <?php if (!empty($o['stu_name']) && $o['stu_name'] !== $o['cust_name']): ?>
    <div class="wide"><span class="lbl">Student name</span><span class="val"><?= e($o['stu_name']) ?></span></div>
    <?php endif; ?>
  </section>

  <table class="slip-table">
    <thead><tr><th>Service / Item</th><th class="num">Qty</th><th class="num">Unit Price</th><th class="num">Total</th></tr></thead>
    <tbody><?= $rows ?></tbody>
    <tfoot><tr><td colspan="3" class="num"><strong>TOTAL</strong></td><td class="num"><strong><?= e(money($o['total_amount'])) ?></strong></td></tr></tfoot>
  </table>

  <section class="slip-grid slip-sign">
    <div><span class="lbl">Measure / Cutter</span><span class="val"><?= $dash($o['measured_name']) ?></span></div>
    <div><span class="lbl">Deposit</span><span class="val"><?= $has_pay && (float)$pay['deposit'] > 0 ? e(money($pay['deposit'])) : '&nbsp;' ?></span></div>
    <div><span class="lbl">OR No.</span><span class="val"><?= $has_pay ? e($pay['or_nos']) : '&nbsp;' ?></span></div>
    <div><span class="lbl">Date (paid)</span><span class="val"><?= $has_pay ? e(format_date($pay['paid_at'])) : '&nbsp;' ?></span></div>
    <div><span class="lbl">Checked by</span><span class="val"><?= $dash($o['checked_name']) ?></span></div>
    <div><span class="lbl">Received by</span><span class="val"><?= $dash($o['received_by']) ?></span></div>
    <div class="wide"><span class="lbl">OIC-Manager</span><span class="val"><?= $dash($o['oic_name']) ?></span></div>
  </section>

  <?php if ($show_ref): ?>
  <footer class="slip-foot">
    Reference no.: <strong><?= e($o['order_ref']) ?></strong>
    <?php if ($o['status'] === 'approved' && $o['expires_at']): ?> &middot; Held until <?= e(format_datetime($o['expires_at'])) ?><?php endif; ?>
    &middot; Pay at the Cashier, then bring the proof of payment to the Garment Shop to claim.
  </footer>
  <?php endif; ?>
</article>
<?php
    return (string)ob_get_clean();
}
