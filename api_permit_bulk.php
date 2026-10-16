<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
requireLogin();

header('Content-Type: application/json');

$action       = $_POST['action']       ?? '';
$fisherfolk_id = intval($_POST['fisherfolk_id'] ?? 0);

if (!$fisherfolk_id) {
    echo json_encode(['success' => false, 'error' => 'Missing fisherfolk_id']);
    exit();
}

if ($action === 'suspend_all') {
    $ok = $conn->query("UPDATE permits SET status='Suspended'
                        WHERE fisherfolk_id=$fisherfolk_id AND status='Active'");
    echo json_encode(['success' => (bool)$ok, 'affected' => $conn->affected_rows]);
    exit();
}

if ($action === 'reinstate_all') {
    $ok = $conn->query("UPDATE permits SET status='Active'
                        WHERE fisherfolk_id=$fisherfolk_id AND status='Suspended'");
    echo json_encode(['success' => (bool)$ok, 'affected' => $conn->affected_rows]);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);