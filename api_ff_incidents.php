<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
requireLogin();

header('Content-Type: application/json');

$ff_id = intval($_GET['ff_id'] ?? 0);
if (!$ff_id) {
    echo json_encode([]);
    exit();
}

try {

    $result = $conn->query("
        SELECT
            i.id,
            v.name AS violation_type,
            i.description,
            i.location_name,
            i.municipality,
            i.reported_by_name,
            i.status,
            i.reported_at,
            i.boat_registry_number
        FROM incidents i
        JOIN violation_catalog v ON v.id = i.violation_id
        WHERE i.fisherfolk_id = $ff_id
        ORDER BY i.reported_at DESC
    ");

    $badgeMap = [
        'Pending'             => 'badge-pending',
        'Under Investigation' => 'badge-investigating',
        'Resolved'            => 'badge-resolved',
    ];

    $incidents = [];
    while ($row = $result->fetch_assoc()) {
        $incidents[] = [
            'id'                   => (int)$row['id'],
            'violation_type'       => htmlspecialchars($row['violation_type']),
            'description'          => htmlspecialchars($row['description'] ?? ''),
            'location_name'        => htmlspecialchars($row['location_name'] ?? ''),
            'municipality'         => htmlspecialchars($row['municipality'] ?? ''),
            'reported_by_name'     => htmlspecialchars($row['reported_by_name'] ?? ''),
            'status'               => $row['status'],
            'status_badge'         => $badgeMap[$row['status']] ?? 'badge-pending',
            'reported_at'          => date('M j, Y g:i A', strtotime($row['reported_at'])),
            'boat_registry_number' => $row['boat_registry_number'] ?? null,
        ];
    }

    echo json_encode($incidents, JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('api_ff_incidents: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([]);
}
