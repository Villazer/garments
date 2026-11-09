<?php
/**
 * Staff panel header (top bar + role-aware sidebar). Opens <main>.
 * Set before including:
 *   $page_title    string  shown in <title> and as the page heading
 *   $nav_active    string  nav key to highlight (see staff_nav_items())
 *   $staff_layout  'panel' (default, requires sign-in) or 'bare' (login, 404 when signed out)
 */
$staff_layout = $staff_layout ?? 'panel';
$page_title   = $page_title ?? 'Staff panel';
$nav_active   = $nav_active ?? '';

// Runs before any output, so an anonymous visitor is redirected cleanly.
$staff_user = ($staff_layout === 'panel') ? require_login() : current_user();

if (!headers_sent()) {
    header('Cache-Control: no-store');           // signed-in pages must not sit in a shared cache
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
$main_css = (string)config('ui.main_css', 'css/app.css');   // Session 1 stylesheet (change the key if yours differs)
?>
<!DOCTYPE html>
<html lang="en" class="sp-root">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($page_title) ?> · Staff · WMSU Garment Shop</title>
  <link rel="stylesheet" href="<?= e(asset($main_css)) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/staff.css')) ?>">
  <script>document.documentElement.className += ' sp-js';</script>
</head>
<?php if ($staff_layout === 'panel' && $staff_user): ?>
<body class="sp-body">
  <a class="sp-skip" href="#sp-main">Skip to content</a>

  <header class="sp-topbar">
    <button type="button" class="sp-menu-btn" aria-controls="sp-sidebar" aria-expanded="false">Menu</button>
    <a class="sp-brand" href="<?= e(url('admin/index.php')) ?>">WMSU Garment Shop <span>Staff panel</span></a>
    <div class="sp-user">
      <span class="sp-user-name"><?= e($staff_user['full_name']) ?></span>
      <?= role_badge($staff_user['role']) ?>
      <a class="sp-link" href="<?= e(url('admin/change_password.php')) ?>">Change password</a>
      <form method="post" action="<?= e(url('admin/logout.php')) ?>" class="sp-inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="sp-btn sp-btn-small sp-btn-secondary">Sign out</button>
      </form>
    </div>
  </header>

  <div class="sp-shell">
    <nav id="sp-sidebar" class="sp-sidebar" aria-label="Staff navigation">
      <?php $sp_group = null; ?>
      <?php foreach (staff_nav_visible() as $item): ?>
        <?php if ($item['group'] !== $sp_group): $sp_group = $item['group']; ?>
          <p class="sp-nav-group"><?= e($sp_group) ?></p>
        <?php endif; ?>
        <a class="sp-nav-link<?= $item['key'] === $nav_active ? ' is-active' : '' ?>"
           href="<?= e($item['href']) ?>"<?= $item['key'] === $nav_active ? ' aria-current="page"' : '' ?>>
          <?= e($item['label']) ?>
          <?php if (!$item['ready']): ?><span class="sp-soon">soon</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <main id="sp-main" class="sp-main" tabindex="-1">
      <h1 class="sp-title"><?= e($page_title) ?></h1>
      <div class="sp-flash"><?php flash_show(); ?></div>
<?php else: ?>
<body class="sp-body sp-bare">
  <main id="sp-main" class="sp-bare-main">
    <div class="sp-flash"><?php flash_show(); ?></div>
<?php endif; ?>
