<?php
/**
 * Waitlist (Session 5). Depends on includes/public5_common.php.
 * Staff manage the list in Session 7; this file only lets guests join.
 */

const WL_IP_LIMIT = 8;   // joins per IP per hour

/** One variant with its item and availability, or null when unknown/inactive. */
function wl_find_variant(int $variant_id): ?array
{
    $row = db_one(
        "SELECT v.variant_id, v.size_label, v.low_stock_threshold,
                (v.quantity_on_hand - v.quantity_reserved) AS available,
                i.item_id, i.name AS item_name, i.item_type, i.tracks_stock, i.max_qty_per_request
           FROM item_variants v JOIN items i ON i.item_id = v.item_id
          WHERE v.variant_id = ? AND v.is_active = 1 AND i.is_active = 1",
        [$variant_id]
    );
    return $row ?: null;
}

/** Can guests wait for this variant? Only stock-tracked sizes that are out of stock. */
function wl_is_joinable(array $v): bool
{
    return (int) $v['tracks_stock'] === 1 && (int) $v['available'] <= 0;
}

/** Out-of-stock sizes for the picker page (waitlist.php without ?variant=). */
function wl_out_of_stock_list(): array
{
    return db_all(
        "SELECT v.variant_id, v.size_label, i.item_id, i.name AS item_name
           FROM item_variants v JOIN items i ON i.item_id = v.item_id
          WHERE v.is_active = 1 AND i.is_active = 1 AND i.tracks_stock = 1
            AND (v.quantity_on_hand - v.quantity_reserved) <= 0
          ORDER BY i.name, v.variant_id"
    );
}

/** Highest quantity a guest may ask for on the waitlist (the item's online cap, at most 10). */
function wl_max_quantity(array $v): int
{
    return max(1, min(10, (int) $v['max_qty_per_request']));
}

/** Validate the form. Returns [clean values, errors by field]. */
function wl_validate(array $post, array $variant): array
{
    $c = [
        'contact_name' => clean_str($post['contact_name'] ?? ''),
        'contact_no'   => clean_str($post['contact_no'] ?? ''),
        'email'        => clean_str($post['email'] ?? ''),
        'quantity'     => (int) ($post['quantity'] ?? 1),
    ];
    $err = [];
    $len = function_exists('mb_strlen') ? mb_strlen($c['contact_name']) : strlen($c['contact_name']);
    if ($len < 2 || $len > 120) {
        $err['contact_name'] = 'Enter your full name (2 to 120 characters).';
    }
    $mobile = normalize_ph_mobile($c['contact_no']);
    if ($mobile === null) {
        $err['contact_no'] = 'Enter a valid mobile number, for example 09171234567.';
    } else {
        $c['contact_no'] = $mobile;
    }
    if ($c['email'] !== '' && (strlen($c['email']) > 120 || !filter_var($c['email'], FILTER_VALIDATE_EMAIL))) {
        $err['email'] = 'Enter a valid email address or leave it blank.';
    }
    $max = wl_max_quantity($variant);
    if (!s5_waitlist_has_quantity()) {
        $c['quantity'] = 1; // column not migrated yet: the form hides the field
    } elseif ($c['quantity'] < 1 || $c['quantity'] > $max) {
        $err['quantity'] = 'Enter a quantity from 1 to ' . $max . '.';
    }
    return [$c, $err];
}

/**
 * Add the guest to the waitlist, once per person and size.
 * "Same person" = same contact number, or same email when one was given, among
 * entries not yet notified. A repeat submit updates the quantity instead of adding a row.
 * Returns ['ok'=>true,'duplicate'=>bool] or ['ok'=>false,'error','kind'].
 */
function wl_join(int $variant_id, array $c): array
{
    $ip_key = s5_rate_key('ip', s5_client_ip());
    if (s5_rate_count('waitlist_join', $ip_key, 60) >= WL_IP_LIMIT) {
        return ['ok' => false, 'kind' => 'rate', 'error' => 'Too many waitlist requests were sent from this device. Please try again later or visit the Garment Shop.'];
    }

    $result = s5_transaction(function () use ($variant_id, $c) {
        // Locking the variant serialises joins for the same size, so the
        // check-then-insert below cannot create two rows for one person.
        $v = db_one(
            'SELECT v.variant_id, (v.quantity_on_hand - v.quantity_reserved) AS available, i.tracks_stock, i.is_active AS item_active, v.is_active
               FROM item_variants v JOIN items i ON i.item_id = v.item_id
              WHERE v.variant_id = ? FOR UPDATE',
            [$variant_id]
        );
        if (!$v || !(int) $v['is_active'] || !(int) $v['item_active'] || (int) $v['tracks_stock'] !== 1) {
            return ['ok' => false, 'kind' => 'gone', 'error' => 'That size is not available for the waitlist.'];
        }
        if ((int) $v['available'] > 0) {
            return ['ok' => false, 'kind' => 'in_stock', 'error' => 'Good news: this size is in stock again, so there is no need to wait. You can reserve it from the catalog.'];
        }

        $has_q = s5_waitlist_has_quantity();
        $email = $c['email'] !== '' ? $c['email'] : null;
        $existing = db_one(
            'SELECT waitlist_id FROM waitlist
              WHERE variant_id = ? AND notified_at IS NULL
                AND (contact_no = ? OR (? IS NOT NULL AND email = ?))
              ORDER BY waitlist_id LIMIT 1 FOR UPDATE',
            [$variant_id, $c['contact_no'], $email, $email]
        );
        if ($existing) {
            if ($has_q) {
                s5_exec('UPDATE waitlist SET quantity_wanted = ? WHERE waitlist_id = ?', [$c['quantity'], $existing['waitlist_id']]);
            }
            if ($email !== null) {
                s5_exec("UPDATE waitlist SET email = ? WHERE waitlist_id = ? AND (email IS NULL OR email = '')", [$email, $existing['waitlist_id']]);
            }
            return ['ok' => true, 'duplicate' => true];
        }

        if ($has_q) {
            s5_exec(
                'INSERT INTO waitlist (variant_id, contact_name, contact_no, email, quantity_wanted) VALUES (?, ?, ?, ?, ?)',
                [$variant_id, $c['contact_name'], $c['contact_no'], $email, $c['quantity']]
            );
        } else {
            s5_exec(
                'INSERT INTO waitlist (variant_id, contact_name, contact_no, email) VALUES (?, ?, ?, ?)',
                [$variant_id, $c['contact_name'], $c['contact_no'], $email]
            );
        }
        return ['ok' => true, 'duplicate' => false];
    });

    if (!empty($result['ok'])) {
        s5_rate_hit('waitlist_join', $ip_key);
    }
    return $result;
}
