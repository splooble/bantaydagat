<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/requirements.php';
if (!function_exists('e')) { function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

requireRole(['admin', 'mao_staff']);
if (!canHandleTransfers()) { header('Location: dashboard.php'); exit(); }
$mun = (string)$_SESSION['user_mun'];
$uid = (int)$_SESSION['user_id'];

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function tf_flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }
function tf_back(string $tab = 'incoming'): void { header('Location: transfers.php?tab=' . $tab); exit(); }

$ready = tfHas($conn, 'all');
if ($ready) expireTransfers($conn);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $backTab = in_array($action, ['request', 'followup'], true) ? 'sent' : 'incoming';
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { tf_flash('error', 'Session expired. Please try again.'); tf_back($backTab); }
    if (!$ready) { tf_flash('error', 'Import migration_v4b.sql in phpMyAdmin first.'); tf_back($backTab); }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


    $hasPending = function (int $fid) use ($conn): bool {
        $st = $conn->prepare("SELECT 1 FROM transfer_requests WHERE fisherfolk_id = ? AND status = 'Pending' LIMIT 1");
        $st->bind_param('i', $fid); $st->execute(); $r = (bool)$st->get_result()->fetch_row(); $st->close(); return $r;
    };
    $sendRequest = function (int $fid, string $to, string $note, ?int $prev) use ($conn, $uid): void {
        $days = transferWindowDays($to);
        $st = $conn->prepare("INSERT INTO transfer_requests (fisherfolk_id, from_municipality, to_municipality, status, requested_by, expires_at, request_note, prev_request_id)
                              VALUES (?, ?, ?, 'Pending', ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?, ?)");
        $from = $_SESSION['user_mun'];
        $noteV = $note !== '' ? $note : null;
        $st->bind_param('issiisi', $fid, $from, $to, $uid, $days, $noteV, $prev); $st->execute(); $st->close();
    };

    try {

        if ($action === 'request') {
            $fid  = (int)($_POST['fisherfolk_id'] ?? 0);
            $to   = trim($_POST['to_municipality'] ?? '');
            $note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 255));
            $st = $conn->prepare("SELECT first_name, last_name, municipality, profile_status FROM fisherfolk WHERE id = ?");
            $st->bind_param('i', $fid); $st->execute(); $ff = $st->get_result()->fetch_assoc(); $st->close();
            if (!$ff || $ff['municipality'] !== $mun)  { tf_flash('error', 'Record not found, or it is outside your municipality.'); tf_back('sent'); }
            if (!isset(MUN_CODES[$to]) || $to === $mun) { tf_flash('error', 'Choose the municipality the fisherfolk is moving to.'); tf_back('sent'); }
            if ($ff['profile_status'] === 'Archived')   { tf_flash('error', 'An archived record cannot be transferred.'); tf_back('sent'); }
            if ($hasPending($fid))                      { tf_flash('error', 'A transfer request for this person is already waiting for an answer.'); tf_back('sent'); }
            $sendRequest($fid, $to, $note, null);
            tf_flash('success', 'Transfer request sent to ' . $to . ' for ' . $ff['first_name'] . ' ' . $ff['last_name'] . '. They have ' . transferWindowDays($to) . ' days to answer.');
            tf_back('sent');
        }


        if ($action === 'followup') {
            $rid = (int)($_POST['request_id'] ?? 0);
            $st = $conn->prepare("SELECT t.*, f.municipality AS cur_mun, f.first_name, f.last_name FROM transfer_requests t JOIN fisherfolk f ON f.id = t.fisherfolk_id WHERE t.id = ? AND t.from_municipality = ?");
            $st->bind_param('is', $rid, $mun); $st->execute(); $tr = $st->get_result()->fetch_assoc(); $st->close();
            if (!$tr || $tr['status'] !== 'Expired') { tf_flash('error', 'Only an expired request can be followed up.'); tf_back('sent'); }
            if ($tr['cur_mun'] !== $mun)             { tf_flash('error', 'This person is no longer in your municipality.'); tf_back('sent'); }
            if ($hasPending((int)$tr['fisherfolk_id'])) { tf_flash('error', 'A transfer request for this person is already waiting for an answer.'); tf_back('sent'); }
            $nx = $conn->prepare("SELECT 1 FROM transfer_requests WHERE prev_request_id = ? LIMIT 1");
            $nx->bind_param('i', $rid); $nx->execute(); $done = (bool)$nx->get_result()->fetch_row(); $nx->close();
            if ($done) { tf_flash('error', 'This request was already followed up.'); tf_back('sent'); }
            $sendRequest((int)$tr['fisherfolk_id'], $tr['to_municipality'], (string)($tr['request_note'] ?? ''), $rid);
            tf_flash('success', 'Follow-up sent to ' . $tr['to_municipality'] . ' for ' . $tr['first_name'] . ' ' . $tr['last_name'] . '.');
            tf_back('sent');
        }


        if ($action === 'reject') {
            $rid  = (int)($_POST['request_id'] ?? 0);
            $note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 255));
            if ($note === '') { tf_flash('error', 'Please give a short reason for rejecting.'); tf_back('incoming'); }
            $st = $conn->prepare("UPDATE transfer_requests SET status = 'Rejected', responded_by = ?, responded_at = NOW(), response_note = ?, seen_at = COALESCE(seen_at, NOW())
                                  WHERE id = ? AND to_municipality = ? AND status = 'Pending'");
            $st->bind_param('isis', $uid, $note, $rid, $mun); $st->execute();
            $n = $st->affected_rows; $st->close();
            tf_flash($n ? 'success' : 'error', $n ? 'Request rejected. The sending MAO can see your reason.' : 'This request is no longer pending.');
            tf_back('incoming');
        }


        if ($action === 'accept') {
            $rid      = (int)($_POST['request_id'] ?? 0);
            $barangay = trim(mb_substr((string)($_POST['barangay'] ?? ''), 0, 100));
            $address  = trim((string)($_POST['address'] ?? ''));
            if ($barangay === '') { tf_flash('error', 'Enter the fisherfolk\'s barangay in ' . $mun . '.'); tf_back('incoming'); }
            $conn->begin_transaction();
            $st = $conn->prepare("SELECT *, (expires_at < NOW()) AS is_late FROM transfer_requests WHERE id = ? FOR UPDATE");
            $st->bind_param('i', $rid); $st->execute(); $tr = $st->get_result()->fetch_assoc(); $st->close();
            if (!$tr || $tr['to_municipality'] !== $mun) { $conn->rollback(); tf_flash('error', 'Request not found.'); tf_back('incoming'); }
            if ($tr['status'] !== 'Pending')             { $conn->rollback(); tf_flash('error', 'This request is no longer pending. It is ' . strtolower($tr['status']) . '.'); tf_back('incoming'); }
            if ((int)$tr['is_late'] === 1) {
                $conn->query("UPDATE transfer_requests SET status = 'Expired' WHERE id = $rid");
                $conn->commit();
                tf_flash('error', 'This request has expired. The sending MAO can send a follow-up.'); tf_back('incoming');
            }
            $fid = (int)$tr['fisherfolk_id'];
            $st = $conn->prepare("SELECT first_name, last_name, municipality, boat_registry_number FROM fisherfolk WHERE id = ? FOR UPDATE");
            $st->bind_param('i', $fid); $st->execute(); $ff = $st->get_result()->fetch_assoc(); $st->close();
            if (!$ff || $ff['municipality'] !== $tr['from_municipality']) { $conn->rollback(); tf_flash('error', 'The record is no longer in ' . $tr['from_municipality'] . '.'); tf_back('incoming'); }


            $old = $ff['boat_registry_number'];
            $chk = $conn->prepare("SELECT 1 FROM fisherfolk WHERE boat_registry_number = ? UNION SELECT 1 FROM registry_number_history WHERE old_number = ?");
            for ($i = 0; $i < 25; $i++) {
                $new = makeRegistryNumber($conn, $mun);
                $chk->bind_param('ss', $new, $new); $chk->execute();
                if (!$chk->get_result()->fetch_row()) break;
                $new = null;
            }
            $chk->close();
            if (empty($new)) throw new RuntimeException('Could not make a new registry number.');

            $h = $conn->prepare("INSERT INTO registry_number_history (fisherfolk_id, old_number, new_number, from_municipality, to_municipality, transfer_request_id, changed_by) VALUES (?,?,?,?,?,?,?)");
            $h->bind_param('issssii', $fid, $old, $new, $tr['from_municipality'], $mun, $rid, $uid); $h->execute(); $h->close();

            $u = $conn->prepare("UPDATE fisherfolk SET municipality = ?, boat_registry_number = ?, barangay = ?, address = ?, is_transferee = 1, transferred_at = NOW(), id_reprint_needed = 1, qr_token = NULL, updated_by = ? WHERE id = ?");
            $u->bind_param('ssssii', $mun, $new, $barangay, $address, $uid, $fid); $u->execute(); $u->close();
            $a = $conn->prepare("UPDATE transfer_requests SET status = 'Accepted', responded_by = ?, responded_at = NOW(), seen_at = COALESCE(seen_at, NOW()) WHERE id = ?");
            $a->bind_param('ii', $uid, $rid); $a->execute(); $a->close();
            $conn->commit();
            tf_flash('success', $ff['first_name'] . ' ' . $ff['last_name'] . ' is now in ' . $mun . '. New registry number: ' . $new . '. The record is on hold until every item on your checklist has a fresh copy, and the ID needs reprinting.');
            tf_back('incoming');
        }
    } catch (Throwable $ex) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        error_log('transfers: ' . $ex->getMessage());
        tf_flash('error', 'Something went wrong and nothing was saved. Please try again.');
        tf_back($backTab);
    }
    tf_back($backTab);
}


$tab = ($_GET['tab'] ?? 'incoming') === 'sent' ? 'sent' : 'incoming';
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

$incoming = $sent = $history = $docsBy = $reqList = [];
$sum = ['pending' => 0, 'unseen' => 0, 'secs' => null];
if ($ready) {
    if ($tab === 'incoming') {

        $conn->query("UPDATE transfer_requests SET seen_at = NOW() WHERE to_municipality = '" . $conn->real_escape_string($mun) . "' AND status = 'Pending' AND seen_at IS NULL");
    }
    $sum = incomingSummary($conn, $mun);
    $m = $conn->real_escape_string($mun);
    $cols = "t.id, t.fisherfolk_id, t.from_municipality, t.to_municipality, t.status, t.requested_at, t.expires_at, t.responded_at, t.response_note, t.request_note, t.prev_request_id,
             GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), t.expires_at)) AS secs, f.first_name, f.last_name, f.middle_name, f.boat_registry_number, f.barangay, f.address,
             f.contact_number, f.boat_ownership, f.municipality AS cur_mun, ru.name AS req_by, au.name AS resp_by";
    $join = "FROM transfer_requests t JOIN fisherfolk f ON f.id = t.fisherfolk_id LEFT JOIN users ru ON ru.id = t.requested_by LEFT JOIN users au ON au.id = t.responded_by";
    $incoming = $conn->query("SELECT $cols $join WHERE t.to_municipality = '$m' AND t.status = 'Pending' ORDER BY t.expires_at ASC")->fetch_all(MYSQLI_ASSOC);
    $history  = $conn->query("SELECT $cols $join WHERE t.to_municipality = '$m' AND t.status <> 'Pending' ORDER BY t.id DESC LIMIT 30")->fetch_all(MYSQLI_ASSOC);
    $sent     = $conn->query("SELECT $cols,
                    (SELECT COUNT(*) FROM transfer_requests x WHERE x.prev_request_id = t.id) AS followed,
                    (SELECT COUNT(*) FROM transfer_requests y WHERE y.fisherfolk_id = t.fisherfolk_id AND y.status = 'Pending') AS other_pending
                    $join WHERE t.from_municipality = '$m' ORDER BY t.id DESC LIMIT 60")->fetch_all(MYSQLI_ASSOC);

    foreach ($incoming as $r) {
        $fid = (int)$r['fisherfolk_id'];
        $q = $conn->query("SELECT permit_type AS t, permit_number AS n, issue_date, expiry_date, status FROM permits WHERE fisherfolk_id = $fid
                           UNION ALL SELECT doc_type, doc_number, issue_date, expiry_date, 'On file' FROM registration_documents WHERE fisherfolk_id = $fid ORDER BY issue_date DESC");
        $docsBy[$fid] = $q ? $q->fetch_all(MYSQLI_ASSOC) : [];
    }
}
$myList = effectiveRequirements($mun);

function tf_name(array $r): string { return trim($r['last_name'] . ', ' . $r['first_name'] . ' ' . ($r['middle_name'] ?? '')); }
function tf_badge(string $s): string {
    $c = ['Pending' => ['#FEF9C3', '#A16207'], 'Accepted' => ['#DCFCE7', '#15803D'], 'Rejected' => ['#FEE2E2', '#B91C1C'], 'Expired' => ['#EEF2F6', '#5a7185']][$s] ?? ['#EEF2F6', '#5a7185'];
    return '<span style="display:inline-block;background:' . $c[0] . ';color:' . $c[1] . ';font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px">' . e($s) . '</span>';
}
function tf_role(string $r): string { return $r === 'Owner' ? 'Boat owner' : ($r === 'Crew' ? 'Crew' : 'No boat'); }
?>
<?php pageHead('Transfer Requests'); ?>
<script src="confirm-modal.js"></script>
<style>
    .tf-tabs { display:flex; gap:6px; margin-bottom:18px; border-bottom:1px solid #d5e6f1; }
    .tf-tab { padding:10px 16px; font-size:14px; font-weight:700; color:#5a7185; text-decoration:none; border-bottom:3px solid transparent; margin-bottom:-1px; display:inline-flex; gap:8px; align-items:center; }
    .tf-tab.on { color:#054b7e; border-bottom-color:#006bb7; }
    .tf-pill { background:#DC2626; color:#fff; font-size:11px; font-weight:700; padding:1px 7px; border-radius:999px; }
    .tf-card { margin-bottom:16px; overflow:hidden; }
    .tf-top { padding:14px 20px; display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; border-bottom:1px solid rgba(213,230,241,.6); }
    .tf-name { font-size:16px; font-weight:700; color:#16324a; margin:0; }
    .tf-sub { font-size:12.5px; color:#5a7185; margin:2px 0 0; }
    .tf-mono { font-family:'JetBrains Mono',monospace; font-size:12px; color:#006bb7; font-weight:600; }
    .tf-body { padding:14px 20px; display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    @media (max-width: 900px) { .tf-body { grid-template-columns:1fr; } }
    .tf-h { font-size:11px; font-weight:800; color:#006bb7; text-transform:uppercase; letter-spacing:.1em; margin:0 0 6px; }
    .tf-doc { font-size:12.5px; padding:5px 0; border-bottom:1px solid #eef4f8; display:flex; justify-content:space-between; gap:8px; }
    .tf-doc span:last-child { color:#7d93a5; white-space:nowrap; }
    .tf-note { font-size:13px; background:#f4f9fc; border:1px solid #d5e6f1; border-radius:9px; padding:8px 12px; margin-top:8px; }
    .tf-actions { padding:14px 20px; background:rgba(234,246,253,.45); border-top:1px solid rgba(213,230,241,.6); display:grid; grid-template-columns:1.4fr 1fr; gap:18px; }
    @media (max-width: 900px) { .tf-actions { grid-template-columns:1fr; } }
    .tf-time { font-size:12px; font-weight:700; padding:3px 10px; border-radius:999px; background:#FEF3C7; color:#92400E; }
    .tf-time.low { background:#FEE2E2; color:#B91C1C; }
    .tf-empty { padding:36px 20px; text-align:center; color:#7d93a5; font-size:14px; }
</style>
<body>
<?php sidebar('transfers'); ?>
<main class="fade-up" style="margin-left:224px;min-height:100vh;padding:32px 36px;max-width:1150px;">
    <div style="margin-bottom:22px;">
        <h1 style="font-size:22px;font-weight:700;color:#16324a;margin:0 0 4px;">Transfer Requests</h1>
        <p style="font-size:13px;color:#5a7185;margin:0;">Fisherfolk moving between municipalities. Start a transfer from a record's Update screen in the Fisherfolk Registry.</p>
    </div>

    <?php if ($flash): ?><div class="alert-<?= e($flash[0]) ?>" style="margin-bottom:18px;"><?= e($flash[1]) ?></div><?php endif; ?>
    <?php if (!$ready): ?><div class="alert-error" style="margin-bottom:18px;">Import migration_v4b.sql in phpMyAdmin first. Transfers need it.</div><?php endif; ?>

    <div class="tf-tabs">
        <a class="tf-tab <?= $tab === 'incoming' ? 'on' : '' ?>" href="transfers.php?tab=incoming">Incoming<?php if ($sum['pending']): ?><span class="tf-pill"><?= (int)$sum['pending'] ?></span><?php endif; ?></a>
        <a class="tf-tab <?= $tab === 'sent' ? 'on' : '' ?>" href="transfers.php?tab=sent">Sent</a>
    </div>

<?php if ($tab === 'incoming'): ?>
    <?php if (!$incoming): ?>
        <div class="card"><div class="tf-empty">No transfer requests are waiting for <?= e($mun) ?>.</div></div>
    <?php endif; ?>
    <?php foreach ($incoming as $r): $low = (int)$r['secs'] < 86400; ?>
        <div class="card tf-card">
            <div class="tf-top">
                <div>
                    <p class="tf-name"><?= e(tf_name($r)) ?></p>
                    <p class="tf-sub">From <strong><?= e($r['from_municipality']) ?></strong> &middot; requested <?= e(date('M j, Y', strtotime($r['requested_at']))) ?><?= $r['req_by'] ? ' by ' . e($r['req_by']) : '' ?></p>
                </div>
                <div style="text-align:right;display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap;">
                    <a href="transfer_file.php?request=<?= (int)$r['id'] ?>" class="btn-primary btn-sm" style="text-decoration:none;">Access Permits</a>
                    <span class="tf-time <?= $low ? 'low' : '' ?>"><?= e(tf_left((int)$r['secs'])) ?> left to answer</span>
                </div>
            </div>
            <div class="tf-body">
                <div>
                    <p class="tf-h">Fisherfolk</p>
                    <div class="tf-doc"><span>Current registry no.</span><span class="tf-mono"><?= e($r['boat_registry_number']) ?></span></div>
                    <div class="tf-doc"><span>Boat role</span><span><?= e(tf_role((string)$r['boat_ownership'])) ?></span></div>
                    <div class="tf-doc"><span>Barangay in <?= e($r['from_municipality']) ?></span><span><?= e($r['barangay'] ?: 'none') ?></span></div>
                    <div class="tf-doc"><span>Contact</span><span><?= e($r['contact_number'] ?: 'none') ?></span></div>
                    <?php if ($r['request_note']): ?><div class="tf-note"><strong>Note from <?= e($r['from_municipality']) ?>:</strong> <?= e($r['request_note']) ?></div><?php endif; ?>
                </div>
                <div>
                    <p class="tf-h">Documents on file</p>
                    <?php foreach ($docsBy[(int)$r['fisherfolk_id']] ?? [] as $d): ?>
                        <div class="tf-doc"><span><?= e($d['t']) ?> <span class="tf-mono" style="color:#006bb7;"><?= e($d['n']) ?></span></span><span>issued <?= e($d['issue_date']) ?></span></div>
                    <?php endforeach; ?>
                    <?php if (empty($docsBy[(int)$r['fisherfolk_id']])): ?><div class="tf-doc"><span>None on file</span></div><?php endif; ?>
                    <div class="tf-note">These stay on the record until fresh copies replace them. After accepting, the record is on hold until FishR (and BoatR for boat owners) are renewed and every item on <?= e($mun) ?>'s checklist has a fresh copy: <?= e(implode(', ', $myList)) ?>.</div>
                </div>
            </div>
            <div class="tf-actions">
                <form method="POST" data-confirm="Accept this transfer? The record moves to <?= e($mun) ?> and gets a new registry number.">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="accept"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                    <p class="tf-h">Accept</p>
                    <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:10px;margin-bottom:10px;">
                        <div><label class="form-label">New barangay *</label><input class="form-input" name="barangay" required maxlength="100" placeholder="Barangay in <?= e($mun) ?>"></div>
                        <div><label class="form-label">New address</label><input class="form-input" name="address" value="<?= e($r['address']) ?>" placeholder="Street / Sitio / Purok"></div>
                    </div>
                    <button type="submit" class="btn-primary btn-sm">Accept transfer</button>
                </form>
                <form method="POST" data-confirm="Reject this transfer request?">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                    <p class="tf-h">Reject</p>
                    <div style="margin-bottom:10px;"><label class="form-label">Reason *</label><input class="form-input" name="note" required maxlength="255" placeholder="Short reason for the sending MAO"></div>
                    <button type="submit" class="btn-danger btn-sm">Reject</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($history): ?>
        <div class="card" style="overflow:hidden;margin-top:24px;">
            <div style="padding:14px 20px;border-bottom:1px solid rgba(213,230,241,.5);"><h2 style="font-weight:700;font-size:14px;color:#16324a;margin:0;">Earlier requests</h2></div>
            <div style="overflow-x:auto;"><table class="data-table">
                <thead><tr><th>Fisherfolk</th><th>From</th><th>Status</th><th>Answered</th><th>Note</th></tr></thead>
                <tbody><?php foreach ($history as $r): ?>
                    <tr><td><?= e(tf_name($r)) ?></td><td><?= e($r['from_municipality']) ?></td><td><?= tf_badge($r['status']) ?></td>
                        <td style="font-size:12.5px;color:#5a7185;"><?= $r['responded_at'] ? e(date('M j, Y', strtotime($r['responded_at']))) . ($r['resp_by'] ? '<br>' . e($r['resp_by']) : '') : e(date('M j, Y', strtotime($r['expires_at']))) ?></td>
                        <td style="font-size:12.5px;"><?= e($r['response_note'] ?: '') ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        </div>
    <?php endif; ?>

<?php else: ?>
    <?php if (!$sent): ?>
        <div class="card"><div class="tf-empty">You have not sent any transfer requests. Open a fisherfolk's Update screen to start one.</div></div>
    <?php else: ?>
        <div class="card" style="overflow:hidden;">
            <div style="overflow-x:auto;"><table class="data-table">
                <thead><tr><th>Fisherfolk</th><th>To</th><th>Status</th><th>Sent</th><th>Answer</th><th></th></tr></thead>
                <tbody><?php foreach ($sent as $r): ?>
                    <tr>
                        <td><div style="font-weight:600;"><?= e(tf_name($r)) ?></div><?= $r['prev_request_id'] ? '<div style="font-size:11px;color:#7d93a5;">Follow-up</div>' : '' ?></td>
                        <td><?= e($r['to_municipality']) ?></td>
                        <td><?= tf_badge($r['status']) ?></td>
                        <td style="font-size:12.5px;color:#5a7185;"><?= e(date('M j, Y', strtotime($r['requested_at']))) ?></td>
                        <td style="font-size:12.5px;">
                            <?php if ($r['status'] === 'Pending'): ?><span class="tf-time"><?= e(tf_left((int)$r['secs'])) ?> left</span>
                            <?php elseif ($r['status'] === 'Expired'): ?>No answer by <?= e(date('M j', strtotime($r['expires_at']))) ?>
                            <?php else: ?><?= e(date('M j, Y', strtotime($r['responded_at']))) ?><?= $r['resp_by'] ? ' &middot; ' . e($r['resp_by']) : '' ?><?= $r['response_note'] ? '<div style="color:#5a7185;">' . e($r['response_note']) . '</div>' : '' ?><?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <?php if ($r['status'] === 'Expired' && !(int)$r['followed'] && !(int)$r['other_pending'] && $r['cur_mun'] === $mun): ?>
                                <form method="POST" data-confirm="Send a follow-up to <?= e($r['to_municipality']) ?>?" style="display:inline;">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="followup"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn-warn btn-sm">Send follow-up</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        </div>
    <?php endif; ?>
<?php endif; ?>
</main>
<script>attachConfirmToForms();</script>
</body>
</html>
