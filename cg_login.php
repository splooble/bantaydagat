<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'coastguard') {
    header('Location: scan.php');
    exit();
}
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username && $password) {
        $stmt = $conn->prepare("SELECT id, name, password, role, municipality FROM users WHERE username = ? AND role = 'coastguard'");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_mun']  = $user['municipality'];
            header('Location: scan.php');
            exit();
        } else {
            $error = 'Invalid credentials or not a Coast Guard account.';
        }
    } else {
        $error = 'Please enter your username and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0A1F3D">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Coast Guard Login | BantayDagat: Connect</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Sora', sans-serif;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            background: #0A1F3D;
        }

        .slide {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            opacity: 0;
            transition: opacity 1.6s ease;
        }

        .slide.active {
            opacity: 1;
        }

        #s1 {
            background-image: url('https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=1600&q=80');
        }

        #s2 {
            background-image: url('https://images.unsplash.com/photo-1505118380757-91f5f5632de0?w=1600&q=80');
        }

        #s3 {
            background-image: url('https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=1600&q=80');
        }

        #s4 {
            background-image: url('https://images.unsplash.com/photo-1559827260-dc66d52bef19?w=1600&q=80');
        }

        .overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(160deg, rgba(10, 31, 61, .82) 0%, rgba(10, 31, 61, .65) 40%, rgba(5, 15, 35, .80) 100%);
        }

        .dots {
            position: absolute;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 7px;
            z-index: 10;
        }

        .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .3);
            cursor: pointer;
            transition: all .3s;
            border: none;
            padding: 0;
        }

        .dot.active {
            background: #fff;
            transform: scale(1.2);
        }

        .login-card {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 400px;
            padding: 16px;
        }

        .card-inner {
            background: rgba(255, 255, 255, .93);
            backdrop-filter: blur(28px) saturate(160%);
            -webkit-backdrop-filter: blur(28px) saturate(160%);
            border: 1px solid rgba(255, 255, 255, .9);
            border-radius: 22px;
            padding: 36px 32px 32px;
            box-shadow: 0 32px 80px rgba(10, 31, 61, .45);
            animation: cardUp .55s cubic-bezier(.16, 1, .3, 1) forwards;
        }

        @keyframes cardUp {
            from {
                opacity: 0;
                transform: translateY(28px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .logo-wrap {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #0A1F3D, #1A3A6B);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            box-shadow: 0 8px 24px rgba(10, 31, 61, .35);
        }

        .cg-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(10, 31, 61, .06);
            border: 1px solid rgba(10, 31, 61, .12);
            border-radius: 999px;
            padding: 4px 12px;
            font-size: 11px;
            font-weight: 700;
            color: #0A1F3D;
            letter-spacing: .05em;
            text-transform: uppercase;
            margin-bottom: 16px;
        }

        .cg-badge span {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #1A6B3A;
            display: inline-block;
        }

        h1 {
            font-size: 21px;
            font-weight: 700;
            color: #0A1F3D;
            margin: 0 0 4px;
            text-align: center;
        }

        .subtitle {
            font-size: 12px;
            color: #64748B;
            text-align: center;
            margin: 0 0 24px;
            line-height: 1.5;
        }

        label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: 5px;
        }

        .form-input {
            width: 100%;
            border: 1.5px solid #DDD5C8;
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 14px;
            color: #0A1F3D;
            background: rgba(255, 255, 255, .9);
            outline: none;
            transition: all .2s;
            font-family: 'Sora', sans-serif;
        }

        .form-input:focus {
            border-color: #0A1F3D;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(10, 31, 61, .1);
        }

        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, #0A1F3D, #1A3A6B);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 13px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: .01em;
            transition: all .2s;
            box-shadow: 0 4px 16px rgba(10, 31, 61, .35);
            font-family: 'Sora', sans-serif;
        }

        .btn-login:hover {
            opacity: .92;
            transform: translateY(-1px);
        }

        .alert-error {
            background: #FEF2F2;
            color: #B91C1C;
            border: 1px solid #FCA5A5;
            border-radius: 9px;
            padding: 10px 14px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .admin-link {
            text-align: center;
            margin-top: 18px;
            font-size: 12px;
            color: #94A3B8;
        }

        .admin-link a {
            color: #64748B;
            font-weight: 600;
            text-decoration: none;
        }

        .admin-link a:hover {
            color: #0A1F3D;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0 16px;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(0, 0, 0, .08);
        }

        .divider span {
            font-size: 11px;
            color: #94A3B8;
            white-space: nowrap;
        }
    </style>
</head>

<body>

    <!-- Slideshow -->
    <div class="slide active" id="s1"></div>
    <div class="slide" id="s2"></div>
    <div class="slide" id="s3"></div>
    <div class="slide" id="s4"></div>
    <div class="overlay"></div>

    <!-- Dots -->
    <div class="dots">
        <button class="dot active" onclick="goSlide(0)"></button>
        <button class="dot" onclick="goSlide(1)"></button>
        <button class="dot" onclick="goSlide(2)"></button>
        <button class="dot" onclick="goSlide(3)"></button>
    </div>

    <!-- Login card -->
    <div class="login-card">
        <div class="card-inner">

            <div style="text-align:center;">
                <div style="margin:0 auto 20px;width:80px;height:80px;">
                    <img src="includes/logos/coastguard_logo.png" alt="Coast Guard Logo" style="width:100%;height:100%;object-fit:contain;">
                </div>
                <div style="display:flex;justify-content:center;">
                    <div class="cg-badge"><span></span> Philippine Coast Guard</div>
                </div>
                <h1>Officer Login</h1>
                <p class="subtitle">BantayDagat: Connect — Permit Verification<br>Southern Antique Coastal Zone</p>
            </div>

            <?php if ($error): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="cg_login.php">
                <div style="margin-bottom:14px;">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-input"
                        placeholder="Enter officer username"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        autocomplete="username" required>
                </div>
                <div style="margin-bottom:22px;position:relative;">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-input"
                        placeholder="Enter password"
                        autocomplete="current-password"
                        style="padding-right:44px;" required>
                    <button type="button" onclick="togglePw()" style="position:absolute;right:12px;bottom:11px;background:none;border:none;cursor:pointer;color:#94A3B8;padding:0;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </div>
                <button type="submit" class="btn-login">Sign In &amp; Open Scanner</button>
            </form>

            <div class="divider"><span>or</span></div>
            <div class="admin-link">
                <a href="index.php">← MAO / Admin Login</a>
            </div>

            <p style="text-align:center;font-size:10.5px;color:#CBD5E1;margin-top:20px;line-height:1.6;">
                San Jose de Buenavista &bull; Hamtic<br>Tobias Fornier &bull; Anini-y
            </p>
        </div>
    </div>

    <script>
        let current = 0;
        const slides = document.querySelectorAll('.slide');
        const dots = document.querySelectorAll('.dot');

        function goSlide(n) {
            slides[current].classList.remove('active');
            dots[current].classList.remove('active');
            current = n;
            slides[current].classList.add('active');
            dots[current].classList.add('active');
        }
        setInterval(() => goSlide((current + 1) % slides.length), 5000);

        function togglePw() {
            const pw = document.getElementById('password');
            pw.type = pw.type === 'password' ? 'text' : 'password';
        }
    </script>
</body>

</html>