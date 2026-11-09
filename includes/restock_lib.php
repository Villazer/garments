<?php
/**
 * Public restock schedule (Session 5). Depends on includes/public5_common.php.
 * Staff notes (restock_schedule.note) and expected quantities are internal and
 * are deliberately NOT selected here.
 */

/**
 * Upcoming deliveries. Returns ['dated' => [date => rows], 'unconfirmed' => rows].
 * 'unconfirmed' holds deliveries staff marked delayed, plus scheduled ones whose
 * date has already passed without an update, because those dates cannot be trusted.
 */
function rs_upcoming(): array
{
    $rows = db_all(
        "SELECT r.restock_id, r.expected_date, r.status,
                i.item_id, i.name AS item_name, i.tracks_stock,
                v.variant_id, v.size_label, v.low_stock_threshold,
                (v.quantity_on_hand - v.quantity_reserved) AS available
           FROM restock_schedule r
           LEFT JOIN item_variants v ON v.variant_id = r.variant_id
           JOIN items i ON i.item_id = COALESCE(v.item_id, r.item_id)
          WHERE r.status IN ('scheduled','delayed') AND i.is_active = 1
            AND (v.variant_id IS NULL OR v.is_active = 1)
          ORDER BY r.expected_date, i.name, v.variant_id"
    );
    $today = date('Y-m-d');
    $dated = [];
    $unconfirmed = [];
    foreach ($rows as $r) {
        if ($r['status'] === 'delayed' || $r['expected_date'] < $today) {
            $unconfirmed[] = $r;
        } else {
            $dated[$r['expected_date']][] = $r;
        }
    }
    return ['dated' => $dated, 'unconfirmed' => $unconfirmed];
}

/** Can a guest join the waitlist for this schedule row? (a specific size that is out of stock) */
function rs_can_waitlist(array $r): bool
{
    return $r['variant_id'] !== null && (int) $r['tracks_stock'] === 1 && (int) $r['available'] <= 0;
}

/** Name for the size column: a size, or "All sizes" when the delivery covers the whole item. */
function rs_size_text(array $r): string
{
    return $r['variant_id'] === null ? 'All sizes' : 'Size ' . $r['size_label'];
}

/**
 * One-line snippet for Session 3's item.php: shows the restock link on an
 * out-of-stock item. Echo it where the out-of-stock note is printed.
 */
function rs_item_link_html(int $item_id): string
{
    return '<p class="s5-restock-hint"><a href="' . e(url('restock.php', ['item' => $item_id])) . '">See when this item is expected back in stock</a></p>';
}
