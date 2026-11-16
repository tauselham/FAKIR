<?php
declare(strict_types=1);

$activePage = 'security';
$adminPageTitle = 'Security & Password';
require_once dirname(__DIR__) . '/includes/admin-layout.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'change_password') {
        $currentPw = (string) ($_POST['current_password'] ?? '');
        $newPw     = (string) ($_POST['new_password'] ?? '');
        $confirmPw = (string) ($_POST['confirm_password'] ?? '');
        $logoutAfter = !empty($_POST['logout_after_change']);

        $res = change_admin_password((int) $currentAdmin['id'], $currentPw, $newPw, $confirmPw);
        if ($res['ok']) {
            if ($logoutAfter) {
                logout_admin(false);
                start_secure_session();
                set_flash('success', 'Password updated securely. Please sign in with your new password.');
                redirect_to(admin_url('login.php'));
            }
            session_regenerate_id(true);
            set_flash('success', 'Administrator password updated successfully.');
            redirect_to(admin_url('security.php'));
        } else {
            $errors = $res['errors'];
        }
    }
}

$securityLogs = db()->prepare(
    "SELECT action, ip_address, created_at FROM public.activity_logs
     WHERE admin_id = :aid AND action IN ('admin_login', 'admin_logout', 'password_changed', 'password_reset_completed')
     ORDER BY created_at DESC LIMIT 12"
);
$securityLogs->execute([':aid' => (int) $currentAdmin['id']]);
$logs = $securityLogs->fetchAll();
?>

<section class="adm-panel">
  <h2 class="adm-panel-title">Change Administrator Password</h2>
  <p style="color: var(--adm-muted); margin-top: 0; font-size: 0.9rem;">
    Passwords are hashed using PHP <code>password_hash()</code> (Argon2id / bcrypt) and never stored in plain text.
  </p>

  <?php if (!empty($currentAdmin['must_change_password'])): ?>
    <div class="alert alert-error" role="status">
      Your account is currently using an initial or temporary password. Enter it below as your Current Password and choose a new permanent password.
    </div>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $err): ?>
        <div><?= e($err) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= e(admin_url('security.php')) ?>" style="max-width: 520px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">

    <div class="form-row">
      <label class="form-label" for="current_password">Current Password</label>
      <input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password">
    </div>

    <div class="form-row">
      <label class="form-label" for="new_password_input">New Password</label>
      <input class="form-control" type="password" id="new_password_input" name="new_password" required minlength="10" autocomplete="new-password">
      <span class="form-hint" id="pw_strength_feedback">Minimum 10 characters with uppercase, lowercase, number, and symbol.</span>
    </div>

    <div class="form-row">
      <label class="form-label" for="confirm_password">Confirm New Password</label>
      <input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="10" autocomplete="new-password">
    </div>

    <div class="form-row">
      <label class="form-check">
        <input type="checkbox" name="logout_after_change" value="1" checked>
        <span>Sign out after changing password (recommended)</span>
      </label>
    </div>

    <button type="submit" class="btn btn-green">Update Password</button>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">Recent Security Events</h2>
  <div class="table-wrap">
    <table class="adm-table">
      <thead><tr><th>Event</th><th>IP Address</th><th>Timestamp</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td><?= e($log['action']) ?></td>
            <td class="num-tab"><?= e($log['ip_address'] ?? '') ?></td>
            <td class="num-tab"><?= e(format_date($log['created_at'], 'M j, Y H:i:s')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
