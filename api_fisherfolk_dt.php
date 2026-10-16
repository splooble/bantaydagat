<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/requirements.php';
requireLogin();

$user = getCurrentUser();
$mun_scope = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;


$draw = intval($_GET['draw'] ?? 1);
$start = max(0, intval($_GET['start'] ?? 0));
$length = intval($_GET['length'] ?? 10);
if ($length < 1 || $length > 500) $length = 10;
$search = trim($_GET['search']['value'] ?? '');
$orderColumn = intval($_GET['order'][0]['column'] ?? 5);
$orderDir = strtoupper($_GET['order'][0]['dir'] ?? 'DESC');
$orderDir = in_array($orderDir, ['ASC', 'DESC']) ? $orderDir : 'DESC';



$columns = [
    0 => 'f.last_name',
    1 => 'f.boat_registry_number',
    2 => 'f.boat_ownership',
    3 => 'f.contact_number',
    4 => 'active_permits',
    5 => 'f.created_at',
];
$orderBy = $columns[$orderColumn] ?? 'f.created_at';

$orderSql = "$orderBy $orderDir, f.id DESC";


$where = '1=1';

$mun_filter = $mun_scope ?? trim($_GET['mun'] ?? '');
if ($mun_filter) {
    $m = $conn->real_escape_string($mun_filter);
    $where .= " AND f.municipality='$m'";
}

if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (f.first_name LIKE '%$s%' OR f.last_name LIKE '%$s%' OR f.boat_registry_number LIKE '%$s%' OR f.contact_number LIKE '%$s%' OR f.municipality LIKE '%$s%')";
}

header('Content-Type: application/json');

try {

    $totalResult = $conn->query("SELECT COUNT(*) as total FROM fisherfolk f WHERE 1=1" . ($mun_filter ? " AND f.municipality='" . $conn->real_escape_string($mun_filter) . "'" : ''));
    $totalRecords = (int)$totalResult->fetch_assoc()['total'];


    $filteredResult = $conn->query("SELECT COUNT(*) as total FROM fisherfolk f WHERE $where");
    $filteredRecords = (int)$filteredResult->fetch_assoc()['total'];





    $tfCols = tfHas($conn, 'cols') ? 'f.is_transferee, f.id_reprint_needed,' : '';
    $query = "SELECT
        f.id,
        f.first_name,
        f.last_name,
        f.middle_name,
        f.boat_registry_number,
        f.municipality,
        f.boat_ownership,
        f.contact_number,
        f.profile_status,
        $tfCols
        f.created_at,
        u.name as reg_by,
        b.gross_tonnage,
        (SELECT COUNT(*) FROM permits p WHERE p.fisherfolk_id=f.id) as permit_count,
        (SELECT COUNT(*) FROM permits p WHERE p.fisherfolk_id=f.id AND p.status='Active') as active_permits,
        (SELECT COUNT(*) FROM permits p WHERE p.fisherfolk_id=f.id AND p.status='Suspended') as suspended_permits
    FROM fisherfolk f
    LEFT JOIN users u ON f.registered_by=u.id
    LEFT JOIN boats b ON b.id = COALESCE(
        f.assigned_boat_id,
        (SELECT MIN(ob.id) FROM boats ob WHERE ob.fisherfolk_id = f.id)
    )
    WHERE $where
    ORDER BY $orderSql
    LIMIT $start, $length";

    $result = $conn->query($query);
    $data = [];
    $rowsAll = $result->fetch_all(MYSQLI_ASSOC);

    $holds = holdTextFor($conn, array_map(fn($r) => ['id' => $r['id'], 'municipality' => $r['municipality'], 'boat_ownership' => $r['boat_ownership']], $rowsAll));

    foreach ($rowsAll as $row) {
        $fisherClass = $row['gross_tonnage'] === null
            ? ''
            : ((float)$row['gross_tonnage'] > 3 ? 'Commercial' : 'Municipal');

        $data[] = [
            'id' => $row['id'],
            'first_name' => htmlspecialchars($row['first_name']),
            'last_name' => htmlspecialchars($row['last_name']),
            'middle_name' => htmlspecialchars($row['middle_name'] ?? ''),
            'active_permits' => (int)$row['active_permits'],
            'permit_count' => (int)$row['permit_count'],
            'reg_by' => htmlspecialchars($row['reg_by'] ?? ''),
            'name' => htmlspecialchars($row['last_name'] . ', ' . $row['first_name'] . ' ' . ($row['middle_name'] ?? '')),
            'fisher_class' => $fisherClass,
            'profile_status' => htmlspecialchars($row['profile_status']),
            'hold' => $holds[(int)$row['id']] ?? '',
            'is_transferee' => (int)($row['is_transferee'] ?? 0),
            'id_reprint_needed' => (int)($row['id_reprint_needed'] ?? 0),
            'boat_registry_number' => htmlspecialchars($row['boat_registry_number'] ?? 'N/A'),
            'municipality' => htmlspecialchars($row['municipality']),
            'boat_ownership' => htmlspecialchars($row['boat_ownership'] ?? ''),
            'contact_number' => htmlspecialchars($row['contact_number'] ?? ''),
            'permits' => $row['active_permits'] . ' active / ' . $row['permit_count'] . ' total',
            'has_suspended' => $row['suspended_permits'] > 0 ? '⚠ Suspended' : '',
            'created_at' => date('M j, Y', strtotime($row['created_at'])),
            'initial' => strtoupper(substr($row['last_name'], 0, 1))
        ];
    }

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data' => $data
    ], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('api_fisherfolk_dt: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'server error'
    ]);
}