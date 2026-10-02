<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/requirements.php';
if (!function_exists('e')) { function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

requireRole(['admin', 'mao_staff']);
if (!canHandleTransfers()) { header('Location: dashboard.php'); exit(); }
$mun = (string)$_SESSION['user_mun'];

function tf_go(string $msg): void { $_SESSION['flash'] = ['error', $msg]; header('Location: transfers.php?tab=incoming'); exit(); }

if (!tfHas($conn, 'all')) tf_go('Import migration_v4b.sql in phpMyAdmin first.');
expireTransfers($conn);

$rid = (int)($_GET['request'] ?? 0);
$st = $conn->prepare("SELECT t.id, t.fisherfolk_id, t.from_municipality, t.requested_at, t.expires_at,
                             GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), t.expires_at)) AS secs
                      FROM transfer_requests t
                      WHERE t.id = ? AND t.to_municipality = ? AND t.status = 'Pending'");
$st->bind_param('is', $rid, $mun); $st->execute();
$tr = $st->get_result()->fetch_assoc(); $st->close();
if (!$tr) tf_go('That file is not available. It can only be opened while the request is waiting for your answer.');

$fid = (int)$tr['fisherfolk_id'];
$st = $conn->prepare("SELECT first_name, middle_name, last_name, boat_registry_number, municipality, barangay FROM fisherfolk WHERE id = ?");
$st->bind_param('i', $fid); $st->execute();
$ff = $st->get_result()->fetch_assoc(); $st->close();

if (!$ff || $ff['municipality'] !== $tr['from_municipality']) tf_go('That file is not available.');

$st = $conn->prepare("SELECT permit_type, permit_number, issue_date, expiry_date, status FROM permits WHERE fisherfolk_id = ? ORDER BY issue_date DESC, id DESC");
$st->bind_param('i', $fid); $st->execute();
$permits = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$st = $conn->prepare("SELECT doc_type, doc_number, issue_date, expiry_date FROM registration_documents WHERE fisherfolk_id = ? ORDER BY issue_date DESC, id DESC");
$st->bind_param('i', $fid); $st->execute();
$docs = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$incidents = [];
try {
    $st = $conn->prepare("SELECT v.name AS violation, i.description, i.location_name, i.municipality, i.status, i.reported_at
                          FROM incidents i JOIN violation_catalog v ON v.id = i.violation_id
                          WHERE i.fisherfolk_id = ? ORDER BY i.reported_at DESC");
    $st->bind_param('i', $fid); $st->execute();
    $incidents = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
} catch (Throwable $ex) { error_log('transfer_file incidents: ' . $ex->getMessage()); }

function tfl_date($d): string { return ($d && $d !== '0000-00-00') ? date('M j, Y', strtotime($d)) : 'none'; }
function tfl_badge(string $s): string {
    $c = ['Active' => ['#DCFCE7', '#15803D'], 'Expired' => ['#EEF2F6', '#5a7185'], 'Suspended' => ['#FEE2E2', '#B91C1C'],
          'Pending' => ['#FEF9C3', '#A16207'], 'Under Investigation' => ['#FFEDD5', '#C2410C'], 'Resolved' => ['#DCFCE7', '#15803D']][$s] ?? ['#EEF2F6', '#5a7185'];
    return '<span style="display:inline-block;background:' . $c[0] . ';color:' . $c[1] . ';font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px">' . e($s) . '</span>';
}
$fullName = trim($ff['last_name'] . ', ' . $ff['first_name'] . ' ' . ($ff['middle_name'] ?? ''));
?>
<?php pageHead('Fisherfolk File'); ?>
<style>
    .fl-sec { margin-bottom:16px; overflow:hidden; }
    .fl-head { padding:14px 20px; border-bottom:1px solid rgba(213,230,241,.6); display:flex; justify-content:space-between; align-items:center; }
    .fl-head h2 { font-size:14px; font-weight:700; color:#16324a; margin:0; }
    .fl-grid { padding:14px 20px; display:grid; grid-template-columns:repeat(4,1fr); gap:16px; }
    @media (max-width: 900px) { .fl-grid { grid-template-columns:1fr 1fr; } }
    .fl-k { font-size:11px; font-weight:800; color:#006bb7; text-transform:uppercase; letter-spacing:.1em; margin:0 0 3px; }
    .fl-v { font-size:14px; color:#16324a; margin:0; }
    .fl-mono { font-family:'JetBrains Mono',monospace; font-size:12.5px; color:#006bb7; font-weight:600; }
    .fl-empty { padding:22px 20px; color:#7d93a5; font-size:13.5px; }
</style>
<body>
<?php sidebar('transfers'); ?>
<main class="fade-up" style="margin-left:224px;min-height:100vh;padding:32px 36px;max-width:1150px;">
    <div style="margin-bottom:20px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <h1 style="font-size:22px;font-weight:700;color:#16324a;margin:0 0 4px;">Fisherfolk File</h1>
            <p style="font-size:13px;color:#5a7185;margin:0;">Read only. This is the record from <?= e($tr['from_municipality']) ?>, shown so you can check it before answering the transfer request. It closes once the request is answered or expires.</p>
        </div>
        <a href="transfers.php?tab=incoming" class="btn-primary btn-sm" style="text-decoration:none;">Back to Transfer Requests</a>
    </div>

    <div class="card fl-sec">
        <div class="fl-head"><h2>Basic details</h2><span style="font-size:12px;font-weight:700;color:#92400E;background:#FEF3C7;padding:3px 10px;border-radius:999px;"><?= e(tf_left((int)$tr['secs'])) ?> left to answer</span></div>
        <div class="fl-grid">
            <div><p class="fl-k">Name</p><p class="fl-v"><?= e($fullName) ?></p></div>
            <div><p class="fl-k">Fisherfolk Registry No.</p><p class="fl-v fl-mono"><?= e($ff['boat_registry_number']) ?></p></div>
            <div><p class="fl-k">Home municipality</p><p class="fl-v"><?= e($ff['municipality']) ?></p></div>
            <div><p class="fl-k">Barangay</p><p class="fl-v"><?= e($ff['barangay'] ?: 'none') ?></p></div>
        </div>
    </div>

    <div class="card fl-sec">
        <div class="fl-head"><h2>Permits</h2><span style="font-size:12px;color:#7d93a5;"><?= count($permits) ?> on file</span></div>
        <?php if (!$permits): ?><div class="fl-empty">No permits on file.</div><?php else: ?>
        <div style="overflow-x:auto;"><table class="data-table">
            <thead><tr><th>Permit</th><th>Number</th><th>Issued</th><th>Expires</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($permits as $p): ?>
                <tr><td><?= e($p['permit_type']) ?></td><td class="fl-mono"><?= e($p['permit_number']) ?></td><td><?= e(tfl_date($p['issue_date'])) ?></td><td><?= e(tfl_date($p['expiry_date'])) ?></td><td><?= tfl_badge((string)$p['status']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="card fl-sec">
        <div class="fl-head"><h2>Supporting documents</h2><span style="font-size:12px;color:#7d93a5;"><?= count($docs) ?> on file</span></div>
        <?php if (!$docs): ?><div class="fl-empty">No supporting documents on file.</div><?php else: ?>
        <div style="overflow-x:auto;"><table class="data-table">
            <thead><tr><th>Document</th><th>Number</th><th>Issued</th><th>Expires</th></tr></thead>
            <tbody><?php foreach ($docs as $d): ?>
                <tr><td><?= e($d['doc_type']) ?></td><td class="fl-mono"><?= e($d['doc_number'] ?: 'none') ?></td><td><?= e(tfl_date($d['issue_date'])) ?></td><td><?= e(tfl_date($d['expiry_date'])) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="card fl-sec">
        <div class="fl-head"><h2>Incidents</h2><span style="font-size:12px;color:#7d93a5;"><?= count($incidents) ?> on file</span></div>
        <?php if (!$incidents): ?><div class="fl-empty">No incidents on record.</div><?php else: ?>
        <div style="overflow-x:auto;"><table class="data-table">
            <thead><tr><th>Date</th><th>Violation</th><th>Location</th><th>Municipality</th><th>Status</th><th>Details</th></tr></thead>
            <tbody><?php foreach ($incidents as $i): ?>
                <tr><td style="white-space:nowrap;"><?= e(date('M j, Y', strtotime($i['reported_at']))) ?></td><td><?= e($i['violation']) ?></td><td><?= e($i['location_name'] ?: 'none') ?></td><td><?= e($i['municipality'] ?: 'none') ?></td><td><?= tfl_badge((string)$i['status']) ?></td><td style="font-size:12.5px;color:#5a7185;"><?= e($i['description'] ?: '') ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
