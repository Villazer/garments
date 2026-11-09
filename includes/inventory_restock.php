<?php
/**
 * Session 7: restock schedule and waitlist management.
 * Loaded by inventory.php. Requires migration 005.
 *
 * restock_schedule.status: scheduled / delayed = upcoming,
 * delivered = received, cancelled = called off.
 */

/* ------------------------------------------------------------------ */
/* Restock schedule                                                    */
/* ------------------------------------------------------------------ */

function inv_restock_tabs(): array
{
    return ['upcoming' => 'Upcoming', 'done' => 'Received', 'cancelled' => 'Cancelled'];
}

/** Items that can be restocked (stocked, active). */
function inv_restock_items(): array
{
    return inv_all('SELECT item_id, item_code, name FROM items WHERE tracks_stock = 1 AND is_active = 1 ORDER BY name');
}

function inv_restock_rows(string $tab): array
{
    $where = ['upcoming' => "r.status IN ('scheduled','delayed')", 'done' => "r.status = 'delivered'", 'cancelled' => "r.status = 'cancelled'"];
    $cond  = $where[$tab] ?? $where['upcoming'];
    $order = $tab === 'upcoming' ? 'r.expected_date, r.restock_id' : 'r.updated_at DESC, r.restock_id DESC';
    return inv_all(
        "SELECT r.restock_id, r.variant_id, r.item_id, r.expected_date, r.expected_qty, r.note, r.status, r.updated_at,
                i.item_id AS the_item_id, i.name AS item_name, v.size_label,
                (SELECT COUNT(*) FROM waitlist w WHERE w.variant_id = r.variant_id AND w.status = 'waiting') AS waiting
           FROM restock_schedule r
           LEFT JOIN item_variants v ON v.variant_id = r.variant_id
           LEFT JOIN items i ON i.item_id = COALESCE(r.item_id, v.item_id)
          WHERE $cond
          ORDER BY $order
          LIMIT 200"
    );
}

function inv_restock_find(int $id): ?array
{
    return inv_one(
        'SELECT r.*, i.item_id AS the_item_id, i.name AS item_name, i.tracks_stock, v.size_label
           FROM restock_schedule r
           LEFT JOIN item_variants v ON v.variant_id = r.variant_id
           LEFT JOIN items i ON i.item_id = COALESCE(r.item_id, v.item_id)
          WHERE r.restock_id = ?',
        [$id]
    );
}

function inv_restock_open(array $row): bool
{
    return in_array($row['status'], ['scheduled', 'delayed'], true);
}

/** Sizes of one item that can receive stock. */
function inv_restock_sizes(int $item_id): array
{
    return inv_all('SELECT variant_id, size_label, quantity_on_hand, quantity_reserved FROM item_variants WHERE item_id = ? AND is_active = 1 ORDER BY variant_id', [$item_id]);
}

function inv_restock_label(array $row): string
{
    return (string) ($row['item_name'] ?? 'Unknown item') . ' - ' . (!empty($row['size_label']) ? 'size ' . $row['size_label'] : 'all sizes');
}

/** Optional expected quantity; '' means unknown. Returns [value|null, error|null]. */
function inv_restock_parse_qty($raw): array
{
    $s = trim((string) $raw);
    if ($s === '') {
        return [null, null];
    }
    $n = inv_int($s, 1, INV_MAX_QTY);
    return $n === null ? [null, 'Quantity must be a whole number from 1 to ' . INV_MAX_QTY . '.'] : [$n, null];
}

/** Add entries for one item: one row per size with a quantity, and/or one whole-item row. */
function inv_restock_create(int $item_id, array $post, int $user_id): array
{
    $errors = [];
    $item = inv_one('SELECT item_id, name FROM items WHERE item_id = ? AND tracks_stock = 1 AND is_active = 1', [$item_id]);
    if (!$item) {
        return ['ok' => false, 'errors' => ['item' => 'Choose an active, stocked item.']];
    }

    $date = inv_parse_date($post['expected_date'] ?? '');
    if ($date === null) {
        $errors['expected_date'] = 'Enter the expected delivery date.';
    } elseif ($date < date('Y-m-d')) {
        $errors['expected_date'] = 'The expected date cannot be in the past.';
    }
    $note = inv_clean($post['note'] ?? '');
    if (inv_strlen($note) > 255) {
        $errors['note'] = 'Note can be at most 255 characters.';
    }

    $entries = [];
    $valid_sizes = array_column(inv_restock_sizes($item_id), 'variant_id');
    $size_qty = is_array($post['size_qty'] ?? null) ? $post['size_qty'] : [];
    foreach ($valid_sizes as $vid) {
        [$qty, $err] = inv_restock_parse_qty($size_qty[$vid] ?? '');
        if ($err) {
            $errors['size_' . $vid] = $err;
        } elseif ($qty !== null) {
            $entries[] = [(int) $vid, null, $qty];
        }
    }
    if (inv_post_checkbox($post, 'whole_item')) {
        [$qty, $err] = inv_restock_parse_qty($post['whole_qty'] ?? '');
        if ($err) {
            $errors['whole_qty'] = $err;
        } else {
            $entries[] = [null, $item_id, $qty];
        }
    }
    if (!$entries && !$errors) {
        $errors['entries'] = 'Enter a quantity for at least one size, or tick "whole item".';
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    inv_tx(function () use ($entries, $date, $note, $user_id) {
        foreach ($entries as [$vid, $iid, $qty]) {
            $id = inv_insert(
                "INSERT INTO restock_schedule (variant_id, item_id, expected_date, expected_qty, note, status, created_by)
                 VALUES (?, ?, ?, ?, ?, 'scheduled', ?)",
                [$vid, $iid, $date, $qty, $note === '' ? null : $note, $user_id]
            );
            inv_audit($user_id, 'restock_scheduled', 'restock_schedule', $id, null,
                ['variant_id' => $vid, 'item_id' => $iid, 'expected_date' => $date, 'expected_qty' => $qty]);
        }
    });
    return ['ok' => true, 'count' => count($entries)];
}

/** Edit date, quantity, note and scheduled/delayed status of an open entry. */
function inv_restock_update(int $id, array $post, int $user_id): array
{
    $row = inv_restock_find($id);
    if (!$row || !inv_restock_open($row)) {
        return ['ok' => false, 'errors' => ['general' => 'Only upcoming entries can be edited.']];
    }
    $errors = [];
    $date = inv_parse_date($post['expected_date'] ?? '');
    if ($date === null) {
        $errors['expected_date'] = 'Enter a valid date.';
    }
    [$qty, $qerr] = inv_restock_parse_qty($post['expected_qty'] ?? '');
    if ($qerr) {
        $errors['expected_qty'] = $qerr;
    }
    $note = inv_clean($post['note'] ?? '');
    if (inv_strlen($note) > 255) {
        $errors['note'] = 'Note can be at most 255 characters.';
    }
    $status = (string) ($post['status'] ?? 'scheduled');
    if (!in_array($status, ['scheduled', 'delayed'], true)) {
        $errors['status'] = 'Status must be scheduled or delayed.';
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    inv_q(
        "UPDATE restock_schedule SET expected_date = ?, expected_qty = ?, note = ?, status = ?
          WHERE restock_id = ? AND status IN ('scheduled','delayed')",
        [$date, $qty, $note === '' ? null : $note, $status, $id]
    );
    inv_audit($user_id, 'restock_updated', 'restock_schedule', $id,
        ['expected_date' => $row['expected_date'], 'expected_qty' => $row['expected_qty'], 'status' => $row['status']],
        ['expected_date' => $date, 'expected_qty' => $qty, 'status' => $status]);
    return ['ok' => true];
}

function inv_restock_cancel(int $id, int $user_id): array
{
    $n = inv_exec("UPDATE restock_schedule SET status = 'cancelled' WHERE restock_id = ? AND status IN ('scheduled','delayed')", [$id]);
    if ($n < 1) {
        return ['ok' => false, 'message' => 'Only upcoming entries can be cancelled.'];
    }
    inv_audit($user_id, 'restock_cancelled', 'restock_schedule', $id, null, ['status' => 'cancelled']);
    return ['ok' => true, 'message' => 'Restock entry cancelled. It no longer shows on the public schedule.'];
}

/**
 * Mark an entry received. With $apply the quantities in $qty_by_variant
 * [variant_id => qty] are added to stock (each with a movement row) in the SAME
 * transaction that marks the entry delivered; without it only the status changes.
 */
function inv_restock_receive(int $id, array $qty_by_variant, bool $apply, int $user_id): array
{
    $touched = [];
    $units = 0;
    try {
        inv_tx(function () use ($id, $qty_by_variant, $apply, $user_id, &$touched, &$units) {
            // Lock the entry so a double click cannot apply the same delivery twice.
            $row = inv_one('SELECT * FROM restock_schedule WHERE restock_id = ? FOR UPDATE', [$id]);
            if (!$row || !inv_restock_open($row)) {
                throw new DomainException('This entry was already received or cancelled.');
            }
            if ($apply) {
                $item_id = $row['variant_id'] !== null
                    ? (int) inv_value('SELECT item_id FROM item_variants WHERE variant_id = ?', [(int) $row['variant_id']])
                    : (int) $row['item_id'];
                $allowed = array_column(inv_restock_sizes($item_id), 'variant_id');
                $lines = [];
                foreach ($qty_by_variant as $vid => $qty) {
                    $vid = (int) $vid;
                    if ($qty === null || $qty === '' || (int) $qty === 0) {
                        continue;
                    }
                    if (!in_array($vid, array_map('intval', $allowed), true)
                        || ($row['variant_id'] !== null && $vid !== (int) $row['variant_id'])) {
                        throw new DomainException('A quantity was entered for a size that does not belong to this entry.');
                    }
                    $lines[$vid] = (int) $qty;
                }
                if (!$lines) {
                    throw new DomainException('Enter the received quantity for at least one size.');
                }
                ksort($lines); // fixed lock order avoids deadlocks between concurrent receives
                $reason = 'Restock #' . $id . ($row['note'] ? ': ' . inv_substr((string) $row['note'], 100) : '');
                foreach ($lines as $vid => $qty) {
                    inv_adjust_stock_tx($vid, 'receive', $qty, $reason, $user_id);
                    $touched[] = $vid;
                    $units += $qty;
                }
            }
            inv_q("UPDATE restock_schedule SET status = 'delivered' WHERE restock_id = ?", [$id]);
            inv_audit($user_id, $apply ? 'restock_received' : 'restock_marked_delivered', 'restock_schedule', $id,
                ['status' => $row['status']], ['status' => 'delivered', 'units_applied' => $units]);
        });
    } catch (DomainException $ex) {
        return ['ok' => false, 'message' => $ex->getMessage()];
    } catch (PDOException $ex) {
        error_log('inv_restock_receive: ' . $ex->getMessage());
        return ['ok' => false, 'message' => 'The delivery could not be saved. Nothing was changed. Please try again.'];
    }
    foreach ($touched as $vid) {
        inv_notice_waitlist($vid);
    }
    return ['ok' => true, 'message' => $apply
        ? 'Restock received: ' . $units . ' unit(s) added to stock and the entry is marked delivered.'
        : 'Entry marked delivered. Stock was not changed.'];
}

/* ------------------------------------------------------------------ */
/* Waitlist                                                            */
/* ------------------------------------------------------------------ */

function inv_waitlist_statuses(): array
{
    return ['waiting' => 'Waiting', 'contacted' => 'Contacted', 'fulfilled' => 'Fulfilled', 'cancelled' => 'Cancelled'];
}

/** Allowed moves. Reopening is allowed so a mis-click can be undone. */
function inv_waitlist_moves(): array
{
    return [
        'waiting'   => ['contacted', 'fulfilled', 'cancelled'],
        'contacted' => ['fulfilled', 'cancelled', 'waiting'],
        'fulfilled' => ['waiting'],
        'cancelled' => ['waiting'],
    ];
}

function inv_waitlist_filters(array $get): array
{
    $status = (string) ($get['status'] ?? 'open');
    return [
        'status'  => in_array($status, ['open', 'waiting', 'contacted', 'fulfilled', 'cancelled', 'all'], true) ? $status : 'open',
        'variant' => (int) ($get['variant'] ?? 0),
        'item'    => (int) ($get['item'] ?? 0),
        'q'       => inv_substr(inv_clean($get['q'] ?? ''), 60),
    ];
}

const INV_WAITLIST_LIMIT = 300;

function inv_waitlist_rows(array $f): array
{
    $w = [];
    $p = [];
    if ($f['status'] === 'open') {
        $w[] = "w.status IN ('waiting','contacted')";
    } elseif ($f['status'] !== 'all') {
        $w[] = 'w.status = ?';
        $p[] = $f['status'];
    }
    if ($f['variant'] > 0) {
        $w[] = 'w.variant_id = ?';
        $p[] = $f['variant'];
    }
    if ($f['item'] > 0) {
        $w[] = 'v.item_id = ?';
        $p[] = $f['item'];
    }
    if ($f['q'] !== '') {
        $like = '%' . inv_like($f['q']) . '%';
        $w[] = '(w.contact_name LIKE ? OR w.contact_no LIKE ? OR w.email LIKE ?)';
        array_push($p, $like, $like, $like);
    }
    return inv_all(
        'SELECT w.*, v.size_label, v.item_id, v.quantity_on_hand, v.quantity_reserved,
                (v.quantity_on_hand - v.quantity_reserved) AS available,
                i.name AS item_name, i.tracks_stock,
                cu.full_name AS contacted_by_name
           FROM waitlist w
           JOIN item_variants v ON v.variant_id = w.variant_id
           JOIN items i ON i.item_id = v.item_id
           LEFT JOIN users cu ON cu.user_id = w.contacted_by
          ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . '
          ORDER BY i.name, w.variant_id, w.created_at, w.waitlist_id
          LIMIT ' . INV_WAITLIST_LIMIT,
        $p
    );
}

function inv_waitlist_counts(): array
{
    $counts = array_fill_keys(array_keys(inv_waitlist_statuses()), 0);
    foreach (inv_all('SELECT status, COUNT(*) AS n FROM waitlist GROUP BY status') as $r) {
        $counts[$r['status']] = (int) $r['n'];
    }
    return $counts;
}

/** Waitlisted sizes for the filter drop-down. */
function inv_waitlist_variant_options(): array
{
    return inv_all(
        "SELECT v.variant_id, v.size_label, i.name AS item_name, COUNT(*) AS n
           FROM waitlist w JOIN item_variants v ON v.variant_id = w.variant_id JOIN items i ON i.item_id = v.item_id
          GROUP BY v.variant_id, v.size_label, i.name ORDER BY i.name, v.variant_id"
    );
}

function inv_waitlist_change(int $id, string $to, string $note, int $user_id): array
{
    $note = inv_clean($note);
    if (inv_strlen($note) > 255) {
        return ['ok' => false, 'message' => 'The note can be at most 255 characters.'];
    }
    try {
        $from = null;
        inv_tx(function () use ($id, $to, $note, $user_id, &$from) {
            $row = inv_one('SELECT waitlist_id, status, staff_note FROM waitlist WHERE waitlist_id = ? FOR UPDATE', [$id]);
            if (!$row) {
                throw new DomainException('Waitlist entry not found.');
            }
            $from = $row['status'];
            if (!in_array($to, inv_waitlist_moves()[$from] ?? [], true)) {
                throw new DomainException('That entry is already "' . $from . '"; it cannot be changed to "' . $to . '".');
            }
            $keep_note = $note === '' ? $row['staff_note'] : $note;
            if ($to === 'contacted') {
                inv_q("UPDATE waitlist SET status = 'contacted', notified_at = NOW(), contacted_by = ?, staff_note = ? WHERE waitlist_id = ?", [$user_id, $keep_note, $id]);
            } elseif ($to === 'waiting') {
                inv_q("UPDATE waitlist SET status = 'waiting', notified_at = NULL, contacted_by = NULL, closed_at = NULL, closed_by = NULL, staff_note = ? WHERE waitlist_id = ?", [$keep_note, $id]);
            } else {
                inv_q('UPDATE waitlist SET status = ?, closed_at = NOW(), closed_by = ?, staff_note = ? WHERE waitlist_id = ?', [$to, $user_id, $keep_note, $id]);
            }
            inv_audit($user_id, 'waitlist_status_changed', 'waitlist', $id, ['status' => $from], ['status' => $to]);
        });
    } catch (DomainException $ex) {
        return ['ok' => false, 'message' => $ex->getMessage()];
    }
    return ['ok' => true, 'message' => 'Waitlist entry marked ' . $to . '.'];
}
