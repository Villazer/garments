<?php
/**
 * Create / edit one item: details, images and sizes (variants).
 * Manager and admin only (see inv_catalog_roles()). Every POST goes through
 * csrf_require() and ends in a redirect (post/redirect/get).
 */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/inventory.php';

$user = require_role(inv_catalog_roles());
$uid  = (int) $user['user_id'];

$item_id  = (int) ($_GET['id'] ?? $_POST['item_id'] ?? 0);
$item     = $item_id > 0 ? inv_item_find($item_id) : null;
if ($item_id > 0 && !$item) {
    staff_not_found();
}
$is_new   = $item === null;
$problem  = inv_migration_problem();
$errors   = [];
$form     = $item ?: [
    'name' => '', 'description' => '', 'item_code' => '', 'item_type' => 'ready_made', 'category_id' => '',
    'education_level_id' => '', 'course_id' => '', 'reservation_mode' => 'approval', 'max_qty_per_request' => 3, 'is_active' => 1,
];

if ($problem === null && is_post()) {
    csrf_require();
    $do = (string) ($_POST['do'] ?? '');
    $back = static function (string $type, string $msg) use ($item_id) {
        flash_set($type, $msg);
        redirect('admin/item_edit.php', ['id' => $item_id]);
    };

    try {
        switch ($do) {
            case 'save_item':
                [$clean, $errors] = inv_item_validate($_POST, $item);
                if (!$errors) {
                    if ($is_new) {
                        $new_id = inv_item_create($clean, $uid);
                        flash_set('success', 'Item created. Now add its sizes below.');
                        redirect('admin/item_edit.php', ['id' => $new_id]);
                    }
                    inv_item_update($item_id, $clean, $uid);
                    $back('success', 'Item saved.');
                }
                $form = $clean + ($item ?: []);   // keep what was typed
                break;

            case 'add_variant':
            case 'save_variant':
                if ($is_new) {
                    staff_not_found();
                }
                $vid = $do === 'save_variant' ? (int) ($_POST['variant_id'] ?? 0) : 0;
                if ($vid > 0) {
                    $existing = inv_variant_find($vid);
                    if (!$existing || (int) $existing['item_id'] !== $item_id) {
                        staff_not_found();
                    }
                }
                [$c, $verrors] = inv_variant_validate($_POST, $item, $vid);
                $opening = 0;
                if ($do === 'add_variant' && (int) $item['tracks_stock'] === 1 && trim((string) ($_POST['opening_qty'] ?? '')) !== '') {
                    $opening = inv_int($_POST['opening_qty'], 0, INV_MAX_QTY);
                    if ($opening === null) {
                        $verrors[] = 'Opening stock must be a whole number from 0 to ' . INV_MAX_QTY . '.';
                        $opening = 0;
                    }
                }
                if ($verrors) {
                    $back('error', implode(' ', $verrors));
                }
                if ($vid > 0) {
                    $price_changed = inv_variant_update($vid, $c, $uid);
                    $back('success', 'Size saved.' . ($price_changed ? ' The new price applies to new orders only; existing orders keep the price they were placed at.' : ''));
                }
                inv_variant_create($item, $c, $opening, $uid);
                $back('success', 'Size added.' . ($opening > 0 ? ' Opening stock of ' . $opening . ' recorded in the movement history.' : ''));
                break;

            case 'toggle_variant':
                $vid = (int) ($_POST['variant_id'] ?? 0);
                $v = inv_variant_find($vid);
                if (!$v || (int) $v['item_id'] !== $item_id) {
                    staff_not_found();
                }
                inv_variant_set_active($vid, (int) ($_POST['to'] ?? 0) === 1, $uid);
                $back('success', (int) ($_POST['to'] ?? 0) === 1 ? 'Size reactivated.' : 'Size deactivated. It is hidden from the public catalog; its history is kept.');
                break;

            case 'delete_variant':
                $vid = (int) ($_POST['variant_id'] ?? 0);
                $v = inv_variant_find($vid);
                if (!$v || (int) $v['item_id'] !== $item_id) {
                    staff_not_found();
                }
                inv_variant_delete($vid, $uid);
                $back('success', 'Size deleted.');
                break;

            case 'upload_image':
                inv_image_upload($item_id, $_FILES['image'] ?? [], (string) ($_POST['alt'] ?? $item['name'] ?? ''), $uid);
                $back('success', 'Image uploaded.');
                break;

            case 'delete_image':
                inv_image_delete($item_id, (int) ($_POST['image_id'] ?? 0), $uid);
                $back('success', 'Image removed.');
                break;

            case 'archive':
            case 'restore':
                $open = inv_item_set_active($item_id, $do === 'restore', $uid);
                if ($do === 'archive') {
                    $back($open > 0 ? 'warning' : 'success', $open > 0
                        ? 'Item archived. ' . $open . ' open order(s) still contain it; requested orders cannot be approved while it is archived.'
                        : 'Item archived. It is hidden from the public catalog but its history is kept.');
                }
                $back('success', 'Item restored to the catalog.');
                break;

            case 'delete_item':
                inv_item_delete($item_id, $uid);
                flash_set('success', 'Item deleted permanently.');
                redirect('admin/items.php');
                break;
        }
    } catch (DomainException $ex) {
        if ($do === 'save_item') {
            $errors['general'] = $ex->getMessage();
        } else {
            $back('error', $ex->getMessage());
        }
    }
}

$page_title = $is_new ? 'New item' : 'Edit item';
$nav_active = 'inventory';
require AUTH_ROOT . '/includes/staff_header.php';
echo inv_subnav('items');
if (inv_migration_block()) {
    require AUTH_ROOT . '/includes/staff_footer.php';
    return;
}

$fe = static function (array $errors, string $key): string {
    return isset($errors[$key]) ? '<p class="inv-error">' . e($errors[$key]) . '</p>' : '';
};
$locked = !$is_new;
?>
<h1><?= $is_new ? 'New item' : e($item['name']) ?></h1>
<?php if (!empty($errors['general'])): ?><div class="inv-alert inv-alert-error" role="alert"><?= e($errors['general']) ?></div><?php endif; ?>
<?php if ($errors && empty($errors['general'])): ?><div class="inv-alert inv-alert-error" role="alert">Please fix the highlighted fields.</div><?php endif; ?>

<form class="inv-form" method="post" action="<?= e(url('admin/item_edit.php', $is_new ? [] : ['id' => $item_id])) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save_item">
  <?php if (!$is_new): ?><input type="hidden" name="item_id" value="<?= $item_id ?>"><?php endif; ?>
  <h2>Details</h2>
  <div class="inv-grid-2">
    <div class="inv-field">
      <label for="name">Name</label>
      <input id="name" type="text" name="name" maxlength="150" required value="<?= e($form['name']) ?>">
      <?= $fe($errors, 'name') ?>
    </div>
    <div class="inv-field">
      <label for="item_code">Item code</label>
      <input id="item_code" type="text" name="item_code" maxlength="30" <?= $locked ? 'readonly' : 'required' ?> value="<?= e($form['item_code']) ?>" placeholder="e.g. PRI-BOY-POLO">
      <?php if ($locked): ?><p class="inv-hint">The code cannot be changed after creation.</p><?php endif; ?>
      <?= $fe($errors, 'item_code') ?>
    </div>
    <div class="inv-field">
      <label for="item_type">Type</label>
      <select id="item_type" name="item_type" <?= $locked ? 'disabled' : 'required' ?>>
        <?php foreach (inv_item_types() as $k => $l): ?><option value="<?= e($k) ?>"<?= $form['item_type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
      <?php if ($locked): ?><p class="inv-hint">The type cannot be changed after creation. Archive this item and create a new one if it was set wrongly.</p><?php endif; ?>
      <?= $fe($errors, 'item_type') ?>
    </div>
    <div class="inv-field">
      <label for="category_id">Category</label>
      <select id="category_id" name="category_id" required>
        <option value="">Choose…</option>
        <?php foreach (inv_categories() as $c): ?><option value="<?= (int) $c['category_id'] ?>"<?= (int) $form['category_id'] === (int) $c['category_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
      <?= $fe($errors, 'category_id') ?>
    </div>
    <div class="inv-field">
      <label for="education_level_id">Education level</label>
      <select id="education_level_id" name="education_level_id">
        <option value="0">Any / not specific</option>
        <?php foreach (inv_levels() as $l): ?><option value="<?= (int) $l['education_level_id'] ?>"<?= (int) $form['education_level_id'] === (int) $l['education_level_id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
      </select>
      <?= $fe($errors, 'education_level_id') ?>
    </div>
    <div class="inv-field">
      <label for="course_id">Course (tertiary uniforms)</label>
      <select id="course_id" name="course_id">
        <option value="0">Not course-specific</option>
        <?php foreach (inv_courses() as $c): ?><option value="<?= (int) $c['course_id'] ?>"<?= (int) $form['course_id'] === (int) $c['course_id'] ? ' selected' : '' ?>><?= e($c['code']) ?> - <?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
      <?= $fe($errors, 'course_id') ?>
    </div>
    <div class="inv-field">
      <label for="reservation_mode">Online reservation</label>
      <select id="reservation_mode" name="reservation_mode" required>
        <?php foreach (inv_reservation_modes() as $k => $l): ?><option value="<?= e($k) ?>"<?= $form['reservation_mode'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
      <?= $fe($errors, 'reservation_mode') ?>
    </div>
    <div class="inv-field">
      <label for="max_qty_per_request">Max quantity per online request</label>
      <input id="max_qty_per_request" type="number" name="max_qty_per_request" min="1" max="50" required value="<?= e((string) $form['max_qty_per_request']) ?>">
      <p class="inv-hint">A safety cap for online requests only; staff can add more at the counter. Togas are always 1.</p>
      <?= $fe($errors, 'max_qty_per_request') ?>
    </div>
  </div>
  <div class="inv-field">
    <label for="description">Description</label>
    <textarea id="description" name="description" maxlength="500"><?= e((string) $form['description']) ?></textarea>
    <?= $fe($errors, 'description') ?>
  </div>
  <div class="inv-field">
    <label><input type="checkbox" name="is_active" value="1"<?= !empty($form['is_active']) ? ' checked' : '' ?>> Active (shown in the public catalog)</label>
  </div>
  <button class="inv-btn" type="submit"><?= $is_new ? 'Create item' : 'Save details' ?></button>
  <a class="inv-btn inv-btn-light" href="<?= e(url('admin/items.php')) ?>">Back to items</a>
</form>

<?php if (!$is_new):
    $tracks = (int) $item['tracks_stock'] === 1;
    $variants = inv_variants($item_id);
    $images = inv_images($item_id);
    $img_cfg = inv_image_cfg();
    ?>
<section class="inv-section">
  <h2>Images</h2>
  <?php if ($img_cfg === null || $img_cfg['problem']): ?>
    <div class="inv-alert inv-alert-info"><?= e($img_cfg === null ? 'Image upload is unavailable: the item_images table (migration 001) is missing.' : $img_cfg['problem']) ?></div>
  <?php else: ?>
    <?php if ($images): ?>
      <div class="inv-images">
        <?php foreach ($images as $img): ?>
          <figure>
            <img src="<?= e($img['url']) ?>" alt="<?= e((string) $img['alt']) ?>" loading="lazy">
            <form method="post" action="<?= e(url('admin/item_edit.php', ['id' => $item_id])) ?>">
              <?= csrf_field() ?><input type="hidden" name="do" value="delete_image"><input type="hidden" name="item_id" value="<?= $item_id ?>">
              <input type="hidden" name="image_id" value="<?= (int) $img['image_id'] ?>">
              <button class="inv-btn inv-btn-sm inv-btn-light" type="submit" onclick="return confirm('Remove this image?')">Remove</button>
            </form>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php else: ?><p class="inv-muted">No images yet. The public catalog shows a placeholder.</p><?php endif; ?>
    <?php if (count($images) < INV_IMAGE_MAX_COUNT): ?>
    <form class="inv-form" method="post" enctype="multipart/form-data" action="<?= e(url('admin/item_edit.php', ['id' => $item_id])) ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="upload_image"><input type="hidden" name="item_id" value="<?= $item_id ?>">
      <div class="inv-grid-2">
        <div class="inv-field"><label for="image">Image (JPG, PNG or WebP, up to <?= e((string) round(inv_image_max_bytes() / 1048576, 1)) ?> MB)</label>
          <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp" required></div>
        <div class="inv-field"><label for="alt">Description for screen readers</label>
          <input id="alt" type="text" name="alt" maxlength="150" value="<?= e($item['name']) ?>"></div>
      </div>
      <button class="inv-btn" type="submit">Upload image</button>
      <p class="inv-hint">The file is renamed, checked and stored in uploads/items/. The first image is the main one.</p>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="inv-section">
  <h2>Sizes and prices</h2>
  <p class="inv-muted">A price change applies to new orders only; existing orders keep the price they were placed at.
    <?= $tracks ? 'Change stock with “Adjust”, not here.' : 'This item has no stock count.' ?></p>
  <div class="inv-table-wrap">
  <table class="inv-table">
    <thead><tr><th>Size</th><th>SKU</th><th>Price (₱)</th><?php if ($tracks): ?><th>Low at</th><th class="num">On hand</th><th class="num">Reserved</th><?php endif; ?><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (!$variants): ?><tr><td colspan="8" class="inv-muted">No sizes yet. Add one below.</td></tr><?php endif; ?>
    <?php foreach ($variants as $v):
        $fid = 'vf' . (int) $v['variant_id'];
        $vactive = (int) $v['is_active'] === 1;
        $can_delete = !inv_variant_has_history((int) $v['variant_id']); ?>
      <tr<?= $vactive ? '' : ' class="is-muted"' ?>>
        <td><input form="<?= $fid ?>" type="text" name="size_label" maxlength="20" required value="<?= e($v['size_label']) ?>" aria-label="Size"></td>
        <td><input form="<?= $fid ?>" type="text" name="sku" maxlength="40" required value="<?= e($v['sku']) ?>" aria-label="SKU"></td>
        <td><input form="<?= $fid ?>" type="text" inputmode="decimal" name="unit_price" required value="<?= e($v['unit_price']) ?>" aria-label="Price"></td>
        <?php if ($tracks): ?>
          <td><input form="<?= $fid ?>" type="number" name="low_stock_threshold" min="0" required value="<?= (int) $v['low_stock_threshold'] ?>" aria-label="Low-stock threshold"></td>
          <td class="num"><?= (int) $v['quantity_on_hand'] ?></td>
          <td class="num"><?= (int) $v['quantity_reserved'] ?></td>
        <?php endif; ?>
        <td><?= $vactive ? '<span class="inv-badge inv-badge-in">Active</span>' : '<span class="inv-badge inv-badge-muted">Inactive</span>' ?></td>
        <td><div class="inv-actions">
          <form id="<?= $fid ?>" method="post" action="<?= e(url('admin/item_edit.php', ['id' => $item_id])) ?>">
            <?= csrf_field() ?><input type="hidden" name="do" value="save_variant"><input type="hidden" name="item_id" value="<?= $item_id ?>">
            <input type="hidden" name="variant_id" value="<?= (int) $v['variant_id'] ?>">
            <button class="inv-btn inv-btn-sm" type="submit">Save</button>
          </form>
          <?php if ($tracks && can('inventory.count')): ?><a class="inv-btn inv-btn-sm inv-btn-light" href="<?= e(url('admin/stock_adjust.php', ['variant' => (int) $v['variant_id']])) ?>">Adjust</a><?php endif; ?>
          <form method="post" action="<?= e(url('admin/item_edit.php', ['id' => $item_id])) ?>">
            <?= csrf_field() ?><input type="hidden" name="do" value="toggle_variant"><input type="hidden" name="item_id" value="<?= $item_id ?>">
            <input type="hidden" name="variant_id" value="<?= (int) $v['variant_id'] ?>"><input type="hidden" name="to" value="<?= $vactive ? 0 : 1 ?>">
            <button class="inv-btn inv-btn-sm inv-btn-light" type="submit"><?= $vactive ? 'Deactivate' : 'Reactivate' ?></button>
          </form>
          <?php if ($can_delete): ?>
          <form method="post" action="<?= e(url('admin/item_edit.php', ['id' => $item_id])) ?>">
            <?= csrf_field() ?><input type="hidden" name="do" value="delete_variant"><input type="hidden" name="item_id" value="<?= $item_id ?>">
            <input type="hidden" name="variant_id" value="<?= (int) $v['variant_id'] ?>">
            <button class="inv-btn inv-btn-sm inv-btn-danger" type="submit" onclick="return confirm('Delete this size permanently?')">Delete</button>
          </form>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <form class="inv-form" method="post" action="<?= e(url('admin/item_edit.php', ['id' => $item_id])) ?>">
    <?= csrf_field() ?><input type="hidden" name="do" value="add_variant"><input type="hidden" name="item_id" value="<?= $item_id ?>">
    <h2>Add a size</h2>
    <div class="inv-grid-2">
      <div class="inv-field"><label for="n_size">Size</label><input id="n_size" type="text" name="size_label" maxlength="20" required placeholder="e.g. M, 10, Custom"></div>
      <div class="inv-field"><label for="n_sku">SKU</label><input id="n_sku" type="text" name="sku" maxlength="40" placeholder="Blank = generate"></div>
      <div class="inv-field"><label for="n_price">Price (₱)</label><input id="n_price" type="text" inputmode="decimal" name="unit_price" required placeholder="e.g. 250.00"></div>
      <?php if ($tracks): ?>
        <div class="inv-field"><label for="n_low">Low-stock threshold</label><input id="n_low" type="number" name="low_stock_threshold" min="0" value="5" required></div>
        <div class="inv-field"><label for="n_open">Opening stock (optional)</label><input id="n_open" type="number" name="opening_qty" min="0" max="<?= INV_MAX_QTY ?>" placeholder="0"></div>
      <?php endif; ?>
    </div>
    <button class="inv-btn" type="submit">Add size</button>
  </form>
</section>

<section class="inv-section">
  <h2>Archive or delete</h2>
  <?php $history = inv_item_has_history($item_id); ?>
  <form method="post" action="<?= e(url('admin/item_edit.php', ['id' => $item_id])) ?>" class="inv-actions">
    <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $item_id ?>">
    <?php if ((int) $item['is_active'] === 1): ?>
      <button class="inv-btn inv-btn-light" name="do" value="archive" type="submit" onclick="return confirm('Archive this item? It will be hidden from the public catalog.')">Archive item</button>
    <?php else: ?>
      <button class="inv-btn" name="do" value="restore" type="submit">Restore item</button>
    <?php endif; ?>
    <?php if (!$history): ?>
      <button class="inv-btn inv-btn-danger" name="do" value="delete_item" type="submit" onclick="return confirm('Delete this item and its sizes permanently?')">Delete permanently</button>
    <?php endif; ?>
  </form>
  <p class="inv-hint"><?= $history
      ? 'This item has order, stock, waitlist or restock history, so it can only be archived (history is kept).'
      : 'Nothing refers to this item yet, so it can be deleted permanently.' ?></p>
</section>
<?php endif; ?>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
