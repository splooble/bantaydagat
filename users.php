<?php

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/layout.php';
if (!function_exists('e')) { function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} }

requireRole(['superadmin', 'admin']);
const MIN_PASSWORD = 8;
$isSuper = isSuperadmin();
$myMun   = (string)($_SESSION['user_mun'] ?? '');
if (!$isSuper && $myMun === '') { header('Location: dashboard.php'); exit(); }

$ROLES = $isSuper ? ['admin'] : FIELD_ROLES;

$waters = json_decode((string)@file_get_contents(__DIR__.'/includes/waters.json'), true) ?: ['features' => []];
$ALL_MUNICIPALITIES = array_map(function ($f) { return $f['properties']['name']; }, $waters['features']);
$MUNICIPALITIES = $isSuper ? $ALL_MUNICIPALITIES : [$myMun];
$ROLE_LABEL = ['admin' => 'MAO account', 'mao_staff' => 'MAO staff', 'officer' => 'Officer (Bantay Dagat)', 'coastguard' => 'Coast Guard'];

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$me = (int)$_SESSION['user_id'];

function flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }
function back(): void { header('Location: users.php'); exit(); }

function canManage(?array $t): bool {
    global $isSuper, $myMun;
    if (!$t) return false;
    if ($isSuper) return $t['role'] === 'admin';
    return (string)$t['municipality'] === $myMun && in_array($t['role'], FIELD_ROLES, true);
}
function loadTarget(mysqli $conn, int $id): ?array {
    $st = $conn->prepare('SELECT id, role, municipality FROM users WHERE id = ?');
    $st->bind_param('i', $id); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ?: null;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        flash('error', 'Session expired. Please try again.'); back();
    }
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        $role = $_POST['role'] ?? '';
        $muni = $isSuper ? trim($_POST['municipality'] ?? '') : $myMun;
        $pos  = trim($_POST['position'] ?? '');

        if (!$name || !$username || !in_array($role, $ROLES, true)) {
            flash('error', 'Name, username and a valid role are required.'); back();
        }
        if (!in_array($muni, $MUNICIPALITIES, true)) {
            flash('error', 'Choose a municipality.'); back();
        }
        if (strlen($pass) < MIN_PASSWORD) {
            flash('error', 'Password must be at least '.MIN_PASSWORD.' characters.'); back();
        }
        $chk = $conn->prepare('SELECT id FROM users WHERE username = ?');
        $chk->bind_param('s', $username);
        $chk->execute();
        if ($chk->get_result()->num_rows) { flash('error', 'Username already taken.'); back(); }

        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $ins = $conn->prepare("INSERT INTO users (name, username, password, role, municipality, position, status, created_by) VALUES (?,?,?,?,?,?, 'Active', ?)");
        $ins->bind_param('ssssssi', $name, $username, $hash, $role, $muni, $pos, $me);
        $ins->execute();
        flash('ok', "User '$username' created."); back();
    }


    if (in_array($action, ['reset_password', 'update', 'toggle_status'], true) && !canManage(loadTarget($conn, $id))) {
        flash('error', 'You do not have access to that account.'); back();
    }

    if ($action === 'reset_password') {
        $pass = (string)($_POST['password'] ?? '');
        if (strlen($pass) < MIN_PASSWORD) {
            flash('error', 'Password must be at least '.MIN_PASSWORD.' characters.'); back();
        }
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $up = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $up->bind_param('si', $hash, $id);
        $up->execute();
        flash('ok', 'Password updated.'); back();
    }

    if ($action === 'update') {
        $role = $_POST['role'] ?? '';
        $muni = $isSuper ? trim($_POST['municipality'] ?? '') : $myMun;
        $pos  = trim($_POST['position'] ?? '');
        if (!in_array($role, $ROLES, true)) { flash('error', 'Invalid role.'); back(); }
        if (!in_array($muni, $MUNICIPALITIES, true)) { flash('error', 'Choose a municipality.'); back(); }
        $up = $conn->prepare('UPDATE users SET role = ?, municipality = ?, position = ? WHERE id = ?');
        $up->bind_param('sssi', $role, $muni, $pos, $id);
        $up->execute();
        flash('ok', 'User updated.'); back();
    }

    if ($action === 'toggle_status') {
        if ($id === $me) { flash('error', 'You cannot deactivate your own account.'); back(); }
        $up = $conn->prepare("UPDATE users SET status = IF(status = 'Active', 'Inactive', 'Active') WHERE id = ?");
        $up->bind_param('i', $id);
        $up->execute();
        flash('ok', 'Status changed.'); back();
    }

    flash('error', 'Unknown action.'); back();
}


$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
if ($isSuper) {
    $q = $conn->prepare("SELECT id, name, username, role, municipality, position, status FROM users WHERE role = 'admin' ORDER BY status, municipality, name");
} else {
    $q = $conn->prepare("SELECT id, name, username, role, municipality, position, status FROM users WHERE municipality = ? AND role IN ('mao_staff','officer','coastguard') ORDER BY status, role, name");
    $q->bind_param('s', $myMun);
}
$q->execute(); $users = $q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();

function opts(array $list, string $sel = ''): string {
    global $ROLE_LABEL;
    $o = '';
    foreach ($list as $v) $o .= '<option value="'.e($v).'"'.($v === $sel ? ' selected' : '').'>'.e($ROLE_LABEL[$v] ?? $v).'</option>';
    return $o;
}
?>
<?php pageHead($isSuper ? 'MAO Accounts' : 'Field Staff Accounts'); ?>
<style>

    .um-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px; }
    .um-row-form { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin:0; }
    .um-row-form .form-input { width:auto; min-width:120px; padding:6px 10px; font-size:13px; }
    .um-row-form input.form-input { min-width:150px; }
    .um-actions { display:flex; flex-direction:column; align-items:flex-start; gap:6px; }
    .um-actions form { margin:0; }
    .um-reset summary { list-style:none; cursor:pointer; }
    .um-reset summary::-webkit-details-marker { display:none; }
    .um-reset[open] summary { margin-bottom:6px; }
    .um-you { color:#5a7185; font-weight:500; font-size:12px; }
</style>

<body>
<?php sidebar('users'); ?>
<main class="fade-up" style="margin-left:224px;min-height:100vh;padding:32px 36px;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;">
        <div>
            <h1 style="font-size:22px;font-weight:700;color:#16324a;margin:0 0 4px;"><?= $isSuper ? 'MAO Accounts' : 'Field Staff Accounts' ?></h1>
            <p style="font-size:13px;color:#5a7185;margin:0;"><?= $isSuper ? 'Create the MAO account for each municipality, reset passwords, and activate or deactivate accounts' : 'Create and manage field staff for ' . e($myMun) . ': set roles, reset passwords, and activate or deactivate accounts' ?></p>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert-<?= $flash[0] === 'ok' ? 'success' : 'error' ?>" role="status" style="margin-bottom:20px;"><?= e($flash[1]) ?></div>
    <?php endif; ?>


    <div class="card" style="overflow:hidden;margin-bottom:24px;">
        <div style="padding:14px 20px;border-bottom:1px solid rgba(213,230,241,.5);">
            <h2 style="font-weight:700;font-size:14px;color:#16324a;margin:0;"><?= $isSuper ? 'Add MAO account' : 'Add field staff' ?></h2>
        </div>
        <form method="post" autocomplete="off" style="padding:18px 20px;">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="add">
            <div class="um-grid">
                <div><label class="form-label">Full name</label><input class="form-input" name="name" required></div>
                <div><label class="form-label">Username</label><input class="form-input" name="username" required></div>
                <div><label class="form-label">Temporary password</label><input class="form-input" type="password" name="password" minlength="<?= MIN_PASSWORD ?>" required></div>
                <div><label class="form-label">Role</label><select class="form-input" name="role"><?= opts($ROLES) ?></select></div>
                <div><label class="form-label">Municipality</label>
                    <?php if ($isSuper): ?><select class="form-input" name="municipality" required><option value="">-</option><?= opts($MUNICIPALITIES) ?></select>
                    <?php else: ?><input class="form-input" value="<?= e($myMun) ?>" disabled><?php endif; ?></div>
                <div><label class="form-label">Position</label><input class="form-input" name="position"></div>
            </div>
            <div style="margin-top:18px;">
                <button type="submit" class="btn-primary">
                    <svg style="width:16px;height:16px;flex-shrink:0;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    <?= $isSuper ? 'Create MAO account' : 'Create staff account' ?>
                </button>
            </div>
        </form>
    </div>


    <div class="card" style="overflow:hidden;">
        <div style="padding:14px 20px;border-bottom:1px solid rgba(213,230,241,.5);">
            <h2 style="font-weight:700;font-size:14px;color:#16324a;margin:0;"><?= $isSuper ? 'MAO accounts' : 'Field staff' ?> (<?= count($users) ?>)</h2>
        </div>
        <div style="padding:8px 20px 16px;overflow-x:auto;">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Username</th><th><?= $isSuper ? 'Municipality / Position' : 'Role / Position' ?></th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td style="font-weight:600;"><?= e($u['name']) ?><?= (int)$u['id'] === $me ? ' <span class="um-you">(you)</span>' : '' ?></td>
                        <td><?= e($u['username']) ?></td>
                        <td>
                            <form method="post" class="um-row-form">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <?php if ($isSuper): ?>
                                <input type="hidden" name="role" value="admin">
                                <select class="form-input" name="municipality" aria-label="Municipality"><?= opts($MUNICIPALITIES, (string)$u['municipality']) ?></select>
                                <?php else: ?>
                                <select class="form-input" name="role" aria-label="Role"><?= opts($ROLES, $u['role']) ?></select>
                                <?php endif; ?>
                                <input class="form-input" name="position" value="<?= e($u['position']) ?>" placeholder="Position" aria-label="Position">
                                <button type="submit" class="btn-primary btn-sm">Save</button>
                            </form>
                        </td>
                        <td><span class="badge <?= $u['status'] === 'Active' ? 'badge-active' : 'badge-expired' ?>"><?= e($u['status']) ?></span></td>
                        <td>
                            <div class="um-actions">
                                <details class="um-reset">
                                    <summary class="btn-secondary btn-sm">Reset password</summary>
                                    <form method="post" autocomplete="off" class="um-row-form">
                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                        <input class="form-input" type="password" name="password" minlength="<?= MIN_PASSWORD ?>" placeholder="New password" required>
                                        <button type="submit" class="btn-primary btn-sm">Set</button>
                                    </form>
                                </details>
                                <?php if ((int)$u['id'] !== $me): ?>
                                <form method="post" onsubmit="return confirm('Change this user\'s status?')">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                    <?php if ($u['status'] === 'Active'): ?>
                                        <button type="submit" class="btn-danger btn-sm">Deactivate</button>
                                    <?php else: ?>
                                        <button type="submit" class="btn-secondary btn-sm" style="color:#15803D;">Activate</button>
                                    <?php endif; ?>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</body>
</html>