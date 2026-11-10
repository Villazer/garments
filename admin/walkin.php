<?php
/**
 * Walk-in order: staff enter an order for a customer at the counter.
 * Look a student up by number or name to prefill from past orders, pick items with live
 * availability, and save. The order is created, approved (stock reserved) and the slip is
 * issued in one step; optionally the payment and the claim are recorded at once too.
 */
require __DIR__ . '/_init.php';
$user = require_can('walkin.create');
require_once AUTH_ROOT . '/includes/orders.php';
require_once AUTH_ROOT . '/includes/rentals.php';
require_once AUTH_ROOT . '/includes/customer_lookup.php';
require_once AUTH_ROOT . '/includes/walkin.php';

$errors = [];
$form_error = null;
$posted = [];

if (is_post()) {
    csrf_require();
    $posted = $_POST;
    [$clean, $errors] = walk_validate($_POST);
    if (!$errors) {
        $res = walk_create($clean, (int) $user['user_id']);
        if ($res['ok']) {
            $done = ['slip' => 'The order slip is ready to print.', 'paid' => 'Payment recorded. The items can be claimed.', 'claimed' => 'Paid and handed over.'][$clean['after']];
            flash_set('success', 'Walk-in order saved. Reference ' . $res['ref'] . ', slip no. ' . $res['order_no'] . '. ' . $done);
            redirect('admin/order_slip.php', ['id' => $res['order_id'], 'print' => 1]);
        }
        $form_error = $res['error'];
    }
}

/* Student lookup (a plain GET form, so it works without JavaScript) */
$q = clean_str($_GET['q'] ?? '');
$matches = [];
$prefill = [];
$lookup_note = '';
if (!is_post() && $q !== '') {
    $matches = cust_search_students($q);
    $pick = cust_find_student($q);
    if (!$pick && count($matches) === 1) {
        $pick = cust_find_student($matches[0]['student_no']);
    }
    if ($pick) {
        $prefill = [
            'student_no' => $pick['student_no'], 'student_name' => $pick['full_name'], 'education_level_id' => $pick['education_level_id'],
            'course_id' => $pick['course_id'], 'year_level' => $pick['year_level'],
        ];
        $last = cust_last_customer((int) $pick['student_id']);
        if ($last) {
            $prefill += ['full_name' => $last['full_name'], 'contact_no' => $last['contact_no'], 'email' => $last['email'],
                         'address' => $last['address'], 'relationship' => $last['relationship']];
            $lookup_note = 'Prefilled from ' . $pick['full_name'] . '\'s past orders. Check the details with the customer.';
        } else {
            $lookup_note = 'Student found, but there are no past orders to copy the customer details from.';
        }
        $matches = [];
    } elseif (!$matches) {
        $lookup_note = 'No student found for "' . $q . '". Enter the new details below.';
    }
}

$val = static fn(string $k, string $def = '') => e((string) ($posted[$k] ?? $prefill[$k] ?? $def));
$sel = static fn(string $k, $v, string $def = '') => ((string) ($posted[$k] ?? $prefill[$k] ?? $def) === (string) $v) ? ' selected' : '';
$fe  = static fn(string $k) => isset($errors[$k]) ? '<p class="s8-error" role="alert">' . e($errors[$k]) . '</p>' : '';

$catalog = walk_catalog();
$levels = ord_all('SELECT education_level_id, name FROM education_levels ORDER BY sort_order');
$courses = ord_all('SELECT course_id, code, name FROM courses WHERE is_active = 1 ORDER BY code');
$staff = ord_staff_options();
$managers = ord_staff_options(['manager', 'admin']);
$after = (string) ($posted['after'] ?? 'slip');

$post_v = is_array($posted['variant_id'] ?? null) ? array_values($posted['variant_id']) : [];
$post_q = is_array($posted['qty'] ?? null) ? array_values($posted['qty']) : [];
$row_count = max(3, min(WALK_MAX_LINES, count($post_v)));

/** One <select> of every size, with price and availability in the label and data attributes for the JavaScript. */
$item_select = static function (string $selected) use ($catalog): string {
    $h = '<select name="variant_id[]" aria-label="Item and size"><option value="">Choose an item and size…</option>';
    foreach ($catalog as $it) {
        $h .= '<optgroup label="' . e($it['category'] . ' – ' . $it['name']) . '">';
        foreach ($it['sizes'] as $s) {
            $avail = max(0, (int) $s['available']);
            $label = 'Size ' . $s['size_label'] . ' · ' . money($s['unit_price'])
                . ($it['tracks'] ? ' · ' . ($avail > 0 ? $avail . ' available' : 'out of stock') : ' · made to order')
                . ($it['mode'] === 'none' ? ' · counter only' : '');
            $h .= '<option value="' . (int) $s['variant_id'] . '" data-price="' . e((string) $s['unit_price']) . '" data-available="' . $avail
                . '" data-tracks="' . $it['tracks'] . '" data-cap="' . (int) $it['cap'] . '"'
                . ((string) $s['variant_id'] === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h . '</select>';
};
$row_html = static fn(string $vid, string $qty) => '<tr><td>' . $item_select($vid) . '<div class="s8-avail" aria-live="polite"></div></td>'
    . '<td><input type="number" name="qty[]" min="1" max="' . WALK_MAX_QTY . '" inputmode="numeric" aria-label="Quantity" value="' . e($qty) . '"></td>'
    . '<td><button type="button" class="s8-btn s8-btn-sm s8-btn-light" data-remove-row>Remove</button></td></tr>';

$page_title = 'Walk-in order';
$nav_active = 'walkin';
require AUTH_ROOT . '/includes/staff_header.php';
?>
<link rel="stylesheet" href="<?= e(asset('css/rentals.css')) ?>">
<div class="s8-head">
  <h1>Walk-in order</h1>
  <p class="s8-muted">For a customer at the counter. The slip is issued right away; the customer pays at the cashier and returns with the receipt, unless you record the payment here.</p>
</div>

<form method="get" action="<?= e(url('admin/walkin.php')) ?>" class="s8-filters" role="search">
  <label>Find a returning student <span class="s8-hint">student no. or name</span>
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="2023-00123 or a name"></label>
  <div class="s8-actions"><button type="submit" class="s8-btn">Look up and prefill</button>
    <a class="s8-btn s8-btn-light" href="<?= e(url('admin/walkin.php')) ?>">Clear</a></div>
</form>
<?php if ($lookup_note !== ''): ?><p class="s8-alert s8-alert-info"><?= e($lookup_note) ?></p><?php endif; ?>
<?php if ($matches): ?>
  <div class="s8-card"><strong><?= count($matches) ?> students match. Choose one:</strong>
    <ul class="s8-results">
      <?php foreach ($matches as $m): ?>
        <li><a href="<?= e(url('admin/walkin.php', ['q' => $m['student_no']])) ?>"><?= e($m['full_name']) ?></a>
          <span class="s8-muted">· <?= e($m['student_no']) ?> · <?= e(trim(($m['course_code'] ?: $m['level_name'] ?: '') . ' ' . $m['year_level'])) ?> · <?= (int) $m['order_count'] ?> past order<?= (int) $m['order_count'] === 1 ? '' : 's' ?></span></li>
      <?php endforeach; ?>
    </ul></div>
<?php endif; ?>

<?php if ($form_error): ?><div class="s8-alert s8-alert-error" role="alert"><?= e($form_error) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="s8-alert s8-alert-error" role="alert">Please fix the highlighted fields and save again.</div><?php endif; ?>

<form method="post" action="<?= e(url('admin/walkin.php')) ?>" class="s8-form" style="max-width:none" novalidate>
  <?= csrf_field() ?>

  <section class="s8-card">
    <h2>Customer and student</h2>
    <div class="s8-form">
      <div class="s8-two">
        <label>Customer's full name (the person ordering) *
          <input type="text" name="full_name" maxlength="120" required value="<?= $val('full_name') ?>"><?= $fe('full_name') ?></label>
        <label>Ordering as the student's
          <select name="relationship"><?php foreach (walk_relationships() as $k => $lab): ?><option value="<?= e($k) ?>"<?= $sel('relationship', $k, 'self') ?>><?= e($lab) ?></option><?php endforeach; ?></select></label>
      </div>
      <div class="s8-two">
        <label>Student ID or LRN *
          <input type="text" name="student_no" maxlength="30" required value="<?= $val('student_no') ?>"><?= $fe('student_no') ?></label>
        <label>Student's name <span class="s8-hint">only if different from the customer</span>
          <input type="text" name="student_name" maxlength="120" value="<?= $val('student_name') ?>"><?= $fe('student_name') ?></label>
      </div>
      <div class="s8-two">
        <label>Mobile number *
          <input type="tel" name="contact_no" maxlength="20" required placeholder="09XXXXXXXXX" value="<?= $val('contact_no') ?>"><?= $fe('contact_no') ?></label>
        <label>Email <span class="s8-hint">optional</span>
          <input type="email" name="email" maxlength="120" value="<?= $val('email') ?>"><?= $fe('email') ?></label>
      </div>
      <div class="s8-two">
        <label>Education level
          <select name="education_level_id"><option value="0">Select</option>
            <?php foreach ($levels as $lv): ?><option value="<?= (int) $lv['education_level_id'] ?>"<?= $sel('education_level_id', $lv['education_level_id']) ?>><?= e($lv['name']) ?></option><?php endforeach; ?></select></label>
        <label>Course <span class="s8-hint">college only</span>
          <select name="course_id"><option value="0">Not applicable</option>
            <?php foreach ($courses as $co): ?><option value="<?= (int) $co['course_id'] ?>"<?= $sel('course_id', $co['course_id']) ?>><?= e($co['code'] . ' - ' . $co['name']) ?></option><?php endforeach; ?></select></label>
      </div>
      <div class="s8-two">
        <label>Year / grade level *
          <input type="text" name="year_level" maxlength="20" placeholder="e.g. 3rd Year or Grade 5" value="<?= $val('year_level') ?>"><?= $fe('year_level') ?></label>
        <label>Address <span class="s8-hint">optional</span>
          <input type="text" name="address" maxlength="255" value="<?= $val('address') ?>"></label>
      </div>
    </div>
  </section>

  <section class="s8-card">
    <h2>Items</h2>
    <p class="s8-hint">Availability refreshes while this page is open. At the counter you may order more than the online limit as long as stock is left. A toga is one per student per academic period.</p>
    <?= $fe('lines') ?>
    <div class="s8-table-wrap">
      <table class="s8-table s8-cart">
        <thead><tr><th>Item and size</th><th>Qty</th><th></th></tr></thead>
        <tbody id="s8-cart-body" data-api="<?= e(url('admin/walkin_api.php')) ?>" data-max="<?= WALK_MAX_LINES ?>">
          <?php for ($i = 0; $i < $row_count; $i++): echo $row_html((string) ($post_v[$i] ?? ''), (string) ($post_q[$i] ?? '')); endfor; ?>
        </tbody>
      </table>
    </div>
    <template id="s8-row-tpl"><?= $row_html('', '') ?></template>
    <div class="s8-actions"><button type="button" class="s8-btn s8-btn-light" id="s8-add-row">Add another row</button>
      <span class="s8-total" style="margin-left:auto">Total <span id="s8-total"><?= e(money(0)) ?></span></span></div>
  </section>

  <section class="s8-card">
    <h2>What to do now</h2>
    <div class="s8-form">
      <?php foreach (walk_after_options() as $k => $lab): ?>
        <label class="s8-radio"><input type="radio" name="after" value="<?= e($k) ?>"<?= $after === $k ? ' checked' : '' ?>><span><?= e($lab) ?></span></label>
      <?php endforeach; ?>
      <div class="s8-two">
        <label>Measure/Cutter <span class="s8-hint">optional, printed on the slip</span>
          <select name="measured_by"><option value="">— none —</option>
            <?php foreach ($staff as $s): ?><option value="<?= (int) $s['user_id'] ?>"<?= $sel('measured_by', $s['user_id']) ?>><?= e($s['full_name']) ?></option><?php endforeach; ?></select></label>
        <label>OIC-Manager <span class="s8-hint">optional</span>
          <select name="oic_manager_id"><option value="">— none —</option>
            <?php foreach ($managers as $s): ?><option value="<?= (int) $s['user_id'] ?>"<?= $sel('oic_manager_id', $s['user_id']) ?>><?= e($s['full_name']) ?></option><?php endforeach; ?></select></label>
      </div>
      <p class="s8-hint"><strong>Only if you record the payment now.</strong> The shop does not take payment: copy what the cashier's official receipt shows.</p>
      <div class="s8-two">
        <label>OR number <input type="text" name="or_no" maxlength="30" value="<?= $val('or_no') ?>"></label>
        <label>Total on the receipt (₱) <span class="s8-hint">blank = order total (plus any deposit)</span>
          <input type="text" name="amount_paid" inputmode="decimal" value="<?= $val('amount_paid') ?>"></label>
      </div>
      <div class="s8-two">
        <label>Toga deposit (₱) <span class="s8-hint">optional, toga only; amount to be confirmed with the shop</span>
          <input type="text" name="deposit_amount" inputmode="decimal" value="<?= $val('deposit_amount') ?>"></label>
        <label>Payment date <input type="date" name="paid_date" max="<?= e(date('Y-m-d')) ?>" value="<?= $val('paid_date', date('Y-m-d')) ?>"></label>
      </div>
      <div class="s8-two">
        <label>Received by <span class="s8-hint">who takes the items (blank = the customer)</span>
          <input type="text" name="received_by" maxlength="120" value="<?= $val('received_by') ?>"></label>
        <label>Toga due date <span class="s8-hint">blank = the shop's default</span>
          <input type="date" name="due_date" value="<?= $val('due_date') ?>"></label>
      </div>
      <label>Notes <span class="s8-hint">optional</span>
        <textarea name="remarks" rows="2" maxlength="500"><?= $val('remarks') ?></textarea></label>
    </div>
  </section>

  <div class="s8-actions">
    <button type="submit" class="s8-btn">Save walk-in order</button>
    <a class="s8-btn s8-btn-light" href="<?= e(url('admin/orders.php')) ?>">Cancel</a>
  </div>
</form>
<script src="<?= e(asset('js/walkin.js')) ?>" defer></script>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
