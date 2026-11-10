<?php
/**
 * Small JSON endpoint for the walk-in form (live availability). Read-only.
 *   ?do=stock&ids=1,2,3   -> {"stock": {"1": {"available":5,"tracks":1,"price":"245.00"}, ...}}
 */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/orders.php';
require_once AUTH_ROOT . '/includes/walkin.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in again.']);
    exit;
}
if (!can('walkin.create', $user['role'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not allowed.']);
    exit;
}

if (($_GET['do'] ?? '') === 'stock') {
    $ids = array_slice(explode(',', (string) ($_GET['ids'] ?? '')), 0, 60);
    echo json_encode(['stock' => (object) walk_stock($ids)]);
    exit;
}
http_response_code(400);
echo json_encode(['error' => 'Unknown request.']);
