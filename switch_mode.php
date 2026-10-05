<?php
require_once 'includes/auth.php';
requireLogin();

if (!hasDualAccess()) {
    header('Location: dashboard.php');
    exit();
}


$_SESSION['user_role'] = (($_GET['to'] ?? '') === 'field') ? 'officer' : ($_SESSION['user_base_role'] ?? 'mao_staff');
header('Location: dashboard.php');
exit();
