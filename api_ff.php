<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/requirements.php';
requireRole(MAO_ROLES, 'api');

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo json_encode(null); exit(); }

$user      = getCurrentUser();
$mun_scope = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;

try {

    $scope_sql = "SELECT id, municipality, boat_ownership FROM fisherfolk WHERE id = $id" . ($mun_scope ? " AND municipality='" . $conn->real_escape_string($mun_scope) . "'" : '');
    $scope_row = $conn->query($scope_sql)->fetch_assoc();
    if (!$scope_row) { echo json_encode(null); exit(); }


    if (!empty($_GET['permits'])) {
        $result = $conn->query("SELECT p.*, u.name AS issued_by_name,
            (SELECT l.reason FROM permit_status_log l WHERE l.permit_id = p.id ORDER BY l.id DESC LIMIT 1) AS last_reason,
            (SELECT l.incident_id FROM permit_status_log l WHERE l.permit_id = p.id ORDER BY l.id DESC LIMIT 1) AS last_incident_id
            FROM permits p
            LEFT JOIN users u ON p.issued_by = u.id
            WHERE p.fisherfolk_id = $id
            ORDER BY p.issue_date DESC");
        $permits = [];
        while ($row = $result->fetch_assoc()) $permits[] = $row;

        $dq = $conn->query("SELECT doc_type, doc_number, issue_date, expiry_date FROM registration_documents WHERE fisherfolk_id = $id ORDER BY issue_date DESC, id DESC");
        $documents = $dq ? $dq->fetch_all(MYSQLI_ASSOC) : [];
        echo json_encode([
            'permits'   => $permits,
            'documents' => $documents,
            'required'  => munRequirements($scope_row['municipality'], $scope_row['boat_ownership']),
            'chain'     => munChain($scope_row['municipality'], $scope_row['boat_ownership']),
            'catalog'   => array_keys(DOC_KIND),

            'transfer'  => transferInfo($conn, $id, $scope_row),
            'can_transfer' => canHandleTransfers() && $scope_row['municipality'] === ($user['mun'] ?? ''),
            'transfer_to'  => array_values(array_diff(array_keys(MUN_CODES), [$scope_row['municipality']])),
        ]);
        exit();
    }





    $r = $conn->query("SELECT
            f.id, f.boat_registry_number, f.first_name, f.middle_name, f.last_name, f.suffix,
            f.birthdate, f.sex, f.civil_status, f.contact_number, f.address, f.barangay, f.municipality,
            f.years_fishing, f.fishing_environment, f.fishing_ground, f.fma_no,
            f.boat_ownership, f.assigned_boat_id,
            f.emergency_name, f.emergency_relationship, f.emergency_contact,
            f.profile_status, f.photo_path, f.created_at, f.updated_at,
            u.name AS reg_by,
            b.id AS boat_id, b.name AS boat_name, b.homeport, b.boat_type, b.material AS boat_material,
            b.no_fishers, b.length_m, b.breadth_m, b.depth_m, b.gross_tonnage, b.horsepower, b.engine_make,
            (SELECT c.uid FROM rfid_cards c WHERE c.fisherfolk_id = f.id AND c.status = 'Active' LIMIT 1) AS rfid_uid
        FROM fisherfolk f
        LEFT JOIN users u ON f.registered_by = u.id
        LEFT JOIN boats b ON b.id = COALESCE(
            f.assigned_boat_id,
            (SELECT MIN(ob.id) FROM boats ob WHERE ob.fisherfolk_id = f.id)
        )
        WHERE f.id = $id");
    $ff = $r->fetch_assoc();
    if (!$ff) { echo json_encode(null); exit(); }


    $ff['gear'] = [];
    if (!empty($ff['boat_id'])) {
        $boat_id = (int)$ff['boat_id'];
        $g = $conn->query("SELECT gc.gear_name AS name, bg.units, bg.mesh_size_cm
            FROM boat_gear bg
            JOIN gear_catalog gc ON gc.id = bg.gear_id
            WHERE bg.boat_id = $boat_id
            ORDER BY gc.sort_order, gc.gear_name");
        while ($row = $g->fetch_assoc()) $ff['gear'][] = $row;
    }


    $ff['violation_count']       = (int)$conn->query("SELECT COUNT(*) AS c FROM incidents WHERE fisherfolk_id = $id")->fetch_assoc()['c'];
    $ff['active_incident_count'] = (int)$conn->query("SELECT COUNT(*) AS c FROM incidents WHERE fisherfolk_id = $id AND status != 'Resolved'")->fetch_assoc()['c'];
    $latest = $conn->query("SELECT v.name AS violation_type, i.status
        FROM incidents i
        JOIN violation_catalog v ON v.id = i.violation_id
        WHERE i.fisherfolk_id = $id AND i.status != 'Resolved'
        ORDER BY i.reported_at DESC LIMIT 1")->fetch_assoc();
    $ff['latest_incident_type']   = $latest['violation_type'] ?? null;
    $ff['latest_incident_status'] = $latest['status'] ?? null;

    $ff['id_office'] = getSetting((string)$ff['municipality'], 'office_name');
    $ff['transfer']  = transferInfo($conn, $id, $scope_row);
    echo json_encode($ff, JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('api_ff: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(null);
}
