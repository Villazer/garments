<?php
require __DIR__ . '/includes/catalog_bootstrap.php';
require_once __DIR__ . '/includes/reserve_lib.php';
res_session_start();

// ---- guard: list must exist and pass the rules --------------------------------------
$ev = res_evaluate_current();
if (!$ev['lines']) { flash_set('info', 'Your reservation list is empty.'); redirect('catalog.php'); }
if (!$ev['ok'])    { flash_set('error', 'Please fix the marked lines before checking out.'); redirect('reserve.php'); }

$levels  = res_q('SELECT education_level_id, name FROM education_levels ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);
$courses = res_q('SELECT course_id, code, name FROM courses WHERE is_active = 1 ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
$rels    = ['self' => 'Myself (the student)', 'parent' => 'Parent', 'guardian' => 'Guardian', 'other' => 'Other'];

if (empty($_SESSION['checkout_token'])) { $_SESSION['checkout_token'] = bin2hex(random_bytes(16)); }

$errors = []; $in = [];

if (is_post()) {
    csrf_require();
    $tok = (string)($_POST['checkout_token'] ?? '');

    // double-submit: this exact form was already processed -> go to its confirmation
    if ($tok !== '' && isset($_SESSION['res_done'][$tok])) {
        redirect('confirmation.php', ['ref' => $_SESSION['res_done'][$tok]]);
    }
    if ($tok === '' || !hash_equals((string)$_SESSION['checkout_token'], $tok)) {
        flash_set('error', 'This form has expired. Please review your details and submit again.');
        $_SESSION['checkout_token'] = bin2hex(random_bytes(16));
        redirect('checkout.php');
    }

    // honeypot
    if (trim((string)($_POST['website'] ?? '')) !== '') { redirect('catalog.php'); }

    $f = fn($k, $max = 255) => mb_substr(clean_str($_POST[$k] ?? ''), 0, $max);
    $in = [
        'full_name' => $f('full_name', 120), 'relationship' => $f('relationship', 10),
        'student_name' => $f('student_name', 120), 'student_no' => $f('student_no', 30),
        'contact_no' => $f('contact_no', 20), 'email' => $f('email', 120),
        'education_level_id' => (int)($_POST['education_level_id'] ?? 0), 'course_id' => (int)($_POST['course_id'] ?? 0),
        'year_level' => $f('year_level', 20), 'address' => $f('address', 255), 'notes' => $f('notes', 500),
    ];

    if (mb_strlen($in['full_name']) < 2) { $errors['full_name'] = 'Please enter your full name.'; }
    if (!isset($rels[$in['relationship']])) { $in['relationship'] = 'self'; }
    if ($in['student_no'] === '') { $errors['student_no'] = 'Please enter the student ID or LRN.'; }
    elseif (($m = validate_student_no($in['student_no'])) !== null) { $errors['student_no'] = $m; }
    if ($in['contact_no'] === '') { $errors['contact_no'] = 'Please enter a mobile number.'; }
    elseif (($m = validate_ph_mobile($in['contact_no'])) !== null) { $errors['contact_no'] = $m; }
    else { $in['contact_no'] = normalize_ph_mobile($in['contact_no']); }
    if ($in['email'] !== '' && ($m = validate_email($in['email'])) !== null) { $errors['email'] = $m; }
    if ($in['year_level'] === '') { $errors['year_level'] = 'Please enter the year or grade level (for example "3rd Year" or "Grade 5").'; }
    if ($in['education_level_id'] && !in_array($in['education_level_id'], array_map('intval', array_column($levels, 'education_level_id')), true)) { $in['education_level_id'] = 0; }
    if ($in['course_id'] && !in_array($in['course_id'], array_map('intval', array_column($courses, 'course_id')), true)) { $in['course_id'] = 0; }

    if (!$errors) {
        $res = res_submit(res_list(), $in);
        if ($res['ok']) {
            res_empty();
            res_grant_view($res['ref']);
            $_SESSION['res_done'][$tok] = $res['ref'];
            $_SESSION['checkout_token'] = bin2hex(random_bytes(16));
            unset($_SESSION['res_problems']);
            redirect('confirmation.php', ['ref' => $res['ref']]);
        }
        if ($res['problems']) {                     // stock changed meanwhile: keep the list, show what failed
            $_SESSION['res_problems'] = array_values($res['problems']);
            remember_input($_POST);
            redirect('reserve.php');
        }
        $errors['_form'] = $res['error'] ?: 'Sorry, we could not save your request. Please try again.';
    }
}

$val = fn($k) => e((string)($in[$k] ?? old($k, '')));
$sel = fn($k, $v) => ((string)($in[$k] ?? old($k, '')) === (string)$v) ? 'selected' : '';
$err = fn($k) => isset($errors[$k]) ? '<span class="rv-field-err" role="alert">' . e($errors[$k]) . '</span>' : '';

$page_title = 'Checkout'; $nav_active = 'catalog';
require CATALOG_HEADER;
echo catalog_asset_tags();
echo '<link rel="stylesheet" href="' . e(asset('css/reserve.css')) . '">';
?>
<main class="rv-wrap rv-two-col">
  <section>
    <h1>Your details</h1>
    <p class="rv-muted">No account needed. We use these details for your order slip and to find your request later.</p>
    <?php if (isset($errors['_form'])): ?><div class="rv-alert rv-alert-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['_form'])): ?><div class="rv-alert rv-alert-error" role="alert">Please fix the highlighted fields.</div><?php endif; ?>

    <form method="post" action="<?= e(url('checkout.php')) ?>" class="rv-card rv-form" data-once novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="checkout_token" value="<?= e($_SESSION['checkout_token']) ?>">
      <div class="rv-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

      <label for="full_name">Your full name *</label>
      <input id="full_name" name="full_name" maxlength="120" required autocomplete="name" value="<?= $val('full_name') ?>"><?= $err('full_name') ?>

      <label for="relationship">You are the student's</label>
      <select id="relationship" name="relationship">
        <?php foreach ($rels as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $sel('relationship', $k) ?>><?= e($lbl) ?></option><?php endforeach; ?>
      </select>

      <label for="student_name">Student's name <small>(only if different from yours)</small></label>
      <input id="student_name" name="student_name" maxlength="120" value="<?= $val('student_name') ?>">

      <label for="student_no">Student ID or LRN *</label>
      <input id="student_no" name="student_no" maxlength="30" required value="<?= $val('student_no') ?>"><?= $err('student_no') ?>

      <label for="contact_no">Mobile number *</label>
      <input id="contact_no" name="contact_no" type="tel" maxlength="20" required autocomplete="tel" placeholder="09XXXXXXXXX" value="<?= $val('contact_no') ?>"><?= $err('contact_no') ?>

      <label for="email">Email <small>(optional)</small></label>
      <input id="email" name="email" type="email" maxlength="120" autocomplete="email" value="<?= $val('email') ?>"><?= $err('email') ?>

      <div class="rv-row">
        <div>
          <label for="education_level_id">Education level</label>
          <select id="education_level_id" name="education_level_id">
            <option value="0">Select</option>
            <?php foreach ($levels as $lv): ?><option value="<?= (int)$lv['education_level_id'] ?>" <?= $sel('education_level_id', $lv['education_level_id']) ?>><?= e($lv['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="course_id">Course <small>(college only)</small></label>
          <select id="course_id" name="course_id">
            <option value="0">Not applicable</option>
            <?php foreach ($courses as $co): ?><option value="<?= (int)$co['course_id'] ?>" <?= $sel('course_id', $co['course_id']) ?>><?= e($co['code'] . ' - ' . $co['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>

      <label for="year_level">Year / grade level *</label>
      <input id="year_level" name="year_level" maxlength="20" required placeholder="e.g. 3rd Year or Grade 5" value="<?= $val('year_level') ?>"><?= $err('year_level') ?>

      <label for="address">Address <small>(optional)</small></label>
      <input id="address" name="address" maxlength="255" autocomplete="street-address" value="<?= $val('address') ?>">

      <label for="notes">Notes <small>(optional)</small></label>
      <textarea id="notes" name="notes" rows="3" maxlength="500"><?= $val('notes') ?></textarea>

      <div class="rv-actions">
        <a class="rv-btn" href="<?= e(url('reserve.php')) ?>">Back to list</a>
        <button class="rv-btn rv-btn-primary" type="submit">Submit reservation</button>
      </div>
    </form>
  </section>

  <aside class="rv-card rv-summary" aria-label="Order summary">
    <h2>Summary</h2>
    <ul>
      <?php foreach ($ev['lines'] as $l): ?>
        <li><span><?= e($l['item_name']) ?> (<?= e($l['size_label']) ?>) &times; <?= (int)$l['qty'] ?></span><span><?= e(money($l['line_cents'] / 100)) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <p class="rv-total"><span>Total</span><strong><?= e(money($ev['total_cents'] / 100)) ?></strong></p>
    <p class="rv-muted"><?= $ev['approval_needed']
        ? 'Staff will review this request first. Your items are held once it is approved.'
        : 'Your items are reserved as soon as you submit.' ?></p>
  </aside>
</main>
<script src="<?= e(asset('js/reserve.js')) ?>"></script>
<?php require CATALOG_FOOTER;
