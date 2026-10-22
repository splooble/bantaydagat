<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/qr_crypto.php';
require_once 'includes/transfers.php';


if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit();
}

$officer_name = $_SESSION['user_name'] ?? null;
$officer_role = $_SESSION['user_role'] ?? null;
$officer_id   = $_SESSION['user_id']   ?? null;
$is_cg        = ($officer_role === 'coastguard');


const BOAT_JOIN = "LEFT JOIN boats b ON b.id = COALESCE(f.assigned_boat_id, (SELECT MIN(x.id) FROM boats x WHERE x.fisherfolk_id = f.id))";



function logScan($conn, $officer_id, $officer_name, $fisherfolk_id, $brn, $scan_type, $violation_id, $result, $municipality)
{
    $oid  = $officer_id    ? (int)$officer_id    : 'NULL';
    $ffid = $fisherfolk_id ? (int)$fisherfolk_id : 'NULL';
    $oname = $conn->real_escape_string($officer_name   ?? 'Unknown');
    $brn   = $conn->real_escape_string($brn            ?? '');
    $stype = $conn->real_escape_string($scan_type);
    $vid   = $violation_id ? (int)$violation_id : 'NULL';
    $res   = $conn->real_escape_string($result);
    $mun   = $municipality  ? "'" . $conn->real_escape_string($municipality) . "'"  : 'NULL';
    $conn->query("INSERT INTO scan_logs
        (scanned_by_user,scanned_by_name,fisherfolk_id,boat_registry_number,scan_type,violation_id,result,municipality)
        VALUES ($oid,'$oname',$ffid,'$brn','$stype',$vid,'$res',$mun)");
}


$__api = isset($_GET['token']) || isset($_GET['brn']) || isset($_GET['q']) || $_SERVER['REQUEST_METHOD'] === 'POST';
requireRole(OFFICER_ROLES, $__api ? 'api' : 'page', 'login.php');


if (isset($_GET['q'])) {
    header('Content-Type: application/json');
    $q = trim($_GET['q']);
    $tokens = array_slice(array_values(array_filter(preg_split('/\s+/', $q))), 0, 4);
    if (strlen($q) < 3 || !$tokens) { echo json_encode(['results' => []]); exit(); }
    $sql = "SELECT f.first_name, f.middle_name, f.last_name, f.barangay, f.municipality, b.name AS boat_name, f.boat_registry_number
            FROM fisherfolk f " . BOAT_JOIN . " WHERE 1=1";
    $types = ''; $args = [];
    foreach ($tokens as $t) {
        $sql .= " AND CONCAT_WS(' ', f.first_name, f.middle_name, f.last_name, f.barangay, b.name, f.boat_registry_number) LIKE ?";
        $types .= 's'; $args[] = '%' . addcslashes($t, '%_\\') . '%';
    }
    $sql .= " ORDER BY f.last_name, f.first_name LIMIT 8";
    $st = $conn->prepare($sql); $st->bind_param($types, ...$args); $st->execute();
    echo json_encode(['results' => $st->get_result()->fetch_all(MYSQLI_ASSOC)]);
    exit();
}

if (isset($_GET['token']) || isset($_GET['brn'])) {
    header('Content-Type: application/json');

    $brn = null;

    if (isset($_GET['token'])) {
        try {
            $payload = decryptQRPayload(trim($_GET['token']));
            $brn     = strtoupper(trim($payload['brn']));
        } catch (RuntimeException $e) {
            logScan($conn, $officer_id, $officer_name, null, trim($_GET['token']), 'verify', null, 'not_found', null);
            echo json_encode(['error' => 'Invalid or tampered QR code. ' . $e->getMessage()]);
            exit();
        }
    } else {
        $brn = strtoupper(trim($_GET['brn']));
    }










    $tfSel = tfHas($conn, 'cols') ? ', f.is_transferee, f.id_reprint_needed' : '';
    $findFF = function ($key) use ($conn, $tfSel) {
        $lk = $conn->prepare("SELECT f.id, f.first_name, f.middle_name, f.last_name, f.suffix, f.birthdate, f.sex, f.contact_number,
               f.barangay, f.municipality, f.boat_registry_number, f.fishing_environment, f.fishing_ground,
               f.boat_ownership, f.profile_status, f.photo_path$tfSel,
               b.name AS boat_name, b.boat_type, b.material AS boat_material, b.homeport,
               b.gross_tonnage, b.horsepower, b.engine_make,
               rc.uid AS rfid_uid
          FROM fisherfolk f " . BOAT_JOIN . "
          LEFT JOIN rfid_cards rc ON rc.fisherfolk_id = f.id AND rc.status = 'Active'
         WHERE f.boat_registry_number = ? OR rc.uid = ?
         LIMIT 1");
        $lk->bind_param('ss', $key, $key);
        $lk->execute();
        $row = $lk->get_result()->fetch_assoc();
        $lk->close();
        return $row;
    };
    $ff = $findFF($brn);
    $oldCard = false;
    if (!$ff && tfHas($conn, 'history')) {
        $hq = $conn->prepare("SELECT f.boat_registry_number AS cur FROM registry_number_history h JOIN fisherfolk f ON f.id = h.fisherfolk_id WHERE h.old_number = ? LIMIT 1");
        $hq->bind_param('s', $brn); $hq->execute();
        $hr = $hq->get_result()->fetch_assoc(); $hq->close();
        if ($hr) { $ff = $findFF($hr['cur']); $oldCard = (bool)$ff; }
    }
    if ($ff) $ff['old_card'] = $oldCard;

    if (!$ff) {
        logScan($conn, $officer_id, $officer_name, null, $brn, 'verify', null, 'not_found', null);
        echo json_encode(['error' => 'No fisherfolk record found for: ' . htmlspecialchars($brn)]);
        exit();
    }

    $resolved_brn = $ff['boat_registry_number'];


    $conn->query("UPDATE permits p
                  JOIN fisherfolk f ON p.fisherfolk_id = f.id
                  SET p.status = 'Expired'
                  WHERE p.expiry_date < CURDATE() AND p.status = 'Active'
                    AND f.id = {$ff['id']}");

    $stmt = $conn->prepare("SELECT * FROM permits WHERE fisherfolk_id = ? ORDER BY issue_date DESC");
    $stmt->bind_param('i', $ff['id']);
    $stmt->execute();
    $permits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $inc_r = $conn->query("SELECT i.id, i.violation_id, v.name AS violation_type, i.status, i.reported_at
        FROM incidents i JOIN violation_catalog v ON v.id = i.violation_id
        WHERE i.fisherfolk_id={$ff['id']} AND i.status!='Resolved' ORDER BY i.reported_at DESC LIMIT 5");
    $active_incidents = $inc_r->fetch_all(MYSQLI_ASSOC);

    logScan($conn, $officer_id, $officer_name, $ff['id'], $resolved_brn, 'verify', null, 'found', $ff['municipality']);

    echo json_encode(['fisherfolk' => $ff, 'permits' => $permits, 'active_incidents' => $active_incidents]);
    exit();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report_violation') {
    header('Content-Type: application/json');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $violation_id     = (int)($_POST['violation_id']    ?? 0);
    $legacy_type      = trim($_POST['violation_type']   ?? '');
    $description      = trim($_POST['description']      ?? '');
    $location_name    = trim($_POST['location_name']    ?? '');
    $municipality     = trim($_POST['municipality']     ?? '');
    $reported_by_name = trim($_POST['reported_by_name'] ?? 'Coast Guard');
    $brn              = trim($_POST['brn']              ?? '');
    $fisherfolk_id    = (int)($_POST['fisherfolk_id']   ?? 0) ?: null;
    $lat = null;
    $lon = null;

    if (preg_match('/Lat:\s*([-\d.]+),\s*Lon:\s*([-\d.]+)/', $location_name, $m)) {
        $lat = floatval($m[1]);
        $lon = floatval($m[2]);
        $location_name = "GPS: {$lat}, {$lon}";
    }



    if (!$violation_id && $legacy_type !== '') {
        $legacy_map = [
            'Illegal Fishing' => 'Unauthorized fisheries activity',
            'Use of Banned Fishing Gear' => 'Use of prohibited fishing gear',
            'Fishing in Restricted Zone' => 'Fishing in prohibited area',
            'Fishing Without Permit' => 'Fishing without required permit/authorization',
            'Dynamite / Cyanide Fishing' => 'Destructive fishing method',
            'Trespassing in Marine Protected Area' => 'Fishing in a marine protected area',
            'Coral Reef Damage' => 'Destruction of marine habitat',
            'Illegal Anchoring' => 'Destruction of marine habitat',
            'No Permit' => 'Fishing without required permit/authorization',
            'Expired Permit' => "Expired Mayor's Permit",
            'Suspended Permit' => 'Fishing without required permit/authorization',
            'Unregistered Vessel' => 'No valid BoatR registration',
            'Other' => 'Unauthorized fisheries activity',
        ];
        $lookup = $legacy_map[$legacy_type] ?? $legacy_type;
        $q = $conn->prepare("SELECT id FROM violation_catalog WHERE name = ? LIMIT 1");
        $q->bind_param('s', $lookup); $q->execute();
        $row = $q->get_result()->fetch_assoc(); $q->close();
        if ($row) {
            $violation_id = (int)$row['id'];
            if (isset($legacy_map[$legacy_type])) $description = trim($description . ' [Legacy type: ' . $legacy_type . ']');
        }
    }

    if (!$violation_id || !$municipality) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }

    $q = $conn->prepare("SELECT id FROM violation_catalog WHERE id = ? AND active = 1");
    $q->bind_param('i', $violation_id); $q->execute();
    $valid_violation = (bool)$q->get_result()->fetch_assoc(); $q->close();
    if (!$valid_violation) {
        echo json_encode(['success' => false, 'message' => 'Unknown or inactive violation type']);
        exit();
    }

    $reported_by_user = $officer_id ? (int)$officer_id : null;
    $brn_val = $brn ?: null;
    $suspended = 0;

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare("INSERT INTO incidents
            (fisherfolk_id,boat_registry_number,violation_id,description,location_name,municipality,reported_by_name,reported_by_user,latitude,longitude)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param(
            'isissssidd',
            $fisherfolk_id,
            $brn_val,
            $violation_id,
            $description,
            $location_name,
            $municipality,
            $reported_by_name,
            $reported_by_user,
            $lat,
            $lon
        );
        $stmt->execute();
        $incident_id = $conn->insert_id;
        $stmt->close();


        if ($fisherfolk_id) {
            $pq = $conn->prepare("SELECT id FROM permits WHERE fisherfolk_id = ? AND status = 'Active'");
            $pq->bind_param('i', $fisherfolk_id); $pq->execute();
            $permit_ids = array_column($pq->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
            $pq->close();

            $up  = $conn->prepare("UPDATE permits SET status = 'Suspended' WHERE id = ?");
            $log = $conn->prepare("INSERT INTO permit_status_log (permit_id, old_status, new_status, reason, incident_id, changed_by) VALUES (?, 'Active', 'Suspended', ?, ?, ?)");
            $reason = 'Violation reported (incident #' . $incident_id . ')';
            foreach ($permit_ids as $pid) {
                $up->bind_param('i', $pid); $up->execute();
                $log->bind_param('isii', $pid, $reason, $incident_id, $reported_by_user); $log->execute();
                $suspended++;
            }
            $up->close(); $log->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        error_log('scan.php report_violation: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. The report was not saved.']);
        exit();
    }

    logScan($conn, $officer_id, $officer_name, $fisherfolk_id, $brn, 'violation', $violation_id, 'found', $municipality);

    echo json_encode(['success' => true, 'message' => 'Violation reported. Active permits suspended.', 'incident_id' => $incident_id, 'permits_suspended' => $suspended]);
    exit();
}


if (isset($_GET['action']) && $_GET['action'] === 'get_logs') {
    header('Content-Type: application/json');
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $type  = $_GET['type'] ?? 'all';
    $where = '1=1';
    if ($officer_id) {
        $where .= " AND sl.scanned_by_user = $officer_id";
    } elseif ($officer_name) {
        $n = $conn->real_escape_string($officer_name);
        $where .= " AND sl.scanned_by_name = '$n'";
    }
    if ($type !== 'all') {
        $t = $conn->real_escape_string($type);
        $where .= " AND sl.scan_type = '$t'";
    }
    $result = $conn->query("
        SELECT sl.id, sl.boat_registry_number, sl.scan_type, sl.violation_id, v.name AS violation_type,
               sl.result, sl.municipality, sl.scanned_at,
               CONCAT(f.last_name, ', ', f.first_name) AS fisherfolk_name
        FROM scan_logs sl
        LEFT JOIN fisherfolk f ON sl.fisherfolk_id = f.id
        LEFT JOIN violation_catalog v ON v.id = sl.violation_id
        WHERE $where ORDER BY sl.scanned_at DESC LIMIT $limit");
    $logs = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    echo json_encode(['logs' => $logs]);
    exit();
}



$violation_groups = [];
$vq = $conn->query("SELECT id, category_code, category_name, name FROM violation_catalog
                    WHERE active = 1 AND detection = 'officer' ORDER BY sort_order, id");
while ($vq && ($vr = $vq->fetch_assoc())) {
    $violation_groups[$vr['category_code'] . '|' . $vr['category_name']][] = $vr;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0a2f4f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="BD Scan">
    <link rel="manifest" href="manifest-scan.json">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <title>BantayDagat — RFID Verify</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/zxing-library.min.js"></script>
    <script src="offline-db.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Instrument Sans', sans-serif;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            margin: 0;
            background: #06263f;
            color: #fff;
            min-height: 100vh;
            overflow-x: hidden;
        }

        #screen-scanner {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .scanner-header {
            background: linear-gradient(135deg, #0a2f4f, #06263f);
            padding: 52px 20px 18px;
            text-align: center;
            position: relative;
            z-index: 2;
        }

        .logo-box {
            width: 56px;
            height: 56px;
            background: #fff;
            border-radius: 14px;
            padding: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            box-shadow: 0 4px 16px rgba(0,0,0, .25);
        }

        .scanner-header h1 {
            font-size: 18px;
            font-weight: 700;
            margin: 0 0 2px;
        }

        .scanner-header p {
            font-size: 12px;
            color: rgba(255, 255, 255, .45);
            margin: 0;
        }

        .rfid-wrap {
            position: relative;
            flex: 1;
            background: radial-gradient(circle at 50% 40%, #14213F, #06263f);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            min-height: 300px;
            padding: 20px;
            text-align: center;
        }

        .rfid-ring {
            width: 160px;
            height: 160px;
            border-radius: 50%;
            border: 2px solid rgba(0,107,183, .3);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            margin-bottom: 24px;
        }

        .rfid-ring::before,
        .rfid-ring::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 2px solid rgba(0,107,183, .5);
        }

        .rfid-ring.listening::before {
            animation: rfidPulse 1.8s ease-out infinite;
        }

        .rfid-ring.listening::after {
            animation: rfidPulse 1.8s ease-out infinite .6s;
        }

        @keyframes rfidPulse {
            0% {
                transform: scale(1);
                opacity: .7;
            }

            100% {
                transform: scale(1.6);
                opacity: 0;
            }
        }

        .rfid-icon-core {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #006bb7, #0a2f4f);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 20px rgba(0,107,183, .35);
        }

        .rfid-title {
            font-size: 16px;
            font-weight: 700;
            color: #fff;
            margin: 0 0 4px;
        }

        .rfid-sub {
            font-size: 12.5px;
            color: rgba(255, 255, 255, .45);
            margin: 0 0 4px;
            max-width: 260px;
        }

        #rfid-input {
            position: absolute;
            opacity: 0;
            width: 1px;
            height: 1px;
            border: none;
            padding: 0;
            pointer-events: none;
        }

        .scan-hint {
            position: relative;
            padding: 4px 20px 32px;
            text-align: center;
            font-size: 13px;
            color: rgba(255, 255, 255, .5);
        }

        .scan-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            margin-top: 8px;
        }

        .scan-status.active {
            background: rgba(34, 197, 94, .15);
            color: #86efac;
        }

        .scan-status.inactive {
            background: rgba(239, 68, 68, .15);
            color: #fca5a5;
        }

        .scan-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        .scan-status.active .scan-dot {
            animation: pulse 1s ease-in-out infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: .4
            }
        }

        .manual-btn {
            background: rgba(0,107,183, .15);
            border: 1px solid rgba(0,107,183, .35);
            color: #9ed8f5;
            font-size: 13px;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 999px;
            cursor: pointer;
            margin-top: 8px;
            display: inline-block;
            font-family: 'Instrument Sans', sans-serif;
        }

        .history-btn {
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .15);
            color: rgba(255, 255, 255, .7);
            font-size: 12px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 999px;
            cursor: pointer;
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: 'Instrument Sans', sans-serif;
        }

        #manual-panel,
        #history-panel,
        #qr-panel {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 60;
            background: rgba(13, 22, 40, .92);
            backdrop-filter: blur(12px);
            align-items: flex-end;
            justify-content: center;
        }

        #manual-panel.open,
        #history-panel.open,
        #qr-panel.open {
            display: flex;
        }

        .sheet {
            background: #1B2440;
            border-radius: 22px 22px 0 0;
            width: 100%;
            max-width: 480px;
            border-top: 1px solid rgba(255, 255, 255, .08);
            animation: sheetUp .3s cubic-bezier(.16, 1, .3, 1);
        }

        @keyframes sheetUp {
            from {
                transform: translateY(100%)
            }

            to {
                transform: translateY(0)
            }
        }

        .manual-sheet {
            padding: 24px 20px 44px;
        }

        .manual-sheet h3 {
            font-size: 16px;
            font-weight: 700;
            margin: 0 0 4px;
        }

        .manual-sheet p {
            font-size: 12px;
            color: rgba(255, 255, 255, .4);
            margin: 0 0 16px;
        }

        .qr-cam-wrap {
            position: relative;
            width: 100%;
            aspect-ratio: 1/1;
            max-height: 300px;
            background: #000;
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 14px;
        }

        #qr-video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .qr-frame {
            position: absolute;
            inset: 14%;
            border: 2.5px solid rgba(139, 92, 246, .8);
            border-radius: 12px;
            pointer-events: none;
        }

        .history-sheet {
            padding: 0 0 44px;
            max-height: 78vh;
            display: flex;
            flex-direction: column;
        }

        .history-sheet-head {
            padding: 20px 20px 0;
            flex-shrink: 0;
        }

        .tab-bar {
            display: flex;
            gap: 6px;
            margin-bottom: 14px;
        }

        .tab-btn {
            flex: 1;
            padding: 7px 0;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            font-family: 'Instrument Sans', sans-serif;
            transition: all .2s;
        }

        .tab-btn.active {
            background: #006bb7;
            color: #fff;
        }

        .tab-btn.inactive {
            background: rgba(255, 255, 255, .07);
            color: rgba(255, 255, 255, .5);
        }

        .history-list {
            flex: 1;
            overflow-y: auto;
            padding: 0 20px;
        }

        .history-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, .06);
        }

        .history-item:last-child {
            border-bottom: none;
        }

        .h-icon {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .h-icon.verify {
            background: rgba(34, 197, 94, .12);
        }

        .h-icon.violation {
            background: rgba(220, 38, 38, .12);
        }

        .h-icon.not-found {
            background: rgba(148, 163, 184, .1);
        }

        .h-name {
            font-size: 13px;
            font-weight: 600;
            color: #fff;
            margin: 0 0 2px;
        }

        .h-brn {
            font-size: 10.5px;
            color: #006bb7;
            font-family: 'JetBrains Mono', monospace;
            margin: 0 0 1px;
        }

        .h-meta {
            font-size: 10.5px;
            color: rgba(255, 255, 255, .35);
            margin: 0;
        }

        .h-badge {
            display: inline-flex;
            padding: 2px 7px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            flex-shrink: 0;
            margin-left: auto;
        }

        .h-badge.verify {
            background: rgba(34, 197, 94, .15);
            color: #86efac;
        }

        .h-badge.violation {
            background: rgba(220, 38, 38, .15);
            color: #fca5a5;
        }

        .h-badge.not-found {
            background: rgba(148, 163, 184, .1);
            color: #7d93a5;
        }

        .history-empty {
            text-align: center;
            padding: 32px 0;
            color: rgba(255, 255, 255, .3);
            font-size: 13px;
        }

        .manual-input {
            width: 100%;
            background: rgba(255, 255, 255, .06);
            border: 1.5px solid rgba(255, 255, 255, .12);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 15px;
            color: #fff;
            outline: none;
            font-family: 'JetBrains Mono', monospace;
            text-transform: uppercase;
            letter-spacing: .05em;
            transition: border .2s;
        }

        .manual-input:focus {
            border-color: #006bb7;
        }

        \1
        #manual-results { max-height: 30vh; overflow-y: auto; margin-bottom: 10px; }
        .mr-row { display: block; width: 100%; text-align: left; background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.14); color: #fff; border-radius: 10px; padding: 10px 12px; margin-bottom: 6px; cursor: pointer; font-family: inherit; }
        .mr-row strong { display: block; font-size: 13.5px; }
        .mr-row span { display: block; font-size: 11.5px; color: rgba(255,255,255,.6); margin-top: 2px; }
        .mr-note { font-size: 12.5px; color: rgba(255,255,255,.6); margin: 4px 0 8px; }

        .btn-verify {
            width: 100%;
            background: linear-gradient(135deg, #006bb7, #0a2f4f);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 13px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 12px;
            font-family: 'Instrument Sans', sans-serif;
            box-shadow: 0 4px 16px rgba(0,107,183, .35);
        }

        .btn-cancel {
            width: 100%;
            background: transparent;
            border: 1px solid rgba(255, 255, 255, .1);
            color: rgba(255, 255, 255, .4);
            border-radius: 10px;
            padding: 12px;
            font-size: 14px;
            cursor: pointer;
            margin-top: 8px;
            font-family: 'Instrument Sans', sans-serif;
        }

        #screen-loading {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 70;
            background: #06263f;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 16px;
        }

        #screen-loading.show {
            display: flex;
        }

        .spinner {
            width: 44px;
            height: 44px;
            border: 3px solid rgba(0,107,183, .2);
            border-top-color: #006bb7;
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg)
            }
        }

        #screen-result {
            display: none;
            min-height: 100vh;
            background: linear-gradient(145deg, #eaf6fd, #f4f9fc);
            flex-direction: column;
            overflow-y: auto;
        }

        #screen-result.show {
            display: flex;
        }

        .result-header {
            background: linear-gradient(135deg, #0a2f4f, #006bb7);
            padding: 20px 20px 22px;
            position: relative;
            flex-shrink: 0;
        }

        .back-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #fff;
            background: rgba(255, 255, 255, .12);
            border: none;
            border-radius: 10px;
            width: 36px;
            height: 36px;
            margin-bottom: 16px;
        }

        .result-header-inner {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .avatar {
            width: 80px;
            height: 80px;
            overflow: hidden;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(255, 255, 255, .25), rgba(255, 255, 255, .1));
            border: 2px solid rgba(255, 255, 255, .3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        .result-name {
            font-size: 19px;
            font-weight: 700;
            color: #fff;
            margin: 0 0 2px;
            line-height: 1.2;
        }

        .result-sub {
            font-size: 12px;
            color: rgba(255, 255, 255, .55);
            margin: 0;
        }

        .brn-tag {
            display: inline-block;
            background: rgba(255, 255, 255, .12);
            border: 1px solid rgba(255, 255, 255, .2);
            border-radius: 999px;
            padding: 3px 10px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            color: rgba(255, 255, 255, .8);
            margin-top: 8px;
        }

        .enc-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: rgba(34, 197, 94, .15);
            border: 1px solid rgba(34, 197, 94, .25);
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 10px;
            font-weight: 600;
            color: #86efac;
            margin-top: 6px;
        }

        .status-banner {
            margin: 0 16px;
            transform: translateY(-16px);
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, .1);
        }

        .status-banner.all-clear {
            background: #DCFCE7;
        }

        .status-banner.has-suspended {
            background: #FEF3C7;
        }

        .status-banner.has-expired,
        .status-banner.has-incident,
        .status-banner.no-permits {
            background: #FEE2E2;
        }

        .sdot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .all-clear .sdot {
            background: #16A34A;
        }

        .has-suspended .sdot {
            background: #D97706;
            animation: pulse 1.5s infinite;
        }

        .has-expired .sdot,
        .no-permits .sdot,
        .has-incident .sdot {
            background: #DC2626;
        }

        .stext {
            font-size: 13px;
            font-weight: 700;
        }

        .all-clear .stext {
            color: #15803D;
        }

        .has-suspended .stext {
            color: #B45309;
        }

        .has-expired .stext,
        .no-permits .stext,
        .has-incident .stext {
            color: #B91C1C;
        }

        .ssub {
            font-size: 11px;
            color: rgba(0, 0, 0, .4);
            margin-top: 1px;
        }

        .result-body {
            flex: 1;
            padding: 0 16px 40px;
        }

        .info-card {
            background: rgba(255, 255, 255, .78);
            border: 1px solid rgba(213,230,241, .7);
            border-radius: 14px;
            margin-bottom: 14px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(10,47,79, .06);
        }

        .info-card-header {
            padding: 12px 16px;
            font-size: 10.5px;
            font-weight: 700;
            color: #006bb7;
            text-transform: uppercase;
            letter-spacing: .08em;
            border-bottom: 1px solid rgba(213,230,241, .5);
            background: rgba(234,246,253, .4);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 11px 16px;
            border-bottom: 1px solid rgba(213,230,241, .3);
            gap: 12px;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-size: 11px;
            color: #5a7185;
            font-weight: 600;
            flex-shrink: 0;
        }

        .info-value {
            font-size: 13px;
            color: #16324a;
            font-weight: 500;
            text-align: right;
            word-break: break-word;
        }

        .mono {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: #006bb7;
            font-weight: 600;
        }

        .permit-item {
            padding: 14px 16px;
            border-bottom: 1px solid rgba(213,230,241, .3);
        }

        .permit-item:last-child {
            border-bottom: none;
        }

        .permit-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 5px;
        }

        .permit-name {
            font-size: 13.5px;
            font-weight: 600;
            color: #16324a;
            flex: 1;
            line-height: 1.3;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            flex-shrink: 0;
            white-space: nowrap;
        }

        .badge-active {
            background: #DCFCE7;
            color: #15803D;
        }

        .badge-expired {
            background: #FEE2E2;
            color: #B91C1C;
        }

        .badge-suspended {
            background: #FEF3C7;
            color: #B45309;
        }

        .permit-meta {
            font-size: 11px;
            color: #7d93a5;
            font-family: 'JetBrains Mono', monospace;
        }

        .permit-area {
            font-size: 11.5px;
            color: #5a7185;
            margin-top: 3px;
        }

        .verified-footer {
            text-align: center;
            padding: 16px 20px 8px;
            font-size: 11px;
            color: #7d93a5;
        }

        .verified-stamp {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, .6);
            border: 1px solid rgba(213,230,241, .6);
            border-radius: 999px;
            padding: 5px 14px;
            font-size: 11px;
            color: #5a7185;
            margin-bottom: 4px;
        }

        .incident-warning {
            background: #FEF2F2;
            border: 1px solid #FCA5A5;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 10px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .incident-warning-title {
            font-size: 12px;
            font-weight: 700;
            color: #B91C1C;
            margin: 0 0 2px;
        }

        .incident-warning-sub {
            font-size: 11px;
            color: #DC2626;
            margin: 0;
        }

        #screen-error {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 80;
            background: #06263f;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            padding: 40px 24px;
            text-align: center;
        }

        #screen-error.show {
            display: flex;
        }

        .error-icon {
            width: 72px;
            height: 72px;
            background: rgba(220, 38, 38, .12);
            border: 2px solid rgba(220, 38, 38, .25);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .btn-retry {
            background: linear-gradient(135deg, #006bb7, #0a2f4f);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 13px 32px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Instrument Sans', sans-serif;
        }




        select { color-scheme: dark; }
        select option, select optgroup { background-color: #1B2440; color: #fff; }
        select optgroup { color: #9fb0d0; font-style: normal; }
    </style>
</head>

<body>


    <div id="screen-scanner">
        <div class="scanner-header">
            <?php if ($is_cg): ?>
                <div style="position:absolute;top:0;left:0;right:0;background:rgba(10,31,61,.85);padding:10px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,.08);">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#1A3A6B,#0A1F3D);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;color:#fff;flex-shrink:0;"><?= strtoupper(substr($officer_name, 0, 1)) ?></div>
                        <div>
                            <p style="font-size:11px;font-weight:600;color:#fff;margin:0;line-height:1.2;"><?= htmlspecialchars($officer_name) ?></p>
                            <p style="font-size:9.5px;color:rgba(255,255,255,.4);margin:0;">Philippine Coast Guard</p>
                        </div>
                    </div>
                    <a href="scan.php?logout=1" style="font-size:11px;color:rgba(248,113,113,.75);font-weight:600;text-decoration:none;padding:4px 10px;border:1px solid rgba(248,113,113,.25);border-radius:6px;">Sign Out</a>
                </div>
            <?php endif; ?>
            <div class="logo-box"><img src="landing/images/antique-coastal-emblem.jpg" alt="Bantay Dagat emblem" style="width:100%;height:100%;object-fit:contain;mix-blend-mode:multiply;"></div>
            <h1>BantayDagat: Connect</h1>
            <p><?= $is_cg ? 'Coast Guard Permit Verification' : 'Permit Verification — Tap RFID tag on reader' ?></p>
            <div id="net-status-row" style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:8px;flex-wrap:wrap;">
                <span id="net-status-pill" style="display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:999px;background:rgba(34,197,94,.18);color:#86efac;border:1px solid rgba(134,239,172,.3);">● Online</span>
                <span id="cache-sync-pill" style="font-size:10px;color:rgba(255,255,255,.4);"></span>
            </div>
        </div>
        <div id="violation-queue-banner" style="display:none;align-items:center;gap:8px;background:#FEF3C7;border-bottom:1px solid #FCD34D;color:#92400E;font-size:12px;font-weight:600;padding:9px 16px;">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <span id="violation-queue-text">0 violation reports waiting to sync</span>
        </div>
        <div class="rfid-wrap" onclick="focusRFIDInput()">
            <input id="rfid-input" type="text" inputmode="none" autocomplete="off" autocapitalize="off" spellcheck="false" aria-hidden="true" tabindex="-1">
            <div class="rfid-ring listening" id="rfid-ring">
                <div class="rfid-icon-core">
                    <svg width="42" height="42" fill="none" stroke="#fff" stroke-width="1.6" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 6.5a7 7 0 010 11M4 4a10.5 10.5 0 010 16M17.5 6.5a7 7 0 010 11M20 4a10.5 10.5 0 010 16" />
                        <circle cx="12" cy="12" r="1.6" fill="#fff" stroke="none" />
                    </svg>
                </div>
            </div>
            <p class="rfid-title" id="rfid-title">Ready to Scan</p>
            <p class="rfid-sub">Tap or hold the fisherfolk's RFID tag against the reader</p>
            <div class="scan-hint">
                <div id="scan-status" class="scan-status inactive">
                    <span class="scan-dot"></span>
                    <span id="scan-status-text">Reader not focused</span>
                </div><br>
                <button class="manual-btn" onclick="openManual()">✎ &nbsp;Enter number or search by name</button><br>
                <button class="manual-btn" style="background:rgba(139,92,246,.15);border-color:rgba(139,92,246,.35);color:#C4B5FD;" onclick="openQRFallback()">▦ &nbsp;Scan QR code instead</button><br>
                <button class="history-btn" onclick="openHistory()">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Scan History
                </button>
            </div>
        </div>
    </div>


    <div id="manual-panel">
        <div class="sheet manual-sheet">
            <div style="width:36px;height:4px;background:rgba(255,255,255,.15);border-radius:2px;margin:0 auto 20px;"></div>
            <h3>Manual Verification</h3>
            <p>Enter the registry number or card number, or search by name</p>
            <input type="text" id="manual-brn" class="manual-input" placeholder="Registry no., or name (e.g. Juan Dela Cruz)" maxlength="60" autocomplete="off">
            <div id="manual-results"></div>
            <button class="btn-verify" onclick="manualVerify()">Verify Permit</button>
            <button class="btn-cancel" onclick="closeManual()">Cancel</button>
        </div>
    </div>


    <div id="history-panel">
        <div class="sheet history-sheet">
            <div class="history-sheet-head">
                <div style="width:36px;height:4px;background:rgba(255,255,255,.15);border-radius:2px;margin:0 auto 18px;"></div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                    <h3 style="margin:0;font-size:16px;font-weight:700;">Scan History</h3>
                    <button onclick="closeHistory()" style="background:none;border:none;color:rgba(255,255,255,.4);cursor:pointer;font-size:22px;line-height:1;">&times;</button>
                </div>
                <div class="tab-bar">
                    <button class="tab-btn active" id="tab-all" onclick="switchTab('all')">All</button>
                    <button class="tab-btn inactive" id="tab-verify" onclick="switchTab('verify')">Verifications</button>
                    <button class="tab-btn inactive" id="tab-violation" onclick="switchTab('violation')">Violations</button>
                </div>
            </div>
            <div class="history-list" id="history-list">
                <div class="history-empty">Loading...</div>
            </div>
        </div>
    </div>


    <div id="qr-panel">
        <div class="sheet manual-sheet">
            <div style="width:36px;height:4px;background:rgba(255,255,255,.15);border-radius:2px;margin:0 auto 20px;"></div>
            <h3>QR Code (Fallback)</h3>
            <p>Point the camera at the QR code printed on the fisherfolk's ID card</p>
            <div class="qr-cam-wrap">
                <video id="qr-video" playsinline autoplay muted></video>
                <div class="qr-frame"></div>
            </div>
            <p id="qr-status" style="font-size:11.5px;color:rgba(255,255,255,.4);text-align:center;margin:0 0 14px;">Starting camera…</p>
            <button class="btn-cancel" onclick="closeQRFallback()">Cancel</button>
        </div>
    </div>


    <div id="screen-loading">
        <div class="spinner"></div>
        <p style="font-size:13px;color:rgba(255,255,255,.4);margin:0;">Verifying permit...</p>
    </div>


    <div id="screen-result">
        <div class="result-header">
            <button class="back-btn" onclick="resetToScanner()">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </button>
            <div class="result-header-inner">
                <div class="avatar" id="r-avatar"></div>
                <div>
                    <p class="result-name" id="r-name"></p>
                    <p class="result-sub" id="r-sub"></p>
                    <div class="brn-tag" id="r-brn"></div><br>
                    <div id="r-oldcard" style="display:none;margin:6px 0 8px;background:#FEF3C7;color:#92400E;border-radius:8px;padding:6px 10px;font-size:12px;font-weight:700;"></div>
                    <div class="enc-badge" id="r-rfid-badge" style="display:none;">
                        <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 6.5a7 7 0 010 11M4 4a10.5 10.5 0 010 16M17.5 6.5a7 7 0 010 11M20 4a10.5 10.5 0 010 16" />
                        </svg>
                        RFID Tag Verified
                    </div>
                    <div class="enc-badge" id="r-qr-badge" style="display:none;background:rgba(139,92,246,.15);color:#C4B5FD;border-color:rgba(196,181,253,.3);">
                        <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v4m0 0h4m-4 0L8 4m4 4v12m8-16v4m0 0h-4m4 0l-4-4M4 8v12a2 2 0 002 2h12a2 2 0 002-2V8" />
                        </svg>
                        QR Code Verified
                    </div>
                    <div class="enc-badge" id="r-offline-badge" style="display:none;background:rgba(251,191,36,.15);color:#FCD34D;border-color:rgba(252,211,77,.3);">
                        <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.121-2.121m2.121 2.121L21 21M5.636 5.636a9 9 0 000 12.728m9.9-2.121a5 5 0 00-7.072 0M9 9l6 6" />
                        </svg>
                        From Offline Cache
                    </div>
                </div>
            </div>
        </div>
        <div class="status-banner" id="r-banner">
            <div class="sdot"></div>
            <div>
                <div class="stext" id="r-banner-text"></div>
                <div class="ssub" id="r-banner-sub"></div>
            </div>
        </div>
        <div class="result-body">
            <div id="r-incident-warning" style="display:none;" class="incident-warning">
                <div style="flex-shrink:0;margin-top:1px;"><svg width="16" height="16" fill="none" stroke="#B91C1C" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg></div>
                <div>
                    <p class="incident-warning-title" id="r-incident-warning-title"></p>
                    <p class="incident-warning-sub" id="r-incident-warning-sub"></p>
                </div>
            </div>
            <div class="info-card">
                <div class="info-card-header">Personal Information</div>
                <div class="info-row"><span class="info-label">Full Name</span><span class="info-value" id="r-fullname"></span></div>
                <div class="info-row"><span class="info-label">Municipality</span><span class="info-value" id="r-municipality"></span></div>
                <div class="info-row"><span class="info-label">Barangay</span><span class="info-value" id="r-barangay"></span></div>
                <div class="info-row"><span class="info-label">Contact</span><span class="info-value" id="r-contact"></span></div>
                <div class="info-row"><span class="info-label">Sex / Birthdate</span><span class="info-value" id="r-sexbirth"></span></div>
                <div class="info-row"><span class="info-label">Fishing Ground</span><span class="info-value" id="r-ground"></span></div>
            </div>
            <div class="info-card">
                <div class="info-card-header">Vessel Information</div>
                <div class="info-row"><span class="info-label">Fisherfolk Registry No.</span><span class="info-value mono" id="r-brn2"></span></div>
                <div class="info-row"><span class="info-label">Boat Name</span><span class="info-value" id="r-boatname"></span></div>
                <div class="info-row"><span class="info-label">Type / Material</span><span class="info-value" id="r-boattype"></span></div>
                <div class="info-row"><span class="info-label">Engine</span><span class="info-value" id="r-engine"></span></div>
                <div class="info-row"><span class="info-label">Gross Tonnage</span><span class="info-value" id="r-gt"></span></div>
                <div class="info-row"><span class="info-label">Fishing Type</span><span class="info-value" id="r-fishtype"></span></div>
                <div class="info-row"><span class="info-label">Homeport</span><span class="info-value" id="r-homeport"></span></div>
                <div class="info-row"><span class="info-label">Ownership</span><span class="info-value" id="r-ownership"></span></div>
            </div>
            <div class="info-card">
                <div class="info-card-header">Permits &amp; Licenses</div>
                <div id="r-permits"></div>
            </div>
            <div class="verified-footer">
                <div>
                    <div class="verified-stamp"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>Verified via BantayDagat: Connect</div>
                </div>
                <p id="r-timestamp" style="margin:4px 0 0;font-size:10.5px;"></p>
            </div>
            <div style="padding:0 0 24px;">
                <button onclick="openViolationModal()" style="background:linear-gradient(135deg,#DC2626,#B91C1C);color:#fff;border:none;border-radius:10px;padding:12px 24px;font-size:14px;font-weight:600;cursor:pointer;font-family:'Instrument Sans',sans-serif;display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(220,38,38,.3);width:100%;justify-content:center;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    Report Violation
                </button>
            </div>
        </div>
    </div>


    <div id="violation-modal" style="display:none;position:fixed;inset:0;z-index:100;background:rgba(0,0,0,.7);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:20px;">
        <div style="background:#1B2440;border-radius:16px;max-width:420px;width:100%;max-height:90vh;overflow-y:auto;padding:24px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                <h3 style="margin:0;font-size:18px;font-weight:700;color:#fff;">Report Violation</h3>
                <button onclick="closeViolationModal()" style="background:none;border:none;color:rgba(255,255,255,.5);cursor:pointer;font-size:24px;line-height:1;">&times;</button>
            </div>
            <div id="v-ff-strip" style="background:rgba(220,38,38,.1);border:1px solid rgba(220,38,38,.25);border-radius:8px;padding:8px 12px;margin-bottom:16px;display:none;">
                <p style="font-size:11px;color:#FCA5A5;margin:0 0 1px;font-weight:600;">Filing violation against:</p>
                <p id="v-ff-name" style="font-size:13px;color:#fff;font-weight:700;margin:0;"></p>
                <p id="v-ff-brn-label" style="font-size:11px;color:rgba(255,255,255,.5);font-family:'JetBrains Mono',monospace;margin:2px 0 0;"></p>
            </div>
            <form id="violation-form">
                <input type="hidden" id="v-brn" name="brn" value="">
                <input type="hidden" id="v-ff-id" name="fisherfolk_id" value="">
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:12px;color:rgba(255,255,255,.6);margin-bottom:6px;">Violation Type *</label>
                    <select id="v-type" name="violation_id" required style="width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:8px;padding:10px 12px;font-size:14px;color:#fff;font-family:'Instrument Sans',sans-serif;">
                        <option value="">Select violation type</option>
                        <?php foreach ($violation_groups as $gkey => $items): ?>
                            <optgroup label="<?= htmlspecialchars(explode('|', $gkey, 2)[1]) ?>">
                                <?php foreach ($items as $it): ?>
                                    <option value="<?= (int)$it['id'] ?>"><?= htmlspecialchars($it['name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:12px;color:rgba(255,255,255,.6);margin-bottom:6px;">Location *</label>
                    <div style="display:flex;gap:8px;">
                        <input type="text" id="v-location" name="location_name" required placeholder="e.g. Poblacion Beach" style="flex:1;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:8px;padding:10px 12px;font-size:14px;color:#fff;font-family:'Instrument Sans',sans-serif;">
                        <button type="button" onclick="getCurrentLocation()" style="background:rgba(0,107,183,.2);border:1px solid rgba(0,107,183,.4);border-radius:8px;padding:10px 14px;color:#6fd3f5;cursor:pointer;" title="Use current location">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </button>
                    </div>
                    <p id="v-location-status" style="font-size:11px;color:rgba(255,255,255,.4);margin:6px 0 0;display:none;"></p>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:12px;color:rgba(255,255,255,.6);margin-bottom:6px;">Municipality *</label>
                    <select id="v-municipality" name="municipality" required style="width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:8px;padding:10px 12px;font-size:14px;color:#fff;font-family:'Instrument Sans',sans-serif;">
                        <option value="">Select municipality</option>
                        <option>San Jose de Buenavista</option>
                        <option>Hamtic</option>
                        <option>Tobias Fornier</option>
                        <option>Anini-y</option>
                    </select>
                </div>
                <div style="margin-bottom:20px;">
                    <label style="display:block;font-size:12px;color:rgba(255,255,255,.6);margin-bottom:6px;">Description</label>
                    <textarea id="v-description" name="description" rows="3" placeholder="Describe the violation..." style="width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:8px;padding:10px 12px;font-size:14px;color:#fff;font-family:'Instrument Sans',sans-serif;resize:vertical;"></textarea>
                </div>
                <div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);border-radius:8px;padding:10px 12px;margin-bottom:16px;display:flex;gap:8px;align-items:flex-start;">
                    <svg width="14" height="14" fill="none" stroke="#FCD34D" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <p style="font-size:11px;color:#FCD34D;margin:0;line-height:1.5;">Submitting will automatically <strong>suspend all active permits</strong> for this fisherfolk until resolved.</p>
                </div>
                <div style="display:flex;gap:10px;">
                    <button type="button" onclick="closeViolationModal()" style="flex:1;background:transparent;border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.7);border-radius:8px;padding:12px;font-size:14px;cursor:pointer;font-family:'Instrument Sans',sans-serif;">Cancel</button>
                    <button type="submit" id="v-submit-btn" style="flex:1;background:linear-gradient(135deg,#DC2626,#B91C1C);color:#fff;border:none;border-radius:8px;padding:12px;font-size:14px;font-weight:600;cursor:pointer;font-family:'Instrument Sans',sans-serif;">Submit Report</button>
                </div>
            </form>
        </div>
    </div>


    <div id="screen-error">
        <div class="error-icon"><svg width="34" height="34" fill="none" stroke="#DC2626" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg></div>
        <h2 style="font-size:18px;font-weight:700;margin:0 0 8px;">Verification Failed</h2>
        <p id="error-msg" style="font-size:13px;color:rgba(255,255,255,.5);margin:0 0 24px;line-height:1.6;"></p>
        <button class="btn-retry" onclick="resetToScanner()">Scan Again</button>
        <button class="manual-btn" style="margin-top:12px;" onclick="resetToScanner();setTimeout(openManual,200)">Enter Manually</button>
    </div>

    <script>

        function bdConfirm(message, options = {}) {
            return new Promise((resolve) => {
                const overlay = document.createElement('div');
                overlay.className = 'bd-confirm-overlay';
                overlay.innerHTML = `
                    <div class="bd-confirm-box">
                        <p class="bd-confirm-message"></p>
                        <div class="bd-confirm-actions">
                            <button type="button" class="bd-confirm-cancel">${options.cancelText || 'Cancel'}</button>
                            <button type="button" class="bd-confirm-ok">${options.confirmText || 'Confirm'}</button>
                        </div>
                    </div>
                `;
                overlay.querySelector('.bd-confirm-message').textContent = message;
                document.body.appendChild(overlay);
                requestAnimationFrame(() => overlay.classList.add('bd-confirm-visible'));

                function cleanup(result) {
                    overlay.classList.remove('bd-confirm-visible');
                    setTimeout(() => overlay.remove(), 150);
                    resolve(result);
                }
                overlay.querySelector('.bd-confirm-ok').addEventListener('click', () => cleanup(true));
                overlay.querySelector('.bd-confirm-cancel').addEventListener('click', () => cleanup(false));
                overlay.addEventListener('click', (e) => { if (e.target === overlay) cleanup(false); });
                document.addEventListener('keydown', function escHandler(e) {
                    if (e.key === 'Escape') { cleanup(false); document.removeEventListener('keydown', escHandler); }
                });
            });
        }
        (function injectConfirmStyles() {
            if (document.getElementById('bd-confirm-styles')) return;
            const style = document.createElement('style');
            style.id = 'bd-confirm-styles';
            style.textContent = `
                .bd-confirm-overlay {
                    position: fixed; inset: 0; background: rgba(0,0,0,0.5);
                    display: flex; align-items: center; justify-content: center;
                    z-index: 9999; opacity: 0; transition: opacity 0.15s ease;
                }
                .bd-confirm-overlay.bd-confirm-visible { opacity: 1; }
                .bd-confirm-box {
                    background: #fff; border-radius: 10px; padding: 1.5rem;
                    max-width: 340px; width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                    transform: scale(0.95); transition: transform 0.15s ease;
                }
                .bd-confirm-overlay.bd-confirm-visible .bd-confirm-box { transform: scale(1); }
                .bd-confirm-message { margin: 0 0 1.25rem; font-size: 15px; color: #1a2b22; line-height: 1.4; }
                .bd-confirm-actions { display: flex; gap: 8px; justify-content: flex-end; }
                .bd-confirm-actions button {
                    border: none; border-radius: 6px; padding: 8px 16px;
                    font-size: 14px; cursor: pointer; font-weight: 500;
                }
                .bd-confirm-cancel { background: #eee; color: #333; }
                .bd-confirm-ok { background: #0f6e56; color: #fff; }
            `;
            document.head.appendChild(style);
        })();

        let isScanningLocked = false;
        let currentFisherfolk = null;
        let wasRFIDScan = false;
        let wasQRScan = false;
        let wasOfflineResult = false;
        let currentTab = 'all';


        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw-scan.js').then(() => {
                navigator.serviceWorker.addEventListener('message', (e) => {
                    if (e.data && e.data.type === 'bd-queue-synced') refreshViolationQueueBanner();
                });
            }).catch(() => {});
        }

        function updateNetStatusPill() {
            const pill = document.getElementById('net-status-pill');
            if (navigator.onLine) {
                pill.style.background = 'rgba(34,197,94,.18)';
                pill.style.color = '#86efac';
                pill.style.borderColor = 'rgba(134,239,172,.3)';
                pill.textContent = '● Online';
            } else {
                pill.style.background = 'rgba(239,68,68,.18)';
                pill.style.color = '#fca5a5';
                pill.style.borderColor = 'rgba(252,165,165,.3)';
                pill.textContent = '● Offline — using cached data';
            }
        }
        window.addEventListener('online', () => { updateNetStatusPill(); syncFFCache(); flushViolationQueue(); });
        window.addEventListener('offline', updateNetStatusPill);
        updateNetStatusPill();




        async function syncFFCache() {
            if (!navigator.onLine) return;
            const pill = document.getElementById('cache-sync-pill');
            try {
                const res = await fetch('api_cache_sync.php');
                const data = await res.json();
                if (data.records) {
                    await bdReplaceFFCache(data.records);
                    await bdPut('meta', { key: 'last_sync', value: data.synced_at });
                    if (pill) pill.textContent = 'Cache synced · ' + data.count + ' records';
                }
            } catch (e) {

            }
        }
        async function showCacheStatus() {
            const pill = document.getElementById('cache-sync-pill');
            const meta = await bdGet('meta', 'last_sync');
            if (pill && meta) {
                const d = new Date(meta.value);
                pill.textContent = 'Cache synced ' + d.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' });
            }
        }
        showCacheStatus();
        syncFFCache();
        setInterval(syncFFCache, 5 * 60 * 1000);


        async function refreshViolationQueueBanner() {
            const q = await bdGetAll('violation_queue');
            const banner = document.getElementById('violation-queue-banner');
            const text = document.getElementById('violation-queue-text');
            if (q.length > 0) {
                text.textContent = q.length + ' violation report' + (q.length > 1 ? 's' : '') + ' waiting to sync';
                banner.style.display = 'flex';
            } else {
                banner.style.display = 'none';
            }
        }
        async function flushViolationQueue() {
            if (!navigator.onLine) return;
            const queued = await bdGetAll('violation_queue');
            for (const item of queued) {
                try {
                    const fd = new FormData();
                    Object.entries(item.payload).forEach(([k, v]) => fd.append(k, v));
                    const res = await fetch('scan.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) await bdDelete('violation_queue', item.local_id);
                } catch (e) { break; }
            }
            refreshViolationQueueBanner();
            if ('serviceWorker' in navigator && 'SyncManager' in window) {
                navigator.serviceWorker.ready.then((reg) => reg.sync.register('bd-sync-violations')).catch(() => {});
            }
        }
        refreshViolationQueueBanner();

        function showEl(id) {
            const e = document.getElementById(id);
            e.style.display = 'flex';
            setTimeout(() => e.classList.add('show'), 10);
        }

        function hideEl(id) {
            const e = document.getElementById(id);
            e.classList.remove('show');
            e.style.display = 'none';
        }






        const rfidInput = document.getElementById('rfid-input');

        function focusRFIDInput() {
            if (document.getElementById('screen-scanner').style.display === 'none') return;
            rfidInput.focus({ preventScroll: true });
            updateScanStatus(true);
        }

        rfidInput.addEventListener('blur', () => {
            updateScanStatus(false);


            setTimeout(() => {
                const scannerVisible = document.getElementById('screen-scanner').style.display !== 'none';
                const modalOpen = document.getElementById('manual-panel').classList.contains('open') ||
                    document.getElementById('history-panel').classList.contains('open') ||
                    document.getElementById('qr-panel').classList.contains('open') ||
                    document.getElementById('violation-modal').style.display === 'flex';
                if (scannerVisible && !modalOpen) focusRFIDInput();
            }, 250);
        });

        let rfidTimer = null, lastScan = { v: '', t: 0 };
        rfidInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                clearTimeout(rfidTimer);
                const val = rfidInput.value;
                rfidInput.value = '';
                handleScan(val);
            }
        });

        rfidInput.addEventListener('input', () => {
            clearTimeout(rfidTimer);
            rfidTimer = setTimeout(() => {
                const v = rfidInput.value;
                if (v.trim().length >= 6) { rfidInput.value = ''; handleScan(v); }
            }, 180);
        });

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) focusRFIDInput();
        });

        function updateScanStatus(active) {
            const el = document.getElementById('scan-status'),
                tx = document.getElementById('scan-status-text'),
                ring = document.getElementById('rfid-ring'),
                title = document.getElementById('rfid-title');
            if (!el || !tx) return;
            el.className = 'scan-status ' + (active ? 'active' : 'inactive');
            tx.textContent = active ? 'Reader Ready' : 'Reader not focused — tap screen';
            if (ring) ring.classList.toggle('listening', active);
            if (title) title.textContent = active ? 'Ready to Scan' : 'Tap Screen to Resume';
        }

        function handleScan(raw) {
            if (isScanningLocked) return;
            const val = String(raw || '').replace(/[^\x20-\x7E]/g, '').trim().toUpperCase();
            if (!val || val.length < 3) return;

            if (val === lastScan.v && Date.now() - lastScan.t < 1500) return;
            lastScan = { v: val, t: Date.now() };
            isScanningLocked = true;
            wasRFIDScan = true;
            wasQRScan = false;
            lookupBRN(val);
        }

        function openManual() {
            document.getElementById('manual-panel').classList.add('open');
            setTimeout(() => document.getElementById('manual-brn').focus(), 300);
        }

        function closeManual() {
            document.getElementById('manual-panel').classList.remove('open');
            document.getElementById('manual-results').innerHTML = '';
            focusRFIDInput();
        }

        function scanEsc(v) { const d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

        const looksLikeId = (v) => /^[A-Z]{3}-VES-\d{4}-\d+$/i.test(v) || (/^[0-9A-F]{6,}$/i.test(v));

        async function manualVerify() {
            const raw = document.getElementById('manual-brn').value.trim();
            if (!raw) { document.getElementById('manual-brn').focus(); return; }
            if (looksLikeId(raw)) {
                closeManual();
                wasRFIDScan = false;
                wasQRScan = false;
                lookupBRN(raw.toUpperCase());
                return;
            }
            await searchByName(raw);
        }

        async function searchByName(q) {
            const box = document.getElementById('manual-results');
            if (q.length < 3) { box.innerHTML = '<p class="mr-note">Type at least 3 letters.</p>'; return; }
            box.innerHTML = '<p class="mr-note">Searching...</p>';
            let rows = [];
            try {
                if (!navigator.onLine) throw new Error('offline');
                const d = await (await fetch('scan.php?q=' + encodeURIComponent(q))).json();
                if (d.error) throw new Error(d.error);
                rows = d.results || [];
            } catch (e) {
                try { rows = (await bdSearchFFCache(q)).slice(0, 8); } catch (e2) { rows = []; }
            }
            box.innerHTML = rows.length ? rows.map(r => `<button type="button" class="mr-row" data-brn="${scanEsc(r.boat_registry_number)}"><strong>${scanEsc(r.last_name + ', ' + r.first_name + (r.middle_name ? ' ' + r.middle_name.charAt(0) + '.' : ''))}</strong><span>${scanEsc([r.barangay, r.municipality, r.boat_name].filter(Boolean).join(' \u00b7 '))}</span></button>`).join('')
                : '<p class="mr-note">No match. Check the spelling or try another name.</p>';
        }
        document.getElementById('manual-results').addEventListener('click', (e) => {
            const b = e.target.closest('.mr-row');
            if (!b) return;
            closeManual();
            wasRFIDScan = false;
            wasQRScan = false;
            lookupBRN(b.dataset.brn);
        });
        document.getElementById('manual-brn').addEventListener('keydown', e => {
            if (e.key === 'Enter') manualVerify();
        });









        const qrVideo = document.getElementById('qr-video');
        const qrCodeReader = (typeof ZXing !== 'undefined') ? new ZXing.BrowserMultiFormatReader() : null;
        let qrControls = null;

        function openQRFallback() {
            document.getElementById('qr-panel').classList.add('open');
            startQRCamera();
        }

        function closeQRFallback() {
            document.getElementById('qr-panel').classList.remove('open');
            stopQRCamera();
        }

        function stopQRCamera() {
            try {
                if (qrControls) { qrControls.stop(); qrControls = null; }
                if (qrCodeReader) qrCodeReader.reset();
            } catch (e) {}
            if (qrVideo.srcObject) {
                qrVideo.srcObject.getTracks().forEach(t => t.stop());
                qrVideo.srcObject = null;
            }
        }

        async function startQRCamera() {
            const statusEl = document.getElementById('qr-status');
            if (!qrCodeReader) {
                statusEl.textContent = 'QR scanning unavailable on this device.';
                return;
            }
            statusEl.textContent = 'Starting camera…';
            try {
                const devices = await qrCodeReader.listVideoInputDevices();
                if (!devices || !devices.length) throw new Error('No camera found');
                const back = devices.find(d => /back|rear|environment/i.test(d.label)) || devices[devices.length - 1];
                qrControls = await qrCodeReader.decodeFromVideoDevice(back.deviceId, qrVideo, result => {
                    if (result) {
                        const token = result.getText().trim();
                        closeQRFallback();
                        handleQRToken(token);
                    }
                });
                statusEl.textContent = 'Align the QR code within the frame';
            } catch (e) {
                statusEl.textContent = 'Camera error: ' + e.message;
            }
        }

        function handleQRToken(token) {
            if (isScanningLocked || !token) return;
            isScanningLocked = true;
            wasRFIDScan = false;
            wasQRScan = true;
            lookupQRToken(token);
        }

        async function lookupQRToken(token) {
            document.getElementById('screen-scanner').style.display = 'none';
            showEl('screen-loading');

            if (!navigator.onLine) {
                await lookupQRFromCache(token);
                return;
            }

            try {
                const res = await fetch('scan.php?token=' + encodeURIComponent(token));
                const data = await res.json();
                hideEl('screen-loading');
                if (data.error) {
                    document.getElementById('error-msg').innerHTML = data.error;
                    showEl('screen-error');
                    return;
                }
                wasOfflineResult = false;
                displayResult(data.fisherfolk, data.permits, data.active_incidents || []);
            } catch (e) {


                await lookupQRFromCache(token, true);
            }
        }

        async function lookupQRFromCache(token, wasConnectionDrop) {
            try {
                const cached = await bdFindFFByQRToken(token);
                hideEl('screen-loading');
                if (!cached) {
                    document.getElementById('error-msg').innerHTML =
                        (wasConnectionDrop ? 'Connection error, and t' : 'T') +
                        'his QR code isn\'t in the offline cache yet.' +
                        '<br><br>It syncs down once the fisherfolk\'s ID card has been generated online and this device has synced since.';
                    showEl('screen-error');
                    return;
                }
                wasOfflineResult = true;
                displayResult(cached, cached.permits || [], cached.active_incidents || []);
            } catch (e) {
                hideEl('screen-loading');
                document.getElementById('error-msg').innerHTML = 'Offline cache error<br>' + e.message;
                showEl('screen-error');
            }
        }

        async function lookupBRN(brn) {
            document.getElementById('screen-scanner').style.display = 'none';
            showEl('screen-loading');

            if (!navigator.onLine) {
                await lookupBRNFromCache(brn);
                return;
            }

            try {
                const res = await fetch('scan.php?brn=' + encodeURIComponent(brn));
                const data = await res.json();
                hideEl('screen-loading');
                if (data.error) {
                    document.getElementById('error-msg').innerHTML = data.error + '<br><br>BRN: ' + brn;
                    showEl('screen-error');
                    return;
                }
                wasOfflineResult = false;
                displayResult(data.fisherfolk, data.permits, data.active_incidents || []);
            } catch (e) {


                await lookupBRNFromCache(brn, true);
            }
        }

        async function lookupBRNFromCache(brn, wasConnectionDrop) {
            try {



                let cached = await bdFindFFByBRN(brn);
                if (!cached) cached = await bdFindFFByRFID(brn);
                hideEl('screen-loading');
                if (!cached) {
                    document.getElementById('error-msg').innerHTML =
                        (wasConnectionDrop ? 'Connection error, and n' : 'N') +
                        'o cached record found for: ' + brn +
                        '<br><br>This device\'s offline cache may not include this fisherfolk yet.';
                    showEl('screen-error');
                    return;
                }
                wasOfflineResult = true;
                displayResult(cached, cached.permits || [], cached.active_incidents || []);
            } catch (e) {
                hideEl('screen-loading');
                document.getElementById('error-msg').innerHTML = 'Offline cache error<br>' + e.message;
                showEl('screen-error');
            }
        }

        function displayResult(ff, permits, activeIncidents) {
            currentFisherfolk = ff;
            hideEl('screen-scanner');
            hideEl('screen-error');
            const fullName = ff.last_name + ', ' + ff.first_name + (ff.middle_name ? ' ' + ff.middle_name : '');
            const av = document.getElementById('r-avatar');
            av.textContent = ff.last_name.charAt(0).toUpperCase();
            if (ff.photo_path) {
                const im = new Image();
                im.alt = '';
                im.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:inherit';
                im.onload = function () { av.textContent = ''; av.appendChild(im); };
                im.src = 'uploads/photos/' + encodeURIComponent(ff.photo_path);
            }
            document.getElementById('r-name').textContent = fullName;

            const gt = ff.gross_tonnage;
            const hasGT = gt !== null && gt !== undefined && gt !== '';
            const fishType = hasGT ? (parseFloat(gt) > 3 ? 'Commercial Fishing' : 'Municipal Fishing') : '';
            const hp = parseFloat(ff.horsepower);
            const engineText = hp > 0
                ? ((ff.engine_make || 'Engine') + ' \u00b7 ' + hp + ' HP')
                : ((ff.boat_type || '').toLowerCase().startsWith('non') ? 'Non-motorized' : 'N/A');
            document.getElementById('r-sub').textContent = fishType || (ff.fishing_environment ? ff.fishing_environment + ' fisherfolk' : 'Fisherfolk');
            document.getElementById('r-brn').textContent = ff.boat_registry_number || 'N/A';
            const oc = document.getElementById('r-oldcard');
            if (oc) {
                oc.textContent = ff.old_card ? 'Old ID card. This person transferred and has a new registry number. Ask for the new ID.'
                    : (ff.id_reprint_needed ? 'Transferred. A new ID is being reprinted.' : '');
                oc.style.display = oc.textContent ? 'block' : 'none';
            }
            document.getElementById('r-fullname').textContent = fullName;
            document.getElementById('r-municipality').textContent = ff.municipality || 'N/A';
            document.getElementById('r-barangay').textContent = ff.barangay || 'N/A';
            document.getElementById('r-contact').textContent = ff.contact_number || 'Shown when online';
            document.getElementById('r-sexbirth').textContent = (ff.sex || 'N/A') + ' / ' + (ff.birthdate || 'N/A');
            document.getElementById('r-ground').textContent = ff.fishing_ground || 'N/A';
            document.getElementById('r-brn2').textContent = ff.boat_registry_number || 'N/A';
            document.getElementById('r-boatname').textContent = ff.boat_name || 'N/A';
            document.getElementById('r-boattype').textContent = (ff.boat_type || 'N/A') + ' / ' + (ff.boat_material || 'N/A');
            document.getElementById('r-engine').textContent = engineText;
            document.getElementById('r-fishtype').textContent = fishType || 'N/A';


            const detail = (v) => v === undefined ? (wasOfflineResult ? 'Shown when online' : 'N/A') : ((v === null || v === '') ? 'N/A' : v);
            document.getElementById('r-gt').textContent = hasGT ? (parseFloat(gt) + ' GT') : detail(gt === null ? '' : gt);
            document.getElementById('r-homeport').textContent = detail(ff.homeport);
            document.getElementById('r-ownership').textContent = detail(ff.boat_ownership);
            document.getElementById('r-rfid-badge').style.display = wasRFIDScan ? 'inline-flex' : 'none';
            document.getElementById('r-qr-badge').style.display = wasQRScan ? 'inline-flex' : 'none';
            document.getElementById('r-offline-badge').style.display = wasOfflineResult ? 'inline-flex' : 'none';

            const warnEl = document.getElementById('r-incident-warning');
            if (activeIncidents && activeIncidents.length > 0) {
                const l = activeIncidents[0];
                document.getElementById('r-incident-warning-title').textContent = '⚠ Active Incident: ' + l.violation_type;
                document.getElementById('r-incident-warning-sub').textContent = activeIncidents.length + ' open incident' + (activeIncidents.length > 1 ? 's' : '') + ' — Status: ' + l.status;
                warnEl.style.display = 'flex';
            } else {
                warnEl.style.display = 'none';
            }

            const banner = document.getElementById('r-banner'),
                bt = document.getElementById('r-banner-text'),
                bs = document.getElementById('r-banner-sub');
            banner.className = 'status-banner';
            if (activeIncidents && activeIncidents.length > 0) {
                banner.classList.add('has-incident');
                bt.textContent = 'Active Violation on Record';
                bs.textContent = 'Permits suspended pending resolution.';
            } else if (!permits || permits.length === 0) {
                banner.classList.add('no-permits');
                bt.textContent = 'No Permits on Record';
                bs.textContent = 'No permits registered.';
            } else {
                const hasSusp = permits.some(p => p.status === 'Suspended'),
                    hasExp = permits.some(p => p.status === 'Expired'),
                    allAct = permits.every(p => p.status === 'Active');
                if (hasSusp) {
                    banner.classList.add('has-suspended');
                    bt.textContent = 'Permit Suspended';
                    bs.textContent = 'One or more permits suspended.';
                } else if (allAct) {
                    banner.classList.add('all-clear');
                    bt.textContent = 'All Permits Valid';
                    bs.textContent = 'All permits active and up to date.';
                } else if (hasExp) {
                    banner.classList.add('has-expired');
                    bt.textContent = 'Permit(s) Expired';
                    bs.textContent = 'One or more permits have expired.';
                }
            }

            let html = '';
            if (!permits || permits.length === 0) {
                html = '<div class="permit-item" style="color:#7d93a5;font-size:13px;">No permits recorded.</div>';
            } else {
                permits.forEach(p => {
                    const bc = {
                            Active: 'badge-active',
                            Expired: 'badge-expired',
                            Suspended: 'badge-suspended'
                        } [p.status] || 'badge-suspended',
                        ic = {
                            Active: '✓',
                            Expired: '✕',
                            Suspended: '⚠'
                        } [p.status] || '?';
                    html += `<div class="permit-item"><div class="permit-top"><div class="permit-name">${p.permit_type}</div><div class="badge ${bc}">${ic} ${p.status}</div></div><div class="permit-meta">${p.permit_number} · ${p.issue_date} → ${p.expiry_date}</div>${p.fishing_area?`<div class="permit-area">Area: ${p.fishing_area}</div>`:''}</div>`;
                });
            }
            document.getElementById('r-permits').innerHTML = html;
            document.getElementById('r-timestamp').textContent = 'Verified on ' + new Date().toLocaleString('en-PH', {
                dateStyle: 'medium',
                timeStyle: 'short'
            });
            hideEl('screen-loading');
            showEl('screen-result');
        }

        function resetToScanner() {
            isScanningLocked = false;
            currentFisherfolk = null;
            wasOfflineResult = false;
            stopQRCamera();
            document.getElementById('qr-panel').classList.remove('open');
            hideEl('screen-result');
            hideEl('screen-error');
            hideEl('screen-loading');
            document.getElementById('violation-modal').style.display = 'none';
            document.getElementById('screen-scanner').style.display = 'flex';
            focusRFIDInput();
        }

        function openHistory() {
            document.getElementById('history-panel').classList.add('open');
            loadHistory(currentTab);
        }

        function closeHistory() {
            document.getElementById('history-panel').classList.remove('open');
        }

        function switchTab(tab) {
            currentTab = tab;
            ['all', 'verify', 'violation'].forEach(t => {
                document.getElementById('tab-' + t).className = 'tab-btn ' + (t === tab ? 'active' : 'inactive');
            });
            loadHistory(tab);
        }

        function loadHistory(type) {
            const list = document.getElementById('history-list');
            list.innerHTML = '<div class="history-empty">Loading...</div>';
            fetch('scan.php?action=get_logs&type=' + type + '&limit=50')
                .then(r => r.json())
                .then(data => {
                    const logs = data.logs || [];
                    if (!logs.length) {
                        list.innerHTML = '<div class="history-empty">No scans recorded yet.</div>';
                        return;
                    }
                    list.innerHTML = logs.map(log => {
                        const isV = log.scan_type === 'violation',
                            nf = log.result === 'not_found';
                        const ic = nf ? 'not-found' : (isV ? 'violation' : 'verify');
                        const bl = nf ? 'Not Found' : (isV ? 'Violation' : 'Verified');
                        const icon = nf ?
                            `<svg width="16" height="16" fill="none" stroke="#7d93a5" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>` :
                            isV ?
                            `<svg width="16" height="16" fill="none" stroke="#fca5a5" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>` :
                            `<svg width="16" height="16" fill="none" stroke="#86efac" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;
                        const name = log.fisherfolk_name || (nf ? 'Unknown' : '—');
                        const sub = isV && log.violation_type ? log.violation_type : (log.municipality || '—');
                        const time = new Date(log.scanned_at).toLocaleString('en-PH', {
                            dateStyle: 'short',
                            timeStyle: 'short'
                        });
                        return `<div class="history-item">
                    <div class="h-icon ${ic}">${icon}</div>
                    <div style="flex:1;min-width:0;">
                        <p class="h-name">${name}</p>
                        <p class="h-brn">${log.boat_registry_number||'—'}</p>
                        <p class="h-meta">${sub} · ${time}</p>
                    </div>
                    <span class="h-badge ${ic}">${bl}</span>
                </div>`;
                    }).join('');
                })
                .catch(() => {
                    list.innerHTML = '<div class="history-empty">Failed to load history.</div>';
                });
        }

        function openViolationModal() {
            if (!currentFisherfolk) return;
            const ff = currentFisherfolk;
            document.getElementById('v-brn').value = ff.boat_registry_number || '';
            document.getElementById('v-ff-id').value = ff.id || '';
            const fn = ff.last_name + ', ' + ff.first_name + (ff.middle_name ? ' ' + ff.middle_name : '');
            document.getElementById('v-ff-name').textContent = fn;
            document.getElementById('v-ff-brn-label').textContent = ff.boat_registry_number || '';
            document.getElementById('v-ff-strip').style.display = 'block';
            const ms = document.getElementById('v-municipality');
            for (let i = 0; i < ms.options.length; i++) {
                if (ms.options[i].value === ff.municipality) {
                    ms.selectedIndex = i;
                    break;
                }
            }
            document.getElementById('violation-modal').style.display = 'flex';
        }

        function closeViolationModal() {
            document.getElementById('violation-modal').style.display = 'none';
            document.getElementById('violation-form').reset();
            document.getElementById('v-location-status').style.display = 'none';
            document.getElementById('v-ff-strip').style.display = 'none';
        }

        function getCurrentLocation() {
            const sEl = document.getElementById('v-location-status'),
                iEl = document.getElementById('v-location');
            if (!navigator.geolocation) {
                sEl.textContent = '⚠️ Not supported';
                sEl.style.display = 'block';
                return;
            }
            sEl.textContent = '📍 Getting location...';
            sEl.style.display = 'block';
            navigator.geolocation.getCurrentPosition(
                pos => {
                    const lat = pos.coords.latitude,
                        lon = pos.coords.longitude;
                    iEl.value = `Lat: ${lat.toFixed(5)}, Lon: ${lon.toFixed(5)}`;
                    sEl.textContent = '📍 Location detected';
                },
                err => {
                    const m = {
                        1: '⚠️ Access denied',
                        2: '⚠️ Unavailable',
                        3: '⚠️ Timed out'
                    };
                    sEl.textContent = m[err.code] || '⚠️ ' + err.message;
                }, {
                    enableHighAccuracy: false,
                    timeout: 30000,
                    maximumAge: 60000
                }
            );
        }
        document.getElementById('violation-form').addEventListener('submit', async function(e) {
            e.preventDefault();

            const confirmed = await bdConfirm('Submit this violation report? This will suspend the fisherfolk\'s active permits.');
            if (!confirmed) return;

            const btn = document.getElementById('v-submit-btn');
            btn.disabled = true;
            btn.textContent = 'Submitting...';
            const fd = new FormData(this);
            fd.append('action', 'report_violation');
            fd.append('reported_by_name', '<?= addslashes($officer_name ?? 'Coast Guard') ?>');

            if (!navigator.onLine) {
                await queueViolationReport(fd);
                return;
            }

            try {
                const res = await fetch('scan.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (data.success) {
                    closeViolationModal();
                    const brn = currentFisherfolk?.boat_registry_number;
                    if (brn) {
                        wasRFIDScan = false;
                        wasQRScan = false;
                        lookupBRN(brn);
                    }
                    if (document.getElementById('history-panel').classList.contains('open')) loadHistory(currentTab);
                } else {
                    alert('Error: ' + (data.message || 'Failed'));
                    btn.disabled = false;
                    btn.textContent = 'Submit Report';
                }
            } catch (err) {
                await queueViolationReport(fd);
            }
        });

        async function queueViolationReport(fd) {
            const payload = {};
            for (const [k, v] of fd.entries()) payload[k] = v;
            await bdPut('violation_queue', { payload, queued_at: new Date().toISOString() });

            const brn = currentFisherfolk?.boat_registry_number;
            if (brn) {
                const cached = await bdFindFFByBRN(brn);
                if (cached && cached.permits) {
                    cached.permits.forEach(p => { if (p.status === 'Active') p.status = 'Suspended'; });
                    await bdPut('ff_cache', cached);
                }
            }

            closeViolationModal();
            refreshViolationQueueBanner();
            if ('serviceWorker' in navigator && 'SyncManager' in window) {
                navigator.serviceWorker.ready.then((reg) => reg.sync.register('bd-sync-violations')).catch(() => {});
            }
            const btn = document.getElementById('v-submit-btn');
            btn.disabled = false;
            btn.textContent = 'Submit Report';
            alert('Offline — violation report queued and will sync automatically once connection returns.');
        }

        window.addEventListener('load', () => {
            setTimeout(focusRFIDInput, 300);
        });
    </script>
</body>

</html>