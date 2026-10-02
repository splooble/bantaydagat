<?php


require_once __DIR__ . '/tracking_settings.php';



function tracker_is_commercial($grossTonnage): bool {
    return $grossTonnage !== null && is_numeric($grossTonnage)
        && (float)$grossTonnage > TRACKER_COMMERCIAL_MIN_GT;
}

function tracker_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(sqrt($a));
}



function tracker_waters(): array {
    static $waters = null;
    if ($waters === null) {
        $waters = [];
        $json = @file_get_contents(__DIR__ . '/waters.json');
        $data = $json ? json_decode($json, true) : null;
        foreach (($data['features'] ?? []) as $f) {
            $name = $f['properties']['name'] ?? null;
            $ring = $f['geometry']['coordinates'][0] ?? null;
            if ($name && $ring) $waters[$name] = $ring;
        }
        if (!$waters) error_log('tracking_lib: includes/waters.json missing or invalid');
    }
    return $waters;
}


function tracker_point_in_ring(float $lat, float $lng, array $ring): bool {
    $inside = false;
    $n = count($ring);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = $ring[$i][0]; $yi = $ring[$i][1];
        $xj = $ring[$j][0]; $yj = $ring[$j][1];
        if ((($yi > $lat) !== ($yj > $lat))
            && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
            $inside = !$inside;
        }
    }
    return $inside;
}


function tracker_municipalities_inside(float $lat, float $lng): array {
    $inside = [];
    foreach (tracker_waters() as $name => $ring) {
        if (tracker_point_in_ring($lat, $lng, $ring)) {
            $inside[] = $name;
        }
    }
    return $inside;
}




function tracking_update_zones(mysqli $conn, int $trackerId, float $lat, float $lng, bool $commercial): array {
    $inside = $commercial ? tracker_municipalities_inside($lat, $lng) : [];


    $grace  = (int)TRACKER_EXIT_GRACE_SECONDS;
    $sql    = 'DELETE FROM tracker_zone_state WHERE tracker_id = ?';
    $types  = 'i';
    $params = [$trackerId];
    if ($inside) {
        $sql    .= ' AND municipality NOT IN (' . implode(',', array_fill(0, count($inside), '?')) . ')';
        $types  .= str_repeat('s', count($inside));
        $params  = array_merge($params, $inside);
    }
    $sql    .= ' AND last_inside_at < (NOW() - INTERVAL ? SECOND)';
    $types  .= 'i';
    $params[] = $grace;
    $del = $conn->prepare($sql);
    $del->bind_param($types, ...$params);
    $del->execute();
    $del->close();


    if ($inside) {
        $up = $conn->prepare(
            'INSERT INTO tracker_zone_state (tracker_id, municipality, inside_since, last_inside_at)
             VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE last_inside_at = NOW()'
        );
        foreach ($inside as $name) {
            $up->bind_param('is', $trackerId, $name);
            $up->execute();
        }
        $up->close();
    }
    return $inside;
}
