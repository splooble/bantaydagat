<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/layout.php';
require_once 'includes/requirements.php';
require_once 'includes/tracking_settings.php';
requireRole(array_merge(MAO_ROLES, OFFICER_ROLES));
$user = getCurrentUser();
$isOfficer = isOfficer();

$conn->query("UPDATE permits SET status='Expired' WHERE expiry_date < CURDATE() AND status='Active'");

$isAdmin      = $user['role'] === 'superadmin';
$munWhere_ff  = !$isAdmin ? " AND municipality='{$conn->real_escape_string($user['mun'])}'" : '';
$munWhere_inc = !$isAdmin ? " AND municipality='{$conn->real_escape_string($user['mun'])}'" : '';

$ff_shown = 100;
$ff_total = (int)$conn->query("SELECT COUNT(*) AS c FROM fisherfolk WHERE 1=1$munWhere_ff")->fetch_assoc()['c'];
$all_ff = $conn->query("SELECT boat_registry_number, first_name, last_name, municipality, created_at FROM fisherfolk WHERE 1=1$munWhere_ff ORDER BY created_at DESC LIMIT $ff_shown");

$recent_inc = $conn->query("SELECT v.name AS violation_type, i.municipality, i.status, i.reported_at FROM incidents i JOIN violation_catalog v ON v.id = i.violation_id WHERE 1=1" . str_replace(' AND municipality=', ' AND i.municipality=', $munWhere_inc) . " ORDER BY i.reported_at DESC LIMIT 4");

$total_ff = $active_permits = $expired_permits = $suspended_permits = $new_this_month = $boat_expired = $valid_pct = 0;
$mun_labels = $mun_counts = $month_labels = $month_counts = [];
$recent_ff = $recent_permits = $recent_violations = null;
$user_stats = ['total' => 0, 'active' => 0, 'inactive' => 0];

if (!$isOfficer) {
    $total_ff       = $ff_total;
    $new_this_month = (int)$conn->query("SELECT COUNT(*) AS c FROM fisherfolk WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())$munWhere_ff")->fetch_assoc()['c'];
    $ps = $conn->query("SELECT
            COALESCE(SUM(p.permit_type='Mayor''s Permit' AND p.status='Active'), 0)  AS mp_valid,
            COALESCE(SUM(p.permit_type='Mayor''s Permit' AND p.status='Expired'), 0) AS mp_expired,
            COALESCE(SUM(p.permit_type='BoatR' AND p.status='Expired'), 0)           AS boat_expired,
            COALESCE(SUM(p.status='Suspended'), 0)                                   AS suspended
        FROM permits p JOIN fisherfolk f ON f.id = p.fisherfolk_id WHERE 1=1$munWhere_ff")->fetch_assoc();
    $active_permits    = (int)$ps['mp_valid'];
    $expired_permits   = (int)$ps['mp_expired'];
    $boat_expired      = (int)$ps['boat_expired'];
    $suspended_permits = (int)$ps['suspended'];
    $valid_pct         = $total_ff > 0 ? round($active_permits / $total_ff * 100) : 0;
}

if ($isAdmin) {

    $mun_data_raw = $conn->query("SELECT municipality, COUNT(*) as total FROM fisherfolk GROUP BY municipality ORDER BY total DESC");
    $mun_labels = []; $mun_counts = [];
    while ($row = $mun_data_raw->fetch_assoc()) {
        $mun_labels[] = $row['municipality'];
        $mun_counts[] = (int)$row['total'];
    }

    $monthly_raw = $conn->query("
        SELECT DATE_FORMAT(created_at,'%b %Y') as month_label,
               DATE_FORMAT(created_at,'%Y-%m') as month_key,
               COUNT(*) as total
        FROM fisherfolk
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY month_key, month_label
        ORDER BY month_key ASC
    ");
    $month_labels = []; $month_counts = [];
    while ($row = $monthly_raw->fetch_assoc()) {
        $month_labels[] = $row['month_label'];
        $month_counts[] = (int)$row['total'];
    }

    $recent_ff = $conn->query("
        SELECT f.first_name, f.last_name, f.municipality, f.barangay,
               f.boat_registry_number, f.created_at, u.name as reg_by
        FROM fisherfolk f
        LEFT JOIN users u ON f.registered_by=u.id
        ORDER BY f.created_at DESC LIMIT 6
    ");

    $recent_permits = $conn->query("
        SELECT p.permit_number, p.permit_type, p.status,
               p.issue_date, p.expiry_date,
               f.first_name, f.last_name, f.municipality
        FROM permits p
        JOIN fisherfolk f ON p.fisherfolk_id=f.id
        ORDER BY p.issue_date DESC LIMIT 6
    ");

    $recent_violations = $conn->query("
        SELECT p.permit_number, p.permit_type,
               f.first_name, f.last_name, f.municipality,
               p.issue_date as flagged_at
        FROM permits p
        JOIN fisherfolk f ON p.fisherfolk_id=f.id
        WHERE p.status='Suspended'
        ORDER BY p.issue_date DESC LIMIT 5
    ");

    $user_stats = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(status='Active'),0) AS active, COALESCE(SUM(status<>'Active'),0) AS inactive FROM users")->fetch_assoc();
}

$map_waters = json_decode((string)@file_get_contents(__DIR__ . '/includes/waters.json'), true) ?: ['features' => []];
$mun_names  = array_map(function ($f) { return $f['properties']['name']; }, $map_waters['features']);
$map_scope_mun = !$isAdmin ? $user['mun'] : null;

function renderStatCards(array $cards) {
    echo '<div style="display:grid;grid-template-columns:repeat(' . count($cards) . ',1fr);gap:16px;margin-bottom:8px;">';
    foreach ($cards as $i => $c) {
        echo '<div class="stat-card rise-in rise-d' . ($i + 1) . '">';
        echo '<div class="accent-bar" style="background:linear-gradient(90deg,' . $c['from'] . ',' . $c['to'] . ');"></div>';
        echo '<div class="stat-icon" style="background:' . $c['bg'] . ';">';
        echo '<svg width="18" height="18" fill="none" stroke="' . $c['from'] . '" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="' . $c['icon'] . '"/></svg>';
        echo '</div>';
        echo '<div class="stat-value" data-count="' . (int)$c['value'] . '">0</div>';
        echo '<div class="stat-label">' . htmlspecialchars($c['label']) . '</div>';
        echo '<div class="stat-sub">' . htmlspecialchars($c['sub']) . '</div>';
        echo '</div>';
    }
    echo '</div>';
}

$stat_cards = [
    ['from' => '#006bb7', 'to' => '#0a2f4f', 'bg' => 'rgba(0,107,183,.1)',  'value' => $total_ff,
     'label' => 'Total Fisherfolk', 'sub' => '+' . $new_this_month . ' this month',
     'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
    ['from' => '#10B981', 'to' => '#059669', 'bg' => 'rgba(16,185,129,.1)', 'value' => $active_permits,
     'label' => "Valid Mayor's Permits", 'sub' => $valid_pct . '% of registered fisherfolk',
     'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
    ['from' => '#EF4444', 'to' => '#DC2626', 'bg' => 'rgba(239,68,68,.1)',  'value' => $expired_permits,
     'label' => "Expired Mayor's Permits", 'sub' => 'Require renewal',
     'icon' => 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    ['from' => '#8B5CF6', 'to' => '#7C3AED', 'bg' => 'rgba(139,92,246,.1)', 'value' => $boat_expired,
     'label' => 'Expired Boat Registrations', 'sub' => 'BoatR past its expiry date',
     'icon' => 'M12 3v11m0 0H5.5a1 1 0 01-.8-1.6L11 3.5M12 14h6.5a1 1 0 00.8-1.6L13 3.5M4 18l1 2h14l1-2'],
    ['from' => '#F59E0B', 'to' => '#D97706', 'bg' => 'rgba(245,158,11,.1)', 'value' => $suspended_permits,
     'label' => 'Suspended', 'sub' => 'Flagged / under review',
     'icon' => 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636'],
];

pageHead('Dashboard');
?>

<link rel="stylesheet" href="https://cdn.datatables.net/2.3.7/css/dataTables.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.3.7/js/dataTables.min.js"></script>
<?php if ($isAdmin): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php endif; ?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
.dt-container { font-family: 'Instrument Sans', sans-serif; font-size: 13.5px; }
.dt-search input {
    border: 1.5px solid #d5e6f1 !important; border-radius: 9px !important;
    padding: 7px 12px !important; font-family: 'Instrument Sans', sans-serif !important;
    font-size: 13px !important; outline: none !important;
    background: rgba(255,255,255,.85) !important; transition: border .2s;
}
.dt-search input:focus { border-color: #006bb7 !important; }
.dt-search label, .dt-length label { font-size: 12px; font-weight: 600; color: #5a7185; }
.dt-length select {
    border: 1.5px solid #d5e6f1 !important; border-radius: 9px !important;
    padding: 6px 10px !important; font-family: 'Instrument Sans', sans-serif !important;
    font-size: 13px !important; outline: none !important;
    background: rgba(255,255,255,.85) !important;
}
.dt-info { font-size: 12px; color: #5a7185; }
.dt-paging .dt-paging-button {
    border-radius: 7px !important; font-size: 13px !important;
    padding: 4px 10px !important; font-family: 'Instrument Sans', sans-serif !important;
    border: 1px solid transparent !important;
}
.dt-paging .dt-paging-button.current {
    background: linear-gradient(135deg,#006bb7,#0a2f4f) !important;
    color: #fff !important; border: none !important;
}
.dt-paging .dt-paging-button:hover:not(.current) {
    background: rgba(0,107,183,.08) !important; color: #006bb7 !important;
    border-color: rgba(0,107,183,.2) !important;
}
table.dataTable thead th {
    background: rgba(234,246,253,.9) !important; color: #5a7185 !important;
    font-size: 10.5px !important; font-weight: 700 !important;
    text-transform: uppercase !important; letter-spacing: .08em !important;
    padding: 10px 14px !important; border-bottom: 1.5px solid #d5e6f1 !important;
}
table.dataTable tbody td {
    padding: 11px 14px !important;
    border-bottom: 1px solid rgba(213,230,241,.45) !important;
    color: #16324a !important; vertical-align: middle !important;
}
table.dataTable tbody tr:hover td { background: rgba(0,107,183,.03) !important; }
table.dataTable tbody tr:last-child td { border-bottom: none !important; }
table.dataTable { border-collapse: collapse !important; width: 100% !important; }

@keyframes riseIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}
.rise-in { opacity: 0; animation: riseIn .5s cubic-bezier(.16,1,.3,1) forwards; }
.rise-d1 { animation-delay: .03s; } .rise-d2 { animation-delay: .08s; }
.rise-d3 { animation-delay: .13s; } .rise-d4 { animation-delay: .18s; }
.rise-d5 { animation-delay: .23s; } .rise-d6 { animation-delay: .28s; }

@keyframes softPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,.45); }
    50%      { box-shadow: 0 0 0 8px rgba(239,68,68,0); }
}
@keyframes dotPulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50%      { transform: scale(1.5); opacity: .55; }
}

.section-label-row {
    display: flex; align-items: baseline; justify-content: space-between;
    margin: 30px 0 12px;
}
.section-label-row:first-of-type { margin-top: 0; }
.section-eyebrow {
    font-size: 11px; font-weight: 700; color: #006bb7; text-transform: uppercase;
    letter-spacing: .09em; margin: 0 0 2px;
}
.section-title { font-size: 16px; font-weight: 700; color: #16324a; margin: 0; }
.section-hint { font-size: 12px; color: #7d93a5; margin: 0; }

.stat-card {
    background: rgba(255,255,255,.94);
    border: 1px solid rgba(213,230,241,.55);
    border-radius: 16px; padding: 18px 20px;
    position: relative; overflow: hidden;
    transition: transform .2s cubic-bezier(.16,1,.3,1), box-shadow .2s;
}
.stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 28px rgba(10,47,79,.1); }
.stat-card .accent-bar {
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
    border-radius: 16px 16px 0 0;
}
.stat-card .stat-icon {
    width: 36px; height: 36px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; margin-bottom: 12px;
}
.stat-card .stat-value {
    font-size: 27px; font-weight: 800; color: #16324a;
    line-height: 1; margin-bottom: 3px; font-variant-numeric: tabular-nums;
}
.stat-card .stat-label { font-size: 11.5px; color: #5a7185; font-weight: 600; }
.stat-card .stat-sub   { font-size: 10.5px; color: #7d93a5; margin-top: 5px; }

.section-card {
    background: rgba(255,255,255,.94);
    border: 1px solid rgba(213,230,241,.55);
    border-radius: 16px; overflow: hidden;
    transition: box-shadow .2s;
}
.section-card:hover { box-shadow: 0 8px 24px rgba(10,47,79,.06); }
.section-card .card-head {
    padding: 13px 18px; border-bottom: 1px solid rgba(213,230,241,.5);
    display: flex; align-items: center; justify-content: space-between;
}
.section-card .card-head h2 {
    font-size: 13px; font-weight: 700; color: #16324a; margin: 0;
    display: flex; align-items: center; gap: 7px;
}
.activity-row {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 10px 18px; border-bottom: 1px solid rgba(213,230,241,.4);
    transition: background .15s;
}
.activity-row:last-child { border-bottom: none; }
.activity-row:hover { background: rgba(0,107,183,.025); }
.activity-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; margin-top: 5px; }
.activity-row .row-title { font-size: 12.5px; font-weight: 600; color: #16324a; }
.activity-row .row-sub   { font-size: 11px; color: #5a7185; margin-top: 2px; }
.activity-row .row-time  { font-size: 10px; color: #7d93a5; white-space: nowrap; margin-left: auto; flex-shrink: 0; }
.pill { display:inline-block; font-size:9.5px; font-weight:700; padding:2px 7px; border-radius:999px; }
.pill-active    { background:#DCFCE7; color:#15803D; }
.pill-expired   { background:#FEE2E2; color:#B91C1C; }
.pill-suspended { background:#FEF3C7; color:#92400E; }
.chart-wrap { padding: 14px 18px 18px; }

.map-hero {
    background: linear-gradient(135deg, #06263f, #0a2f4f);
    border-radius: 18px; overflow: hidden; position: relative;
    box-shadow: 0 16px 44px rgba(13,22,40,.28);
}
.map-hero-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 14px; padding: 16px 20px 12px; flex-wrap: wrap;
}
.map-hero-head h2 { color: #fff; font-size: 15px; font-weight: 700; margin: 0 0 2px; display:flex; align-items:center; gap: 8px; }
.map-hero-head p  { color: rgba(255,255,255,.5); font-size: 11.5px; margin: 0; }
.demo-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(245,158,11,.16); border: 1px solid rgba(245,158,11,.35);
    color: #FCD34D; font-size: 10px; font-weight: 700; padding: 4px 10px; border-radius: 999px;
}
.mun-tabs { display: flex; gap: 6px; padding: 0 20px 12px; flex-wrap: wrap; }
.mun-tab {
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
    color: rgba(255,255,255,.65); font-size: 11.5px; font-weight: 600;
    padding: 6px 13px; border-radius: 999px; cursor: pointer; transition: all .15s;
    font-family: 'Instrument Sans', sans-serif;
}
.mun-tab:hover { background: rgba(255,255,255,.1); color: #fff; }
.mun-tab.active { background: #006bb7; border-color: #006bb7; color: #fff; }
#live-map { height: 420px; width: 100%; background: #06263f; }
.map-tiles-dark { filter: invert(1) hue-rotate(180deg) brightness(0.92) contrast(0.88) saturate(0.7); }
.map-legend {
    position: absolute; left: 20px; bottom: 20px; z-index: 500;
    background: rgba(13,22,40,.88); backdrop-filter: blur(6px);
    border: 1px solid rgba(255,255,255,.1); border-radius: 12px;
    padding: 10px 14px; display: flex; flex-direction: column; gap: 6px;
}
.legend-row { display: flex; align-items: center; gap: 7px; font-size: 11px; color: rgba(255,255,255,.75); }
.legend-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.map-side {
    background: rgba(255,255,255,.97); padding: 14px 16px; max-height: 420px; overflow-y: auto;
}
.map-side h3 { font-size: 11px; font-weight: 700; color: #5a7185; text-transform: uppercase; letter-spacing: .06em; margin: 0 0 10px; }
.alert-item {
    display: flex; gap: 9px; align-items: flex-start; padding: 9px 0;
    border-bottom: 1px solid rgba(213,230,241,.4);
}
.alert-item:last-child { border-bottom: none; }
.alert-icon {
    width: 26px; height: 26px; border-radius: 8px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: rgba(239,68,68,.12); color: #EF4444;
}
.alert-item .alert-title { font-size: 12px; font-weight: 700; color: #16324a; }
.alert-item .alert-sub   { font-size: 10.5px; color: #5a7185; margin-top: 1px; }
.no-alerts { text-align: center; padding: 30px 10px; color: #7d93a5; }
.vessel-item { padding: 9px 0; border-bottom: 1px solid rgba(213,230,241,.4); }
.vessel-item:last-child { border-bottom: none; }
.vessel-item .v-name { font-size: 12px; font-weight: 700; color: #16324a; display: flex; align-items: center; gap: 6px; }
.vessel-item .v-sub  { font-size: 10.5px; color: #5a7185; margin-top: 2px; }
.vessel-pin { border-radius: 50%; border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.4); }
.vessel-pin.warn { animation: dotPulse 1.1s ease-in-out infinite; }
</style>

<body>
<?php sidebar('dashboard'); ?>

<main class="fade-up" style="margin-left:224px;min-height:100vh;padding:28px 32px;">

    <?php
    $tfBanner = (!$isOfficer && canHandleTransfers()) ? incomingSummary($conn, (string)$user['mun']) : null;
    if ($tfBanner && $tfBanner['pending'] > 0): ?>
    <a href="transfers.php" style="display:flex;align-items:center;gap:14px;background:#FEF2F2;border:1.5px solid #FCA5A5;color:#991B1B;border-radius:14px;padding:12px 18px;margin-bottom:20px;text-decoration:none;">
        <span style="background:#DC2626;color:#fff;font-weight:800;font-size:14px;min-width:30px;height:30px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;"><?= (int)$tfBanner['pending'] ?></span>
        <span style="font-size:14px;font-weight:700;flex:1;">
            <?= (int)$tfBanner['pending'] === 1 ? '1 transfer request is' : (int)$tfBanner['pending'] . ' transfer requests are' ?> waiting for your answer.
            <span style="font-weight:500;">The soonest expires in <?= htmlspecialchars(tf_left($tfBanner['secs'])) ?>.</span>
        </span>
        <span style="font-size:13px;font-weight:700;white-space:nowrap;">Open Transfer Requests &rarr;</span>
    </a>
    <?php endif; ?>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;gap:16px;">
        <div>
            <h1 style="font-size:22px;font-weight:700;color:#16324a;margin:0 0 3px;">Dashboard</h1>
            <p style="font-size:13px;color:#5a7185;margin:0;">Welcome back, <strong><?= htmlspecialchars($user['name']) ?></strong> &mdash; <?= date('l, F j, Y') ?></p>
        </div>
        <div style="display:flex;gap:10px;">
            <?php if ($isOfficer): ?><a href="scan.php" target="_blank" class="btn-secondary" style="white-space:nowrap;display:inline-flex;align-items:center;gap:6px;flex-shrink:0;text-decoration:none;">
                <svg style="width:15px;height:15px;flex-shrink:0;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.5 6.5a7 7 0 010 11M4 4a10.5 10.5 0 010 16M17.5 6.5a7 7 0 010 11M20 4a10.5 10.5 0 010 16"/></svg>
                RFID Scan
            </a><?php endif; ?>
            <?php if (!$isOfficer): ?><a href="register.php" target="_blank" class="btn-primary" style="white-space:nowrap;display:inline-flex;align-items:center;gap:6px;flex-shrink:0;text-decoration:none;">
                <svg style="width:15px;height:15px;flex-shrink:0;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Register Fisherfolk
            </a><?php endif; ?>
        </div>
    </div>

    <div class="map-hero rise-in">
        <div class="map-hero-head">
            <div>
                <h2>
                    <svg width="16" height="16" fill="none" stroke="#6fd3f5" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                    Municipal Waters Monitor
                </h2>
                <p>Vessel positions &amp; municipal waters<?= $map_scope_mun ? ' — ' . htmlspecialchars($map_scope_mun) : '' ?></p>
            </div>
            <span class="demo-badge" id="live-status">
                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span id="live-status-text">Connecting&hellip;</span>
            </span>
        </div>

        <?php if ($isAdmin): ?>
        <div class="mun-tabs" id="mun-tabs">
            <div class="mun-tab active" data-mun="__all__">All Municipalities</div>
            <?php foreach ($mun_names as $mname): ?>
                <div class="mun-tab" data-mun="<?= htmlspecialchars($mname) ?>"><?= htmlspecialchars($mname) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 260px;">
            <div style="position:relative;">
                <div id="live-map"></div>
                <div class="map-legend">
                    <div class="legend-row"><span class="legend-dot" style="background:#6fd3f5;"></span> Municipal fisher</div>
                    <div class="legend-row"><span class="legend-dot" style="background:#F59E0B;"></span> Commercial (licensed)</div>
                    <div class="legend-row"><span class="legend-dot" style="background:#EF4444;"></span> Encroachment alert</div>
                    <div class="legend-row"><span class="legend-dot" style="background:#7d93a5;"></span> No recent signal</div>
                    <div class="legend-row"><span class="legend-dot" style="background:rgba(0,107,183,.3);border:1px solid #6fd3f5;"></span> Municipal waters boundary</div>
                </div>
            </div>
            <div class="map-side">
                <h3>Encroachment Alerts</h3>
                <div id="alert-list"><div class="no-alerts" style="padding:14px 10px;"><p style="font-size:11.5px;margin:0;">No alerts</p></div></div>
                <?php if (defined('TRACKER_DEMO_MODE') && TRACKER_DEMO_MODE): ?>
                <button type="button" id="reset-timers" style="margin-top:8px;width:100%;padding:7px 10px;font-size:12px;font-weight:600;color:#054b7e;background:#eaf6fd;border:1px solid #d5e6f1;border-radius:8px;cursor:pointer;">Reset alert timers</button>
                <?php endif; ?>
                <h3 style="margin-top:16px;">Tracked Vessels</h3>
                <div id="vessel-list"><div class="no-alerts"><p style="font-size:11.5px;margin:0;">Waiting for tracker data&hellip;</p></div></div></div>
            </div>
        </div>
    </div>

    <?php if ($isOfficer): ?>
    <?php
    $officer_inc = $conn->query("SELECT v.name AS violation_type, i.location_name, i.municipality, i.status, i.reported_at, i.reported_by_name,
        CONCAT(f.last_name, ', ', f.first_name) AS ff_name
        FROM incidents i JOIN violation_catalog v ON v.id = i.violation_id LEFT JOIN fisherfolk f ON f.id = i.fisherfolk_id
        WHERE 1=1" . str_replace(' AND municipality=', ' AND i.municipality=', $munWhere_inc) . " ORDER BY i.reported_at DESC LIMIT 15");
    $inc_badges = ['Pending' => 'badge-pending', 'Under Investigation' => 'badge-investigating', 'Resolved' => 'badge-resolved'];
    ?>
    <div class="section-label-row"><div><p class="section-eyebrow">Incidents</p><h2 class="section-title">Recent Incident Reports</h2></div></div>
    <div class="section-card rise-in" style="margin-bottom:22px;">
        <div class="card-head">
            <h2>
                <svg width="14" height="14" fill="none" stroke="#D97706" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                Recent Incident Reports
            </h2>
        </div>
    <?php if ($officer_inc && $officer_inc->num_rows > 0): while ($r = $officer_inc->fetch_assoc()): ?>
        <div class="activity-row">
            <div class="activity-dot" style="background:#D97706;"></div>
            <div style="flex:1;min-width:0;">
                <div class="row-title"><?= htmlspecialchars($r['violation_type']) ?></div>
                <div class="row-sub"><?= htmlspecialchars(($r['location_name'] ?: 'Location not recorded') . ' · ' . $r['municipality']) ?><?= $r['ff_name'] ? ' · ' . htmlspecialchars($r['ff_name']) : '' ?></div>
                <div class="row-sub">Reported by <?= htmlspecialchars($r['reported_by_name'] ?: 'Unknown') ?> · <?= date('M j, Y g:i A', strtotime($r['reported_at'])) ?></div>
            </div>
            <span class="badge <?= $inc_badges[$r['status']] ?? 'badge-pending' ?>" style="flex-shrink:0;align-self:flex-start;"><?= htmlspecialchars($r['status']) ?></span>
        </div>
    <?php endwhile; else: ?>
        <p style="font-size:12.5px;color:#7d93a5;padding:20px 18px;margin:0;">No incidents reported.</p>
    <?php endif; ?>
    </div>

    <?php elseif ($isAdmin): ?>

    <div class="section-label-row">
        <div>
            <p class="section-eyebrow">Overview</p>
            <h2 class="section-title">Registry at a Glance</h2>
        </div>
    </div>

    <?php renderStatCards($stat_cards); ?>

    <div class="section-label-row" style="margin-top:22px;">
        <div>
            <p class="section-eyebrow">Administration</p>
            <h2 class="section-title">Admin Tools</h2>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:22px;">
        <a href="users.php" class="card rise-in" style="padding:16px 18px;text-decoration:none;display:block;">
            <h3 style="font-size:13.5px;font-weight:700;color:#16324a;margin:0 0 4px;">User Management</h3>
            <p style="font-size:12px;color:#5a7185;margin:0 0 10px;">Add users, reset passwords, change roles, activate or deactivate accounts.</p>
            <p style="font-size:11.5px;color:#006bb7;font-weight:600;margin:0;">
                <?= (int)$user_stats['active'] ?> active &middot; <?= (int)$user_stats['inactive'] ?> inactive &middot; <?= (int)$user_stats['total'] ?> total
            </p>
        </a>
    </div>

    <div style="display:grid;grid-template-columns:1.6fr 1fr;gap:16px;margin-top:22px;margin-bottom:22px;">

        <div class="section-card rise-in rise-d2">
            <div class="card-head">
                <h2>
                    <svg width="14" height="14" fill="none" stroke="#006bb7" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    Fisherfolk by Municipality
                </h2>
                <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:999px;background:rgba(0,107,183,.1);color:#006bb7;">Population Overview</span>
            </div>
            <div class="chart-wrap" style="height:220px;">
                <canvas id="munChart"></canvas>
            </div>
        </div>

        <div class="section-card rise-in rise-d3">
            <div class="card-head">
                <h2>
                    <svg width="14" height="14" fill="none" stroke="#10B981" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    Registration Trend
                </h2>
                <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:999px;background:rgba(16,185,129,.1);color:#10B981;">Last 6 Months</span>
            </div>
            <div class="chart-wrap" style="height:220px;">
                <canvas id="trendChart"></canvas>
            </div>
        </div>

    </div>

    <div class="section-label-row">
        <div>
            <p class="section-eyebrow">Activity</p>
            <h2 class="section-title">Registrations &amp; Permits</h2>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

        <div class="section-card rise-in rise-d1">
            <div class="card-head">
                <h2>
                    <svg width="13" height="13" fill="none" stroke="#006bb7" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                    Recent Registrations
                </h2>
                <a href="fisherfolk.php" style="font-size:11px;color:#006bb7;text-decoration:none;font-weight:600;">View all →</a>
            </div>
            <?php if (!$recent_ff || $recent_ff->num_rows === 0): ?>
                <p style="font-size:12px;color:#7d93a5;padding:20px 18px;">No registrations yet.</p>
            <?php else: while ($row = $recent_ff->fetch_assoc()): ?>
            <div class="activity-row">
                <div class="activity-dot" style="background:#006bb7;"></div>
                <div style="flex:1;min-width:0;">
                    <div class="row-title"><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></div>
                    <div class="row-sub"><?= htmlspecialchars($row['municipality']) ?><?= $row['barangay'] ? ' · ' . htmlspecialchars($row['barangay']) : '' ?></div>
                    <div style="font-size:10px;font-family:'JetBrains Mono',monospace;color:#006bb7;margin-top:2px;"><?= htmlspecialchars($row['boat_registry_number'] ?? '—') ?></div>
                </div>
                <div class="row-time"><?= date('M j', strtotime($row['created_at'])) ?></div>
            </div>
            <?php endwhile; endif; ?>
        </div>

        <div class="section-card rise-in rise-d2">
            <div class="card-head">
                <h2>
                    <svg width="13" height="13" fill="none" stroke="#10B981" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Recent Permit Changes
                </h2>
                <a href="fisherfolk.php" style="font-size:11px;color:#006bb7;text-decoration:none;font-weight:600;">View all →</a>
            </div>
            <?php if (!$recent_permits || $recent_permits->num_rows === 0): ?>
                <p style="font-size:12px;color:#7d93a5;padding:20px 18px;">No permit activity yet.</p>
            <?php else: while ($row = $recent_permits->fetch_assoc()):
                $pill = 'pill-active'; $dot = '#10B981';
                if ($row['status'] === 'Expired')   { $pill = 'pill-expired';   $dot = '#EF4444'; }
                if ($row['status'] === 'Suspended') { $pill = 'pill-suspended'; $dot = '#F59E0B'; }
                $date_col = $row['issue_date'];
            ?>
            <div class="activity-row">
                <div class="activity-dot" style="background:<?= $dot ?>;"></div>
                <div style="flex:1;min-width:0;">
                    <div class="row-title" style="font-size:12px;"><?= htmlspecialchars($row['permit_type']) ?></div>
                    <div class="row-sub"><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?> · <?= htmlspecialchars($row['municipality']) ?></div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:3px;flex-shrink:0;">
                    <span class="pill <?= $pill ?>"><?= $row['status'] ?></span>
                    <span class="row-time"><?= date('M j', strtotime($date_col)) ?></span>
                </div>
            </div>
            <?php endwhile; endif; ?>
        </div>

    </div>

    <div class="section-label-row" style="margin-top:8px;">
        <div>
            <p class="section-eyebrow" style="color:#7d93a5;">Lower Priority</p>
            <h2 class="section-title" style="font-size:14px;">Incident &amp; Suspension Log</h2>
        </div>
        <a href="incidents.php" style="font-size:11.5px;color:#006bb7;text-decoration:none;font-weight:600;">Open incident reports →</a>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:22px;">
        <div class="section-card rise-in rise-d1">
            <div class="card-head" style="padding:10px 16px;">
                <h2 style="font-size:12px;">
                    <svg width="12" height="12" fill="none" stroke="#D97706" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    Recent Incidents
                </h2>
            </div>
            <?php if ($recent_inc->num_rows > 0): while ($r = $recent_inc->fetch_assoc()):
                $badge = match($r['status']) {
                    'Pending'             => 'badge-pending',
                    'Under Investigation' => 'badge-investigating',
                    'Resolved'            => 'badge-resolved',
                    default               => 'badge-pending'
                };
            ?>
            <div class="activity-row">
                <div class="activity-dot" style="background:#D97706;"></div>
                <div style="flex:1;min-width:0;">
                    <div class="row-title" style="font-size:12px;"><?= htmlspecialchars($r['violation_type']) ?></div>
                    <div class="row-sub"><?= htmlspecialchars($r['municipality']) ?></div>
                </div>
                <span class="badge <?= $badge ?>" style="flex-shrink:0;"><?= htmlspecialchars($r['status']) ?></span>
            </div>
            <?php endwhile; else: ?>
                <p style="font-size:12px;color:#7d93a5;padding:16px 18px;">No incidents reported.</p>
            <?php endif; ?>
        </div>

        <div class="section-card rise-in rise-d2">
            <div class="card-head" style="padding:10px 16px;">
                <h2 style="font-size:12px;">
                    <svg width="12" height="12" fill="none" stroke="#EF4444" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                    Suspensions
                </h2>
                <span style="font-size:9.5px;font-weight:700;padding:2px 6px;border-radius:999px;background:rgba(239,68,68,.08);color:#EF4444;"><?= $suspended_permits ?> total</span>
            </div>
            <?php if (!$recent_violations || $recent_violations->num_rows === 0): ?>
                <p style="font-size:12px;color:#7d93a5;padding:16px 18px;">No suspensions on record.</p>
            <?php else: while ($row = $recent_violations->fetch_assoc()): ?>
            <div class="activity-row">
                <div class="activity-dot" style="background:#F59E0B;"></div>
                <div style="flex:1;min-width:0;">
                    <div class="row-title" style="font-size:12px;"><?= htmlspecialchars($row['permit_type']) ?></div>
                    <div class="row-sub"><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?> · <?= htmlspecialchars($row['municipality']) ?></div>
                </div>
                <span class="row-time" style="flex-shrink:0;"><?= date('M j', strtotime($row['flagged_at'])) ?></span>
            </div>
            <?php endwhile; endif; ?>
        </div>
    </div>

    <div class="card rise-in" style="overflow:hidden;">
        <div style="padding:14px 20px;border-bottom:1px solid rgba(213,230,241,.5);">
            <h2 style="font-weight:700;font-size:14px;color:#16324a;margin:0;">All Registered Fisherfolk</h2>
        </div>
        <div style="padding:16px 20px;">
            <table id="fisherfolk-table" style="width:100%;">
                <thead><tr>
                    <th>Fisherfolk Registry No.</th><th>Name</th><th>Municipality</th><th>Date Registered</th>
                </tr></thead>
                <tbody>
                <?php while ($r = $all_ff->fetch_assoc()): ?>
                    <tr>
                        <td style="font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:600;color:#006bb7;"><?= htmlspecialchars($r['boat_registry_number'] ?? 'N/A') ?></td>
                        <td style="font-weight:600;"><?= htmlspecialchars($r['last_name'] . ', ' . $r['first_name']) ?></td>
                        <td style="color:#5a7185;"><?= htmlspecialchars($r['municipality']) ?></td>
                        <td style="color:#5a7185;font-family:'JetBrains Mono',monospace;font-size:12px;"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php else: ?>

    <div class="section-label-row">
        <div>
            <p class="section-eyebrow">Overview</p>
            <h2 class="section-title"><?= htmlspecialchars($user['mun']) ?> at a Glance</h2>
        </div>
    </div>
    <?php renderStatCards($stat_cards); ?>
    <div style="height:22px;"></div>

    <div class="section-label-row">
        <div>
            <p class="section-eyebrow">Registry</p>
            <h2 class="section-title"><?= htmlspecialchars($user['mun']) ?> Fisherfolk</h2>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start;">

        <div class="card rise-in" style="overflow:hidden;">
            <div style="padding:16px 20px;">
                <table id="fisherfolk-table" style="width:100%;">
                    <thead><tr>
                        <th>Fisherfolk Registry No.</th><th>Name</th><th>Municipality</th><th>Date Registered</th>
                    </tr></thead>
                    <tbody>
                    <?php while ($r = $all_ff->fetch_assoc()): ?>
                        <tr>
                            <td style="font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:600;color:#006bb7;"><?= htmlspecialchars($r['boat_registry_number'] ?? 'N/A') ?></td>
                            <td style="font-weight:600;"><?= htmlspecialchars($r['last_name'] . ', ' . $r['first_name']) ?></td>
                            <td style="color:#5a7185;"><?= htmlspecialchars($r['municipality']) ?></td>
                            <td style="color:#5a7185;font-family:'JetBrains Mono',monospace;font-size:12px;"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:16px;">

            <div class="card rise-in rise-d1" style="padding:16px 18px;">
                <h2 style="font-weight:700;font-size:13.5px;color:#16324a;margin:0 0 12px;">Quick Links</h2>
                <a href="fisherfolk.php" style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(213,230,241,.3);text-decoration:none;color:#16324a;">
                    <svg style="width:15px;height:15px;color:#006bb7;flex-shrink:0;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span style="font-size:13px;font-weight:500;">Fisherfolk Registry</span>
                </a>
                <a href="register.php" target="_blank" style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(213,230,241,.3);text-decoration:none;color:#16324a;">
                    <svg style="width:15px;height:15px;color:#8B5CF6;flex-shrink:0;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    <span style="font-size:13px;font-weight:500;">Register Fisherfolk (Field App)</span>
                </a>
                <?php if (hasDualAccess()): ?>
                <a href="switch_mode.php?to=field" style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(213,230,241,.3);text-decoration:none;color:#16324a;">
                    <svg style="width:15px;height:15px;color:#15803D;flex-shrink:0;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.5 6.5a7 7 0 010 11M4 4a10.5 10.5 0 010 16M17.5 6.5a7 7 0 010 11M20 4a10.5 10.5 0 010 16"/></svg>
                    <span style="font-size:13px;font-weight:500;">RFID Scanner</span>
                </a>
                <?php endif; ?>
                <a href="incidents.php" style="display:flex;align-items:center;gap:10px;padding:9px 0;text-decoration:none;color:#16324a;">
                    <svg style="width:15px;height:15px;color:#D97706;flex-shrink:0;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <span style="font-size:13px;font-weight:500;">Incident Reports</span>
                </a>
            </div>

            <div class="card rise-in rise-d2" style="padding:16px 18px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                    <h2 style="font-weight:700;font-size:12.5px;color:#5a7185;margin:0;">Recent Incidents</h2>
                    <a href="incidents.php" style="font-size:11px;color:#006bb7;font-weight:600;text-decoration:none;">View all</a>
                </div>
                <?php if ($recent_inc->num_rows > 0): while ($r = $recent_inc->fetch_assoc()):
                    $badge = match($r['status']) {
                        'Pending'             => 'badge-pending',
                        'Under Investigation' => 'badge-investigating',
                        'Resolved'            => 'badge-resolved',
                        default               => 'badge-pending'
                    };
                ?>
                <div style="padding:8px 0;border-bottom:1px solid rgba(213,230,241,.35);">
                    <p style="font-size:12px;font-weight:600;color:#16324a;margin:0 0 3px;line-height:1.3;"><?= htmlspecialchars($r['violation_type']) ?></p>
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <p style="font-size:10.5px;color:#5a7185;margin:0;"><?= htmlspecialchars($r['municipality']) ?></p>
                        <span class="badge <?= $badge ?>"><?= htmlspecialchars($r['status']) ?></span>
                    </div>
                </div>
                <?php endwhile; else: ?>
                    <p style="font-size:12.5px;color:#7d93a5;">No incidents reported.</p>
                <?php endif; ?>
            </div>

        </div>
    </div>
    <?php endif; ?>

</main>

<script>
$(document).ready(function () {
    $('#fisherfolk-table').DataTable({
        pageLength: 15,
        lengthMenu: [10, 15, 25, 50, 100],
        order: [[3, 'desc']],
        language: {
            search: 'Search:', lengthMenu: 'Show _MENU_ entries',
            info: 'Showing _START_ to _END_ of _TOTAL_ fisherfolk',
            infoEmpty: 'No records found', infoFiltered: '(filtered from _MAX_ total)',
            paginate: { first: '«', last: '»', next: '›', previous: '‹' }
        },
        columnDefs: [
            { targets: 0, width: '160px' },
            { targets: 2, width: '190px' },
            { targets: 3, width: '130px' }
        ]
    });
});

document.querySelectorAll('.stat-value[data-count]').forEach(function (el) {
    const target = parseInt(el.getAttribute('data-count'), 10) || 0;
    const dur = 700, start = performance.now();
    function tick(now) {
        const p = Math.min(1, (now - start) / dur);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = Math.round(eased * target).toLocaleString();
        if (p < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
});

<?php if ($isAdmin): ?>
const munCtx = document.getElementById('munChart').getContext('2d');
new Chart(munCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($mun_labels) ?>,
        datasets: [{
            label: 'Fisherfolk',
            data: <?= json_encode($mun_counts) ?>,
            backgroundColor: [
                'rgba(0,107,183,.82)','rgba(16,185,129,.82)',
                'rgba(245,158,11,.82)','rgba(139,92,246,.82)','rgba(239,68,68,.82)'
            ],
            borderRadius: 8, borderSkipped: false,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { family: 'Instrument Sans', size: 11 }, color: '#5a7185' } },
            y: { beginAtZero: true, grid: { color: 'rgba(213,230,241,.4)' },
                 ticks: { font: { family: 'Instrument Sans', size: 11 }, color: '#5a7185',
                          callback: v => Number.isInteger(v) ? v : '' } }
        }
    }
});

const trendCtx = document.getElementById('trendChart').getContext('2d');
const trendGrad = trendCtx.createLinearGradient(0, 0, 0, 200);
trendGrad.addColorStop(0, 'rgba(16,185,129,.25)');
trendGrad.addColorStop(1, 'rgba(16,185,129,.00)');
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($month_labels) ?>,
        datasets: [{
            label: 'Registrations',
            data: <?= json_encode($month_counts) ?>,
            borderColor: '#10B981', backgroundColor: trendGrad,
            borderWidth: 2.5, pointBackgroundColor: '#10B981',
            pointRadius: 4, pointHoverRadius: 6, fill: true, tension: 0.4,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { family: 'Instrument Sans', size: 11 }, color: '#5a7185' } },
            y: { beginAtZero: true, grid: { color: 'rgba(213,230,241,.4)' },
                 ticks: { font: { family: 'Instrument Sans', size: 11 }, color: '#5a7185',
                          callback: v => Number.isInteger(v) ? v : '' } }
        }
    }
});
<?php endif; ?>

(function () {
    const waters = <?= json_encode($map_waters) ?>;
    const scopeMun = <?= json_encode($map_scope_mun) ?>;

    const map = L.map('live-map', { zoomControl: true, attributionControl: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, subdomains: 'abc', className: 'map-tiles-dark'
    }).addTo(map);
    L.control.attribution({ prefix: false }).addAttribution('© OpenStreetMap contributors').addTo(map);

    const boundaryCircles = {};
    waters.features.forEach(function (f) {
        const name = f.properties.name;
        if (scopeMun && scopeMun !== name) return;
        boundaryCircles[name] = L.geoJSON(f, {
            style: { color: '#6fd3f5', weight: 1.5, dashArray: '6 5', fillColor: '#006bb7', fillOpacity: 0.07 }
        }).addTo(map).bindTooltip(name + ' — municipal waters', { sticky: true });
    });

    function fitAll() {
        const layers = Object.values(boundaryCircles);
        if (layers.length) map.fitBounds(new L.featureGroup(layers).getBounds().pad(0.1));
    }
    function fitOne(name) {
        if (boundaryCircles[name]) map.fitBounds(boundaryCircles[name].getBounds().pad(0.1));
    }

    if (scopeMun && boundaryCircles[scopeMun]) {
        fitOne(scopeMun);
    } else {
        fitAll();
    }

    const markers = {};
    const rings = {};
    (function pulseRings(t) {
        const k = (t % 1600) / 1600;
        Object.keys(rings).forEach(function (id) {
            rings[id].setRadius(6.5 + k * 16);
            rings[id].setStyle({ opacity: 0.85 * (1 - k) });
        });
        requestAnimationFrame(pulseRings);
    })(0);
    let firstLoad = true, busy = false, staleSeconds = 90, alertIds = {};
    const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    function ago(sec) {
        if (sec == null) return 'never';
        if (sec < 90) return sec + 's ago';
        if (sec < 5400) return Math.round(sec / 60) + 'm ago';
        return Math.round(sec / 3600) + 'h ago';
    }
    function isStale(v) { return v.stale || (v.fix_age_s != null && v.fix_age_s > staleSeconds); }
    function colorFor(v) {
        if (alertIds[v.id]) return '#EF4444';
        if (isStale(v)) return '#7d93a5';
        return v.type === 'commercial' ? '#F59E0B' : '#6fd3f5';
    }
    function setStatus(text, live) {
        document.getElementById('live-status-text').textContent = text;
        const el = document.getElementById('live-status');
        el.style.background  = live ? 'rgba(34,197,94,.16)' : '';
        el.style.borderColor = live ? 'rgba(34,197,94,.4)' : '';
        el.style.color       = live ? '#86EFAC' : '';
    }
    function fitEverything() {
        const layers = scopeMun
            ? Object.values(boundaryCircles)
            : Object.values(boundaryCircles).concat(Object.values(markers));
        if (layers.length) map.fitBounds(new L.featureGroup(layers).getBounds().pad(0.15));
    }

    function renderVessels(data) {
        staleSeconds = data.settings.stale_seconds || staleSeconds;
        alertIds = {};
        (data.alerts || []).forEach(function (a) { alertIds[a.vessel_id] = true; });
        const seen = {};
        data.vessels.forEach(function (v) {
            seen[v.id] = true;
            const tip = '<strong>' + esc(v.boat || 'Unlinked tracker') + '</strong><br>' +
                esc(v.owner) + (v.owner_mun ? ' · ' + esc(v.owner_mun) : '') + '<br>' + (v.type === 'commercial' ? 'Commercial' : 'Municipal') +
                (v.gross_tonnage != null ? ' · ' + v.gross_tonnage + ' GT' : '') + '<br>' +
                'Position ' + ago(v.fix_age_s) + '<br>' + v.lat.toFixed(5) + ', ' + v.lng.toFixed(5) +
                (alertIds[v.id] ? '<br><strong style="color:#EF4444;">Encroachment alert</strong>' : '');
            if (!markers[v.id]) {
                markers[v.id] = L.circleMarker([v.lat, v.lng], {
                    radius: 6.5, color: '#fff', weight: 1.5, fillColor: colorFor(v), fillOpacity: 1, className: 'vessel-pin'
                }).addTo(map).bindTooltip(tip, { sticky: true });
            } else {
                markers[v.id].setLatLng([v.lat, v.lng]);
                markers[v.id].setStyle({ fillColor: colorFor(v) });
                markers[v.id].setTooltipContent(tip);
            }
            if (alertIds[v.id]) {
                if (!rings[v.id]) {
                    rings[v.id] = L.circleMarker([v.lat, v.lng], {
                        radius: 6.5, color: '#EF4444', weight: 2, fill: false, opacity: 0.85, interactive: false
                    }).addTo(map);
                } else {
                    rings[v.id].setLatLng([v.lat, v.lng]);
                }
            } else if (rings[v.id]) {
                map.removeLayer(rings[v.id]); delete rings[v.id];
            }
        });
        Object.keys(markers).forEach(function (id) {
            if (!seen[id]) {
                map.removeLayer(markers[id]); delete markers[id];
                if (rings[id]) { map.removeLayer(rings[id]); delete rings[id]; }
            }
        });

        const list = document.getElementById('vessel-list');
        list.innerHTML = data.vessels.length ? data.vessels.map(function (v) {
            return '<div class="vessel-item"><div class="v-name"><span class="legend-dot" style="background:' + colorFor(v) + ';"></span>' +
                esc(v.boat || 'Unlinked tracker') + '</div><div class="v-sub">' + esc(v.owner) + (v.owner_mun ? ' · ' + esc(v.owner_mun) : '') + ' · ' +
                (v.type === 'commercial' ? 'Commercial' : 'Municipal') + '</div><div class="v-sub">Last heard ' +
                ago(v.seen_age_s) + (v.sats != null ? ' · ' + v.sats + ' sats' : '') + '</div></div>';
        }).join('') : '<div class="no-alerts"><p style="font-size:11.5px;margin:0;">No tracker has reported a position yet.</p></div>';

        const alertBox = document.getElementById('alert-list');
        const alerts = data.alerts || [];
        alertBox.innerHTML = alerts.length ? alerts.map(function (a) {
            return '<div class="alert-item"><div class="alert-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg></div><div><div class="alert-title">' +
                esc(a.boat || 'Unlinked tracker') + '</div><div class="alert-sub">Inside ' + esc(a.municipality) + ' waters' + (a.stale ? '. Signal lost.' : '') + '</div></div></div>';
        }).join('') : '<div class="no-alerts" style="padding:14px 10px;"><p style="font-size:11.5px;margin:0;">No alerts</p></div>';

        const s = data.summary;
        if (!s.tracked)        setStatus('Waiting for first tracker position', false);
        else if (s.reporting)  setStatus('Live · ' + s.reporting + ' of ' + s.tracked + ' tracker' + (s.tracked > 1 ? 's' : '') + ' reporting · last packet ' + ago(s.latest_packet_age), true);
        else                   setStatus('No signal · last packet ' + ago(s.latest_packet_age), false);

        if (firstLoad && data.vessels.length) { firstLoad = false; fitEverything(); }
    }

    function poll() {
        if (busy) return;
        busy = true;
        fetch('tracker_positions.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) {
                if (!(r.headers.get('content-type') || '').includes('json')) throw new Error('session');
                return r.json();
            })
            .then(function (data) { if (data.ok) renderVessels(data); else throw new Error('server'); })
            .catch(function (e) {
                setStatus(e.message === 'session' ? 'Session expired — reload the page' : 'Cannot reach server — retrying', false);
            })
            .finally(function () { busy = false; });
    }
    poll();
    setInterval(poll, 2000);
    const resetBtn = document.getElementById('reset-timers');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            resetBtn.disabled = true;
            fetch('tracker_demo_reset.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    resetBtn.textContent = d.ok ? 'Timers reset' : 'Could not reset';
                    poll();
                })
                .catch(function () { resetBtn.textContent = 'Could not reset'; })
                .finally(function () {
                    setTimeout(function () { resetBtn.textContent = 'Reset alert timers'; resetBtn.disabled = false; }, 2000);
                });
        });
    }

    const tabs = document.getElementById('mun-tabs');
    if (tabs) {
        tabs.addEventListener('click', function (e) {
            const t = e.target.closest('.mun-tab');
            if (!t) return;
            tabs.querySelectorAll('.mun-tab').forEach(x => x.classList.remove('active'));
            t.classList.add('active');
            const mun = t.getAttribute('data-mun');
            if (mun === '__all__') fitAll(); else fitOne(mun);
        });
    }
})();
</script>
</body>
</html>