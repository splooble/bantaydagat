<?php

require_once 'includes/auth.php';
require_once 'includes/db.php';
requireRole(OFFICER_ROLES, 'api');

header('Content-Type: application/json');
if (extension_loaded('zlib')) ob_start('ob_gzhandler');

$user      = getCurrentUser();
$mun_scope = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;


$conn->query("UPDATE permits SET status='Expired' WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND status='Active'");

$where = '1=1';
if ($mun_scope) $where .= " AND f.municipality='" . $conn->real_escape_string($mun_scope) . "'";


$LIMIT = 15000;

$result = $conn->query("
    SELECT f.id, f.first_name, f.middle_name, f.last_name, f.boat_registry_number, f.qr_token,
           f.municipality, f.barangay, f.fishing_environment, f.fishing_ground, f.profile_status, f.boat_ownership, f.photo_path,
           rc.uid AS rfid_uid,
           b.id AS boat_id, b.name AS boat_name, b.boat_type, b.material AS boat_material, b.homeport,
           b.length_m, b.gross_tonnage, b.horsepower, b.engine_make, b.no_fishers
    FROM fisherfolk f
    LEFT JOIN boats b ON b.id = COALESCE(f.assigned_boat_id, (SELECT MIN(x.id) FROM boats x WHERE x.fisherfolk_id = f.id))
    LEFT JOIN rfid_cards rc ON rc.fisherfolk_id = f.id AND rc.status = 'Active'
    WHERE $where
    ORDER BY f.created_at DESC
    LIMIT $LIMIT
");

$out    = [];
$byId   = [];
$byBoat = [];
while ($row = $result->fetch_assoc()) {
    $gt = $row['gross_tonnage'];
    $row['fisher_class']     = $gt === null ? '' : ((float)$gt > 3 ? 'Commercial' : 'Municipal');
    $row['gear']             = [];
    $row['permits']          = [];
    $row['active_incidents'] = [];
    $row['incidents_5y']     = 0;
    $out[$row['boat_registry_number']] = $row;
    $byId[(int)$row['id']] = $row['boat_registry_number'];
    if ($row['boat_id'] !== null) $byBoat[(int)$row['boat_id']][] = $row['boat_registry_number'];
}

if ($byId) {
    $idList = implode(',', array_keys($byId));

    if ($byBoat) {
        $boatList = implode(',', array_keys($byBoat));
        $q = $conn->query("SELECT g.boat_id, gc.gear_name, gc.method, g.units, g.mesh_size_cm
                           FROM boat_gear g JOIN gear_catalog gc ON gc.id = g.gear_id
                           WHERE g.boat_id IN ($boatList)");
        while ($g = $q->fetch_assoc()) {
            $item = ['name' => $g['gear_name'], 'method' => $g['method'], 'units' => (int)$g['units'],
                     'mesh_size_cm' => $g['mesh_size_cm'] === null ? null : (float)$g['mesh_size_cm']];
            foreach ($byBoat[(int)$g['boat_id']] as $brn) $out[$brn]['gear'][] = $item;
        }
    }

    $q = $conn->query("SELECT id, fisherfolk_id, boat_id, permit_number, permit_type, fishing_area, issue_date, expiry_date, status
                       FROM permits WHERE fisherfolk_id IN ($idList) ORDER BY issue_date DESC");
    while ($p = $q->fetch_assoc()) $out[$byId[(int)$p['fisherfolk_id']]]['permits'][] = $p;

    $q = $conn->query("SELECT i.id, i.fisherfolk_id, i.violation_id, v.name AS violation_type, i.status, i.reported_at
                       FROM incidents i JOIN violation_catalog v ON v.id = i.violation_id
                       WHERE i.fisherfolk_id IN ($idList) AND i.status != 'Resolved' ORDER BY i.reported_at DESC");
    while ($i = $q->fetch_assoc()) {
        $k = $byId[(int)$i['fisherfolk_id']];
        if (count($out[$k]['active_incidents']) < 5) $out[$k]['active_incidents'][] = $i;
    }


    $q = $conn->query("SELECT fisherfolk_id, COUNT(*) AS c FROM incidents
                       WHERE fisherfolk_id IN ($idList) AND reported_at >= DATE_SUB(CURDATE(), INTERVAL 5 YEAR) GROUP BY fisherfolk_id");
    while ($c = $q->fetch_assoc()) $out[$byId[(int)$c['fisherfolk_id']]]['incidents_5y'] = (int)$c['c'];
}

echo json_encode(['schema' => 3, 'synced_at' => date('c'), 'count' => count($out), 'records' => array_values($out)], JSON_INVALID_UTF8_SUBSTITUTE);