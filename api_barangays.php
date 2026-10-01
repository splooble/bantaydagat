<?php

require_once 'includes/auth.php';
require_once 'includes/barangays.php';
requireRole(array_merge(MAO_ROLES, OFFICER_ROLES), 'api');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, max-age=3600');

$mun = trim((string)($_GET['municipality'] ?? ''));
if ($mun === '') {
    echo json_encode(['success' => true, 'municipalities' => allBarangays()], JSON_UNESCAPED_UNICODE);
    exit();
}
if (!isset(BARANGAY_LISTS[$mun])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Unknown municipality.']);
    exit();
}
echo json_encode(['success' => true, 'municipality' => $mun, 'barangays' => barangaysFor($mun)], JSON_UNESCAPED_UNICODE);
