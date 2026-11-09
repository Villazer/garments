<?php
/**
 * Order tracking (Session 5): guarded lookup, timeline, next-step text, cancel.
 * Depends on includes/public5_common.php.
 */

const TRACK_GENERIC_ERROR = 'We could not find an order with those details. Check the reference number and the contact number or email you used when ordering.';
const TRACK_LOCKED_ERROR  = 'Too many unsuccessful attempts. Please wait about 15 minutes and try again.';
const TRACK_WINDOW_MIN    = 15;   // failure window
const TRACK_MAX_PER_IP    = 10;   // failed lookups per IP in the window
const TRACK_MAX_PER_REF   = 20;   // failed lookups per reference in the window (guess protection)

/** Order row for a public reference, with the customer's contact data for matching. */
function track_fetch_order(string $ref): ?array
{
    if ($ref === '') {
        return null;
    }
    // Expired orders sit in the recycle bin (deleted_at set) but customers must
    // still see "expired" instead of "not found"; every other deleted order stays hidden.
    $row = db_one(
        "SELECT o.order_id, o.order_no, o.order_ref, o.order_type, o.status, o.source,
                o.total_amount, o.remarks, o.decline_reason, o.pickup_date,
                o.created_at, o.updated_at, o.approved_at, o.declined_at, o.slip_issued_at,
                o.paid_at, o.claimed_at, o.expires_at, o.claim_deadline, o.unclaimed_flagged_at,
                TIMESTAMPDIFF(SECOND, NOW(), o.expires_at)    AS expires_in,
                TIMESTAMPDIFF(SECOND, NOW(), o.claim_deadline) AS claim_in,
                c.contact_no AS c_contact_no, c.email AS c_email,
                s.full_name AS student_name, s.student_no
           FROM orders o
           JOIN customers c ON c.customer_id = o.customer_id
           LEFT JOIN students s ON s.student_id = o.student_id
          WHERE o.order_ref = ? AND (o.deleted_at IS NULL OR o.status = 'expired')
          LIMIT 1",
        [$ref]
    );
    return $row ?: null;
}

/** Does what the guest typed match the order's contact number or email? */
function track_contact_matches(array $row, string $input): bool
{
    $input = trim($input);
    if ($input === '') {
        return false;
    }
    if (strpos($input, '@') !== false) {
        $stored = strtolower(trim((string) ($row['c_email'] ?? '')));
        return $stored !== '' && hash_equals($stored, strtolower($input));
    }
    $typed  = normalize_ph_mobile($input);
    $stored = (string) ($row['c_contact_no'] ?? '');
    $stored_norm = normalize_ph_mobile($stored) ?? $stored;
    return $typed !== null && $stored_norm !== '' && hash_equals($stored_norm, $typed);
}

/**
 * Guarded lookup. Returns ['ok'=>true,'order'=>row] or ['ok'=>false,'error'=>text].
 * Every mismatch (unknown reference, wrong contact, bad format) gives the same
 * error and counts as one failure, so references cannot be guessed or probed.
 */
function track_attempt(string $ref_raw, string $contact_raw): array
{
    $ref = s5_clean_ref($ref_raw);
    $ip_key  = s5_rate_key('ip', s5_client_ip());
    $ref_key = s5_rate_key('ref', $ref);

    $blocked = s5_rate_blocked('track_failed', [
        $ip_key  => TRACK_MAX_PER_IP,
        $ref_key => TRACK_MAX_PER_REF,
    ], TRACK_WINDOW_MIN);
    if ($blocked) {
        return ['ok' => false, 'error' => TRACK_LOCKED_ERROR, 'locked' => true];
    }

    $row = track_fetch_order($ref);
    if ($row !== null && track_contact_matches($row, $contact_raw)) {
        return ['ok' => true, 'order' => $row];
    }

    s5_rate_hit('track_failed', $ip_key);
    s5_rate_hit('track_failed', $ref_key);
    return ['ok' => false, 'error' => TRACK_GENERIC_ERROR];
}

/** Lines of an order for the items table. */
function track_items(int $order_id): array
{
    return db_all(
        'SELECT item_name, size_label, quantity, unit_price, line_total
           FROM order_items WHERE order_id = ? ORDER BY order_item_id',
        [$order_id]
    );
}

/* ------------------------------------------------------------------ */
/* Timeline, countdown, next step                                      */
/* ------------------------------------------------------------------ */

function track_steps(): array
{
    return [
        'requested'   => 'Requested',
        'approved'    => 'Approved',
        'slip_issued' => 'Slip issued',
        'paid'        => 'Paid',
        'claimed'     => 'Claimed',
    ];
}

function track_exit_labels(): array
{
    return ['declined' => 'Declined', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];
}

/** When each step happened, if known. */
function track_step_time(array $o, string $step): ?string
{
    $map = [
        'requested' => 'created_at', 'approved' => 'approved_at', 'slip_issued' => 'slip_issued_at',
        'paid' => 'paid_at', 'claimed' => 'claimed_at',
    ];
    $v = $o[$map[$step]] ?? null;
    return $v ?: null;
}

/**
 * Timeline entries: ['key','label','state'(done|current|todo|exit),'when'].
 * In-progress orders show all five steps. Exit states show only the steps that
 * really happened (from their timestamps) followed by the exit step.
 */
function track_timeline(array $o): array
{
    $steps  = track_steps();
    $status = (string) $o['status'];
    $exits  = track_exit_labels();
    $out    = [];

    if (isset($exits[$status])) {
        foreach ($steps as $key => $label) {
            $when = track_step_time($o, $key);
            if ($key === 'requested' || $when !== null) {
                $out[] = ['key' => $key, 'label' => $label, 'state' => 'done', 'when' => $when];
            }
        }
        $exit_when = ['declined' => $o['declined_at'] ?? null, 'expired' => $o['expires_at'] ?? null,
                      'cancelled' => $o['updated_at'] ?? null][$status] ?? null;
        $out[] = ['key' => $status, 'label' => $exits[$status], 'state' => 'exit', 'when' => $exit_when];
        return $out;
    }

    // 'unclaimed' = paid but past the claim period: the timeline stops at Paid.
    $current_key = ($status === 'unclaimed') ? 'paid' : $status;
    $keys = array_keys($steps);
    $current_idx = array_search($current_key, $keys, true);
    if ($current_idx === false) {
        $current_idx = 0;
    }
    foreach ($keys as $i => $key) {
        if ($status === 'claimed') {
            $state = 'done';
        } elseif ($i < $current_idx) {
            $state = 'done';
        } elseif ($i === $current_idx) {
            $state = 'current';
        } else {
            $state = 'todo';
        }
        $out[] = ['key' => $key, 'label' => $steps[$key], 'state' => $state, 'when' => track_step_time($o, $key)];
    }
    return $out;
}

/** Countdown for the unpaid hold or the claim period: ['label','seconds'] or null. */
function track_countdown(array $o): ?array
{
    $status = (string) $o['status'];
    if (in_array($status, ['approved', 'slip_issued'], true) && $o['expires_in'] !== null) {
        return ['label' => 'Reservation hold ends in', 'seconds' => (int) $o['expires_in'], 'at' => $o['expires_at']];
    }
    if ($status === 'paid' && $o['claim_in'] !== null) {
        return ['label' => 'Claim period ends in', 'seconds' => (int) $o['claim_in'], 'at' => $o['claim_deadline']];
    }
    return null;
}

/** Plain-language "what do I do now" message: ['tone','title','text']. */
function track_next_step(array $o): array
{
    $status = (string) $o['status'];
    $rental = ($o['order_type'] === 'rental');
    $until  = !empty($o['expires_at']) ? ' until ' . format_datetime($o['expires_at']) : '';
    $claim_by = !empty($o['claim_deadline']) ? ' by ' . format_datetime($o['claim_deadline']) : '';
    $terms = $rental ? ' The toga deposit, rental fee details and return rules are confirmed by the Garment Shop.' : '';

    switch ($status) {
        case 'requested':
            return ['tone' => 'info', 'title' => 'Waiting for the shop to review your request',
                'text' => 'Staff will check your request. No stock is held for you yet. Visit this page again later to see the result.' . $terms];
        case 'approved':
            if (!empty($o['expires_at']) && (int) $o['expires_in'] <= 0) {
                return ['tone' => 'warning', 'title' => 'Your reservation hold has ended',
                    'text' => 'The unpaid hold is over and your items will be released shortly. If you still want them, place a new request or visit the Garment Shop right away.'];
            }
            return ['tone' => 'success', 'title' => 'Your items are reserved',
                'text' => 'Stock is being held for you' . $until . '. The Garment Shop will prepare your Order Slip. Once you have it, pay at the cashier and keep the official receipt. Unpaid reservations are released automatically when the hold ends.' . $terms];
        case 'slip_issued':
            $pickup = !empty($o['pickup_date']) ? ' Scheduled pickup date: ' . format_date($o['pickup_date'], 'F j, Y') . '.' : '';
            return ['tone' => 'info', 'title' => 'Pay at the cashier',
                'text' => 'Take your Order Slip to the cashier, which is beside the Garment Shop, and pay. Then return to the shop with your proof of payment (official receipt) to claim your items.' . $pickup . ' The reservation is held' . ($until !== '' ? $until : ' for a limited time') . '.' . $terms];
        case 'paid':
            return ['tone' => 'success', 'title' => 'Ready to claim',
                'text' => 'Your payment is recorded. Bring your proof of payment to the Garment Shop to claim your items' . $claim_by . '.' . $terms];
        case 'claimed':
            return ['tone' => 'success', 'title' => 'Order completed',
                'text' => 'Your items were claimed on ' . format_datetime($o['claimed_at']) . '. Thank you!'
                    . ($rental ? ' Please return the toga on time. Return date, deposit and penalty rules are confirmed by the Garment Shop.' : '')];
        case 'declined':
            $why = trim((string) ($o['decline_reason'] ?? ''));
            return ['tone' => 'error', 'title' => 'Your request was declined',
                'text' => ($why !== '' ? 'Reason: ' . $why . '. ' : '') . 'You can place a new request from the catalog or visit the Garment Shop for help.'];
        case 'expired':
            return ['tone' => 'warning', 'title' => 'Your reservation expired',
                'text' => 'The hold ended before a payment was recorded, so the stock was released to other students. You can place a new request from the catalog.'];
        case 'cancelled':
            return ['tone' => 'warning', 'title' => 'This order was cancelled',
                'text' => 'Any reserved stock has been released. You can place a new request from the catalog at any time.'];
        case 'unclaimed':
            return ['tone' => 'warning', 'title' => 'Paid but not yet claimed',
                'text' => 'Your payment was recorded but the claim period has passed. Please visit the Garment Shop as soon as possible so staff can check what can still be released to you.'];
    }
    return ['tone' => 'info', 'title' => 'Status update', 'text' => 'Please visit the Garment Shop for details about this order.'];
}

/* ------------------------------------------------------------------ */
/* Customer cancel                                                     */
/* ------------------------------------------------------------------ */

function track_can_cancel(array $o): bool
{
    return in_array((string) $o['status'], ['requested', 'approved'], true);
}

/**
 * Cancel an order for the customer. Mirrors sp_release_order but also covers a
 * 'requested' order, and re-checks the status under a row lock so a staff action
 * at the same moment cannot be overridden. Returns ['ok'=>bool,'message'=>text].
 *
 * Lock order: orders row, then variants in ascending id (same as sp_approve_order).
 */
function track_cancel_order(int $order_id): array
{
    return s5_transaction(function () use ($order_id) {
        $o = db_one('SELECT status FROM orders WHERE order_id = ? FOR UPDATE', [$order_id]);
        if (!$o || !in_array($o['status'], ['requested', 'approved'], true)) {
            return ['ok' => false, 'message' => 'This order can no longer be cancelled online. Please visit the Garment Shop if you need help.'];
        }

        // Only lines that really hold stock are released (requested orders hold none).
        $held = db_all(
            'SELECT variant_id, SUM(quantity) AS qty FROM order_items
              WHERE order_id = ? AND stock_held = 1 GROUP BY variant_id ORDER BY variant_id',
            [$order_id]
        );
        foreach ($held as $h) {
            $vid = (int) $h['variant_id'];
            $qty = (int) $h['qty'];
            db_one('SELECT variant_id FROM item_variants WHERE variant_id = ? FOR UPDATE', [$vid]);
            $n = s5_exec(
                'UPDATE item_variants SET quantity_reserved = quantity_reserved - ?
                  WHERE variant_id = ? AND quantity_reserved >= ?',
                [$qty, $vid, $qty]
            );
            if ($n !== 1) {
                throw new RuntimeException('Reserved stock is out of step for variant ' . $vid);
            }
            s5_exec(
                "INSERT INTO inventory_movements (variant_id, movement_type, on_hand_delta, reserved_delta, order_id, user_id, reason)
                 VALUES (?, 'released', 0, ?, ?, NULL, 'Cancelled by customer')",
                [$vid, -$qty, $order_id]
            );
        }

        s5_exec('UPDATE order_items SET stock_held = 0 WHERE order_id = ?', [$order_id]);
        // Frees the one-active-rental slot for the student.
        s5_exec(
            "UPDATE rentals r JOIN order_items oi ON oi.order_item_id = r.order_item_id
                SET r.status = 'cancelled'
              WHERE oi.order_id = ? AND r.status = 'reserved'",
            [$order_id]
        );
        s5_exec("UPDATE orders SET status = 'cancelled' WHERE order_id = ?", [$order_id]);
        s5_exec(
            "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
             VALUES (NULL, 'order_cancelled_by_customer', 'orders', ?, ?, '{\"status\":\"cancelled\"}', ?)",
            [(string) $order_id, json_encode(['status' => $o['status']]), substr(s5_client_ip(), 0, 45)]
        );
        return ['ok' => true, 'message' => 'Your order was cancelled and any reserved stock was released.'];
    });
}
