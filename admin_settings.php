<?php

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/requirements.php';
if (!function_exists('e')) { function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} }

requireRole(['admin']);
$mun = (string)($_SESSION['user_mun'] ?? '');
$uid = (int)$_SESSION['user_id'];
if ($mun === '' || !isset(MUN_CODES[$mun])) { header('Location: dashboard.php'); exit(); }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }
function back(): void { header('Location: admin_settings.php'); exit(); }


$tbl = $conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('settings','settings_log') ");
$ready = $tbl && $tbl->num_rows === 2;


$DOC_ORDER = array_merge(CHAIN_TYPES, ['Sanitary Permit', 'MAO Certification', 'Brgy Clearance', 'Cedula', 'Receipt']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { flash('error', 'Session expired. Please try again.'); back(); }
    if (!$ready) { flash('error', 'Import migration_v4a.sql in phpMyAdmin first.'); back(); }
    $section = $_POST['section'] ?? '';
    $changes = [];
    $err = '';

    if ($section === 'requirements') {
        $picked = array_values(array_intersect($DOC_ORDER, (array)($_POST['req'] ?? [])));
        if (!$picked) $err = 'Pick at least one required document.';
        else $changes['requirements'] = $picked;
    } elseif ($section === 'permits') {
        $v = [];
        foreach (PERMIT_TYPES as $t) {
            $n = (int)($_POST['validity'][$t] ?? 0);
            if ($n < 1 || $n > 60) { $err = "$t validity must be 1 to 60 months."; break; }
            $v[$t] = $n;
        }
        $soon = (int)($_POST['expiring_soon_days'] ?? 0);
        if (!$err && ($soon < 1 || $soon > 180)) $err = 'Expiring-soon window must be 1 to 180 days.';
        if (!$err) { $changes['permit_validity'] = $v; $changes['expiring_soon_days'] = $soon; }
    } elseif ($section === 'transfers') {
        $d = (int)($_POST['transfer_window_days'] ?? 0);
        if ($d < 1 || $d > 30) $err = 'Transfer response window must be 1 to 30 days.';
        else $changes['transfer_window_days'] = $d;
    } elseif ($section === 'office') {
        $name = trim((string)($_POST['office_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) $err = 'Office name is required (up to 80 characters).';
        else $changes['office_name'] = $name;
    } elseif ($section === 'waters') {
        $acc = $_POST['waters_access'] ?? '';
        if (!in_array($acc, ['Open', 'Closed'], true)) $err = 'Choose Open or Closed.';
        $lines = array_values(array_filter(array_map(fn($l) => trim(preg_replace('/\s+/', ' ', $l)), preg_split('/\R/', (string)($_POST['access_requirements'] ?? '')))));
        $lines = array_values(array_unique(array_map(fn($l) => mb_substr($l, 0, 80), $lines)));
        if (count($lines) > 20) $err = $err ?: 'Keep the access checklist to 20 items or fewer.';
        if (!$err) { $changes['waters_access'] = $acc; $changes['access_requirements'] = $lines; }
    } elseif ($section === 'timer') {
        $s = (int)($_POST['encroach_seconds'] ?? 0);
        if ($s < 1 || $s > 86400) $err = 'Timer must be 1 to 86400 seconds.';
        else $changes['encroach_seconds'] = $s;
    } else $err = 'Unknown section.';

    if ($err) { flash('error', $err); back(); }
    $n = 0;
    foreach ($changes as $k => $val) if (saveSetting($conn, $mun, $k, $val, $uid)) $n++;
    flash('ok', $n ? 'Saved. ' . $n . ' setting' . ($n === 1 ? '' : 's') . ' changed.' : 'Nothing changed.');
    back();
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

$reqNow     = effectiveRequirements($mun);
$validity   = getSetting($mun, 'permit_validity');
$soonDays   = (int)getSetting($mun, 'expiring_soon_days');
$window     = (int)getSetting($mun, 'transfer_window_days');
$office     = (string)getSetting($mun, 'office_name');
$access     = (string)getSetting($mun, 'waters_access');
$accessList = getSetting($mun, 'access_requirements');
$timerSec   = (int)getSetting($mun, 'encroach_seconds');

$log = [];
if ($ready) {
    $q = $conn->prepare("SELECT l.setting_key, l.old_value, l.new_value, l.changed_at, u.name
                           FROM settings_log l LEFT JOIN users u ON u.id = l.changed_by
                          WHERE l.municipality = ? ORDER BY l.id DESC LIMIT 40");
    $q->bind_param('s', $mun); $q->execute(); $log = $q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
}
$LABEL = [
    'requirements' => 'Required documents', 'access_requirements' => 'Access permit checklist',
    'transfer_window_days' => 'Transfer response window (days)', 'expiring_soon_days' => 'Expiring-soon window (days)',
    'permit_validity' => 'Permit validity (months)', 'office_name' => 'Office name on ID',
    'encroach_seconds' => 'Encroachment timer (seconds)', 'waters_access' => 'Waters access',
];
function shortVal($k, $v) {
    if ($v === null || $v === '') return 'none';
    $d = json_decode((string)$v, true);
    if (is_array($d)) {
        $isList = array_keys($d) === range(0, count($d) - 1);
        if (!$d) return 'none';
        if ($isList) return implode(', ', $d);
        $p = []; foreach ($d as $a => $b) $p[] = "$a: $b"; return implode(', ', $p);
    }
    return (string)$v;
}
?>
<?php pageHead('Admin Settings'); ?>
<style>
    .as-sec { margin-bottom:22px; overflow:hidden; }
    .as-head { padding:14px 20px; border-bottom:1px solid rgba(213,230,241,.5); }
    .as-head h2 { font-weight:700; font-size:14px; color:#16324a; margin:0 0 2px; }
    .as-head p { font-size:12.5px; color:#5a7185; margin:0; }
    .as-body { padding:18px 20px; }
    .as-checks { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:10px; }
    .as-check { display:flex; align-items:center; gap:10px; border:1.5px solid #d5e6f1; border-radius:9px; padding:10px 12px; font-size:14px; font-weight:600; cursor:pointer; }
    .as-check input { width:16px; height:16px; accent-color:#006bb7; }
    .as-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:14px; }
    .as-hint { font-size:12px; color:#5a7185; margin-top:6px; }
    .as-foot { margin-top:16px; }
    .as-code { font-family:'JetBrains Mono',monospace; background:#eaf6fd; color:#054b7e; border-radius:8px; padding:6px 12px; font-weight:600; display:inline-block; }
    .as-log td { font-size:13px; }
</style>
<body>
<?php sidebar('admin_settings'); ?>
<main class="fade-up" style="margin-left:224px;min-height:100vh;padding:32px 36px;max-width:1100px;">

    <div style="margin-bottom:24px;">
        <h1 style="font-size:22px;font-weight:700;color:#16324a;margin:0 0 4px;">Admin Settings</h1>
        <p style="font-size:13px;color:#5a7185;margin:0;">Rules for <?= e($mun) ?>. Changes apply to this municipality only and are recorded in the change log below.</p>
    </div>

    <?php if (!$ready): ?>
        <div class="alert-error" style="margin-bottom:20px;">The settings tables are missing. Import <b>migration_v4a.sql</b> in phpMyAdmin, then reload this page.</div>
    <?php endif; ?>
    <?php if ($flash): ?>
        <div class="alert-<?= $flash[0] === 'ok' ? 'success' : 'error' ?>" role="status" style="margin-bottom:20px;"><?= e($flash[1]) ?></div>
    <?php endif; ?>


    <div class="card as-sec">
        <div class="as-head"><h2>Municipality</h2><p>The code is part of every registry number, so it cannot be changed here.</p></div>
        <div class="as-body">
            <div class="as-grid">
                <div><label class="form-label">Municipality</label><div style="font-weight:600;"><?= e($mun) ?></div></div>
                <div><label class="form-label">Municipality code (read-only)</label><span class="as-code"><?= e(MUN_CODES[$mun]) ?></span></div>
            </div>
        </div>
    </div>


    <form method="post" class="card as-sec">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="section" value="requirements">
        <div class="as-head"><h2>Registration requirements</h2><p>Documents a fisherfolk needs before the record is complete. BoatR still applies to boat owners only.</p></div>
        <div class="as-body">
            <div class="as-checks">
                <?php foreach ($DOC_ORDER as $t): ?>
                    <label class="as-check"><input type="checkbox" name="req[]" value="<?= e($t) ?>" <?= in_array($t, $reqNow, true) ? 'checked' : '' ?>><?= e($t) ?></label>
                <?php endforeach; ?>
            </div>
            <p class="as-hint">FishR, BoatR and Mayor's Permit are always taken in that order. Changing this list updates the registration form and every record's hold status right away.</p>
            <div class="as-foot"><button class="btn-primary" type="submit">Save requirements</button></div>
        </div>
    </form>


    <form method="post" class="card as-sec">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="section" value="permits">
        <div class="as-head"><h2>Permit types and validity</h2><p>How many months a permit is valid after it is renewed.</p></div>
        <div class="as-body">
            <div class="as-grid">
                <?php foreach (PERMIT_TYPES as $t): ?>
                    <div><label class="form-label"><?= e($t) ?> (months)</label><input class="form-input" type="number" min="1" max="60" name="validity[<?= e($t) ?>]" value="<?= (int)($validity[$t] ?? 12) ?>" required></div>
                <?php endforeach; ?>
                <div><label class="form-label">Expiring-soon window (days)</label><input class="form-input" type="number" min="1" max="180" name="expiring_soon_days" value="<?= $soonDays ?>" required></div>
            </div>
            <p class="as-hint">The expiring-soon window is saved now and will be used by the dashboard and registry flags in a later step.</p>
            <div class="as-foot"><button class="btn-primary" type="submit">Save permit settings</button></div>
        </div>
    </form>


    <form method="post" class="card as-sec">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="section" value="transfers">
        <div class="as-head"><h2>Transfers</h2><p>How long a municipality has to answer a transfer request before it expires.</p></div>
        <div class="as-body">
            <div class="as-grid"><div><label class="form-label">Response window (days)</label><input class="form-input" type="number" min="1" max="30" name="transfer_window_days" value="<?= $window ?>" required></div></div>
            <p class="as-hint">This applies to requests sent to your municipality. After it passes, the request expires and the sending MAO can send a follow-up.</p>
            <div class="as-foot"><button class="btn-primary" type="submit">Save transfer window</button></div>
        </div>
    </form>


    <form method="post" class="card as-sec">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="section" value="office">
        <div class="as-head"><h2>ID and office details</h2><p>The office name printed on the back of the ID card.</p></div>
        <div class="as-body">
            <div style="max-width:460px;"><label class="form-label">Office name</label><input class="form-input" name="office_name" maxlength="80" value="<?= e($office) ?>" required></div>
            <div class="as-foot"><button class="btn-primary" type="submit">Save office name</button></div>
        </div>
    </form>


    <form method="post" class="card as-sec">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="section" value="waters">
        <div class="as-head"><h2>Municipal waters access</h2><p>Open waters welcome fishers from other municipalities. Closed waters need an Outside Fisher Access Permit.</p></div>
        <div class="as-body">
            <div style="max-width:260px;margin-bottom:16px;">
                <label class="form-label">Waters access</label>
                <select class="form-input" name="waters_access"><option value="Open" <?= $access === 'Open' ? 'selected' : '' ?>>Open</option><option value="Closed" <?= $access === 'Closed' ? 'selected' : '' ?>>Closed</option></select>
            </div>
            <label class="form-label">Outside Fisher Access Permit checklist (one item per line)</label>
            <textarea class="form-input" name="access_requirements" rows="5" style="max-width:460px;"><?= e(implode("\n", $accessList)) ?></textarea>
            <p class="as-hint">Items an outside fisher must hand in to get access. Used when access permits are added in a later step.</p>
            <div class="as-foot"><button class="btn-primary" type="submit">Save waters access</button></div>
        </div>
    </form>


    <form method="post" class="card as-sec">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="section" value="timer">
        <div class="as-head"><h2>Encroachment grace timer</h2><p>How long a commercial boat may stay inside your waters before the map raises an alert.</p></div>
        <div class="as-body">
            <div style="max-width:260px;"><label class="form-label">Seconds</label><input class="form-input" type="number" min="1" max="86400" name="encroach_seconds" value="<?= $timerSec ?>" required></div>
            <p class="as-hint">1800 seconds is 30 minutes. Use a small value like 25 only for demos.</p>
            <div class="as-foot"><button class="btn-primary" type="submit">Save timer</button></div>
        </div>
    </form>


    <div class="card as-sec">
        <div class="as-head"><h2>Change log</h2><p>The latest 40 changes for <?= e($mun) ?>.</p></div>
        <div style="padding:8px 20px 16px;overflow-x:auto;">
            <table class="data-table as-log">
                <thead><tr><th>When</th><th>Who</th><th>Setting</th><th>From</th><th>To</th></tr></thead>
                <tbody>
                <?php foreach ($log as $r): ?>
                    <tr><td style="white-space:nowrap;"><?= e($r['changed_at']) ?></td><td><?= e($r['name'] ?? 'Unknown') ?></td>
                        <td><?= e($LABEL[$r['setting_key']] ?? $r['setting_key']) ?></td>
                        <td><?= e(shortVal($r['setting_key'], $r['old_value'])) ?></td><td><?= e(shortVal($r['setting_key'], $r['new_value'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$log): ?><tr><td colspan="5" style="color:#5a7185;">No changes yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</body>
