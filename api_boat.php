<?php

require_once 'includes/auth.php';
require_once 'includes/db.php';
requireRole(MAO_ROLES, 'api');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json');
header('Cache-Control: no-store');

$user      = getCurrentUser();
$isSuper   = $user['role'] === 'superadmin';
$mun_scope = (!$isSuper && !empty($user['mun'])) ? $user['mun'] : null;
if (!$isSuper && $mun_scope === null) { reply(['success' => false, 'error' => 'Your account has no municipality.'], 403); }

const BOAT_TYPES     = ['Non-motorized banca', 'Motorized banca', 'Others'];
const BOAT_MATERIALS = ['Wood', 'Fiber glass', 'Composite'];

function reply(array $body, int $code = 200): void {
    http_response_code($code);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}
function fail(string $msg, int $code = 400): void { reply(['success' => false, 'error' => $msg, 'message' => $msg], $code); }


function loadBoat(mysqli $conn, int $id, ?string $scope): ?array {
    $sql = "SELECT b.*, f.id AS owner_id, f.first_name, f.middle_name, f.last_name, f.suffix, f.boat_registry_number,
                   f.contact_number, f.barangay, f.municipality, f.fishing_environment
              FROM boats b JOIN fisherfolk f ON f.id = b.fisherfolk_id WHERE b.id = ?";
    $st = $conn->prepare($sql); $st->bind_param('i', $id); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if (!$row) return null;
    if ($scope !== null && $row['municipality'] !== $scope) return null;
    return $row;
}
function numField($v, float $max, bool $int = false) {
    if ($v === null || $v === '') return null;
    if (!is_numeric($v) || (float)$v < 0 || (float)$v > $max) return false;
    return $int ? (int)$v : (float)$v;
}

try {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
        if (!is_array($in)) fail('Bad request.');
        if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($in['csrf'] ?? ''))) {
            fail('Session expired. Reload the page and try again.', 403);
        }
        $boatId = (int)($in['boat_id'] ?? 0);
        $boat   = loadBoat($conn, $boatId, $mun_scope);
        if (!$boat) fail('Boat not found, or it is outside your municipality.', 404);
        $action = (string)($in['action'] ?? '');


        if ($action === 'save_boat') {
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '') fail('Enter the boat name.');
            if (mb_strlen($name) > 100) fail('The boat name is too long.');
            $homeport = trim((string)($in['homeport'] ?? ''));
            $engine   = trim((string)($in['engine_make'] ?? ''));
            if (mb_strlen($homeport) > 100 || mb_strlen($engine) > 100) fail('Homeport or engine make is too long.');

            $type = trim((string)($in['boat_type'] ?? ''));
            $mat  = trim((string)($in['material'] ?? ''));

            if ($type !== '' && !in_array($type, BOAT_TYPES, true) && $type !== (string)$boat['boat_type']) fail('Choose a boat type from the list.');
            if ($mat  !== '' && !in_array($mat,  BOAT_MATERIALS, true) && $mat  !== (string)$boat['material'])  fail('Choose a material from the list.');

            $nf = numField($in['no_fishers'] ?? null, 1000, true);
            $len = numField($in['length_m'] ?? null, 999);
            $br  = numField($in['breadth_m'] ?? null, 999);
            $dp  = numField($in['depth_m'] ?? null, 999);
            $gt  = numField($in['gross_tonnage'] ?? null, 99999);
            $hp  = numField($in['horsepower'] ?? null, 99999);
            foreach (['Number of fishers' => $nf, 'Length' => $len, 'Breadth' => $br, 'Depth' => $dp, 'Gross tonnage' => $gt, 'Horsepower' => $hp] as $label => $v) {
                if ($v === false) fail($label . ' must be a number of zero or more.');
            }


            $env  = in_array($boat['fishing_environment'], ['Marine', 'Inland'], true) ? $boat['fishing_environment'] : 'Marine';
            $gear = [];
            $gq = $conn->prepare('SELECT environment, takes_mesh, gear_name FROM gear_catalog WHERE id = ?');
            foreach ((is_array($in['gear'] ?? null) ? $in['gear'] : []) as $g) {
                $units = (int)($g['units'] ?? 0);
                if ($units <= 0) continue;
                if ($units > 65535) fail('Gear units are too large.');
                $gid = (int)($g['gear_id'] ?? 0);
                $gq->bind_param('i', $gid); $gq->execute();
                $gr = $gq->get_result()->fetch_assoc();
                if (!$gr) fail('Unknown gear selected.');
                if ($gr['environment'] !== $env) fail($gr['gear_name'] . ' is not a ' . $env . ' gear.');
                $mesh = null;
                if ($gr['takes_mesh'] && isset($g['mesh_size_cm']) && $g['mesh_size_cm'] !== '') {
                    $mesh = numField($g['mesh_size_cm'], 999);
                    if ($mesh === false) fail('Mesh size must be a number of zero or more.');
                }
                if (isset($gear[$gid])) $gear[$gid]['units'] += $units; else $gear[$gid] = ['units' => $units, 'mesh' => $mesh];
            }
            $gq->close();

            $conn->begin_transaction();
            try {
                $u = $conn->prepare('UPDATE boats SET name=?, homeport=?, boat_type=?, material=?, no_fishers=?, length_m=?, breadth_m=?, depth_m=?, gross_tonnage=?, horsepower=?, engine_make=? WHERE id=?');
                $u->bind_param('ssssidddddsi', $name, $homeport, $type, $mat, $nf, $len, $br, $dp, $gt, $hp, $engine, $boatId);
                $u->execute(); $u->close();

                $d = $conn->prepare('DELETE FROM boat_gear WHERE boat_id = ?');
                $d->bind_param('i', $boatId); $d->execute(); $d->close();
                if ($gear) {
                    $gi = $conn->prepare('INSERT INTO boat_gear (boat_id, gear_id, units, mesh_size_cm) VALUES (?,?,?,?)');
                    foreach ($gear as $gid => $gv) { $gu = $gv['units']; $gm = $gv['mesh']; $gi->bind_param('iiid', $boatId, $gid, $gu, $gm); $gi->execute(); }
                    $gi->close();
                }
                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
            reply(['success' => true, 'message' => 'Boat details saved.']);
        }


        if ($action === 'add_crew' || $action === 'remove_crew') {
            $ffid = (int)($in['fisherfolk_id'] ?? 0);
            $st = $conn->prepare('SELECT id, municipality, boat_ownership, assigned_boat_id FROM fisherfolk WHERE id = ?');
            $st->bind_param('i', $ffid); $st->execute();
            $ff = $st->get_result()->fetch_assoc(); $st->close();
            if (!$ff || ($mun_scope !== null && $ff['municipality'] !== $mun_scope)) fail('Fisherfolk record not found, or it is outside your municipality.', 404);

            if ($action === 'add_crew') {
                if ((int)$ff['id'] === (int)$boat['owner_id']) fail('The owner cannot be added as crew on their own boat.');
                if (!in_array($ff['boat_ownership'], ['Crew', 'No boat'], true)) fail('Only fisherfolk registered as Crew or No boat can be added as crew.');
                if (!empty($ff['assigned_boat_id'])) fail('This person is already assigned to a boat. Remove them from that boat first.');
                $u = $conn->prepare("UPDATE fisherfolk SET boat_ownership = 'Crew', assigned_boat_id = ? WHERE id = ? AND (assigned_boat_id IS NULL OR assigned_boat_id = 0)");
                $u->bind_param('ii', $boatId, $ffid); $u->execute();
                $changed = $u->affected_rows; $u->close();
                if ($changed < 1) fail('Could not add this person. They may have just been assigned to another boat.');
                reply(['success' => true, 'message' => 'Crew member added.']);
            }


            if ($ff['boat_ownership'] !== 'Crew' || (int)$ff['assigned_boat_id'] !== $boatId) fail('This person is not crew on this boat.');
            $u = $conn->prepare("UPDATE fisherfolk SET assigned_boat_id = NULL WHERE id = ? AND assigned_boat_id = ? AND boat_ownership = 'Crew'");
            $u->bind_param('ii', $ffid, $boatId); $u->execute(); $u->close();
            reply(['success' => true, 'message' => 'Crew member removed from this boat.']);
        }

        fail('Unknown action.');
    }


    $boatId = (int)($_GET['id'] ?? 0);
    $boat   = $boatId ? loadBoat($conn, $boatId, $mun_scope) : null;
    if (!$boat) fail('Boat not found, or it is outside your municipality.', 404);


    if (isset($_GET['crew_search'])) {
        $q = trim((string)$_GET['crew_search']);
        $sql = "SELECT id, first_name, middle_name, last_name, boat_registry_number, barangay, municipality, boat_ownership
                  FROM fisherfolk
                 WHERE boat_ownership IN ('Crew','No boat') AND (assigned_boat_id IS NULL OR assigned_boat_id = 0) AND id <> ?";
        $types = 'i'; $args = [(int)$boat['owner_id']];
        if ($mun_scope !== null) { $sql .= ' AND municipality = ?'; $types .= 's'; $args[] = $mun_scope; }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= ' AND (first_name LIKE ? OR last_name LIKE ? OR boat_registry_number LIKE ?)';
            $types .= 'sss'; array_push($args, $like, $like, $like);
        }
        $sql .= ' ORDER BY last_name, first_name LIMIT 15';
        $st = $conn->prepare($sql); $st->bind_param($types, ...$args); $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
        $out = array_map(fn($r) => [
            'id' => (int)$r['id'],
            'name' => trim($r['last_name'] . ', ' . $r['first_name'] . ' ' . ($r['middle_name'] ?? '')),
            'registry_no' => $r['boat_registry_number'], 'barangay' => $r['barangay'],
            'municipality' => $r['municipality'], 'role' => $r['boat_ownership'],
        ], $rows);
        reply(['success' => true, 'results' => $out]);
    }


    $st = $conn->prepare("SELECT id, first_name, middle_name, last_name, suffix, boat_registry_number, contact_number, barangay, municipality
                            FROM fisherfolk WHERE assigned_boat_id = ? AND boat_ownership = 'Crew' ORDER BY last_name, first_name");
    $st->bind_param('i', $boatId); $st->execute();
    $crew = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
        $crew[] = [
            'id' => (int)$c['id'],
            'name' => trim($c['last_name'] . ', ' . $c['first_name'] . ' ' . ($c['middle_name'] ?? '') . ' ' . ($c['suffix'] ?? '')),
            'registry_no' => $c['boat_registry_number'], 'contact' => $c['contact_number'],
            'barangay' => $c['barangay'], 'municipality' => $c['municipality'],
            'can_remove' => $mun_scope === null || $c['municipality'] === $mun_scope,
        ];
    }
    $st->close();


    $env = in_array($boat['fishing_environment'], ['Marine', 'Inland'], true) ? $boat['fishing_environment'] : 'Marine';
    $st = $conn->prepare('SELECT bg.gear_id, gc.gear_name AS name, bg.units, bg.mesh_size_cm, gc.takes_mesh
                            FROM boat_gear bg JOIN gear_catalog gc ON gc.id = bg.gear_id
                           WHERE bg.boat_id = ? ORDER BY gc.sort_order, gc.gear_name');
    $st->bind_param('i', $boatId); $st->execute();
    $gear = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    foreach ($gear as &$g) { $g['gear_id'] = (int)$g['gear_id']; $g['units'] = (int)$g['units']; $g['takes_mesh'] = (int)$g['takes_mesh']; $g['mesh_size_cm'] = $g['mesh_size_cm'] === null ? null : (float)$g['mesh_size_cm']; }
    unset($g);

    $st = $conn->prepare('SELECT id, method, gear_name, takes_mesh FROM gear_catalog WHERE environment = ? ORDER BY sort_order, id');
    $st->bind_param('s', $env); $st->execute();
    $options = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    foreach ($options as &$o) { $o['id'] = (int)$o['id']; $o['takes_mesh'] = (int)$o['takes_mesh']; }
    unset($o);


    $boatr = null;
    $st = $conn->prepare("SELECT permit_number, status, issue_date, expiry_date FROM permits WHERE boat_id = ? AND permit_type = 'BoatR' ORDER BY issue_date DESC, id DESC LIMIT 1");
    $st->bind_param('i', $boatId); $st->execute();
    $boatr = $st->get_result()->fetch_assoc() ?: null; $st->close();

    $gt = $boat['gross_tonnage'];
    reply([
        'success' => true,
        'boat' => [
            'id' => (int)$boat['id'], 'name' => $boat['name'], 'homeport' => $boat['homeport'],
            'boat_type' => $boat['boat_type'], 'material' => $boat['material'],
            'no_fishers' => $boat['no_fishers'] === null ? null : (int)$boat['no_fishers'],
            'length_m' => $boat['length_m'] === null ? null : (float)$boat['length_m'],
            'breadth_m' => $boat['breadth_m'] === null ? null : (float)$boat['breadth_m'],
            'depth_m' => $boat['depth_m'] === null ? null : (float)$boat['depth_m'],
            'gross_tonnage' => $gt === null ? null : (float)$gt,
            'horsepower' => $boat['horsepower'] === null ? null : (float)$boat['horsepower'],
            'engine_make' => $boat['engine_make'],
            'fisher_class' => $gt === null ? '' : ((float)$gt > 3 ? 'Commercial' : 'Municipal'),
        ],
        'owner' => [
            'name' => trim($boat['last_name'] . ', ' . $boat['first_name'] . ' ' . ($boat['middle_name'] ?? '') . ' ' . ($boat['suffix'] ?? '')),
            'registry_no' => $boat['boat_registry_number'], 'contact' => $boat['contact_number'],
            'barangay' => $boat['barangay'], 'municipality' => $boat['municipality'],
            'fishing_environment' => $env,
        ],
        'crew' => $crew,
        'gear' => $gear,
        'gear_options' => $options,
        'boatr' => $boatr,
        'types' => BOAT_TYPES,
        'materials' => BOAT_MATERIALS,
    ]);
} catch (Throwable $e) {
    error_log('api_boat: ' . $e->getMessage());
    fail('Server error.', 500);
}
