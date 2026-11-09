<?php
/**
 * Public toga rental request. One active rental per student per academic period
 * is enforced inside a transaction (see tog_create_locked in includes/toga_lib.php).
 */
require __DIR__ . '/includes/catalog_bootstrap.php';
require_once __DIR__ . '/includes/public5_common.php';
require_once __DIR__ . '/includes/toga_lib.php';

$period  = s5_current_period();
$togas   = tog_catalog();
$courses = tog_courses();

$variant_ids = [];
foreach ($togas as $t) {
    foreach ($t['sizes'] as $s) {
        $variant_ids[] = (int) $s['variant_id'];
    }
}
$course_ids = array_map(static function ($c) { return (int) $c['course_id']; }, $courses);

$errors = [];
$form_error = null;
$form_error_code = 0;
$selected = (int) ($_POST['variant_id'] ?? ($_GET['variant'] ?? 0));

if (is_post()) {
    csrf_require();
    // Honeypot: real people never fill the hidden field.
    if (clean_str($_POST['website'] ?? '') !== '') {
        redirect('toga_request.php');
    }
    [$clean, $errors] = tog_validate($_POST, $course_ids, $variant_ids);
    if (!$errors && $period !== null) {
        $res = tog_create_request($clean);
        if ($res['ok']) {
            s5_grant_view($res['ref']);
            redirect('toga_confirmation.php', ['ref' => $res['ref']]);
        }
        $form_error = $res['error'];
        $form_error_code = (int) $res['code'];
    }
}

/** Posted value, escaped, for refilling the form after an error. */
function tog_old(string $key): string
{
    return e((string) ($_POST[$key] ?? ''));
}

$page_title = 'Toga rental request';
$nav_active = 'toga';
require CATALOG_HEADER;
echo s5_asset_tags(true);
flash_show();
?>
<main class="s5-page" id="main">
  <h1>Toga rental request</h1>
  <p class="s5-lead">Request a graduation toga here. The Garment Shop reviews your request, then you pay at the cashier and claim the toga at the shop.</p>

  <div class="s5-alert">
    <h3>Rental terms are confirmed by the shop</h3>
    <p>The deposit amount, final rental fee, rental period and any penalty rules are not set online. The Garment Shop confirms them with you. The price shown below is the listed fee only.</p>
  </div>

<?php if ($period === null): ?>
  <div class="s5-alert s5-alert--warning"><p>Toga requests are closed because no academic period is open right now. Please ask the Garment Shop.</p></div>
<?php elseif (!$togas): ?>
  <div class="s5-alert s5-alert--warning"><p>No toga is open for online requests right now. Please visit the Garment Shop.</p></div>
<?php else: ?>

  <?php if ($form_error): ?>
    <div class="s5-alert s5-alert--error" role="alert">
      <p><?= e($form_error) ?>
      <?php if ($form_error_code === 1): ?>
        <a href="<?= e(url('waitlist.php', ['variant' => (int) ($_POST['variant_id'] ?? 0)])) ?>">Join the waitlist</a>.
      <?php endif; ?></p>
    </div>
  <?php elseif ($errors): ?>
    <div class="s5-alert s5-alert--error" role="alert"><p>Please fix the highlighted fields and send the form again.</p></div>
  <?php endif; ?>

  <form method="post" action="<?= e(url('toga_request.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="s5-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

    <?php foreach ($togas as $t): ?>
      <fieldset class="s5-sizes">
        <legend class="s5-label"><?= e($t['name']) ?> &ndash; choose a size</legend>
        <?php if (!empty($t['description'])): ?><p class="s5-hint"><?= e($t['description']) ?></p><?php endif; ?>
        <?php foreach ($t['sizes'] as $s):
            $out = ((int) $s['available'] <= 0);
            $vid = (int) $s['variant_id']; ?>
          <label class="s5-size<?= $out ? ' s5-size--out' : '' ?>">
            <input type="radio" name="variant_id" value="<?= $vid ?>" <?= $selected === $vid ? 'checked' : '' ?> <?= $out ? 'disabled' : '' ?>>
            <span>Size <?= e($s['size_label']) ?> &ndash; <?= e(money($s['unit_price'])) ?></span>
            <?= $out ? '<span class="s5-pill s5-pill--bad">Out of stock</span>' : stock_badge((int) $s['available'], (int) $s['low_stock_threshold']) ?>
          </label>
          <?php if ($out): ?>
            <p class="s5-hint" style="margin:-.3rem 0 .6rem"><a href="<?= e(url('waitlist.php', ['variant' => $vid])) ?>">Join the waitlist for size <?= e($s['size_label']) ?></a></p>
          <?php endif; ?>
        <?php endforeach; ?>
        <?= field_error($errors, 'variant_id') ?>
      </fieldset>
    <?php endforeach; ?>

    <div class="s5-card">
      <h2 style="margin-top:0">Student</h2>
      <div class="s5-field">
        <label for="student_no">Student number</label>
        <input class="s5-input" id="student_no" name="student_no" maxlength="30" required autocomplete="off" value="<?= tog_old('student_no') ?>">
        <?= field_error($errors, 'student_no') ?>
      </div>
      <div class="s5-field">
        <label for="student_name">Student full name</label>
        <input class="s5-input" id="student_name" name="student_name" maxlength="120" required autocomplete="name" value="<?= tog_old('student_name') ?>">
        <?= field_error($errors, 'student_name') ?>
      </div>
      <div class="s5-field">
        <label for="course_id">Course</label>
        <select class="s5-select" id="course_id" name="course_id" required>
          <option value="">Choose a course</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int) $c['course_id'] ?>" <?= (int) ($_POST['course_id'] ?? 0) === (int) $c['course_id'] ? 'selected' : '' ?>><?= e($c['code']) ?> &ndash; <?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= field_error($errors, 'course_id') ?>
      </div>
      <div class="s5-field">
        <label for="year_level">Year level</label>
        <input class="s5-input" id="year_level" name="year_level" maxlength="20" required placeholder="e.g. 4th Year" value="<?= tog_old('year_level') ?>">
        <?= field_error($errors, 'year_level') ?>
      </div>
      <div class="s5-field">
        <span class="s5-label">Academic period</span>
        <p style="margin:0"><b><?= e(s5_period_label($period)) ?></b></p>
        <p class="s5-hint">Set by the shop. Only one active toga rental is allowed per student in each academic period.</p>
      </div>
    </div>

    <div class="s5-card">
      <h2 style="margin-top:0">Your contact details</h2>
      <div class="s5-field">
        <label for="requester">Who is making this request?</label>
        <select class="s5-select" id="requester" name="requester">
          <?php foreach (tog_requester_options() as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= (($_POST['requester'] ?? 'self') === $k) ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="s5-field" id="requester_name_wrap"<?= (($_POST['requester'] ?? 'self') === 'self') ? ' hidden' : '' ?>>
        <label for="requester_name">Your full name</label>
        <input class="s5-input" id="requester_name" name="requester_name" maxlength="120" value="<?= tog_old('requester_name') ?>">
        <?= field_error($errors, 'requester_name') ?>
      </div>
      <div class="s5-field">
        <label for="contact_no">Mobile number</label>
        <input class="s5-input" id="contact_no" name="contact_no" type="tel" inputmode="tel" maxlength="20" required placeholder="09171234567" value="<?= tog_old('contact_no') ?>">
        <?= field_error($errors, 'contact_no') ?>
      </div>
      <div class="s5-field">
        <label for="email">Email (optional)</label>
        <input class="s5-input" id="email" name="email" type="email" maxlength="120" autocomplete="email" value="<?= tog_old('email') ?>">
        <p class="s5-hint">You can use your mobile number or email to track the request later.</p>
        <?= field_error($errors, 'email') ?>
      </div>
    </div>

    <div class="s5-field">
      <label style="font-weight:400">
        <input type="checkbox" name="agree" value="1" <?= !empty($_POST['agree']) ? 'checked' : '' ?>>
        I understand the deposit, rental fee details and return rules will be confirmed by the Garment Shop.
      </label>
      <?= field_error($errors, 'agree') ?>
    </div>
    <button class="s5-btn s5-btn--block" type="submit">Send toga request</button>
  </form>
<?php endif; ?>

  <?= s5_quicklinks_html() ?>
</main>
<?php require CATALOG_FOOTER;
