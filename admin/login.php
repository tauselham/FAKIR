<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

if (!defined('ADMIN_ROUTED_ENTRY')) {
    http_response_code(404);
    exit('404 Not Found');
}

send_admin_nocache_headers();
start_secure_session();
if (current_admin()) {
    redirect_to(admin_url('dashboard.php'));
}

$error = null;
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password   = (string) ($_POST['password'] ?? '');

    $result = attempt_admin_login($identifier, $password);
    if ($result['ok']) {
        set_flash('success', 'Welcome back to the Admin Panel.');
        redirect_to(admin_url('dashboard.php'));
    }
    if (!empty($result['rate_limited']) && !empty($result['limit_result'])) {
        RateLimiter::applyTooManyRequestsHeaders($result['limit_result']);
    }
    $error = $result['message'];
}

$flash = get_flash();
$siteSettings = get_website_settings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Sign In — <?= e($siteSettings['site_name']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(admin_url('assets/css/admin.css')) ?>">
</head>
<body class="auth-page">
  <div class="auth-card">
    <div class="auth-brand"><?= e($siteSettings['site_name']) ?></div>
    <h1 class="auth-title">Admin Sign In</h1>
    <p class="auth-subtitle">Sign in with your administrator username or email address.</p>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(admin_url('login.php')) ?>" autocomplete="on">
      <?= csrf_field() ?>
      <div class="form-row">
        <label class="form-label" for="identifier">Username or Email</label>
        <input class="form-control" type="text" id="identifier" name="identifier" value="<?= e($identifier) ?>" required autofocus autocomplete="username">
      </div>

      <div class="form-row">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
      </div>

      <button type="submit" class="btn btn-green" style="width: 100%;">Sign In</button>
    </form>

    <div style="display: flex; justify-content: space-between; margin-top: 18px; font-size: 0.85rem;">
      <a href="<?= e(admin_url('forgot-password.php')) ?>" style="color: var(--adm-green); font-weight: 600;">Forgot password?</a>
      <a href="/" style="color: var(--adm-muted);">← Public Website</a>
    </div>
  </div>
</body>
</html>
