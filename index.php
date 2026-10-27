<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/db.php';
if (!function_exists('e')) { function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} }

$loggedIn   = !empty($_SESSION['user_id']);
$loginError = '';
$loginUser  = '';


if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    if ($loggedIn) {
        header('Location: dashboard.php');
        exit();
    }
    $loginUser = trim($_POST['username'] ?? '');
    $password  = $_POST['password'] ?? '';
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $loginError = 'Your session expired. Please try again.';
    } elseif ($loginUser && $password) {
        $stmt = $conn->prepare("SELECT id, name, password, role, municipality, position, also_officer FROM users WHERE username = ? AND status = 'Active'");
        $stmt->bind_param('s', $loginUser);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if ($user && password_verify($password, $user['password'])) {
            startUserSession($user, 'office');
            header('Location: dashboard.php');
            exit();
        }
        $loginError = 'Invalid username or password.';
    } else {
        $loginError = 'Please enter your username and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#006bb7">
        <meta name="description" content="A connected platform for fisherfolk registration, permit verification, and coastal monitoring in southern Antique.">
        <title>Connected records. Protected coasts. - Bantay Dagat</title>
        <link rel="preload" href="landing/fonts/instrument-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="landing/fonts/instrument-sans-latin-600-normal.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="stylesheet" href="landing/css/fonts.css">
        <link rel="stylesheet" href="landing/css/base.css">
        <link rel="stylesheet" href="landing/css/landing.css">
        <link rel="stylesheet" href="landing/css/login-form.css">
        <script src="landing/js/landing.js" defer></script>
    </head>
    <body class="coastal-page">
        <svg class="coastal-symbols" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
            <symbol id="coastal-icon-waves" viewBox="0 0 48 40"><path d="M3 12c7-7 13-7 20-1s13 6 22-1M3 20c7-7 13-7 20-1s13 6 22-1M3 28c7-7 13-7 20-1s13 6 22-1" /></symbol>
            <symbol id="coastal-icon-users" viewBox="0 0 32 32"><circle cx="12" cy="11" r="4"/><path d="M3 26v-3a9 9 0 0 1 18 0v3H3ZM22 8a4 4 0 0 1 0 8M24 18a7 7 0 0 1 5 7v1h-5"/></symbol>
            <symbol id="coastal-icon-qr" viewBox="0 0 32 32"><path d="M3 3h10v10H3zM19 3h10v10H19zM3 19h10v10H3zM19 19h4v4h-4zM26 19h3M29 23v6h-6M19 27h2M7 7h2M23 7h2M7 23h2"/></symbol>
            <symbol id="coastal-icon-pin" viewBox="0 0 32 32"><path d="M16 29s10-10 10-17a10 10 0 0 0-20 0c0 7 10 17 10 17Z"/><circle cx="16" cy="12" r="3.5"/></symbol>
            <symbol id="coastal-icon-document" viewBox="0 0 32 32"><path d="M7 2h13l6 6v22H7zM20 2v7h6M11 15h11M11 20h11M11 25h8"/></symbol>
            <symbol id="coastal-icon-town" viewBox="0 0 40 40"><path d="M6 34h28M9 34V18l11-8 11 8v16M16 34V23h8v11M20 10V3M17 6h6M5 18h30M11 12V7M29 12V7"/></symbol>
            <symbol id="coastal-icon-mountain" viewBox="0 0 40 40"><path d="M2 31 16 13l5 7 5-5 12 16H2ZM10 21l5 3 5-4 5 4 4-2"/></symbol>
            <symbol id="coastal-icon-palm" viewBox="0 0 40 40"><path d="M19 35c3-9 4-17 2-25M13 35h16M21 11c-6-7-14-4-17 0 7-1 11 1 13 4M21 11c1-9 9-9 14-6-6 2-8 5-9 8M21 11c7-4 15 0 16 6-5-3-9-2-13 0M20 12c-5-3-10 1-12 7 6-3 10-3 13-2"/></symbol>
        </svg>
        <a class="coastal-skip-link" href="#main-content">Skip to content</a>
        <div class="coastal-shell">
            <header class="coastal-header">
                <?php $brandClass = ""; include __DIR__."/landing/brand.php"; ?>
                <nav class="coastal-nav" aria-label="Main navigation"><a href="#platform">The platform</a><a href="#how-it-works">How it works</a><a href="#in-action">In action</a><a href="#communities">Our communities</a><a href="#about">About</a></nav>
                <?php if ($loggedIn): ?><a class="coastal-header__login" href="dashboard.php">Dashboard</a><?php endif; ?>
                <button class="coastal-menu-toggle" type="button" aria-expanded="false" aria-controls="coastal-mobile-nav" aria-label="Open menu" data-menu-toggle><span></span><span></span><span></span></button>
            </header>
            <nav id="coastal-mobile-nav" class="coastal-mobile-nav" aria-label="Mobile navigation" hidden><a href="#platform">The platform</a><a href="#how-it-works">How it works</a><a href="#in-action">In action</a><a href="#communities">Our communities</a><a href="#about">About</a><?php if ($loggedIn): ?><a href="dashboard.php">Dashboard</a><?php else: ?><a href="#login" data-login-open>Login</a><?php endif; ?></nav>
            <main id="main-content"><?php include __DIR__."/landing/page.php"; ?></main>
            <footer class="coastal-footer">
                <?php $brandClass = "coastal-brand--footer"; include __DIR__."/landing/brand.php"; ?>
                <div class="coastal-footer__copy"><span>A capstone project &middot; St. Anthony's College</span><span>Southern Antique, Philippines</span></div>
                <nav class="coastal-footer__links" aria-label="Footer navigation"><a href="#platform">Platform</a><a href="#communities">Communities</a><a href="#about">About</a></nav>
            </footer>
        </div>
        <dialog class="coastal-guide-dialog" aria-labelledby="guide-title" data-guide-dialog>
            <div class="coastal-guide-dialog__header"><div><p class="coastal-eyebrow">Personnel guide</p><h2 id="guide-title">Working together along the coast</h2></div><button type="button" aria-label="Close personnel guide" data-guide-close>&times;</button></div>
            <p>Municipal offices manage local fisherfolk, vessel, and permit records. Coast Guard and Bantay Dagat personnel use the shared information for verification and coastal monitoring.</p>
            <p>Fisherfolk do not need an account; their municipal office maintains their records.</p>
            <div class="coastal-guide-dialog__actions">
                <?php if ($loggedIn): ?>
                    <a class="coastal-button coastal-button--blue" href="dashboard.php">Go to dashboard</a>
                <?php else: ?>
                    <a class="coastal-button coastal-button--blue" href="#login" data-login-open>Personnel log in</a>
                <?php endif; ?>
            </div>
        </dialog>
    </body>
</html>