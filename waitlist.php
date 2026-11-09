<?php
/**
 * Public waitlist. waitlist.php            -> list of out-of-stock sizes to pick from
 *                  waitlist.php?variant=ID -> join form for one size
 *                  waitlist.php?done=1     -> confirmation page
 */
require __DIR__ . '/includes/catalog_bootstrap.php';
require_once __DIR__ . '/includes/public5_common.php';
require_once __DIR__ . '/includes/waitlist_lib.php';

s5_session();
$variant_id = (int) ($_POST['variant_id'] ?? ($_GET['variant'] ?? 0));
$variant = $variant_id > 0 ? wl_find_variant($variant_id) : null;
$errors = [];
$form_error = null;
$view = 'picker';

if (isset($_GET['done'])) {
    $view = isset($_SESSION['s5_wl_done']) ? 'done' : 'picker';
} elseif ($variant_id > 0) {
    if ($variant === null) {
        abort(404, 'We could not find that item size.');
    }
    $view = 'form';
}

if (is_post() && $view === 'form') {
    csrf_require();
    if (clean_str($_POST['website'] ?? '') !== '') { // honeypot
        redirect('waitlist.php');
    }
    if (!wl_is_joinable($variant)) {
        $form_error = ((int) $variant['tracks_stock'] === 1)
            ? 'Good news: this size is in stock again, so there is no need to wait. You can reserve it from the catalog.'
            : 'This item is made to order, so there is no waitlist for it.';
    } else {
        [$clean, $errors] = wl_validate($_POST, $variant);
        if (!$errors) {
            $res = wl_join($variant_id, $clean);
            if ($res['ok']) {
                $_SESSION['s5_wl_done'] = [
                    'item' => $variant['item_name'], 'size' => $variant['size_label'], 'name' => $clean['contact_name'],
                    'contact' => $clean['contact_no'], 'qty' => $clean['quantity'], 'duplicate' => !empty($res['duplicate']),
                    'show_qty' => s5_waitlist_has_quantity(),
                ];
                redirect('waitlist.php', ['done' => 1]);
            }
            $form_error = $res['error'];
        }
    }
}

$page_title = 'Waitlist';
$nav_active = 'waitlist';
require CATALOG_HEADER;
echo s5_asset_tags(true);
flash_show();
?>
<main class="s5-page" id="main">
<?php if ($view === 'done'): $d = $_SESSION['s5_wl_done']; unset($_SESSION['s5_wl_done']); ?>
  <h1>You are on the waitlist</h1>
  <div class="s5-alert s5-alert--success">
    <h3><?= $d['duplicate'] ? 'You were already on the list' : 'Thank you, ' . e($d['name']) ?></h3>
    <p><?= $d['duplicate']
        ? 'We kept a single entry for this size and updated it with your latest details.'
        : 'The Garment Shop will contact you when this size is back in stock.' ?></p>
  </div>
  <div class="s5-card">
    <dl class="s5-meta">
      <div><dt>Item</dt><dd><?= e($d['item']) ?></dd></div>
      <div><dt>Size</dt><dd><?= e($d['size']) ?></dd></div>
      <?php if ($d['show_qty']): ?><div><dt>Quantity wanted</dt><dd><?= (int) $d['qty'] ?></dd></div><?php endif; ?>
      <div><dt>We will call or text</dt><dd><?= e($d['contact']) ?></dd></div>
    </dl>
  </div>
  <p class="s5-hint">Joining the waitlist does not reserve stock. Reserve from the catalog as soon as the size is available.</p>
  <p>
    <a class="s5-btn" href="<?= e(url('restock.php')) ?>">See the restock schedule</a>
    <a class="s5-btn s5-btn--ghost" href="<?= e(url('catalog.php')) ?>">Back to catalog</a>
  </p>

<?php elseif ($view === 'form'): ?>
  <h1>Join the waitlist</h1>
  <p class="s5-lead"><b><?= e($variant['item_name']) ?></b>, size <b><?= e($variant['size_label']) ?></b></p>

  <?php if (!wl_is_joinable($variant)): ?>
    <div class="s5-alert s5-alert--success">
      <h3><?= (int) $variant['tracks_stock'] === 1 ? 'This size is in stock' : 'No waitlist needed' ?></h3>
      <p><?= (int) $variant['tracks_stock'] === 1
          ? 'There is no need to wait. You can reserve it from the catalog now.'
          : 'This item is made to order, so you can request it directly.' ?></p>
    </div>
    <p><a class="s5-btn" href="<?= e(url('item.php', ['id' => (int) $variant['item_id']])) ?>">Go to the item page</a></p>
  <?php else: ?>
    <p class="s5-lead">This size is out of stock. Leave your details and the shop will contact you when it is available. <a href="<?= e(url('restock.php', ['item' => (int) $variant['item_id']])) ?>">See the restock schedule</a>.</p>
    <?php if ($form_error): ?>
      <div class="s5-alert s5-alert--error" role="alert"><p><?= e($form_error) ?></p></div>
    <?php elseif ($errors): ?>
      <div class="s5-alert s5-alert--error" role="alert"><p>Please fix the highlighted fields and send the form again.</p></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('waitlist.php', ['variant' => $variant_id])) ?>" class="s5-card" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="variant_id" value="<?= $variant_id ?>">
      <div class="s5-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <div class="s5-field">
        <label for="contact_name">Your full name</label>
        <input class="s5-input" id="contact_name" name="contact_name" maxlength="120" required autocomplete="name" value="<?= e((string) ($_POST['contact_name'] ?? '')) ?>">
        <?= field_error($errors, 'contact_name') ?>
      </div>
      <div class="s5-field">
        <label for="contact_no">Mobile number</label>
        <input class="s5-input" id="contact_no" name="contact_no" type="tel" inputmode="tel" maxlength="20" required placeholder="09171234567" value="<?= e((string) ($_POST['contact_no'] ?? '')) ?>">
        <?= field_error($errors, 'contact_no') ?>
      </div>
      <div class="s5-field">
        <label for="email">Email (optional)</label>
        <input class="s5-input" id="email" name="email" type="email" maxlength="120" autocomplete="email" value="<?= e((string) ($_POST['email'] ?? '')) ?>">
        <?= field_error($errors, 'email') ?>
      </div>
      <?php if (s5_waitlist_has_quantity()): $max = wl_max_quantity($variant); ?>
        <div class="s5-field">
          <label for="quantity">Quantity wanted</label>
          <input class="s5-input" id="quantity" name="quantity" type="number" inputmode="numeric" min="1" max="<?= $max ?>" required value="<?= e((string) ($_POST['quantity'] ?? '1')) ?>">
          <p class="s5-hint">Up to <?= $max ?>.</p>
          <?= field_error($errors, 'quantity') ?>
        </div>
      <?php endif; ?>
      <button class="s5-btn s5-btn--block" type="submit">Join the waitlist</button>
    </form>
  <?php endif; ?>

<?php else:
    $list = wl_out_of_stock_list(); ?>
  <h1>Waitlist</h1>
  <p class="s5-lead">If the size you need is out of stock, leave your name and contact number and the shop will contact you when it comes back.</p>
  <?php if (!$list): ?>
    <div class="s5-alert s5-alert--success"><p>Everything that can be waitlisted is in stock right now. <a href="<?= e(url('catalog.php')) ?>">Browse the catalog</a>.</p></div>
  <?php else: ?>
    <div class="s5-card">
      <ul class="s5-list">
        <?php foreach ($list as $r): ?>
          <li>
            <span><?= e($r['item_name']) ?> &ndash; size <?= e($r['size_label']) ?> <span class="s5-pill s5-pill--bad">Out of stock</span></span>
            <a class="s5-btn s5-btn--ghost" href="<?= e(url('waitlist.php', ['variant' => (int) $r['variant_id']])) ?>">Join waitlist</a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
<?php endif; ?>

  <?= s5_quicklinks_html() ?>
</main>
<?php require CATALOG_FOOTER;
