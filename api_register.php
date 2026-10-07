<?php

require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/requirements.php';
require_once 'includes/barangays.php';
requireRole(MAO_ROLES, 'api');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json');

function out($ok, $msg, $extra = []) { echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra)); exit(); }

function numOrNull($v) { return (isset($v) && is_numeric($v)) ? (float)$v : null; }
function validDate($d) { $x = DateTime::createFromFormat('Y-m-d', $d); return $x && $x->format('Y-m-d') === $d; }

function normBoatType($t) {
    $k = strtolower(trim($t));
    if ($k === 'motorized' || $k === 'motorized banca')         return 'Motorized banca';
    if ($k === 'non-motorized' || $k === 'non-motorized banca') return 'Non-motorized banca';
    return trim($t);
}


function cleanDocs(array $docs, array $have, $mun, $role) {
    $req   = munRequirements($mun, $role);
    $chain = munChain($mun, $role);
    $pos   = fn($t) => ($i = array_search($t, $req, true)) === false ? 999 : $i;
    usort($docs, fn($a, $b) => $pos($a['type'] ?? '') <=> $pos($b['type'] ?? ''));
    $clean = [];
    foreach ($docs as $d) {
        $type = trim($d['type'] ?? ''); $num = trim($d['number'] ?? '');
        if (!in_array($type, $req, true)) {
            if (skippedForRole($type, $role) && in_array($type, munRequirements($mun), true)) out(false, "$type is for boat owners only.");
            out(false, "$type is not required in $mun.");
        }
        if ($num === '')                  out(false, "$type needs its document number.");
        if (in_array($type, $have, true)) out(false, "$type is already on this record.");
        $ci = array_search($type, $chain, true);
        if ($ci !== false && $ci > 0 && !in_array($chain[$ci - 1], $have, true)) out(false, "$type needs " . $chain[$ci - 1] . " first.");
        $issue  = trim($d['issue'] ?? '')  ?: date('Y-m-d');
        $expiry = trim($d['expiry'] ?? '') ?: null;
        if (!validDate($issue) || ($expiry !== null && !validDate($expiry))) out(false, "$type has an invalid date.");
        $clean[] = ['type' => $type, 'number' => $num, 'issue' => $issue, 'expiry' => $expiry];
        $have[] = $type;
    }
    return $clean;
}

function insertDoc($conn, $ffid, $boatId, $d, $uid) {

    if (docKind($d['type']) === 'support') {

        $st = $conn->prepare("INSERT INTO registration_documents (fisherfolk_id,doc_type,doc_number,issue_date,expiry_date,issued_by) VALUES (?,?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE doc_number = VALUES(doc_number), issue_date = VALUES(issue_date), expiry_date = VALUES(expiry_date), issued_by = VALUES(issued_by)");
        $st->bind_param('issssi', $ffid, $d['type'], $d['number'], $d['issue'], $d['expiry'], $uid);
        $st->execute(); $st->close();
        return;
    }
    $dup = $conn->prepare("SELECT id FROM permits WHERE permit_type = ? AND permit_number = ? LIMIT 1");
    $dup->bind_param('ss', $d['type'], $d['number']); $dup->execute();
    if ($dup->get_result()->fetch_assoc()) { $dup->close(); throw new RuntimeException("{$d['type']} No. {$d['number']} is already registered to another record."); }
    $dup->close();

    $status = ($d['expiry'] === null || $d['expiry'] >= date('Y-m-d')) ? 'Active' : 'Expired';
    $bid = $d['type'] === 'BoatR' ? $boatId : null; $area = '';
    $st = $conn->prepare("INSERT INTO permits (fisherfolk_id,boat_id,permit_number,permit_type,fishing_area,issue_date,expiry_date,status,issued_by) VALUES (?,?,?,?,?,?,?,?,?)");
    $st->bind_param('iissssssi', $ffid, $bid, $d['number'], $d['type'], $area, $d['issue'], $d['expiry'], $status, $uid);
    $st->execute(); $st->close();
}

function saveSignature($dataUrl) {
    $p = 'data:image/png;base64,';
    if (strncmp($dataUrl, $p, strlen($p)) !== 0) return null;
    $bin = base64_decode(substr($dataUrl, strlen($p)), true);
    if ($bin === false || strlen($bin) > 300000 || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    $dir = __DIR__ . '/uploads/signatures';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return null;
    $name = bin2hex(random_bytes(12)) . '.png';
    return file_put_contents("$dir/$name", $bin) !== false ? $name : null;
}


function savePhoto($dataUrl) {
    $p = 'data:image/jpeg;base64,';
    if (!is_string($dataUrl) || strncmp($dataUrl, $p, strlen($p)) !== 0) return null;
    $bin = base64_decode(substr($dataUrl, strlen($p)), true);
    if ($bin === false || strlen($bin) > 400000 || substr($bin, 0, 3) !== "\xFF\xD8\xFF") return null;
    $dir = __DIR__ . '/uploads/photos';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return null;
    $name = bin2hex(random_bytes(12)) . '.jpg';
    return file_put_contents("$dir/$name", $bin) !== false ? $name : null;
}

function summary($conn, $ffid) {
    $ff = $conn->query("SELECT f.id,f.first_name,f.last_name,f.middle_name,f.municipality,f.barangay,f.boat_registry_number,f.boat_ownership,
                               (SELECT rc.uid FROM rfid_cards rc WHERE rc.fisherfolk_id = f.id AND rc.status = 'Active' LIMIT 1) AS rfid_uid
                        FROM fisherfolk f WHERE f.id = " . (int)$ffid)->fetch_assoc();
    $hold = holdTextFor($conn, [['id' => (int)$ffid, 'municipality' => $ff['municipality'], 'boat_ownership' => $ff['boat_ownership']]])[(int)$ffid];
    return ['ff_id' => (int)$ff['id'], 'boat_reg' => $ff['boat_registry_number'], 'rfid_uid' => $ff['rfid_uid'],
        'first_name' => $ff['first_name'], 'last_name' => $ff['last_name'], 'middle_name' => $ff['middle_name'],
        'municipality' => $ff['municipality'], 'barangay' => $ff['barangay'], 'hold' => $hold, 'complete' => $hold === 'Complete'];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); out(false, 'POST required'); }

$user      = getCurrentUser();
$mun_scope = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;

$input = $_POST;
if (empty($input)) { $decoded = json_decode(file_get_contents('php://input'), true); if (is_array($decoded)) $input = $decoded; }


if (($input['action'] ?? '') === 'add_document') {
    $ffid = (int)($input['ff_id'] ?? 0);
    $ff = $conn->query("SELECT id, municipality, boat_ownership FROM fisherfolk WHERE id = $ffid")->fetch_assoc();
    if (!$ff) out(false, 'Record not found.');
    if ($mun_scope && $ff['municipality'] !== $mun_scope) out(false, "You can only update records in {$mun_scope}.");
    $have = heldTypes($conn, $ffid);
    $docs = cleanDocs([$input['document'] ?? []], $have, $ff['municipality'], $ff['boat_ownership']);
    $boat = $conn->query("SELECT id FROM boats WHERE fisherfolk_id = $ffid ORDER BY id LIMIT 1")->fetch_assoc();
    try {
        $conn->begin_transaction();
        insertDoc($conn, $ffid, $boat['id'] ?? null, $docs[0], $user['id']);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); out(false, $e instanceof RuntimeException ? $e->getMessage() : 'Could not save the document.'); }
    out(true, 'Document added.', summary($conn, $ffid));
}


$d = [];
foreach (['first_name','last_name','middle_name','birthdate','sex','civil_status','contact_number','address','barangay','municipality',
          'fishing_environment','fishing_ground','fma_no','boat_ownership','emergency_name','emergency_relationship','emergency_contact',
          'witness','boat_name','homeport','boat_type','boat_material','engine_make','client_id','signed_at','rfid_uid'] as $f) $d[$f] = trim($input[$f] ?? '');
$d['rfid_uid'] = strtoupper($d['rfid_uid']);

if ($mun_scope && $d['municipality'] !== $mun_scope) out(false, "You can only register fisherfolk in {$mun_scope}.");
if (!$d['first_name'] || !$d['last_name'] || !$d['municipality']) out(false, 'Please fill in all required fields.');
if (!isset(MUN_CODES[$d['municipality']])) out(false, 'Unknown municipality.');

if ($d['barangay'] !== '') {
    $brgy = canonicalBarangay($d['municipality'], $d['barangay']);
    if ($brgy === null) out(false, 'Choose a barangay from the list for ' . $d['municipality'] . '.');
    $d['barangay'] = $brgy;
}
if ($d['birthdate'] !== '' && !validDate($d['birthdate'])) out(false, 'Invalid birthdate.');
if (!in_array($d['sex'], ['Male', 'Female'], true)) out(false, 'Select the applicant\'s sex.');
if ($d['civil_status'] !== '' && !in_array($d['civil_status'], ['Single', 'Married', 'Widowed', 'Separated'], true)) out(false, 'Invalid civil status.');
$civil = $d['civil_status'] !== '' ? $d['civil_status'] : null;
$env = in_array($d['fishing_environment'], ['Marine', 'Inland'], true) ? $d['fishing_environment'] : 'Marine';
$years = null;
if (isset($input['years_fishing']) && $input['years_fishing'] !== '') {
    if (!is_numeric($input['years_fishing']) || $input['years_fishing'] < 0 || $input['years_fishing'] > 100) out(false, 'Years fishing must be between 0 and 100.');
    $years = (int)$input['years_fishing'];
}
$d['boat_type'] = normBoatType($d['boat_type']);

$cannot_sign = !empty($input['cannot_sign']) ? 1 : 0;
if ($cannot_sign && $d['witness'] === '') out(false, 'Enter the witness name for a thumbmark on hard copy.');
if (!$cannot_sign && empty($input['signature'])) out(false, 'The applicant signature is required.');
$signed_at = ($d['signed_at'] !== '' && strtotime($d['signed_at'])) ? date('Y-m-d H:i:s', strtotime($d['signed_at'])) : date('Y-m-d H:i:s');


$role = $d['boat_ownership'] === 'Owner' ? 'Owner' : ($d['boat_ownership'] === 'Crew' ? 'Crew' : 'No boat');
if ($role === 'Owner' && $d['boat_name'] === '') out(false, 'Enter the boat name for a boat owner, or set the boat role to Crew or No boat.');
$docs = cleanDocs(is_array($input['documents'] ?? null) ? $input['documents'] : [], [], $d['municipality'], $role);
$doc_types = array_column($docs, 'type');
if (in_array('BoatR', $doc_types, true) && $d['boat_name'] === '') out(false, 'Enter the boat name to record a BoatR.');


if ($d['client_id'] !== '') {
    $st = $conn->prepare("SELECT id FROM fisherfolk WHERE client_id = ?"); $st->bind_param('s', $d['client_id']); $st->execute();
    $dupe = $st->get_result()->fetch_assoc(); $st->close();
    if ($dupe) out(true, 'Already saved.', summary($conn, $dupe['id']) + ['duplicate' => true]);
}
if ($d['rfid_uid'] !== '') {

    $st = $conn->prepare("SELECT f.first_name, f.last_name, rc.status FROM rfid_cards rc JOIN fisherfolk f ON f.id = rc.fisherfolk_id WHERE rc.uid = ?");
    $st->bind_param('s', $d['rfid_uid']); $st->execute();
    $ex = $st->get_result()->fetch_assoc(); $st->close();
    if ($ex) out(false, $ex['status'] === 'Active'
        ? "That RFID card is already linked to {$ex['first_name']} {$ex['last_name']}. Scan a different card or leave it unlinked."
        : "That RFID card is already on file ({$ex['status']}) for {$ex['first_name']} {$ex['last_name']}. Scan a different card or leave it unlinked.");
}


$gear = [];
foreach ((is_array($input['gear'] ?? null) ? $input['gear'] : []) as $g) {
    $u = (int)($g['units'] ?? 0);
    if ($u <= 0) continue;
    $gid = (int)($g['gear_id'] ?? 0);
    if (!$gid) out(false, 'The gear entry "' . trim($g['name'] ?? 'unknown') . '" is missing its catalog ID. Please select the gear again.');
    if ($u > 65535) out(false, 'Gear units are too large.');
    $gq = $conn->prepare("SELECT environment, takes_mesh, gear_name FROM gear_catalog WHERE id = ?");
    $gq->bind_param('i', $gid); $gq->execute();
    $grow = $gq->get_result()->fetch_assoc(); $gq->close();
    if (!$grow) out(false, 'Unknown gear selected.');
    if ($grow['environment'] !== $env) out(false, "{$grow['gear_name']} is not a {$env} gear.");
    $mesh = $grow['takes_mesh'] ? numOrNull($g['mesh_size_cm'] ?? null) : null;
    if (isset($gear[$gid])) { $gear[$gid]['units'] += $u; }
    else $gear[$gid] = ['units' => $u, 'mesh' => $mesh];
}


$hasBoat = $d['boat_name'] !== '' || $d['boat_type'] !== '' || $d['homeport'] !== '' || $gear;
$gt = numOrNull($input['gross_tonnage'] ?? null);


$assignedBoat = null;
if ($hasBoat) {
    $ownership = 'Owner';
} else {
    $ownership = $d['boat_ownership'] === 'Crew' ? 'Crew' : 'No boat';
    if ($ownership === 'Crew' && !empty($input['assigned_boat_id'])) {
        $bid = (int)$input['assigned_boat_id'];
        $bq = $conn->prepare("SELECT id FROM boats WHERE id = ?");
        $bq->bind_param('i', $bid); $bq->execute();
        if (!$bq->get_result()->fetch_assoc()) { $bq->close(); out(false, 'The selected boat was not found.'); }
        $bq->close();
        $assignedBoat = $bid;
    }
}

$code = MUN_CODES[$d['municipality']]; $year = (int)date('Y'); $sigFile = null; $photoFile = null;
try {
    $conn->begin_transaction();
    if (!$cannot_sign) { $sigFile = saveSignature($input['signature']); if (!$sigFile) throw new RuntimeException('The signature could not be saved. Please sign again.'); }

    $boat_reg = $code . '-VES-' . $year . '-' . str_pad(nextSeq($conn, $code, $year), 4, '0', STR_PAD_LEFT);
    $rfid = $d['rfid_uid'] !== '' ? $d['rfid_uid'] : null; $cid = $d['client_id'] !== '' ? $d['client_id'] : null;
    $bd = $d['birthdate'] !== '' ? $d['birthdate'] : null; $wit = $cannot_sign ? $d['witness'] : null;

    $st = $conn->prepare("INSERT INTO fisherfolk (first_name,last_name,middle_name,birthdate,sex,civil_status,contact_number,address,barangay,municipality,years_fishing,fishing_environment,fishing_ground,fma_no,boat_ownership,assigned_boat_id,emergency_name,emergency_relationship,emergency_contact,boat_registry_number,registered_by,client_id,signature_path,cannot_sign,witness_name,consent_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->bind_param(str_repeat('s', 10) . 'i' . 'ssss' . 'i' . 'ssss' . 'i' . 'ss' . 'i' . 'ss',
        $d['first_name'], $d['last_name'], $d['middle_name'], $bd, $d['sex'], $civil, $d['contact_number'], $d['address'], $d['barangay'], $d['municipality'],
        $years, $env, $d['fishing_ground'], $d['fma_no'], $ownership, $assignedBoat,
        $d['emergency_name'], $d['emergency_relationship'], $d['emergency_contact'], $boat_reg,
        $user['id'], $cid, $sigFile, $cannot_sign, $wit, $signed_at);
    $st->execute(); $ffid = $conn->insert_id; $st->close();


    if (!empty($input['photo'])) {
        $photoFile = savePhoto($input['photo']);
        if ($photoFile) {
            $pp = $conn->prepare("UPDATE fisherfolk SET photo_path = ? WHERE id = ?");
            $pp->bind_param('si', $photoFile, $ffid); $pp->execute(); $pp->close();
        }
    }

    $boatId = null;
    if ($hasBoat) {
        $len = numOrNull($input['length_m'] ?? null); $br = numOrNull($input['breadth_m'] ?? null); $dp = numOrNull($input['depth_m'] ?? null);
        $hp = numOrNull($input['horsepower'] ?? null); $nf = isset($input['no_fishers']) && is_numeric($input['no_fishers']) ? (int)$input['no_fishers'] : null;
        $st = $conn->prepare("INSERT INTO boats (fisherfolk_id,name,homeport,boat_type,material,no_fishers,length_m,breadth_m,depth_m,gross_tonnage,horsepower,engine_make) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->bind_param('issssidddd' . 'ds', $ffid, $d['boat_name'], $d['homeport'], $d['boat_type'], $d['boat_material'], $nf, $len, $br, $dp, $gt, $hp, $d['engine_make']);
        $st->execute(); $boatId = $conn->insert_id; $st->close();
        $up = $conn->prepare("UPDATE fisherfolk SET assigned_boat_id = ? WHERE id = ?");
        $up->bind_param('ii', $boatId, $ffid); $up->execute(); $up->close();
        $gs = $conn->prepare("INSERT INTO boat_gear (boat_id, gear_id, units, mesh_size_cm) VALUES (?,?,?,?)");
        foreach ($gear as $gid => $gv) { $gu = $gv['units']; $gm = $gv['mesh']; $gs->bind_param('iiid', $boatId, $gid, $gu, $gm); $gs->execute(); }
        $gs->close();
    }
    if ($rfid !== null) {
        $rs = $conn->prepare("INSERT INTO rfid_cards (fisherfolk_id, uid, status, issued_by) VALUES (?, ?, 'Active', ?)");
        $rs->bind_param('isi', $ffid, $rfid, $user['id']); $rs->execute(); $rs->close();
    }
    foreach ($docs as $doc) insertDoc($conn, $ffid, $boatId, $doc, $user['id']);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    if ($sigFile) @unlink(__DIR__ . '/uploads/signatures/' . $sigFile);
    if ($photoFile) @unlink(__DIR__ . '/uploads/photos/' . $photoFile);

    if ($cid && $e instanceof mysqli_sql_exception && $e->getCode() === 1062 && (strpos($e->getMessage(), 'uq_fisherfolk_client') !== false || strpos($e->getMessage(), 'client_id') !== false)) {
        $r = $conn->query("SELECT id FROM fisherfolk WHERE client_id = '" . $conn->real_escape_string($cid) . "'")->fetch_assoc();
        if ($r) out(true, 'Already saved.', summary($conn, $r['id']) + ['duplicate' => true]);
    }
    if ($e instanceof mysqli_sql_exception && strpos($e->getMessage(), 'rfid') !== false) out(false, 'That RFID card was just linked to another fisherfolk record. Scan a different card or leave it unlinked.');
    out(false, $e instanceof RuntimeException ? $e->getMessage() : 'Could not save the registration. Nothing was saved; please try again.');
}

$sum = summary($conn, $ffid);
out(true, "Fisherfolk registered · {$sum['hold']}" . ($rfid ? ' · RFID linked' : ''), $sum);