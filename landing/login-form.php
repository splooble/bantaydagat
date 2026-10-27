<?php
$loggedIn = $loggedIn ?? !empty($_SESSION['user_id']);
$loginError = $loginError ?? '';
$loginUser = $loginUser ?? '';
?>
<section id="login" class="coastal-login-card" aria-labelledby="login-title"<?= $loginError ? ' data-has-error' : '' ?>>
<?php if ($loggedIn): ?>
    <p class="coastal-eyebrow">Personnel access</p>
    <h2 id="login-title">Welcome back</h2>
    <p class="coastal-login__sub"><?= e($_SESSION['user_name'] ?? 'You are signed in') ?>, your coastal workspace is ready.</p>
    <a class="coastal-login__submit coastal-login__submit--link" href="dashboard.php">Go to dashboard</a>
    <p class="coastal-login__foot"><a href="logout.php">Sign out</a></p>
<?php else: ?>
    <p class="coastal-eyebrow">Personnel access</p>
    <h2 id="login-title">Log in</h2>
    <p class="coastal-login__sub">Welcome back to your coastal workspace.</p>

    <?php if ($loginError): ?>
        <div class="coastal-login__error" role="alert"><?= e($loginError) ?></div>
    <?php endif; ?>

    <form method="post" action="index.php" class="coastal-login__form">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
        <input type="hidden" name="login_submit" value="1">

        <label for="login-username">Username</label>
        <input type="text" id="login-username" name="username" placeholder="Enter your username" value="<?= e($loginUser) ?>" autocomplete="username" required>

        <label for="login-password">Password</label>
        <div class="coastal-login__password">
            <input type="password" id="login-password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
            <button type="button" aria-label="Show password" aria-pressed="false" data-toggle-password>
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            </button>
        </div>

        <div class="coastal-login__row">
            <button type="button" class="coastal-login__link" aria-expanded="false" aria-controls="login-forgot-note" data-forgot-toggle>Forgot password?</button>
        </div>
        <p id="login-forgot-note" class="coastal-login__note" hidden>Password resets are handled by your system administrator. Please contact them to reset your password.</p>

        <button type="submit" class="coastal-login__submit">Log in</button>
    </form>

    <p class="coastal-login__foot">Need an account? <a href="#about">Request access</a></p>
<?php endif; ?>
</section>