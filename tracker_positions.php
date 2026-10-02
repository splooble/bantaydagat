<?php














require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/tracking_lib.php';
require_once 'includes/settings.php';

requireRole(array_merge(MAO_ROLES, OFFICER_ROLES));
$user    = getCurrentUser();
$isAdmin = $user['role'] === 'superadmin';
$scope   = $isAdmin ? null : (string)($user['mun'] ?? '');


if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json');
header('Cache-Control: no-store');

function out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    $rows = $conn->query(
        "SELECT t.id, t.device_id, t.last_lat, t.last_lng, t.last_sats, t.last_rssi, t.last_snr,
                TIMESTAMPDIFF(SECOND, t.last_seen_at, NOW()) AS seen_age,
                TIMESTAMPDIFF(SECOND, t.last_fix_at,  NOW()) AS fix_age,
                b.name AS boat_name, b.gross_tonnage,
                f.first_name, f.last_name, f.municipality AS owner_mun,
                f.boat_registry_number
           FROM trackers t
           LEFT JOIN boats b      ON b.id = t.boat_id
           LEFT JOIN fisherfolk f ON f.id = b.fisherfolk_id
          WHERE t.active = 1 AND t.last_lat IS NOT NULL AND t.last_lng IS NOT NULL"
    )->fetch_all(MYSQLI_ASSOC);


    $vessels = [];
    foreach ($rows as $r) {
        $lat = (float)$r['last_lat'];
        $lng = (float)$r['last_lng'];
        $inWaters   = tracker_municipalities_inside($lat, $lng);
        $commercial = tracker_is_commercial($r['gross_tonnage']);

        if ($scope !== null) {
            if ($scope === '') continue;
            $visible = $commercial
                    || $r['owner_mun'] === $scope
                    || (in_array($scope, $inWaters, true)
                        && getSetting($scope, 'waters_access') === 'Closed');
            if (!$visible) continue;
        }

        $seenAge    = $r['seen_age'] === null ? null : (int)$r['seen_age'];

        $vessels[(int)$r['id']] = [
            'id'            => (int)$r['id'],
            'device_id'     => (int)$r['device_id'],
            'boat'          => $r['boat_name'],
            'owner'         => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            'owner_mun'     => $r['owner_mun'],
            'registry_no'   => $r['boat_registry_number'],
            'gross_tonnage' => $r['gross_tonnage'] === null ? null : (float)$r['gross_tonnage'],
            'type'          => $commercial ? 'commercial' : 'municipal',
            'lat'           => $lat,
            'lng'           => $lng,
            'sats'          => $r['last_sats'] === null ? null : (int)$r['last_sats'],
            'rssi'          => $r['last_rssi'] === null ? null : (int)$r['last_rssi'],
            'snr'           => $r['last_snr']  === null ? null : (float)$r['last_snr'],
            'seen_age_s'    => $seenAge,
            'fix_age_s'     => $r['fix_age'] === null ? null : (int)$r['fix_age'],
            'stale'         => $seenAge === null || $seenAge > TRACKER_STALE_SECONDS,
            'in_waters'     => $inWaters,
            'zones'         => [],
        ];
    }


    $alerts = [];
    if ($vessels) {
        $zrows = $conn->query(
            "SELECT tracker_id, municipality, TIMESTAMPDIFF(SECOND, inside_since, NOW()) AS inside_s
               FROM tracker_zone_state"
        )->fetch_all(MYSQLI_ASSOC);

        foreach ($zrows as $z) {
            $tid = (int)$z['tracker_id'];
            if (!isset($vessels[$tid])) continue;
            if ($scope !== null && $z['municipality'] !== $scope) continue;

            $insideS = max(0, (int)$z['inside_s']);
            $isAlert = $insideS >= max(1, (int)getSetting((string)$z['municipality'], 'encroach_seconds'));
            $vessels[$tid]['zones'][] = [
                'municipality' => $z['municipality'],
                'inside_s'     => $insideS,
                'alert'        => $isAlert,
            ];
            if ($isAlert) {

                $alerts[] = [
                    'vessel_id'    => $tid,
                    'boat'         => $vessels[$tid]['boat'],
                    'municipality' => $z['municipality'],
                    'inside_s'     => $insideS,
                    'stale'        => $vessels[$tid]['stale'],
                ];
            }
        }
    }
    usort($alerts, fn($a, $b) => $b['inside_s'] <=> $a['inside_s']);


    $reporting = 0;
    $latest    = null;
    foreach ($vessels as $v) {
        if (!$v['stale']) $reporting++;
        if ($v['seen_age_s'] !== null && ($latest === null || $v['seen_age_s'] < $latest)) {
            $latest = $v['seen_age_s'];
        }
    }

    out(200, [
        'ok'       => true,
        'scope'    => $scope,
        'settings' => [
            'boundary_km'      => TRACKER_BOUNDARY_KM,
            'encroach_seconds' => ($scope !== null && $scope !== '') ? (int)getSetting($scope, 'encroach_seconds') : TRACKER_ENCROACH_SECONDS,
            'stale_seconds'    => TRACKER_STALE_SECONDS,
        ],
        'summary'  => [
            'tracked'           => count($vessels),
            'reporting'         => $reporting,
            'latest_packet_age' => $latest,
        ],
        'vessels'  => array_values($vessels),
        'alerts'   => $alerts,
    ]);
} catch (Throwable $e) {
    error_log('tracker_positions: ' . $e->getMessage());
    out(500, ['ok' => false, 'error' => 'server error']);
}