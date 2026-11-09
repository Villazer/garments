<?php
/** Closes what staff_header.php opened. */
$staff_layout = $staff_layout ?? 'panel';
?>
<?php if ($staff_layout === 'panel'): ?>
    </main>
  </div>
<?php else: ?>
  </main>
<?php endif; ?>
  <script src="<?= e(asset('js/staff.js')) ?>" defer></script>
</body>
</html>
