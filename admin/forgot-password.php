<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

if (!defined('ADMIN_ROUTED_ENTRY')) {
    http_response_code(404);
    exit('404 Not Found');
}

send_admin_nocache_headers();
start_secure_session();

$message = null;
$errors = [];
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? 'request');
    $targetEmail = trim((string) ($_POST['email'] ?? ''));

    $accountKey = $action === 'reset'
        ? ($token !== '' ? 'token:' . hash('sha256', $token) : null)
        : ($targetEmail !== '' ? 'email:' . strtolower($targetEmail) : null);

    $rlResult = rate_limiter()->check('password_recovery', [
        'ip'      => client_ip(),
        'account' => $accountKey,
    ], true);

    if (!$rlResult['allowed']) {
        RateLimiter::applyTooManyRequestsHeaders($rlResult);
        $errors[] = $rlResult['retry_message'];
    } elseif ($action === 'request') {
        $email = $targetEmail;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $generatedToken = create_password_reset_token($email);
            if ($generatedToken !== null) {
                send_password_reset_email($email, $generatedToken);
            }
            // Never expose whether an account exists in the response message.
            $message = 'If an active administrator account matches that email, a password recovery token has been issued for delivery.';
            if ($generatedToken && APP_DEBUG) {
                $message .= ' Debug reset link: ' . admin_url('forgot-password.php') . '?token=' . $generatedToken;
            }
        }
    } elseif ($action === 'reset') {
        if ($token === '') {
            $errors[] = 'পুনরায় সেট token is missing or invalid.';
        } else {
            $newPw = (string) ($_POST['new_password'] ?? '');
            $confirmPw = (string) ($_POST['confirm_password'] ?? '');
            $res = reset_password_with_token($token, $newPw, $confirmPw);
            if ($res['ok']) {
                set_flash('success', 'আপনার পাসওয়ার্ড পরিবর্তন করা হয়েছে। অনুগ্রহ করে লগইন করুন।');
                redirect_to(admin_url('login.php'));
            }
            $errors = $res['errors'];
        }
    }
}

$siteSettings = get_website_settings();
?>
<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>পাসওয়ার্ড Recovery — <?= e($siteSettings['site_name']) ?></title>
  <link rel="stylesheet" href="<?= e(admin_url('assets/css/admin.css')) ?>">
</head>
<body class="auth-page">
  <div class="auth-card">
    <div class="auth-brand"><?= e($siteSettings['site_name']) ?></div>
    <h1 class="auth-title"><?= $token !== '' ? 'Set New পাসওয়ার্ড' : 'Recover পাসওয়ার্ড' ?></h1>
    <p class="auth-subtitle">
      <?= $token !== '' ? 'নিরাপত্তার সব শর্ত পূরণ করে এমন শক্তিশালী পাসওয়ার্ড দিন।' : 'নির্দিষ্ট সময়ের জন্য পাসওয়ার্ড পুনরুদ্ধার টোকেন পেতে আপনার অ্যাডমিন ইমেইল ঠিকানা লিখুন।' ?>
    </p>

    <?php if ($message): ?>
      <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
      <div class="alert alert-error">
        <?php foreach ($errors as $err): ?>
          <div><?= e($err) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($token !== ''): ?>
      <form method="post" action="<?= e(admin_url('forgot-password.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="form-row">
          <label class="form-label" for="new_password">New পাসওয়ার্ড</label>
          <input class="form-control" type="password" id="new_password" name="new_password" required minlength="10">
          <span class="form-hint">কমপক্ষে ১০ অক্ষর দিন; বড় হাতের অক্ষর, ছোট হাতের অক্ষর, সংখ্যা ও বিশেষ চিহ্ন থাকতে হবে।</span>
        </div>
        <div class="form-row">
          <label class="form-label" for="confirm_password">Confirm পাসওয়ার্ড</label>
          <input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="10">
        </div>
        <button type="submit" class="btn btn-green" style="width: 100%;">পুনরায় সেট পাসওয়ার্ড</button>
      </form>
    <?php else: ?>
      <form method="post" action="<?= e(admin_url('forgot-password.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="request">
        <div class="form-row">
          <label class="form-label" for="email">অ্যাডমিনistrator Email</label>
          <input class="form-control" type="email" id="email" name="email" required>
        </div>
        <button type="submit" class="btn btn-green" style="width: 100%;">পুনরুদ্ধার টোকেনের অনুরোধ করুন</button>
      </form>
    <?php endif; ?>

    <p style="margin-top: 18px; font-size: 0.85rem;">
      <a href="<?= e(admin_url('login.php')) ?>" style="color: var(--adm-green); font-weight: 600;">← Back to লগইন করুন</a>
    </p>
  </div>
</body>
</html>
