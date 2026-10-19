<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
requireRole(MAO_ROLES, 'api');

header('Content-Type: application/json');

$user      = getCurrentUser();

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$mun_scope = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;


$action = $_GET['action'] ?? $_POST['action'] ?? '';


if ($action === 'count') {
    $where = '1=1';
    if ($mun_scope) {
        $m = $conn->real_escape_string($mun_scope);
        $where .= " AND i.municipality='$m'";
    }

    $result = $conn->query("
        SELECT COUNT(*) as unread
        FROM incidents i
        LEFT JOIN incident_reads ir ON i.id = ir.incident_id AND ir.user_id = {$user['id']}
        WHERE $where AND ir.id IS NULL
    ");

    if (!$result) {
        echo json_encode(['unread' => 0, 'error' => $conn->error]);
        exit();
    }

    $count = (int)($result->fetch_assoc()['unread'] ?? 0);
    echo json_encode(['unread' => $count]);
    exit();
}


if ($action === 'mark_read') {
    $incident_id = intval($_POST['incident_id'] ?? 0);

    if (!$incident_id) {
        echo json_encode(['success' => false, 'error' => 'Missing incident_id']);
        exit();
    }

    $ok = $conn->query("
        INSERT IGNORE INTO incident_reads (incident_id, user_id, read_at)
        VALUES ($incident_id, {$user['id']}, NOW())
    ");

    echo json_encode(['success' => (bool)$ok]);
    exit();
}


if ($action === 'unread_ids') {
    $where = '1=1';
    if ($mun_scope) {
        $m = $conn->real_escape_string($mun_scope);
        $where .= " AND i.municipality='$m'";
    }

    $result = $conn->query("
        SELECT i.id
        FROM incidents i
        LEFT JOIN incident_reads ir ON i.id = ir.incident_id AND ir.user_id = {$user['id']}
        WHERE $where AND ir.id IS NULL
    ");

    if (!$result) {
        echo json_encode(['unread_ids' => [], 'error' => $conn->error]);
        exit();
    }

    $unread = [];
    while ($row = $result->fetch_assoc()) {
        $unread[] = (int)$row['id'];
    }

    echo json_encode(['unread_ids' => $unread]);
    exit();
}


echo json_encode(['error' => 'Invalid action']);