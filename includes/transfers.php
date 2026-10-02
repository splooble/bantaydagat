<?php



function tfHas($conn, string $what = 'all'): bool {
    static $cache = [];
    if ($what === 'all') return tfHas($conn, 'cols') && tfHas($conn, 'history') && tfHas($conn, 'requests');
    if (isset($cache[$what])) return $cache[$what];
    $q = [
        'cols'     => "SHOW COLUMNS FROM fisherfolk LIKE 'transferred_at'",
        'history'  => "SHOW TABLES LIKE 'registry_number_history'",
        'requests' => "SHOW COLUMNS FROM transfer_requests LIKE 'request_note'",
    ][$what] ?? null;
    if ($q === null) return false;
    try { $r = $conn->query($q); $ok = $r && $r->num_rows > 0; } catch (Throwable $e) { $ok = false; }
    return $cache[$what] = $ok;
}


function heldTypesFor($conn, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = array_fill_keys($ids, []);
    if (!$ids) return $out;
    $in  = implode(',', $ids);
    $cut = tfHas($conn, 'cols');
    $sql = "SELECT d.fid, d.t FROM (
                SELECT fisherfolk_id AS fid, permit_type AS t, issue_date AS idt FROM permits WHERE fisherfolk_id IN ($in)
                UNION ALL
                SELECT fisherfolk_id, doc_type, issue_date FROM registration_documents WHERE fisherfolk_id IN ($in)
            ) d" . ($cut ? " JOIN fisherfolk f ON f.id = d.fid WHERE (f.transferred_at IS NULL OR d.idt >= DATE(f.transferred_at))" : '');
    $q = $conn->query($sql);
    if ($q) while ($r = $q->fetch_assoc()) { if (!in_array($r['t'], $out[(int)$r['fid']], true)) $out[(int)$r['fid']][] = $r['t']; }
    return $out;
}


function heldTypes($conn, $ffid) {
    $m = heldTypesFor($conn, [(int)$ffid]);
    return $m[(int)$ffid] ?? [];
}


function holdTextFor($conn, array $rows): array {
    $ids  = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'id')))));
    $held = heldTypesFor($conn, $ids);
    $tf = [];
    if ($ids && tfHas($conn, 'cols')) {
        $q = $conn->query("SELECT id FROM fisherfolk WHERE is_transferee = 1 AND id IN (" . implode(',', $ids) . ")");
        if ($q) while ($r = $q->fetch_assoc()) $tf[(int)$r['id']] = true;
    }
    $out = [];
    foreach ($rows as $r) $out[(int)$r['id']] = holdText($held[(int)$r['id']] ?? [], $r['municipality'], $r['boat_ownership'], isset($tf[(int)$r['id']]));
    return $out;
}


function nextSeq($conn, $code, $year) {
    $st = $conn->prepare("INSERT INTO id_counters (mun_code, yr, last_seq) VALUES (?, ?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)");
    $st->bind_param('si', $code, $year); $st->execute(); $st->close();
    return (int)$conn->query("SELECT LAST_INSERT_ID() AS n")->fetch_assoc()['n'];
}


function makeRegistryNumber($conn, string $municipality): string {
    $code = MUN_CODES[$municipality]; $year = (int)date('Y');
    return $code . '-VES-' . $year . '-' . str_pad((string)nextSeq($conn, $code, $year), 4, '0', STR_PAD_LEFT);
}


function expireTransfers($conn): void {
    if (!tfHas($conn, 'all')) return;
    $conn->query("UPDATE transfer_requests SET status = 'Expired' WHERE status = 'Pending' AND expires_at < NOW()");
}


function transferWindowDays(string $receivingMun): int {
    $d = function_exists('getSetting') ? (int)getSetting($receivingMun, 'transfer_window_days') : 0;
    return ($d >= 1 && $d <= 30) ? $d : 7;
}


function canHandleTransfers(): bool {
    $role = $_SESSION['user_role'] ?? '';
    return in_array($role, ['admin', 'mao_staff'], true) && !empty($_SESSION['user_mun']) && isset(MUN_CODES[$_SESSION['user_mun']]);
}


function incomingSummary($conn, string $mun): array {
    $empty = ['pending' => 0, 'unseen' => 0, 'secs' => null, 'oldest' => null];
    if (!tfHas($conn, 'all')) return $empty;
    expireTransfers($conn);
    $st = $conn->prepare("SELECT COUNT(*) AS pending, COALESCE(SUM(seen_at IS NULL), 0) AS unseen,
                                 MIN(GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at))) AS secs, MIN(requested_at) AS oldest
                          FROM transfer_requests WHERE to_municipality = ? AND status = 'Pending'");
    $st->bind_param('s', $mun); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return ['pending' => (int)$r['pending'], 'unseen' => (int)$r['unseen'], 'secs' => $r['secs'] === null ? null : (int)$r['secs'], 'oldest' => $r['oldest']];
}


function tf_left(?int $secs): string {
    if ($secs === null) return '';
    if ($secs <= 0) return 'expired';
    if ($secs >= 172800) return floor($secs / 86400) . ' days';
    if ($secs >= 86400)  return '1 day';
    if ($secs >= 7200)   return floor($secs / 3600) . ' hours';
    if ($secs >= 3600)   return '1 hour';
    return max(1, (int)floor($secs / 60)) . ' minutes';
}


function transferInfo($conn, int $ffid, array $ff): array {
    $info = ['ready' => false, 'hold' => null, 'is_transferee' => 0, 'transferred_at' => null, 'id_reprint_needed' => 0,
             'old_numbers' => [], 'pending' => null, 'last' => null];
    $info['hold'] = holdTextFor($conn, [['id' => $ffid, 'municipality' => $ff['municipality'], 'boat_ownership' => $ff['boat_ownership']]])[$ffid];
    if (!tfHas($conn, 'all')) return $info;
    $info['ready'] = true;
    expireTransfers($conn);
    $r = $conn->query("SELECT is_transferee, transferred_at, id_reprint_needed FROM fisherfolk WHERE id = $ffid")->fetch_assoc();
    $info['is_transferee'] = (int)$r['is_transferee']; $info['transferred_at'] = $r['transferred_at']; $info['id_reprint_needed'] = (int)$r['id_reprint_needed'];
    $h = $conn->query("SELECT old_number, to_municipality, changed_at FROM registry_number_history WHERE fisherfolk_id = $ffid ORDER BY id DESC");
    while ($row = $h->fetch_assoc()) $info['old_numbers'][] = $row;
    $p = $conn->query("SELECT id, from_municipality, to_municipality, requested_at, expires_at, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS secs
                       FROM transfer_requests WHERE fisherfolk_id = $ffid AND status = 'Pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    if ($p) { $p['secs'] = (int)$p['secs']; $p['left'] = tf_left($p['secs']); $info['pending'] = $p; }
    else {
        $l = $conn->query("SELECT id, from_municipality, to_municipality, status, response_note, responded_at, requested_at
                           FROM transfer_requests WHERE fisherfolk_id = $ffid AND status IN ('Rejected','Expired') ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $info['last'] = $l ?: null;
    }
    return $info;
}
