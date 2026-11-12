<?php if (!function_exists('shop_info')) { require_once __DIR__ . '/shop_settings.php'; } ?>
</main>

<footer class="site-footer">
    <div class="container site-footer__grid">
        <div>
            <p class="site-footer__title"><?= e(shop_info('name')) ?></p>
            <p><?= e(config('shop.office')) ?><br><?= e(config('shop.school')) ?></p>
        </div>
        <div>
            <p class="site-footer__title">Visit us</p>
            <p><?= e(shop_info('address')) ?><br><?= e(shop_info('hours')) ?></p>
        </div>
        <div>
            <p class="site-footer__title">Contact</p>
            <p><?= e(shop_info('phone')) ?><br><?= e(shop_info('email')) ?></p>
        </div>
    </div>
    <div class="container site-footer__base">
        <p>Payment is made at the cashier, not at the garment shop. Bring your order slip and proof of payment to claim your items.</p>
        <p>&copy; <?= date('Y') ?> <?= e(config('shop.school')) ?>. <a href="<?= e(url('admin/login.php')) ?>">Staff sign in</a></p>
    </div>
</footer>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
