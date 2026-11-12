<?php
/**
 * Session 9: reports (prefix rep_). Read-only aggregate queries.
 * Every report returns the same shape so one renderer serves the screen, the
 * print view and the CSV export:
 *   ['title', 'subtitle', 'sections' => [['title', 'columns' => [[label, type], ...],
 *                                          'rows' => [[...]], 'foot' => [...]|null, 'note' => string], ...]]
 * Column types: text, int, money.
 */

require_once __DIR__ . '/orders.php';

function rep_types(): array
{
    return [
        'orders'    => 'Orders by period and status',
        'claimed'   => 'Claimed items by size',
        'sizes'     => 'Most requested sizes',
        'rentals'   => 'Toga rentals per period',
        'movements' => 'Stock movement summary',
    ];
}

/** Which filters apply to which report (the form hides the others). */
function rep_uses_period(string $type): bool
{
    return in_array($type, ['orders', 'claimed', 'sizes', 'rentals'], true);
}

function rep_filters(array $get): array
{
    $type = (string) ($get['type'] ?? 'orders');
    if (!isset(rep_types()[$type])) {
        $type = 'orders';
    }
    $from = ord_parse_date($get['from'] ?? '') ?? '';
    $to = ord_parse_date($get['to'] ?? '') ?? '';
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];
    }
    return ['type' => $type, 'from' => $from, 'to' => $to, 'period' => max(0, (int) ($get['period'] ?? 0))];
}

function rep_filter_query(array $f): array
{
    $q = ['type' => $f['type']];
    foreach (['from', 'to'] as $k) {
        if ($f[$k] !== '') {
            $q[$k] = $f[$k];
        }
    }
    if ($f['period'] > 0 && rep_uses_period($f['type'])) {
        $q['period'] = $f['period'];
    }
    return $q;
}

function rep_periods(): array
{
    return db_all('SELECT academic_period_id, school_year, semester FROM academic_periods ORDER BY start_date DESC, academic_period_id DESC');
}

function rep_period_label(array $p): string
{
    $sem = ['1st' => '1st sem', '2nd' => '2nd sem', 'summer' => 'Summer'];
    return $p['school_year'] . ' ' . ($sem[$p['semester']] ?? $p['semester']);
}

/** Adds an inclusive date range on $col to the WHERE parts. */
function rep_range(string $col, array $f, array &$w, array &$p): void
{
    if ($f['from'] !== '') {
        $w[] = "$col >= ?";
        $p[] = $f['from'] . ' 00:00:00';
    }
    if ($f['to'] !== '') {
        $w[] = "$col < ?";
        $p[] = (new DateTime($f['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }
}

function rep_filter_text(array $f): string
{
    $parts = [];
    if ($f['from'] !== '' || $f['to'] !== '') {
        $parts[] = 'Dates: ' . ($f['from'] !== '' ? format_date($f['from']) : 'start') . ' to ' . ($f['to'] !== '' ? format_date($f['to']) : 'today');
    } else {
        $parts[] = 'Dates: all';
    }
    if ($f['period'] > 0 && rep_uses_period($f['type'])) {
        foreach (rep_periods() as $p) {
            if ((int) $p['academic_period_id'] === $f['period']) {
                $parts[] = 'Academic period: ' . rep_period_label($p);
            }
        }
    }
    return implode(' · ', $parts);
}

/** Orders grouped by academic period (rows) and status (columns). */
function rep_orders_pivot(array $f, string $extra_where = ''): array
{
    $w = ['1=1'];
    $p = [];
    if ($extra_where !== '') {
        $w[] = $extra_where;   // trusted constant from this file
    }
    rep_range('o.created_at', $f, $w, $p);
    if ($f['period'] > 0) {
        $w[] = 'o.academic_period_id = ?';
        $p[] = $f['period'];
    }
    $data = db_all(
        "SELECT p.academic_period_id AS pid, p.school_year, p.semester, o.status, COUNT(*) AS n,
                COALESCE(SUM(o.total_amount), 0) AS amt
           FROM orders o LEFT JOIN academic_periods p ON p.academic_period_id = o.academic_period_id
          WHERE " . implode(' AND ', $w) . '
          GROUP BY p.academic_period_id, p.school_year, p.semester, o.status',
        $p
    );
    $statuses = ord_statuses_all();
    $rows = [];
    foreach ($data as $d) {
        $pid = (int) ($d['pid'] ?? 0);
        if (!isset($rows[$pid])) {
            $rows[$pid] = ['label' => $pid ? rep_period_label($d) : '(no period)', 'n' => array_fill_keys($statuses, 0), 'claimed' => 0.0];
        }
        $rows[$pid]['n'][$d['status']] = (int) $d['n'];
        if ($d['status'] === 'claimed') {
            $rows[$pid]['claimed'] += (float) $d['amt'];
        }
    }
    krsort($rows);
    if (isset($rows[0])) {
        $none = $rows[0];
        unset($rows[0]);
        $rows[0] = $none;   // "(no period)" last
    }

    $columns = [['Academic period', 'text']];
    foreach ($statuses as $s) {
        $columns[] = [ord_label($s), 'int'];
    }
    $columns[] = ['Total orders', 'int'];
    $columns[] = ['Claimed total', 'money'];

    $out = [];
    $foot = array_fill(0, count($columns), 0);
    $foot[0] = 'Total';
    foreach ($rows as $r) {
        $line = [$r['label']];
        $total = 0;
        foreach ($statuses as $i => $s) {
            $line[] = $r['n'][$s];
            $total += $r['n'][$s];
            $foot[$i + 1] += $r['n'][$s];
        }
        $line[] = $total;
        $line[] = $r['claimed'];
        $foot[count($statuses) + 1] += $total;
        $foot[count($statuses) + 2] += $r['claimed'];
        $out[] = $line;
    }
    return ['columns' => $columns, 'rows' => $out, 'foot' => $out ? $foot : null];
}

function rep_claimed(array $f): array
{
    $w = ["o.status = 'claimed'"];
    $p = [];
    rep_range('o.claimed_at', $f, $w, $p);
    if ($f['period'] > 0) {
        $w[] = 'o.academic_period_id = ?';
        $p[] = $f['period'];
    }
    $data = db_all(
        'SELECT oi.variant_id, MAX(oi.item_name) AS item_name, MAX(oi.size_label) AS size_label,
                SUM(oi.quantity) AS qty, SUM(oi.line_total) AS total, COUNT(DISTINCT o.order_id) AS orders
           FROM order_items oi JOIN orders o ON o.order_id = oi.order_id
          WHERE ' . implode(' AND ', $w) . '
          GROUP BY oi.variant_id ORDER BY MAX(oi.item_name), oi.variant_id',
        $p
    );
    $rows = [];
    $foot = ['Total', '', 0, 0.0, 0];
    foreach ($data as $d) {
        $rows[] = [$d['item_name'], $d['size_label'], (int) $d['qty'], (float) $d['total'], (int) $d['orders']];
        $foot[2] += (int) $d['qty'];
        $foot[3] += (float) $d['total'];
        $foot[4] += (int) $d['orders'];
    }
    return ['columns' => [['Item', 'text'], ['Size', 'text'], ['Quantity claimed', 'int'], ['Total', 'money'], ['Orders', 'int']],
            'rows' => $rows, 'foot' => $rows ? $foot : null,
            'note' => 'Orders counted by the date they were claimed. The Orders column cannot be added up across sizes when one order has several sizes.'];
}

function rep_sizes(array $f): array
{
    $w = ['1=1'];
    $p = [];
    rep_range('o.created_at', $f, $w, $p);
    if ($f['period'] > 0) {
        $w[] = 'o.academic_period_id = ?';
        $p[] = $f['period'];
    }
    $data = db_all(
        "SELECT oi.variant_id, MAX(oi.item_name) AS item_name, MAX(oi.size_label) AS size_label,
                SUM(oi.quantity) AS requested, COUNT(DISTINCT o.order_id) AS orders,
                SUM(CASE WHEN o.status = 'claimed' THEN oi.quantity ELSE 0 END) AS claimed,
                SUM(CASE WHEN o.status IN ('declined','cancelled','expired') THEN oi.quantity ELSE 0 END) AS not_completed
           FROM order_items oi JOIN orders o ON o.order_id = oi.order_id
          WHERE " . implode(' AND ', $w) . '
          GROUP BY oi.variant_id ORDER BY requested DESC, MAX(oi.item_name), oi.variant_id LIMIT 50',
        $p
    );
    $rows = [];
    foreach ($data as $i => $d) {
        $rows[] = [$i + 1, $d['item_name'], $d['size_label'], (int) $d['requested'], (int) $d['orders'], (int) $d['claimed'], (int) $d['not_completed']];
    }
    return ['columns' => [['#', 'int'], ['Item', 'text'], ['Size', 'text'], ['Quantity requested', 'int'], ['Orders', 'int'], ['Claimed', 'int'], ['Declined / cancelled / expired', 'int']],
            'rows' => $rows, 'foot' => null,
            'note' => 'Every order placed in the date range counts as demand, whatever became of it. Top 50 sizes.'];
}

function rep_rentals(array $f): array
{
    $w = ['1=1'];
    $p = [];
    rep_range('r.created_at', $f, $w, $p);
    if ($f['period'] > 0) {
        $w[] = 'r.academic_period_id = ?';
        $p[] = $f['period'];
    }
    $data = db_all(
        "SELECT p.academic_period_id, p.school_year, p.semester,
                SUM(r.status = 'reserved') AS reserved,
                SUM(r.status = 'released' AND (r.due_date IS NULL OR r.due_date >= CURDATE())) AS issued,
                SUM(r.status = 'overdue' OR (r.status = 'released' AND r.due_date < CURDATE())) AS overdue,
                SUM(r.status = 'returned') AS returned, SUM(r.status = 'cancelled') AS cancelled, COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN r.status IN ('released','overdue','returned') THEN r.deposit_amount END), 0) AS deposits,
                COALESCE(SUM(r.penalty_amount), 0) AS penalties
           FROM rentals r JOIN academic_periods p ON p.academic_period_id = r.academic_period_id
          WHERE " . implode(' AND ', $w) . '
          GROUP BY p.academic_period_id, p.school_year, p.semester, p.start_date
          ORDER BY p.start_date DESC, p.academic_period_id DESC',
        $p
    );
    $rows = [];
    $foot = ['Total', 0, 0, 0, 0, 0, 0, 0.0, 0.0];
    foreach ($data as $d) {
        $line = [rep_period_label($d), (int) $d['reserved'], (int) $d['issued'], (int) $d['overdue'], (int) $d['returned'],
                 (int) $d['cancelled'], (int) $d['total'], (float) $d['deposits'], (float) $d['penalties']];
        for ($i = 1; $i <= 8; $i++) {
            $foot[$i] += $line[$i];
        }
        $rows[] = $line;
    }
    return ['columns' => [['Academic period', 'text'], ['Reserved', 'int'], ['Issued', 'int'], ['Overdue', 'int'], ['Returned', 'int'],
                          ['Cancelled', 'int'], ['Total rentals', 'int'], ['Deposits recorded', 'money'], ['Penalties recorded', 'money']],
            'rows' => $rows, 'foot' => $rows ? $foot : null,
            'note' => 'Deposit and penalty amounts are what staff entered. The shop has not confirmed the rental rules yet.'];
}

function rep_movements_sections(array $f): array
{
    $w = ['1=1'];
    $p = [];
    rep_range('m.created_at', $f, $w, $p);
    $where = implode(' AND ', $w);
    $labels = ['restock' => 'Restock', 'reserved' => 'Reserved', 'released' => 'Released', 'sold' => 'Sold',
               'rented_out' => 'Rented out', 'returned' => 'Returned', 'adjustment' => 'Adjustment'];

    $by_type = db_all(
        "SELECT m.movement_type, COUNT(*) AS n, SUM(m.on_hand_delta) AS oh, SUM(m.reserved_delta) AS rs
           FROM inventory_movements m WHERE $where GROUP BY m.movement_type",
        $p
    );
    $map = [];
    foreach ($by_type as $r) {
        $map[$r['movement_type']] = $r;
    }
    $type_rows = [];
    $foot = ['Total', 0, 0, 0];
    foreach ($labels as $k => $label) {
        if (!isset($map[$k])) {
            continue;
        }
        $type_rows[] = [$label, (int) $map[$k]['n'], (int) $map[$k]['oh'], (int) $map[$k]['rs']];
        $foot[1] += (int) $map[$k]['n'];
        $foot[2] += (int) $map[$k]['oh'];
        $foot[3] += (int) $map[$k]['rs'];
    }

    $by_size = db_all(
        "SELECT v.variant_id, i.name AS item_name, v.size_label,
                SUM(CASE WHEN m.movement_type = 'restock' THEN m.on_hand_delta ELSE 0 END) AS restocked,
                SUM(CASE WHEN m.movement_type = 'sold' THEN -m.on_hand_delta ELSE 0 END) AS sold,
                SUM(CASE WHEN m.movement_type = 'rented_out' THEN -m.on_hand_delta ELSE 0 END) AS rented,
                SUM(CASE WHEN m.movement_type = 'returned' THEN m.on_hand_delta ELSE 0 END) AS returned,
                SUM(CASE WHEN m.movement_type = 'adjustment' THEN m.on_hand_delta ELSE 0 END) AS adjusted,
                SUM(m.on_hand_delta) AS net, COUNT(*) AS n
           FROM inventory_movements m
           JOIN item_variants v ON v.variant_id = m.variant_id JOIN items i ON i.item_id = v.item_id
          WHERE $where GROUP BY v.variant_id, i.name, v.size_label ORDER BY i.name, v.variant_id",
        $p
    );
    $size_rows = [];
    $sfoot = ['Total', '', 0, 0, 0, 0, 0, 0, 0];
    foreach ($by_size as $d) {
        $line = [$d['item_name'], $d['size_label'], (int) $d['restocked'], (int) $d['sold'], (int) $d['rented'],
                 (int) $d['returned'], (int) $d['adjusted'], (int) $d['net'], (int) $d['n']];
        for ($i = 2; $i <= 8; $i++) {
            $sfoot[$i] += $line[$i];
        }
        $size_rows[] = $line;
    }
    return [
        ['title' => 'By movement type', 'columns' => [['Type', 'text'], ['Movements', 'int'], ['On hand change', 'int'], ['Reserved change', 'int']],
         'rows' => $type_rows, 'foot' => $type_rows ? $foot : null,
         'note' => 'On hand change counts units entering (+) or leaving (-) the shelf. Reserved change counts units put on hold or released.'],
        ['title' => 'By item and size', 'columns' => [['Item', 'text'], ['Size', 'text'], ['Restocked', 'int'], ['Sold', 'int'], ['Rented out', 'int'],
                                                      ['Returned', 'int'], ['Adjustments (net)', 'int'], ['Net on hand change', 'int'], ['Movements', 'int']],
         'rows' => $size_rows, 'foot' => $size_rows ? $sfoot : null, 'note' => ''],
    ];
}

/** Run one report. Returns the common shape described at the top of the file. */
function rep_run(array $f): array
{
    $types = rep_types();
    $sections = [];
    switch ($f['type']) {
        case 'claimed':
            $sections[] = ['title' => 'Claimed quantities and totals by item and size'] + rep_claimed($f);
            break;
        case 'sizes':
            $sections[] = ['title' => 'Most requested sizes'] + rep_sizes($f);
            break;
        case 'rentals':
            $sections[] = ['title' => 'Toga rentals by academic period'] + rep_rentals($f);
            $sections[] = ['title' => 'Toga requests (orders) by status'] + rep_orders_pivot($f, "o.order_type = 'rental'") + ['note' => 'All toga rental orders placed in the range, including requests that never became a rental.'];
            break;
        case 'movements':
            $sections = rep_movements_sections($f);
            break;
        default:
            $sections[] = ['title' => 'Orders by academic period and status'] + rep_orders_pivot($f) + ['note' => 'Counted by the date the order was placed. Expired orders in the recycle bin are included. Claimed total is the value of claimed orders.'];
    }
    foreach ($sections as &$s) {
        $s += ['note' => '', 'foot' => null];
    }
    unset($s);
    return ['title' => $types[$f['type']], 'subtitle' => rep_filter_text($f), 'sections' => $sections];
}

/* ------------------------------------------------------------- rendering */

function rep_cell_html($v, string $type): string
{
    if ($v === null || $v === '') {
        return '';
    }
    if ($type === 'int') {
        return e(number_format((int) $v));
    }
    if ($type === 'money') {
        return e(money($v));
    }
    return e($v);
}

/** HTML tables for every section (already escaped). */
function rep_render_html(array $report): string
{
    $html = '';
    foreach ($report['sections'] as $s) {
        $html .= '<section class="s9-report-section"><h2>' . e($s['title']) . '</h2>';
        if (!$s['rows']) {
            $html .= '<p class="s9-muted">No data for these filters.</p></section>';
            continue;
        }
        $html .= '<div class="s9-table-wrap"><table class="s9-table"><thead><tr>';
        foreach ($s['columns'] as [$label, $type]) {
            $html .= '<th' . ($type === 'text' ? '' : ' class="s9-num"') . '>' . e($label) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($s['rows'] as $row) {
            $html .= '<tr>';
            foreach ($s['columns'] as $i => [$label, $type]) {
                $html .= '<td' . ($type === 'text' ? '' : ' class="s9-num"') . '>' . rep_cell_html($row[$i] ?? '', $type) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';
        if ($s['foot']) {
            $html .= '<tfoot><tr>';
            foreach ($s['columns'] as $i => [$label, $type]) {
                $html .= '<th' . ($type === 'text' ? '' : ' class="s9-num"') . '>' . rep_cell_html($s['foot'][$i] ?? '', $type) . '</th>';
            }
            $html .= '</tr></tfoot>';
        }
        $html .= '</table></div>';
        if ($s['note'] !== '') {
            $html .= '<p class="s9-hint">' . e($s['note']) . '</p>';
        }
        $html .= '</section>';
    }
    return $html;
}

/** Excel treats cells starting with = + - @ as formulas; neutralise text cells. */
function rep_csv_cell($v, string $type)
{
    if ($type === 'money') {
        return number_format((float) $v, 2, '.', '');
    }
    if ($type === 'int') {
        return (string) (int) $v;
    }
    $s = (string) $v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !is_numeric($s)) {
        $s = "'" . $s;
    }
    return $s;
}

/** Stream the report as CSV and stop. Call before any output. */
function rep_export_csv(array $report, array $f): void
{
    $name = 'report-' . $f['type'] . '-' . date('Ymd-Hi') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8 (the peso sign is not used in CSV)
    fputcsv($out, [$report['title']]);
    fputcsv($out, [$report['subtitle']]);
    foreach ($report['sections'] as $s) {
        fputcsv($out, []);
        fputcsv($out, [$s['title']]);
        fputcsv($out, array_map(fn($c) => $c[0], $s['columns']));
        foreach ($s['rows'] as $row) {
            $line = [];
            foreach ($s['columns'] as $i => [$label, $type]) {
                $line[] = rep_csv_cell($row[$i] ?? '', $type);
            }
            fputcsv($out, $line);
        }
        if ($s['foot']) {
            $line = [];
            foreach ($s['columns'] as $i => [$label, $type]) {
                $line[] = ($s['foot'][$i] ?? '') === '' ? '' : rep_csv_cell($s['foot'][$i], $type);
            }
            fputcsv($out, $line);
        }
    }
    fclose($out);
}
