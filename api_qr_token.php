<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/qr_crypto.php';
requireRole(MAO_ROLES, 'api');

header('Content-Type: application/json');

$ff_id = intval($_GET['ff_id'] ?? 0);
if (!$ff_id) {
    echo json_encode(['error' => 'Missing ff_id']);
    exit();
}






$regenerate = !empty($_GET['regenerate']);

$user      = getCurrentUser();
$mun_scope = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;

$q = "SELECT id, boat_registry_number, qr_token FROM fisherfolk WHERE id = $ff_id";
if ($mun_scope) {
    $m = $conn->real_escape_string($mun_scope);
    $q .= " AND municipality='$m'";
}

$r  = $conn->query($q);
$ff = $r ? $r->fetch_assoc() : null;

if (!$ff) {
    echo json_encode(['error' => 'Fisherfolk not found']);
    exit();
}

$brn = $ff['boat_registry_number'];
if (!$brn) {
    echo json_encode(['error' => 'No boat registry number on record']);
    exit();
}





if (!$regenerate && !empty($ff['qr_token'])) {
    echo json_encode(['token' => $ff['qr_token'], 'brn' => $brn, 'reused' => true]);
    exit();
}

try {
    $token = encryptQRPayload($brn, (int)$ff['id']);
    $stmt = $conn->prepare("UPDATE fisherfolk SET qr_token = ? WHERE id = ?");
    $stmt->bind_param('si', $token, $ff['id']);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['token' => $token, 'brn' => $brn, 'reused' => false]);
} catch (RuntimeException $e) {
    echo json_encode(['error' => 'Encryption failed: ' . $e->getMessage()]);
}