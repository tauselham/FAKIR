<?php
declare(strict_types=1);

$activePage = 'security';
$adminPageTitle = 'নিরাপত্তা ও পাসওয়ার্ড';
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

        $res = change_admin_password((int) $currentঅ্যাডমিন['id'], $currentPw, $newPw, $confirmPw);
        if ($res['ok']) {
            if ($logoutAfter) {
                logout_admin(false);
                start_secure_session();
                set_flash('success', 'পাসওয়ার্ড updated securely. Please sign in with your new password.');
                redirect_to(admin_url('login.php'));
            }
            session_regenerate_id(true);
            set_flash('success', 'অ্যাডমিনistrator password updated successfully.');
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
$securityLogs->execute([':aid' => (int) $currentঅ্যাডমিন['id']]);
$logs = $securityLogs->fetchAll();
?>

<section class="adm-panel">
  <h2 class="adm-panel-title">Change অ্যাডমিনistrator পাসওয়ার্ড</h2>
  <p style="color: var(--adm-muted); margin-top: 0; font-size: 0.9rem;">
    পাসওয়ার্ডs are hashed using PHP <code>password_hash()</code> (Argon2id / bcrypt) and never stored in plain text.
  </p>

  <?php if (!empty($currentঅ্যাডমিন['must_change_password'])): ?>
    <div class="alert alert-error" role="status">
      Your account is currently using an initial or temporary password. Enter it below as your Current পাসওয়ার্ড and choose a new permanent password.
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
      <label class="form-label" for="current_password">Current পাসওয়ার্ড</label>
      <input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password">
    </div>

    <div class="form-row">
      <label class="form-label" for="new_password_input">New পাসওয়ার্ড</label>
      <input class="form-control" type="password" id="new_password_input" name="new_password" required minlength="10" autocomplete="new-password">
      <span class="form-hint" id="pw_strength_feedback">কমপক্ষে ১০ অক্ষর দিন; বড় হাতের অক্ষর, ছোট হাতের অক্ষর, সংখ্যা ও বিশেষ চিহ্ন থাকতে হবে।</span>
    </div>

    <div class="form-row">
      <label class="form-label" for="confirm_password">Confirm New পাসওয়ার্ড</label>
      <input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="10" autocomplete="new-password">
    </div>

    <div class="form-row">
      <label class="form-check">
        <input type="checkbox" name="logout_after_change" value="1" checked>
        <span>পাসওয়ার্ড পরিবর্তনের পর লগআউট করুন (সুপারিশকৃত)</span>
      </label>
    </div>

    <button type="submit" class="btn btn-green">Update পাসওয়ার্ড</button>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">সাম্প্রতিক নিরাপত্তা কার্যক্রম</h2>
  <div class="table-wrap">
    <table class="adm-table">
      <thead><tr><th>Event</th><th>আইপি ঠিকানা</th><th>সময়stamp</th></tr></thead>
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
