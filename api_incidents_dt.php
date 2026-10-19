<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
requireLogin();

$user      = getCurrentUser();

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$mun_scope = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;

header('Content-Type: application/json');

try {

    if (!empty($_GET['poll'])) {
        $sw = $mun_scope ? " AND municipality='" . $conn->real_escape_string($mun_scope) . "'" : '';

        $total         = (int)$conn->query("SELECT COUNT(*) as c FROM incidents WHERE 1=1$sw")->fetch_assoc()['c'];
        $cnt_pending   = (int)$conn->query("SELECT COUNT(*) as c FROM incidents WHERE status='Pending'$sw")->fetch_assoc()['c'];
        $cnt_invest    = (int)$conn->query("SELECT COUNT(*) as c FROM incidents WHERE status='Under Investigation'$sw")->fetch_assoc()['c'];
        $cnt_resolved  = (int)$conn->query("SELECT COUNT(*) as c FROM incidents WHERE status='Resolved'$sw")->fetch_assoc()['c'];

        $uid    = (int)$user['id'];
        $unread = (int)$conn->query("
            SELECT COUNT(*) as c FROM incidents i
            WHERE 1=1$sw
            AND i.id NOT IN (SELECT incident_id FROM incident_reads WHERE user_id=$uid)
        ")->fetch_assoc()['c'];

        echo json_encode([
            'total'               => $total,
            'unread'              => $unread,
            'count_pending'       => $cnt_pending,
            'count_investigating' => $cnt_invest,
            'count_resolved'      => $cnt_resolved,
        ]);
        exit();
    }


    $draw        = intval($_GET['draw'] ?? 1);
    $start       = max(0, intval($_GET['start'] ?? 0));
    $length      = intval($_GET['length'] ?? 10);
    if ($length < 1 || $length > 500) $length = 10;
    $search      = trim($_GET['search']['value'] ?? '');
    $orderColumn = intval($_GET['order'][0]['column'] ?? 5);
    $orderDir    = strtoupper($_GET['order'][0]['dir'] ?? 'DESC');
    $orderDir    = in_array($orderDir, ['ASC', 'DESC']) ? $orderDir : 'DESC';


    $columns = [
        0 => 'i.id',
        1 => 'v.name',
        2 => 'i.location_name',
        3 => 'i.municipality',
        4 => 'i.reported_by_name',
        5 => 'i.reported_at',
        6 => 'i.status',
    ];
    $orderBy = $columns[$orderColumn] ?? 'i.reported_at';

    $orderSql = "$orderBy $orderDir, i.id DESC";

    $where = '1=1';
    if ($mun_scope) {
        $m = $conn->real_escape_string($mun_scope);
        $where .= " AND i.municipality='$m'";
    }
    if ($search) {
        $s = $conn->real_escape_string($search);
        $where .= " AND (
            v.name                 LIKE '%$s%' OR
            i.description          LIKE '%$s%' OR
            i.location_name        LIKE '%$s%' OR
            i.municipality         LIKE '%$s%' OR
            i.reported_by_name     LIKE '%$s%' OR
            i.boat_registry_number LIKE '%$s%'
        )";
    }

    $totalResult     = $conn->query("SELECT COUNT(*) AS total FROM incidents i WHERE 1=1"
        . ($mun_scope ? " AND i.municipality='" . $conn->real_escape_string($mun_scope) . "'" : ''));
    $totalRecords    = (int)$totalResult->fetch_assoc()['total'];


    $filteredResult  = $conn->query("SELECT COUNT(*) AS total
        FROM incidents i
        JOIN violation_catalog v ON v.id = i.violation_id
        WHERE $where");
    $filteredRecords = (int)$filteredResult->fetch_assoc()['total'];

    $result = $conn->query("
        SELECT
            i.id,
            i.fisherfolk_id,
            i.boat_registry_number,
            v.name AS violation_type,
            i.description,
            i.location_name,
            i.municipality,
            i.reported_by_name,
            i.reported_at,
            i.status,
            i.photo_path,
            i.latitude,
            i.longitude,
            CONCAT(f.last_name, ', ', f.first_name) AS fisherfolk_name
        FROM incidents i
        JOIN violation_catalog v ON v.id = i.violation_id
        LEFT JOIN fisherfolk f ON i.fisherfolk_id = f.id
        WHERE $where
        ORDER BY $orderSql
        LIMIT $start, $length
    ");

    $badgeMap = [
        'Pending'             => 'badge-pending',
        'Under Investigation' => 'badge-investigating',
        'Resolved'            => 'badge-resolved',
    ];

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $badgeClass = $badgeMap[$row['status']] ?? 'badge-pending';

        $data[] = [
            'id'                   => (int)$row['id'],
            'fisherfolk_id'        => $row['fisherfolk_id'] ? (int)$row['fisherfolk_id'] : null,
            'boat_registry_number' => $row['boat_registry_number'] ?? null,
            'fisherfolk_name'      => $row['fisherfolk_name'] ?? null,
            'violation_type'       => htmlspecialchars($row['violation_type']),
            'description'          => htmlspecialchars($row['description'] ?? ''),
            'location_name'        => htmlspecialchars($row['location_name'] ?? ''),
            'municipality'         => htmlspecialchars($row['municipality'] ?? ''),
            'reported_by_name'     => htmlspecialchars($row['reported_by_name'] ?? ''),
            'reported_at'          => date('M j, Y g:i A', strtotime($row['reported_at'])),
            'status'               => $row['status'],
            'status_badge'         => $badgeClass,
            'photo_path'           => $row['photo_path'] ?? null,
            'latitude'             => $row['latitude']  !== null ? (float)$row['latitude']  : null,
            'longitude'            => $row['longitude'] !== null ? (float)$row['longitude'] : null,
        ];
    }

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data'            => $data,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('api_incidents_dt: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(!empty($_GET['poll'])
        ? ['error' => 'server error']
        : ['draw' => intval($_GET['draw'] ?? 1), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'server error']);
}
