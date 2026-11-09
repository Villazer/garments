<?php
/**
 * Public site header. Set these BEFORE including it:
 *   $page_title  (string)  page name, shown in the browser tab
 *   $active_nav  (string)  key from public_nav_items(), e.g. 'catalog'
 * Optional: $body_class, $meta_description, $page_css (array of asset paths).
 */
$page_title       = $page_title ?? '';
$active_nav       = $active_nav ?? '';
$body_class       = $body_class ?? '';
$meta_description = $meta_description ?? 'Check uniform and toga stock and prices, then reserve in advance.';
$page_css         = $page_css ?? [];
$full_title       = ($page_title !== '' ? $page_title . ' | ' : '') . config('app_name', 'Garment Shop');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($full_title) ?></title>
    <meta name="description" content="<?= e($meta_description) ?>">
    <script>document.documentElement.className += ' js';</script>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>" media="print">
    <?php foreach ($page_css as $css): ?>
    <link rel="stylesheet" href="<?= e(asset($css)) ?>">
    <?php endforeach; ?>
</head>
<body class="<?= e($body_class) ?>">
<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header">
    <div class="container site-header__bar">
        <a class="logo" href="<?= e(url('')) ?>">
            <span class="logo__mark" aria-hidden="true">WG</span>
            <span class="logo__text">
                <span class="logo__name"><?= e(config('app_name')) ?></span>
                <span class="logo__sub"><?= e(config('shop.school', '')) ?></span>
            </span>
        </a>
        <button type="button" class="nav-toggle" aria-expanded="false" aria-controls="site-nav">
            <span class="nav-toggle__label">Menu</span>
        </button>
        <nav class="site-nav" id="site-nav" aria-label="Main">
            <ul class="site-nav__list">
                <?php foreach (public_nav_items() as $key => [$label, $path]): ?>
                <li><a href="<?= e(url($path)) ?>"<?= $key === $active_nav ? ' class="is-active" aria-current="page"' : '' ?>><?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </div>
</header>

<main id="main" class="site-main">
<?php if (!empty($_SESSION['flash'])): ?>
    <div class="container flash-area"><?php flash_show(); ?></div>
<?php endif; ?>
