<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}


const SUPERADMIN_ROLE = 'superadmin';
const MAO_ROLES     = ['superadmin', 'admin', 'mao_staff'];
const FIELD_ROLES   = ['mao_staff', 'officer', 'coastguard'];
const OFFICER_ROLES = ['officer', 'coastguard'];

function currentRole() { return $_SESSION['user_role'] ?? ''; }
function isMao()       { return in_array(currentRole(), MAO_ROLES, true); }
function isOfficer()   { return in_array(currentRole(), OFFICER_ROLES, true); }
function isSuperadmin(){ return currentRole() === SUPERADMIN_ROLE; }

function denyJson($code, $msg) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg, 'message' => $msg, 'login_required' => $code === 401]);
    exit();
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
}


function requireRole(array $roles, $mode = 'page', $loginPage = 'login.php') {
    if (!isset($_SESSION['user_id'])) {
        if ($mode === 'api') denyJson(401, 'Your session has ended. Please log in again.');
        header('Location: ' . $loginPage);
        exit();
    }
    if (!in_array(currentRole(), $roles, true)) {
        if ($mode === 'api') denyJson(403, 'Your account does not have access to this.');
        header('Location: dashboard.php');
        exit();
    }
}


function startUserSession(array $user, $mode = 'office') {
    session_regenerate_id(true);

    $dual = (in_array($user['role'], ['mao_staff', 'admin'], true) && !empty($user['also_officer']));
    $_SESSION['user_base_role']   = $user['role'];
    $_SESSION['user_id']          = $user['id'];
    $_SESSION['user_name']        = $user['name'];
    $_SESSION['user_role']        = ($mode === 'field' && $dual) ? 'officer' : $user['role'];
    $_SESSION['user_mun']         = $user['municipality'];
    $_SESSION['user_position']    = $user['position'] ?? '';
    $_SESSION['user_dual_access'] = $dual ? 1 : 0;
}

function hasDualAccess() { return !empty($_SESSION['user_dual_access']); }

function getCurrentUser() {
    return [
        'id'       => $_SESSION['user_id']   ?? null,
        'name'     => $_SESSION['user_name'] ?? '',
        'role'     => $_SESSION['user_role'] ?? '',
        'mun'      => $_SESSION['user_mun']  ?? '',
        'position' => $_SESSION['user_position'] ?? '',
        'dual'     => !empty($_SESSION['user_dual_access']),
    ];
}