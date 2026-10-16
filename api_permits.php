<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
requireLogin();

$ff_id = (int)($_GET['ff_id'] ?? 0);
if (!$ff_id) { echo json_encode([]); exit(); }

$user = getCurrentUser();
$mun_scope = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;


if ($mun_scope) {
    $m = $conn->real_escape_string($mun_scope);
    $check = $conn->query("SELECT id FROM fisherfolk WHERE id=$ff_id AND municipality='$m'");
    if ($check->num_rows === 0) { echo json_encode([]); exit(); }
}


$conn->query("UPDATE permits SET status='Expired' WHERE fisherfolk_id=$ff_id AND expiry_date < CURDATE() AND status='Active'");

$result = $conn->query("SELECT * FROM permits WHERE fisherfolk_id=$ff_id ORDER BY issue_date DESC");
$permits = [];
while ($r = $result->fetch_assoc()) $permits[] = $r;

header('Content-Type: application/json');
echo json_encode($permits);
