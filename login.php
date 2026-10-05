<?php


require_once 'includes/auth.php';
header('Location: ' . (isset($_SESSION['user_id']) ? 'dashboard.php' : 'index.php#login'));
exit();