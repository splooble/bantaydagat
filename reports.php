<?php

require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/layout.php';
require_once 'includes/requirements.php';
require_once 'includes/tracking_settings.php';
require_once 'includes/settings.php';

requireRole(MAO_ROLES);
$user    = getCurrentUser();
$isSuper = isSuperadmin();

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

const RP_TYPES = [
    'registration' => 'Fisherfolk Registration',
    'boats'        => 'Municipal Boats',
    'permits'      => 'Permits',
    'incidents'    => 'Incidents',
    'offenses'     => 'Offenses by Violation',
    'vessels'      => 'Vessel Monitoring',
];

const RP_DATED = ['registration', 'permits', 'incidents', 'offenses'];


function rp_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function rp_date($v, string $default): string {
    $v = (string)$v;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : $default;
}


function rp_query(mysqli $c, string $sql, string $types = '', array $params = []): array {
    $st = $c->prepare($sql);
    if (!$st) throw new RuntimeException('prepare failed: ' . $c->error);
    if ($types !== '') $st->bind_param($types, ...$params);
    if (!$st->execute()) { $err = $st->error; $st->close(); throw new RuntimeException('execute failed: ' . $err); }
    $res  = $st->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    return $rows;
}


function rp_count(array $rows, callable $key, bool $keepOrder = false): array {
    $out = [];
    foreach ($rows as $r) {
        $k = trim((string)$key($r));
        if ($k === '') $k = 'Unspecified';
        $out[$k] = ($out[$k] ?? 0) + 1;
    }
    $keepOrder ? ksort($out) : arsort($out);
    return $out;
}

function rp_chart(string $id, string $title, string $kind, array $counts, int $limit = 10): array {
    $counts = array_slice($counts, 0, $limit, true);
    return ['id' => $id, 'title' => $title, 'kind' => $kind, 'labels' => array_keys($counts), 'data' => array_values($counts)];
}

function rp_fullname(array $r, string $f = 'first_name', string $m = 'middle_name', string $l = 'last_name'): string {
    return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([$r[$f] ?? '', $r[$m] ?? '', $r[$l] ?? '']))));
}


function rp_scope(string $col, string $mun, string &$sql, string &$types, array &$params): void {
    if ($mun === '') return;
    $sql .= " AND $col = ?"; $types .= 's'; $params[] = $mun;
}



function rp_build(mysqli $c, string $type, string $mun, string $from, string $to): array
{
    $types = ''; $params = []; $rep = ['csv_only' => []];

    switch ($type) {

    case 'registration':
        $sql = "SELECT f.boat_registry_number, f.first_name, f.middle_name, f.last_name, f.sex, f.barangay, f.municipality,
                       f.boat_ownership, f.fishing_environment, f.years_fishing, f.profile_status, DATE(f.created_at) AS registered
                  FROM fisherfolk f
                 WHERE f.created_at >= ? AND f.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
        $types = 'ss'; $params = [$from, $to];
        rp_scope('f.municipality', $mun, $sql, $types, $params);
        $sql .= ' ORDER BY f.created_at DESC';
        $rows = rp_query($c, $sql, $types, $params);
        foreach ($rows as &$r) { $r['name'] = rp_fullname($r); } unset($r);

        $rep['cols'] = [
            'boat_registry_number' => 'Registry No.', 'name' => 'Name', 'sex' => 'Sex', 'barangay' => 'Barangay',
            'municipality' => 'Municipality', 'boat_ownership' => 'Boat Role', 'fishing_environment' => 'Fishing Environment',
            'years_fishing' => 'Years Fishing', 'profile_status' => 'Profile Status', 'registered' => 'Registered',
        ];
        $male   = count(array_filter($rows, fn($r) => strcasecmp((string)$r['sex'], 'Male') === 0));
        $female = count(array_filter($rows, fn($r) => strcasecmp((string)$r['sex'], 'Female') === 0));
        $owners = count(array_filter($rows, fn($r) => strcasecmp((string)$r['boat_ownership'], 'Owner') === 0));
        $rep['cards'] = [
            ['Registered fisherfolk', count($rows), 'in the selected period'],
            ['Male', $male, ''], ['Female', $female, ''],
            ['Boat owners', $owners, 'registered as owner'],
        ];
        $rep['charts'] = [
            $mun === ''
                ? rp_chart('c1', 'Registrations by municipality', 'bar', rp_count($rows, fn($r) => $r['municipality']))
                : rp_chart('c1', 'Registrations by barangay (top 10)', 'bar', rp_count($rows, fn($r) => $r['barangay'])),
            rp_chart('c2', 'Registrations per month', 'line', rp_count($rows, fn($r) => substr((string)$r['registered'], 0, 7), true), 24),
        ];
        $rep['rows'] = $rows;
        return $rep;

    case 'boats':

        $sql = "SELECT b.name AS boat_name, b.boat_type, b.material, b.homeport, b.length_m, b.breadth_m, b.depth_m,
                       b.gross_tonnage, b.horsepower, b.engine_make, b.no_fishers,
                       f.first_name, f.last_name, f.boat_registry_number, f.municipality
                  FROM boats b
                  JOIN fisherfolk f ON f.id = b.fisherfolk_id
                 WHERE (b.gross_tonnage IS NULL OR b.gross_tonnage <= " . (float)TRACKER_COMMERCIAL_MIN_GT . ")";
        rp_scope('f.municipality', $mun, $sql, $types, $params);
        $sql .= ' ORDER BY f.municipality, b.name';
        $rows = rp_query($c, $sql, $types, $params);
        foreach ($rows as &$r) { $r['owner'] = rp_fullname($r, 'first_name', 'none', 'last_name'); } unset($r);

        $rep['cols'] = [
            'boat_name' => 'Boat', 'owner' => 'Owner', 'boat_registry_number' => 'Registry No.', 'municipality' => 'Municipality',
            'boat_type' => 'Type', 'material' => 'Material', 'homeport' => 'Homeport', 'length_m' => 'Length (m)',
            'gross_tonnage' => 'Gross Tonnage', 'horsepower' => 'Horsepower', 'engine_make' => 'Engine', 'no_fishers' => 'Fishers',
        ];
        $rep['csv_only'] = ['breadth_m', 'depth_m'];
        $rep['cols_csv_extra'] = ['breadth_m' => 'Breadth (m)', 'depth_m' => 'Depth (m)'];
        $motorized = count(array_filter($rows, fn($r) => (float)($r['horsepower'] ?? 0) > 0));
        $rep['cards'] = [
            ['Municipal boats', count($rows), '3 GT or less'],
            ['Motorized', $motorized, 'with engine horsepower'],
            ['Non-motorized', count($rows) - $motorized, ''],
        ];
        $rep['charts'] = [
            rp_chart('c1', 'Boats by type', 'doughnut', rp_count($rows, fn($r) => $r['boat_type'])),
            $mun === ''
                ? rp_chart('c2', 'Boats by municipality', 'bar', rp_count($rows, fn($r) => $r['municipality']))
                : rp_chart('c2', 'Boats by material', 'bar', rp_count($rows, fn($r) => $r['material'])),
        ];
        $rep['rows'] = $rows;
        return $rep;

    case 'permits':
        $sql = "SELECT p.permit_number, p.permit_type, f.first_name, f.last_name, f.boat_registry_number, f.municipality,
                       p.issue_date, p.expiry_date, p.status
                  FROM permits p
                  JOIN fisherfolk f ON f.id = p.fisherfolk_id
                 WHERE p.issue_date >= ? AND p.issue_date < DATE_ADD(?, INTERVAL 1 DAY)";
        $types = 'ss'; $params = [$from, $to];
        rp_scope('f.municipality', $mun, $sql, $types, $params);
        $sql .= ' ORDER BY p.issue_date DESC, p.permit_number';
        $rows = rp_query($c, $sql, $types, $params);

        $today = date('Y-m-d');
        $soonDays = $mun !== '' ? (int)getSetting($mun, 'expiring_soon_days') : 30;
        if ($soonDays < 1) $soonDays = 30;
        $soonLimit = date('Y-m-d', strtotime("+$soonDays days"));
        $soon = 0;
        foreach ($rows as &$r) {
            $r['holder'] = rp_fullname($r, 'first_name', 'none', 'last_name');

            if ($r['status'] === 'Active' && $r['expiry_date'] !== null && $r['expiry_date'] < $today) $r['status'] = 'Expired';
            if ($r['status'] === 'Active' && $r['expiry_date'] !== null && $r['expiry_date'] <= $soonLimit) $soon++;
        } unset($r);

        $rep['cols'] = [
            'permit_number' => 'Permit No.', 'permit_type' => 'Type', 'holder' => 'Holder', 'boat_registry_number' => 'Registry No.',
            'municipality' => 'Municipality', 'issue_date' => 'Issued', 'expiry_date' => 'Expires', 'status' => 'Status',
        ];
        $by = rp_count($rows, fn($r) => $r['status']);
        $rep['cards'] = [
            ['Permits issued', count($rows), 'in the selected period'],
            ['Active', $by['Active'] ?? 0, ''],
            ['Expired', $by['Expired'] ?? 0, ''],
            ['Suspended', $by['Suspended'] ?? 0, ''],
            ['Expiring soon', $soon, "active, within $soonDays days"],
        ];
        $rep['charts'] = [
            rp_chart('c1', 'Permits by type', 'bar', rp_count($rows, fn($r) => $r['permit_type'])),
            rp_chart('c2', 'Permits by status', 'doughnut', $by),
        ];
        $rep['rows'] = $rows;
        return $rep;

    case 'incidents':
        $sql = "SELECT i.reported_at, COALESCE(v.name, 'Unspecified') AS violation, i.location_name, i.municipality,
                       i.status, i.reported_by_name, i.boat_registry_number, i.description
                  FROM incidents i
                  LEFT JOIN violation_catalog v ON v.id = i.violation_id
                 WHERE i.reported_at >= ? AND i.reported_at < DATE_ADD(?, INTERVAL 1 DAY)";
        $types = 'ss'; $params = [$from, $to];
        rp_scope('i.municipality', $mun, $sql, $types, $params);
        $sql .= ' ORDER BY i.reported_at DESC';
        $rows = rp_query($c, $sql, $types, $params);

        $rep['cols'] = [
            'reported_at' => 'Reported', 'violation' => 'Violation', 'location_name' => 'Location', 'municipality' => 'Municipality',
            'boat_registry_number' => 'Registry No.', 'status' => 'Status', 'reported_by_name' => 'Reported By',
        ];
        $rep['csv_only'] = ['description'];
        $rep['cols_csv_extra'] = ['description' => 'Description'];
        $by = rp_count($rows, fn($r) => $r['status']);
        $rep['cards'] = [
            ['Incidents', count($rows), 'in the selected period'],
            ['Pending', $by['Pending'] ?? 0, ''],
            ['Under investigation', $by['Under Investigation'] ?? 0, ''],
            ['Resolved', $by['Resolved'] ?? 0, ''],
        ];
        $rep['charts'] = [
            rp_chart('c1', 'Incidents by violation', 'bar', rp_count($rows, fn($r) => $r['violation'])),
            rp_chart('c2', 'Incidents by status', 'doughnut', $by),
        ];
        $rep['rows'] = $rows;
        return $rep;

    case 'offenses':
        $sql = "SELECT COALESCE(v.name, 'Unspecified') AS violation, COUNT(*) AS total,
                       COALESCE(SUM(i.status = 'Pending'), 0) AS pending,
                       COALESCE(SUM(i.status = 'Under Investigation'), 0) AS investigating,
                       COALESCE(SUM(i.status = 'Resolved'), 0) AS resolved,
                       COUNT(DISTINCT NULLIF(i.boat_registry_number, '')) AS boats
                  FROM incidents i
                  LEFT JOIN violation_catalog v ON v.id = i.violation_id
                 WHERE i.reported_at >= ? AND i.reported_at < DATE_ADD(?, INTERVAL 1 DAY)";
        $types = 'ss'; $params = [$from, $to];
        rp_scope('i.municipality', $mun, $sql, $types, $params);
        $sql .= ' GROUP BY v.id, v.name ORDER BY total DESC, violation';
        $rows = rp_query($c, $sql, $types, $params);


        $sql2 = "SELECT COUNT(*) AS n FROM (
                    SELECT i.boat_registry_number FROM incidents i
                     WHERE i.boat_registry_number IS NOT NULL AND i.boat_registry_number <> ''
                       AND i.reported_at >= ? AND i.reported_at < DATE_ADD(?, INTERVAL 1 DAY)";
        $t2 = 'ss'; $p2 = [$from, $to];
        rp_scope('i.municipality', $mun, $sql2, $t2, $p2);
        $sql2 .= ' GROUP BY i.boat_registry_number HAVING COUNT(*) >= 2) x';
        $repeat = (int)(rp_query($c, $sql2, $t2, $p2)[0]['n'] ?? 0);

        $rep['cols'] = [
            'violation' => 'Violation', 'total' => 'Total', 'pending' => 'Pending', 'investigating' => 'Under Investigation',
            'resolved' => 'Resolved', 'boats' => 'Boats Involved',
        ];
        $sum = array_sum(array_map(fn($r) => (int)$r['total'], $rows));
        $res = array_sum(array_map(fn($r) => (int)$r['resolved'], $rows));
        $rep['cards'] = [
            ['Offenses recorded', $sum, 'in the selected period'],
            ['Violation types', count($rows), ''],
            ['Resolution rate', $sum > 0 ? round($res / $sum * 100) . '%' : '—', 'resolved of total'],
            ['Repeat offenders', $repeat, 'boats with 2 or more incidents'],
        ];
        $chartData = [];
        foreach ($rows as $r) $chartData[$r['violation']] = (int)$r['total'];
        $rep['charts'] = [rp_chart('c1', 'Offenses by violation type', 'bar', $chartData)];
        $rep['rows'] = $rows;
        return $rep;

    case 'vessels':
        $sql = "SELECT t.id, t.device_id, t.last_seen_at, TIMESTAMPDIFF(SECOND, t.last_seen_at, NOW()) AS seen_age,
                       b.name AS boat_name, b.gross_tonnage,
                       f.first_name, f.last_name, f.boat_registry_number, f.municipality AS owner_mun
                  FROM trackers t
                  LEFT JOIN boats b      ON b.id = t.boat_id
                  LEFT JOIN fisherfolk f ON f.id = b.fisherfolk_id
                 WHERE t.active = 1";
        if ($mun !== '') {

            $sql .= ' AND (f.municipality = ? OR t.id IN (SELECT z.tracker_id FROM tracker_zone_state z WHERE z.municipality = ?))';
            $types = 'ss'; $params = [$mun, $mun];
        }
        $sql .= ' ORDER BY t.device_id';
        $rows = rp_query($c, $sql, $types, $params);

        $zones = [];
        foreach (rp_query($c, 'SELECT tracker_id, municipality, TIMESTAMPDIFF(SECOND, inside_since, NOW()) AS inside_s FROM tracker_zone_state') as $z) {
            $zones[(int)$z['tracker_id']][] = $z;
        }

        $online = $stale = $commercialInside = $alerts = 0;
        $classes = [];
        foreach ($rows as &$r) {
            $r['owner']      = rp_fullname($r, 'first_name', 'none', 'last_name');
            $commercial      = tracker_is_commercial($r['gross_tonnage']);
            $r['class']      = $commercial ? 'Commercial' : 'Municipal';
            $age             = $r['seen_age'] === null ? null : (int)$r['seen_age'];
            if ($age === null)                         { $r['signal'] = 'No signal yet'; $stale++; }
            elseif ($age > TRACKER_STALE_SECONDS)      { $r['signal'] = 'Stale';         $stale++; }
            else                                       { $r['signal'] = 'Online';        $online++; }

            $inside = []; $alert = [];
            foreach ($zones[(int)$r['id']] ?? [] as $z) {
                $secs = max(0, (int)$z['inside_s']);
                $inside[] = $z['municipality'] . ' (' . max(0, intdiv($secs, 60)) . ' min)';
                if ($secs >= max(1, (int)getSetting((string)$z['municipality'], 'encroach_seconds'))) $alert[] = $z['municipality'];
            }
            $r['inside'] = $inside ? implode(', ', $inside) : '—';
            $r['alert']  = $alert ? 'Encroaching: ' . implode(', ', $alert) : 'None';
            if ($commercial && $inside) $commercialInside++;
            if ($alert) $alerts++;
            $classes[$r['class']] = ($classes[$r['class']] ?? 0) + 1;
        } unset($r);

        $rep['cols'] = [
            'device_id' => 'Device', 'boat_name' => 'Boat', 'owner' => 'Owner', 'boat_registry_number' => 'Registry No.',
            'owner_mun' => 'Home Municipality', 'class' => 'Class', 'gross_tonnage' => 'Gross Tonnage', 'signal' => 'Signal',
            'last_seen_at' => 'Last Heard', 'inside' => 'Inside Waters Of', 'alert' => 'Alert',
        ];
        $rep['cards'] = [
            ['Tracked vessels', count($rows), 'active trackers'],
            ['Online', $online, ''],
            ['Stale / no signal', $stale, 'not heard recently'],
            ['Commercial inside municipal waters', $commercialInside, 'right now'],
            ['Active alerts', $alerts, 'past the allowed time'],
        ];
        $rep['charts'] = [
            rp_chart('c1', 'Signal status', 'doughnut', ['Online' => $online, 'Stale / no signal' => $stale]),
            rp_chart('c2', 'Vessel class', 'doughnut', $classes),
        ];
        $rep['rows'] = $rows;
        return $rep;
    }
    return ['cols' => [], 'rows' => [], 'cards' => [], 'charts' => [], 'csv_only' => []];
}


$type = (string)($_GET['report'] ?? 'registration');
if (!isset(RP_TYPES[$type])) $type = 'registration';

$from = rp_date($_GET['from'] ?? '', date('Y-01-01'));
$to   = rp_date($_GET['to']   ?? '', date('Y-m-d'));
if ($from > $to) { [$from, $to] = [$to, $from]; }

if ($isSuper) {
    $m       = (string)($_GET['mun'] ?? '');
    $mun     = isset(MUN_CODES[$m]) ? $m : '';
    $noScope = false;
} else {
    $mun     = (string)($user['mun'] ?? '');
    $noScope = ($mun === '');
}
$usesDates = in_array($type, RP_DATED, true);

$munLabel  = $mun !== '' ? $mun : 'All Municipalities';
$officeName = $mun !== '' ? getSetting($mun, 'office_name') . ', ' . $mun : 'BantayDagat: Connect - All Municipalities';
$periodText = $usesDates
    ? date('F j, Y', strtotime($from)) . ' to ' . date('F j, Y', strtotime($to))
    : 'Current records as of ' . date('F j, Y g:i A');
$generated = date('F j, Y g:i A');


$rep = null; $loadError = false;
if (!$noScope) {
    try {
        $rep = rp_build($conn, $type, $mun, $from, $to);
    } catch (Throwable $e) {
        error_log('reports.php: ' . $e->getMessage());
        $loadError = true;
    }
}


if (($_GET['export'] ?? '') === 'csv') {
    if ($rep === null) { http_response_code(500); header('Content-Type: text/plain'); echo 'Report could not be generated.'; exit(); }
    $cols = $rep['cols'] + ($rep['cols_csv_extra'] ?? []);

    $safe = function ($v) {
        $v = (string)($v ?? '');
        return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v)) ? "'" . $v : $v;
    };
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bantaydagat_' . $type . '_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    foreach ([[RP_TYPES[$type]], ['Office', $officeName], ['Period', $periodText], ['Generated by', $user['name'] . ' on ' . $generated], []] as $meta) {
        fputcsv($out, $meta, ',', '"', '');
    }
    fputcsv($out, array_values($cols), ',', '"', '');
    foreach ($rep['rows'] as $r) {
        $line = [];
        foreach (array_keys($cols) as $k) $line[] = $safe($r[$k] ?? '');
        fputcsv($out, $line, ',', '"', '');
    }
    fclose($out);
    exit();
}


$csvUrl = 'reports.php?' . http_build_query(array_filter([
    'report' => $type, 'mun' => $isSuper ? $mun : null,
    'from' => $usesDates ? $from : null, 'to' => $usesDates ? $to : null, 'export' => 'csv',
], fn($v) => $v !== null && $v !== ''));

$rowCount = $rep ? count($rep['rows']) : 0;
pageHead('Reports & Analytics');
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style id="page-size-style"></style>
<style>
    .rp-card { background:#fff; border:1px solid #d5e6f1; border-radius:16px; box-shadow:0 4px 18px rgba(10,47,79,.05); }
    .rp-stat { padding:16px 18px; }
    .rp-stat .v { font-size:26px; font-weight:800; color:#0a2f4f; line-height:1.1; }
    .rp-stat .l { font-size:12px; font-weight:700; color:#16324a; margin-top:4px; }
    .rp-stat .s { font-size:11px; color:#7d93a5; margin-top:2px; }
    .rp-grid { display:grid; gap:14px; }
    .rp-table-wrap { overflow-x:auto; }
    .rp-table { width:100%; border-collapse:collapse; font-size:13px; }
    .rp-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:#5a7185; background:#f3f9fd; padding:9px 12px; border-bottom:1.5px solid #d5e6f1; white-space:nowrap; }
    .rp-table td { padding:9px 12px; border-bottom:1px solid #e8f1f7; color:#16324a; vertical-align:top; }
    .rp-table tbody tr:nth-child(even) td { background:#fafdff; }
    .rp-table td.num, .rp-table th.num { text-align:right; font-variant-numeric:tabular-nums; }
    .rp-chart-box { padding:14px 18px 18px; }
    .rp-chart-box h3 { font-size:13px; font-weight:700; color:#16324a; margin:0 0 10px; }
    .rp-chart-canvas { position:relative; height:240px; width:100%; min-width:0; overflow:hidden; }
    .rp-charts > * { min-width:0; }
    .rp-lock { display:inline-flex; align-items:center; padding:10px 12px; border:1.5px solid #d5e6f1; border-radius:9px; background:#f3f9fd; font-size:14px; font-weight:600; color:#16324a; }
    .rp-empty { padding:36px 20px; text-align:center; color:#5a7185; font-size:14px; }
    .print-only { display:none; }

    @media print {
        body { background:#fff !important; }
        #app-sidebar, .sidebar, .nav-toggle, .nav-backdrop, .no-print { display:none !important; }
        main { margin-left:0 !important; padding:0 !important; animation:none !important; opacity:1 !important; }
        .print-only { display:block; }
        .rp-card { box-shadow:none; border:1px solid #b9cbd8; }
        .rp-table { font-size:9pt; }
        .rp-table th, .rp-table td { padding:5px 7px; }
        .rp-table thead { display:table-header-group; }
        .rp-table tr { break-inside:avoid; }
        .rp-charts, .rp-cards { break-inside:avoid; }

        .rp-charts { grid-template-columns:1fr 1fr !important; }
        .rp-chart-box { min-width:0; overflow:hidden; break-inside:avoid; }
        .rp-chart-canvas { height:200px !important; overflow:hidden; }
        .rp-chart-canvas canvas { width:100% !important; height:100% !important; max-width:100% !important; }
        .rp-table-wrap { overflow:visible; }
        * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .rp-foot { position:fixed; bottom:0; left:0; font-size:8pt; color:#5a7185; }
    }

    @page { @bottom-right { content: "Page " counter(page) " of " counter(pages); font-size:9pt; color:#5a7185; } }
</style>
</head>

<body>
<?php sidebar('reports'); ?>

<main class="fade-up" style="margin-left:224px;min-height:100vh;padding:28px 32px;">


    <div class="no-print" style="margin-bottom:20px;">
        <h1 style="font-size:22px;font-weight:700;color:#16324a;margin:0 0 3px;">Reports &amp; Analytics</h1>
        <p style="font-size:13px;color:#5a7185;margin:0;">Review fisherfolk, boat, permit, incident, offense and vessel records, then print or export them.</p>
    </div>


    <form method="get" action="reports.php" class="rp-card no-print" style="padding:16px 18px;margin-bottom:18px;">
        <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;">
            <div style="min-width:210px;flex:1;">
                <label class="form-label" for="f-report">Report</label>
                <select id="f-report" name="report" class="form-input">
                    <?php foreach (RP_TYPES as $k => $label): ?>
                        <option value="<?= rp_e($k) ?>" <?= $k === $type ? 'selected' : '' ?> data-dated="<?= in_array($k, RP_DATED, true) ? '1' : '0' ?>"><?= rp_e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:200px;flex:1;">
                <label class="form-label" for="f-mun">Municipality</label>
                <?php if ($isSuper): ?>
                    <select id="f-mun" name="mun" class="form-input">
                        <option value="">All municipalities</option>
                        <?php foreach (array_keys(MUN_CODES) as $m): ?>
                            <option value="<?= rp_e($m) ?>" <?= $m === $mun ? 'selected' : '' ?>><?= rp_e($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <div class="rp-lock"><?= rp_e($mun !== '' ? $mun : 'No municipality assigned') ?></div>
                <?php endif; ?>
            </div>
            <div style="min-width:150px;">
                <label class="form-label" for="f-from">From</label>
                <input id="f-from" type="date" name="from" value="<?= rp_e($from) ?>" max="<?= rp_e(date('Y-m-d')) ?>" class="form-input rp-date" <?= $usesDates ? '' : 'disabled' ?>>
            </div>
            <div style="min-width:150px;">
                <label class="form-label" for="f-to">To</label>
                <input id="f-to" type="date" name="to" value="<?= rp_e($to) ?>" max="<?= rp_e(date('Y-m-d')) ?>" class="form-input rp-date" <?= $usesDates ? '' : 'disabled' ?>>
            </div>
            <button type="submit" class="btn-primary">Generate</button>
        </div>
        <p id="date-note" style="font-size:12px;color:#5a7185;margin:10px 0 0;<?= $usesDates ? 'display:none;' : '' ?>">This report shows the current records, so the date range does not apply.</p>
    </form>

    <?php if ($noScope): ?>
        <div class="rp-card rp-empty">Your account is not assigned to a municipality yet, so there are no records to report. Please ask the system administrator to assign one.</div>

    <?php elseif ($loadError || $rep === null): ?>
        <div class="rp-card rp-empty">This report could not be loaded right now. Please try again, and tell the system administrator if it keeps happening.</div>

    <?php else: ?>


    <div class="no-print" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;justify-content:space-between;margin-bottom:14px;">
        <div style="font-size:13px;color:#5a7185;">
            <strong style="color:#16324a;"><?= rp_e(RP_TYPES[$type]) ?></strong> &middot; <?= rp_e($munLabel) ?> &middot; <?= (int)$rowCount ?> <?= $rowCount === 1 ? 'row' : 'rows' ?>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div>
                <label class="form-label" for="paper-size">Paper size</label>
                <select id="paper-size" class="form-input" style="min-width:190px;">
                    <option value="a4">A4 (210 &times; 297 mm)</option>
                    <option value="letter">Letter / Short bond (8.5 &times; 11 in)</option>
                    <option value="folio">Folio / Long bond (8.5 &times; 13 in)</option>
                    <option value="legal">Legal (8.5 &times; 14 in)</option>
                </select>
            </div>
            <div>
                <label class="form-label" for="paper-orient">Orientation</label>
                <select id="paper-orient" class="form-input">
                    <option value="portrait">Portrait</option>
                    <option value="landscape">Landscape</option>
                </select>
            </div>
            <button type="button" class="btn-primary" onclick="window.print()">Print / Save as PDF</button>
            <a href="<?= rp_e($csvUrl) ?>" class="btn-secondary" style="text-decoration:none;">Export CSV</a>
        </div>
    </div>
    <p class="no-print" style="font-size:12px;color:#7d93a5;margin:0 0 16px;">In the print window, choose &ldquo;Save as PDF&rdquo; as the printer and make sure the paper size there matches the one picked above.</p>


    <div class="print-only" style="margin-bottom:12px;">
        <div style="display:flex;align-items:center;gap:12px;border-bottom:2px solid #0a2f4f;padding-bottom:10px;">
            <img src="assets/idcard/logo.png" alt="" style="height:52px;width:auto;">
            <div>
                <div style="font-size:15pt;font-weight:800;color:#0a2f4f;"><?= rp_e($officeName) ?></div>
                <div style="font-size:10pt;color:#5a7185;">BantayDagat: Connect &middot; Fisheries Registration and Coastal Monitoring System</div>
            </div>
        </div>
        <div style="margin-top:10px;">
            <div style="font-size:14pt;font-weight:800;color:#16324a;"><?= rp_e(RP_TYPES[$type]) ?> Report</div>
            <div style="font-size:10pt;color:#16324a;"><?= rp_e($munLabel) ?> &middot; <?= rp_e($periodText) ?></div>
        </div>
    </div>


    <?php if ($rep['cards']): ?>
    <div class="rp-grid rp-cards" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));margin-bottom:16px;">
        <?php foreach ($rep['cards'] as [$label, $value, $sub]): ?>
            <div class="rp-card rp-stat">
                <div class="v"><?= rp_e(is_numeric($value) ? number_format((float)$value) : $value) ?></div>
                <div class="l"><?= rp_e($label) ?></div>
                <?php if ($sub !== ''): ?><div class="s"><?= rp_e($sub) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>


    <?php $hasChartData = false; foreach ($rep['charts'] as $ch) if (array_sum($ch['data']) > 0) $hasChartData = true; ?>
    <?php if ($hasChartData): ?>
    <div class="rp-grid rp-charts" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));margin-bottom:16px;">
        <?php foreach ($rep['charts'] as $ch): if (array_sum($ch['data']) <= 0) continue; ?>
            <div class="rp-card rp-chart-box">
                <h3><?= rp_e($ch['title']) ?></h3>
                <div class="rp-chart-canvas"><canvas id="<?= rp_e($ch['id']) ?>"></canvas></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>


    <div class="rp-card">
        <?php if ($rowCount === 0): ?>
            <div class="rp-empty">No records match these filters.</div>
        <?php else:
            $showCols = array_diff_key($rep['cols'], array_flip($rep['csv_only']));
            $numeric  = ['total','pending','investigating','resolved','boats','length_m','gross_tonnage','horsepower','no_fishers','years_fishing'];
        ?>
        <div class="rp-table-wrap">
            <table class="rp-table">
                <thead><tr>
                    <?php foreach ($showCols as $k => $label): ?>
                        <th class="<?= in_array($k, $numeric, true) ? 'num' : '' ?>"><?= rp_e($label) ?></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($rep['rows'] as $r): ?>
                    <tr>
                        <?php foreach (array_keys($showCols) as $k):
                            $v = $r[$k] ?? null; ?>
                            <td class="<?= in_array($k, $numeric, true) ? 'num' : '' ?>"><?= ($v === null || $v === '') ? '&mdash;' : rp_e($v) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>


    <div class="print-only" style="margin-top:34px;break-inside:avoid;">
        <div style="display:flex;gap:60px;font-size:10pt;color:#16324a;">
            <div style="flex:1;">Prepared by:<div style="border-bottom:1px solid #16324a;height:34px;"></div>
                <div style="margin-top:3px;font-weight:700;"><?= rp_e($user['name']) ?></div>
                <div style="color:#5a7185;"><?= rp_e($user['position'] !== '' ? $user['position'] : 'Authorized personnel') ?></div></div>
            <div style="flex:1;">Noted by:<div style="border-bottom:1px solid #16324a;height:34px;"></div>
                <div style="margin-top:3px;color:#5a7185;">Municipal Agriculture Office Head</div></div>
        </div>
        <div style="margin-top:14px;font-size:8.5pt;color:#7d93a5;">Generated <?= rp_e($generated) ?> by <?= rp_e($user['name']) ?>. Figures reflect the records in the system at that time.</div>
    </div>
    <div class="print-only rp-foot">BantayDagat: Connect &middot; <?= rp_e(RP_TYPES[$type]) ?> &middot; <?= rp_e($munLabel) ?></div>

    <?php endif; ?>
</main>

<script>
(function () {

    var PAPERS = { a4: ['210mm', '297mm'], letter: ['8.5in', '11in'], folio: ['8.5in', '13in'], legal: ['8.5in', '14in'] };
    var sizeSel = document.getElementById('paper-size');
    var orientSel = document.getElementById('paper-orient');
    var styleEl = document.getElementById('page-size-style');

    function applyPage() {
        var p = PAPERS[sizeSel ? sizeSel.value : 'a4'] || PAPERS.a4;
        var land = orientSel && orientSel.value === 'landscape';
        var w = land ? p[1] : p[0], h = land ? p[0] : p[1];
        styleEl.textContent = '@page { size: ' + w + ' ' + h + '; margin: 14mm 12mm 16mm; }';
        try { localStorage.setItem('bd_report_paper', JSON.stringify({ s: sizeSel.value, o: orientSel.value })); } catch (e) {}
    }
    if (sizeSel && orientSel) {
        try {
            var saved = JSON.parse(localStorage.getItem('bd_report_paper') || 'null');
            if (saved && PAPERS[saved.s]) { sizeSel.value = saved.s; orientSel.value = saved.o === 'landscape' ? 'landscape' : 'portrait'; }
        } catch (e) {}
        sizeSel.addEventListener('change', applyPage);
        orientSel.addEventListener('change', applyPage);
    }
    applyPage();


    var reportSel = document.getElementById('f-report');
    if (reportSel) reportSel.addEventListener('change', function () {
        var dated = reportSel.options[reportSel.selectedIndex].getAttribute('data-dated') === '1';
        document.querySelectorAll('.rp-date').forEach(function (el) { el.disabled = !dated; });
        var note = document.getElementById('date-note');
        if (note) note.style.display = dated ? 'none' : '';
    });


    var CHARTS = <?= json_encode($rep ? $rep['charts'] : [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var COLORS = ['#006bb7', '#6fd3f5', '#054b7e', '#f59e0b', '#16a34a', '#dc2626', '#7c3aed', '#0a2f4f', '#94a3b8', '#e879f9'];
    if (window.Chart) {
        CHARTS.forEach(function (c) {
            var el = document.getElementById(c.id);
            if (!el) return;
            var pie = c.kind === 'doughnut';
            new Chart(el, {
                type: c.kind,
                data: { labels: c.labels, datasets: [{
                    data: c.data,
                    backgroundColor: pie ? COLORS : (c.kind === 'line' ? 'rgba(0,107,183,.15)' : '#006bb7'),
                    borderColor: c.kind === 'line' ? '#006bb7' : '#fff',
                    borderWidth: pie ? 2 : (c.kind === 'line' ? 2 : 0),
                    fill: c.kind === 'line', tension: .3, borderRadius: c.kind === 'bar' ? 6 : 0
                }] },
                options: {
                    responsive: true, maintainAspectRatio: false, animation: false,
                    plugins: { legend: { display: pie, position: 'right', labels: { boxWidth: 12, font: { size: 11 } } } },
                    scales: pie ? {} : { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false }, ticks: { font: { size: 11 } } } }
                }
            });
        });

        window.addEventListener('beforeprint', function () { Object.values(Chart.instances).forEach(function (ch) { ch.resize(); }); });
        window.addEventListener('afterprint',  function () { Object.values(Chart.instances).forEach(function (ch) { ch.resize(); }); });
    }
})();
</script>
</body>
</html>
