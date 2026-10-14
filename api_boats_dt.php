<?php

require_once 'includes/auth.php';
require_once 'includes/db.php';
requireRole(MAO_ROLES, 'api');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json');

$user      = getCurrentUser();
$mun_scope = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;
if ($user['role'] !== 'superadmin' && $mun_scope === null) {
    echo json_encode(['draw' => intval($_GET['draw'] ?? 1), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
    exit();
}

$draw   = intval($_GET['draw'] ?? 1);
$start  = max(0, intval($_GET['start'] ?? 0));
$length = intval($_GET['length'] ?? 25);
if ($length < 1 || $length > 500) $length = 25;
$search = trim($_GET['search']['value'] ?? '');


$columns = [
    0 => 'b.name',
    1 => 'f.last_name',
    2 => 'f.boat_registry_number',
    3 => 'b.homeport',
    4 => 'b.boat_type',
    5 => 'b.gross_tonnage',
    6 => 'crew_count',
];
$orderBy  = $columns[intval($_GET['order'][0]['column'] ?? 0)] ?? 'b.name';
$orderDir = strtoupper($_GET['order'][0]['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

try {
    $where = ['1=1']; $types = ''; $args = [];
    $mun = $mun_scope ?? trim($_GET['mun'] ?? '');
    if ($mun !== '') { $where[] = 'f.municipality = ?'; $types .= 's'; $args[] = $mun; }

    $baseWhere = implode(' AND ', $where);
    $bt = $types; $ba = $args;

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(b.name LIKE ? OR b.homeport LIKE ? OR b.boat_type LIKE ? OR f.first_name LIKE ? OR f.last_name LIKE ? OR f.boat_registry_number LIKE ? OR f.municipality LIKE ?)';
        $types .= 'sssssss';
        array_push($args, $like, $like, $like, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);

    $run = function (string $sql, string $t, array $a) use ($conn) {
        $st = $conn->prepare($sql);
        if ($t !== '') $st->bind_param($t, ...$a);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $rows;
    };

    $from = 'FROM boats b JOIN fisherfolk f ON f.id = b.fisherfolk_id';
    $total    = (int)$run("SELECT COUNT(*) AS c $from WHERE $baseWhere", $bt, $ba)[0]['c'];
    $filtered = (int)$run("SELECT COUNT(*) AS c $from WHERE $whereSql", $types, $args)[0]['c'];

    $sql = "SELECT b.id, b.name, b.homeport, b.boat_type, b.gross_tonnage,
                   f.first_name, f.middle_name, f.last_name, f.boat_registry_number, f.municipality,
                   (SELECT COUNT(*) FROM fisherfolk c WHERE c.assigned_boat_id = b.id AND c.boat_ownership = 'Crew') AS crew_count
            $from
            WHERE $whereSql
            ORDER BY $orderBy $orderDir, b.id DESC
            LIMIT $start, $length";
    $rows = $run($sql, $types, $args);

    $data = [];
    foreach ($rows as $r) {
        $gt = $r['gross_tonnage'];
        $data[] = [
            'id'            => (int)$r['id'],
            'name'          => $r['name'],
            'homeport'      => $r['homeport'],
            'boat_type'     => $r['boat_type'],
            'gross_tonnage' => $gt === null ? null : (float)$gt,
            'fisher_class'  => $gt === null ? '' : ((float)$gt > 3 ? 'Commercial' : 'Municipal'),
            'owner'         => trim($r['last_name'] . ', ' . $r['first_name'] . ' ' . ($r['middle_name'] ?? '')),
            'registry_no'   => $r['boat_registry_number'],
            'municipality'  => $r['municipality'],
            'crew_count'    => (int)$r['crew_count'],
        ];
    }

    echo json_encode([
        'draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $data,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('api_boats_dt: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'server error']);
}
