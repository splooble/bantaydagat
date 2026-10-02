<?php










require_once 'includes/db.php';
require_once 'includes/tracking_config.php';
require_once 'includes/tracking_lib.php';


mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'POST only']);
}

if (TRACKER_INGEST_ALLOWED_IPS && !in_array($_SERVER['REMOTE_ADDR'] ?? '', TRACKER_INGEST_ALLOWED_IPS, true)) {
    respond(403, ['ok' => false, 'error' => 'address not allowed']);
}

$token = $_SERVER['HTTP_X_TRACKER_TOKEN'] ?? '';
if (!hash_equals(TRACKER_INGEST_TOKEN, $token)) {
    respond(401, ['ok' => false, 'error' => 'bad token']);
}


$raw = file_get_contents('php://input', false, null, 0, 2048);
$p = json_decode($raw === false ? '' : $raw, true);
if (!is_array($p)) {
    respond(400, ['ok' => false, 'error' => 'body must be a JSON object']);
}

function intIn($value, int $min, int $max) {

    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
}
function numIn($value, float $min, float $max): ?float {
    if (!is_numeric($value)) return null;
    $v = (float)$value;
    return ($v >= $min && $v <= $max) ? $v : null;
}

$devId = intIn($p['id']  ?? null, 0, 65535);
$seq   = intIn($p['seq'] ?? null, 0, 65535);
$fix   = intIn($p['fix'] ?? null, 0, 1);
if ($devId === false || $seq === false || $fix === false) {
    respond(400, ['ok' => false, 'error' => 'id, seq and fix are required']);
}

$sats = null;
if (array_key_exists('sats', $p)) {
    $sats = intIn($p['sats'], 0, 255);
    if ($sats === false) respond(400, ['ok' => false, 'error' => 'bad sats']);
}

$rssi = numIn($p['rssi'] ?? null, -200, 50);
$snr  = numIn($p['snr']  ?? null, -50, 50);
$rssi = $rssi === null ? null : (int)round($rssi);
$snr  = $snr  === null ? null : round($snr, 1);


$lat = $lng = null;
if ($fix === 1) {
    $lat = numIn($p['lat'] ?? null, -90, 90);
    $lng = numIn($p['lon'] ?? null, -180, 180);
    if ($lat === null || $lng === null || (abs($lat) < 1e-6 && abs($lng) < 1e-6)) {
        respond(422, ['ok' => false, 'error' => 'fix=1 but coordinates are missing or invalid']);
    }
    $lat = round($lat, 7);
    $lng = round($lng, 7);
}


$inTransaction = false;
try {
    $stmt = $conn->prepare(
        'SELECT t.id, t.active, b.gross_tonnage
           FROM trackers t LEFT JOIN boats b ON b.id = t.boat_id
          WHERE t.device_id = ?'
    );
    $stmt->bind_param('i', $devId);
    $stmt->execute();
    $tracker = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tracker) {
        respond(404, ['ok' => false, 'error' => 'unknown device id']);
    }
    if (!(int)$tracker['active']) {
        respond(403, ['ok' => false, 'error' => 'tracker is disabled']);
    }
    $trackerId  = (int)$tracker['id'];
    $commercial = tracker_is_commercial($tracker['gross_tonnage']);

    $conn->begin_transaction();
    $inTransaction = true;

    $ins = $conn->prepare(
        'INSERT INTO positions (tracker_id, seq, fix, latitude, longitude, sats, rssi, snr)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->bind_param('iiiddiid', $trackerId, $seq, $fix, $lat, $lng, $sats, $rssi, $snr);
    $ins->execute();
    $positionId = $ins->insert_id;
    $ins->close();

    if ($fix === 1) {
        $upd = $conn->prepare(
            'UPDATE trackers
                SET last_seen_at = NOW(), last_fix_at = NOW(),
                    last_seq = ?, last_sats = ?, last_rssi = ?, last_snr = ?,
                    last_lat = ?, last_lng = ?
              WHERE id = ?'
        );
        $upd->bind_param('iiidddi', $seq, $sats, $rssi, $snr, $lat, $lng, $trackerId);
    } else {
        $upd = $conn->prepare(
            'UPDATE trackers
                SET last_seen_at = NOW(),
                    last_seq = ?, last_sats = ?, last_rssi = ?, last_snr = ?
              WHERE id = ?'
        );
        $upd->bind_param('iiidi', $seq, $sats, $rssi, $snr, $trackerId);
    }
    $upd->execute();
    $upd->close();

    $inside = [];
    if ($fix === 1) {
        $inside = tracking_update_zones($conn, $trackerId, $lat, $lng, $commercial);
    }

    $conn->commit();
    $inTransaction = false;

    respond(200, [
        'ok'          => true,
        'tracker_id'  => $trackerId,
        'position_id' => $positionId,
        'commercial'  => $commercial,
        'inside'      => $inside,
    ]);
} catch (Throwable $e) {
    if ($inTransaction) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
    }
    error_log('tracker_ingest: ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'server error']);
}