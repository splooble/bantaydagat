<?php

require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/requirements.php';
requireRole(MAO_ROLES, 'api');

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Content-Type: application/json');

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'count') {
    $mun = (string)($_SESSION['user_mun'] ?? '');
    if (!canHandleTransfers()) { echo json_encode(['pending' => 0, 'unseen' => 0]); exit(); }
    $s = incomingSummary($conn, $mun);
    echo json_encode(['pending' => $s['pending'], 'unseen' => $s['unseen']]);
    exit();
}

if ($action === 'id_printed' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) denyJson(403, 'Session expired. Please reload the page.');
    if (!tfHas($conn, 'cols')) { echo json_encode(['success' => true]); exit(); }
    $user = getCurrentUser();
    $mun_scope = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;
    $ffid = (int)($_POST['ff_id'] ?? 0);
    $sql = "UPDATE fisherfolk SET id_reprint_needed = 0 WHERE id = ?" . ($mun_scope ? " AND municipality = ?" : '');
    $st = $conn->prepare($sql);
    if ($mun_scope) $st->bind_param('is', $ffid, $mun_scope); else $st->bind_param('i', $ffid);
    $st->execute(); $st->close();
    echo json_encode(['success' => true]);
    exit();
}

denyJson(400, 'Unknown action.');
