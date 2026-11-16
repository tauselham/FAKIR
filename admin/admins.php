<?php
declare(strict_types=1);

$activePage = 'admins';
$adminPageTitle = 'Admin Users & Permissions';
$requiredSuperAdmin = true;
require_once dirname(__DIR__) . '/includes/admin-layout.php';

$pdo = db();
$allPermissions = all_admin_permissions();
$validRoles = ['super_admin', 'admin', 'editor'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create' || $action === 'update') {
            $adminId    = (int) ($_POST['admin_id'] ?? 0);
            $fullName   = trim((string) ($_POST['full_name'] ?? ''));
            $username   = trim((string) ($_POST['username'] ?? ''));
            $email      = trim((string) ($_POST['email'] ?? ''));
            $role       = in_array($_POST['role'] ?? '', $validRoles, true) ? (string) $_POST['role'] : 'admin';
            $isActive   = !empty($_POST['is_active']);
            $mustChange = !empty($_POST['must_change_password']);
            $password   = (string) ($_POST['password'] ?? '');

            $rawPerms = is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : [];
            $permissions = $role === 'super_admin'
                ? array_keys($allPermissions)
                : decode_admin_permissions($rawPerms);

            if ($fullName === '') {
                throw new RuntimeException('Full name is required.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.');
            }

            if ($action === 'create') {
                if (strlen($username) < 3 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
                    throw new RuntimeException('Username must be at least 3 characters (letters, numbers, dot, underscore, or hyphen).');
                }
                $pwErrors = validate_password_strength($password);
                if ($pwErrors !== []) {
                    throw new RuntimeException(implode(' ', $pwErrors));
                }
                if ($role !== 'super_admin' && $permissions === []) {
                    throw new RuntimeException('Please assign at least one permission (e.g., Community Posts or Photo Gallery).');
                }

                $dup = $pdo->prepare('SELECT id FROM public.admins WHERE LOWER(username) = LOWER(:u) OR LOWER(email) = LOWER(:e) LIMIT 1');
                $dup->execute([':u' => $username, ':e' => $email]);
                if ($dup->fetch()) {
                    throw new RuntimeException('An admin account with this username or email already exists.');
                }

                $hash = hash_admin_password($password);
                $permJson = json_encode($permissions, JSON_THROW_ON_ERROR);

                $newId = db_transaction(function (PDO $pdo) use ($username, $email, $hash, $fullName, $role, $permJson, $mustChange, $isActive, $currentAdmin, $permissions): int {
                    $ins = $pdo->prepare(
                        'INSERT INTO public.admins
                         (username, email, password_hash, full_name, role, permissions, must_change_password, is_active, created_by)
                         VALUES (:u, :e, :h, :fn, :r, CAST(:p AS jsonb), :mc, :act, :cb)
                         RETURNING id'
                    );
                    $ins->execute([
                        ':u'   => $username,
                        ':e'   => $email,
                        ':h'   => $hash,
                        ':fn'  => $fullName,
                        ':r'   => $role,
                        ':p'   => $permJson,
                        ':mc'  => $mustChange ? 'true' : 'false',
                        ':act' => $isActive ? 'true' : 'false',
                        ':cb'  => (int) $currentAdmin['id'],
                    ]);
                    $id = (int) $ins->fetchColumn();
                    log_admin_activity((int) $currentAdmin['id'], 'admin_account_created', 'admin', $id, [
                        'username'    => $username,
                        'role'        => $role,
                        'permissions' => $permissions,
                    ]);
                    return $id;
                });

                set_flash('success', "Admin account '@{$username}' (#{$newId}) created successfully.");
                redirect_to(admin_url('admins.php'));
            } else {
                $st = $pdo->prepare('SELECT * FROM public.admins WHERE id = :id LIMIT 1');
                $st->execute([':id' => $adminId]);
                $existing = $st->fetch();
                if (!$existing) {
                    throw new RuntimeException('Admin account not found.');
                }

                // Prevent locking out or demoting self
                if ($adminId === (int) $currentAdmin['id']) {
                    if ($role !== 'super_admin') {
                        throw new RuntimeException('You cannot downgrade your own Super Admin role.');
                    }
                    if (!$isActive) {
                        throw new RuntimeException('You cannot deactivate your own active account.');
                    }
                }

                // Ensure at least one active super_admin always exists
                if ($existing['role'] === 'super_admin' && ($role !== 'super_admin' || !$isActive)) {
                    $otherSuperCount = (int) $pdo->query(
                        "SELECT COUNT(*) FROM public.admins WHERE role = 'super_admin' AND is_active = TRUE AND id <> " . (int) $adminId
                    )->fetchColumn();
                    if ($otherSuperCount < 1) {
                        throw new RuntimeException('At least one active Super Admin must remain.');
                    }
                }

                if ($role !== 'super_admin' && $permissions === []) {
                    throw new RuntimeException('Please assign at least one permission (e.g., Community Posts or Photo Gallery).');
                }

                $dup = $pdo->prepare('SELECT id FROM public.admins WHERE LOWER(email) = LOWER(:e) AND id <> :id LIMIT 1');
                $dup->execute([':e' => $email, ':id' => $adminId]);
                if ($dup->fetch()) {
                    throw new RuntimeException('Another admin account is already using this email address.');
                }

                $newHash = null;
                if ($password !== '') {
                    $pwErrors = validate_password_strength($password);
                    if ($pwErrors !== []) {
                        throw new RuntimeException(implode(' ', $pwErrors));
                    }
                    $newHash = hash_admin_password($password);
                }

                $permJson = json_encode($permissions, JSON_THROW_ON_ERROR);

                db_transaction(function (PDO $pdo) use ($adminId, $fullName, $email, $role, $permJson, $isActive, $newHash, $mustChange, $currentAdmin, $permissions): void {
                    if ($newHash !== null) {
                        $upd = $pdo->prepare(
                            'UPDATE public.admins
                             SET full_name = :fn, email = :e, role = :r, permissions = CAST(:p AS jsonb),
                                 is_active = :act, password_hash = :h, must_change_password = :mc
                             WHERE id = :id'
                        );
                        $upd->execute([
                            ':fn'  => $fullName,
                            ':e'   => $email,
                            ':r'   => $role,
                            ':p'   => $permJson,
                            ':act' => $isActive ? 'true' : 'false',
                            ':h'   => $newHash,
                            ':mc'  => $mustChange ? 'true' : 'false',
                            ':id'  => $adminId,
                        ]);
                    } else {
                        $upd = $pdo->prepare(
                            'UPDATE public.admins
                             SET full_name = :fn, email = :e, role = :r, permissions = CAST(:p AS jsonb), is_active = :act
                             WHERE id = :id'
                        );
                        $upd->execute([
                            ':fn'  => $fullName,
                            ':e'   => $email,
                            ':r'   => $role,
                            ':p'   => $permJson,
                            ':act' => $isActive ? 'true' : 'false',
                            ':id'  => $adminId,
                        ]);
                    }

                    log_admin_activity((int) $currentAdmin['id'], 'admin_account_updated', 'admin', $adminId, [
                        'role'            => $role,
                        'permissions'     => $permissions,
                        'password_reset'  => $newHash !== null,
                    ]);
                });

                set_flash('success', "Admin account '@{$existing['username']}' updated.");
                redirect_to(admin_url('admins.php'));
            }
        } elseif ($action === 'toggle_active') {
            $adminId = (int) ($_POST['admin_id'] ?? 0);
            if ($adminId === (int) $currentAdmin['id']) {
                throw new RuntimeException('You cannot disable your own account.');
            }
            $st = $pdo->prepare('SELECT id, username, role, is_active FROM public.admins WHERE id = :id LIMIT 1');
            $st->execute([':id' => $adminId]);
            $target = $st->fetch();
            if (!$target) {
                throw new RuntimeException('Admin account not found.');
            }
            $newActive = empty($target['is_active']);
            if (!$newActive && $target['role'] === 'super_admin') {
                $otherSuperCount = (int) $pdo->query(
                    "SELECT COUNT(*) FROM public.admins WHERE role = 'super_admin' AND is_active = TRUE AND id <> " . (int) $adminId
                )->fetchColumn();
                if ($otherSuperCount < 1) {
                    throw new RuntimeException('Cannot disable the last active Super Admin.');
                }
            }
            $pdo->prepare('UPDATE public.admins SET is_active = :act WHERE id = :id')
                ->execute([':act' => $newActive ? 'true' : 'false', ':id' => $adminId]);
            log_admin_activity((int) $currentAdmin['id'], $newActive ? 'admin_enabled' : 'admin_disabled', 'admin', $adminId);
            set_flash('success', "Admin '@{$target['username']}' " . ($newActive ? 'enabled.' : 'disabled.'));
            redirect_to(admin_url('admins.php'));
        } elseif ($action === 'delete') {
            $adminId = (int) ($_POST['admin_id'] ?? 0);
            if ($adminId === (int) $currentAdmin['id']) {
                throw new RuntimeException('You cannot delete your own account.');
            }
            $st = $pdo->prepare('SELECT id, username, role FROM public.admins WHERE id = :id LIMIT 1');
            $st->execute([':id' => $adminId]);
            $target = $st->fetch();
            if ($target) {
                if ($target['role'] === 'super_admin') {
                    $otherSuperCount = (int) $pdo->query(
                        "SELECT COUNT(*) FROM public.admins WHERE role = 'super_admin' AND is_active = TRUE AND id <> " . (int) $adminId
                    )->fetchColumn();
                    if ($otherSuperCount < 1) {
                        throw new RuntimeException('Cannot delete the last active Super Admin.');
                    }
                }
                $pdo->prepare('DELETE FROM public.admins WHERE id = :id')->execute([':id' => $adminId]);
                log_admin_activity((int) $currentAdmin['id'], 'admin_account_deleted', 'admin', $adminId, ['username' => $target['username']]);
                set_flash('success', "Admin account '@{$target['username']}' removed.");
            }
            redirect_to(admin_url('admins.php'));
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
        $redirectTarget = (!empty($_POST['admin_id']) && ($action === 'update'))
            ? admin_url('admins.php') . '?edit=' . (int) $_POST['admin_id']
            : admin_url('admins.php');
        redirect_to($redirectTarget);
    }
}

$admins = $pdo->query(
    'SELECT id, username, email, full_name, role, permissions, must_change_password, is_active, last_login_at, created_at
     FROM public.admins
     ORDER BY CASE role WHEN \'super_admin\' THEN 0 WHEN \'admin\' THEN 1 ELSE 2 END, id ASC'
)->fetchAll();

$editAdmin = null;
$editPerms = ['manage_posts', 'manage_gallery'];
if (!empty($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM public.admins WHERE id = :id LIMIT 1');
    $st->execute([':id' => (int) $_GET['edit']]);
    $editAdmin = $st->fetch() ?: null;
    if ($editAdmin) {
        $editPerms = $editAdmin['role'] === 'super_admin'
            ? array_keys($allPermissions)
            : decode_admin_permissions($editAdmin['permissions'] ?? '[]');
    }
}
?>

<section class="adm-panel">
  <h2 class="adm-panel-title">
    <?= $editAdmin ? 'Edit Admin Permissions — @' . e($editAdmin['username']) : 'Add New Admin & Assign Permissions' ?>
  </h2>
  <p style="margin-top: 0; color: var(--adm-muted); font-size: 0.9rem;">
    Create multiple administrators and choose exactly which sections each person can manage — for example, Posts only, Photo Gallery only, both Posts &amp; Photos, or full access.
  </p>

  <form method="post" action="<?= e(admin_url('admins.php')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editAdmin ? 'update' : 'create' ?>">
    <?php if ($editAdmin): ?>
      <input type="hidden" name="admin_id" value="<?= (int) $editAdmin['id'] ?>">
    <?php endif; ?>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="admin_full_name">Full Name</label>
        <input class="form-control" type="text" id="admin_full_name" name="full_name" value="<?= e($editAdmin['full_name'] ?? '') ?>" required placeholder="e.g., Rahim Uddin">
      </div>

      <div class="form-row">
        <label class="form-label" for="admin_username">Username</label>
        <input
          class="form-control"
          type="text"
          id="admin_username"
          name="username"
          value="<?= e($editAdmin['username'] ?? '') ?>"
          <?= $editAdmin ? 'readonly' : 'required' ?>
          pattern="[a-zA-Z0-9_.-]{3,}"
          placeholder="e.g., rahim_editor"
        >
        <?php if ($editAdmin): ?>
          <span class="form-hint">Username cannot be changed after creation.</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="admin_email">Email Address</label>
        <input class="form-control" type="email" id="admin_email" name="email" value="<?= e($editAdmin['email'] ?? '') ?>" required placeholder="admin@example.com">
      </div>

      <div class="form-row">
        <label class="form-label" for="admin_role_select">Account Role</label>
        <?php $curRole = $editAdmin['role'] ?? 'admin'; ?>
        <select class="form-control" id="admin_role_select" name="role">
          <option value="admin" <?= $curRole === 'admin' ? 'selected' : '' ?>>Admin (Custom Permissions Below)</option>
          <option value="editor" <?= $curRole === 'editor' ? 'selected' : '' ?>>Editor (Custom Permissions Below)</option>
          <option value="super_admin" <?= $curRole === 'super_admin' ? 'selected' : '' ?>>Super Admin (Full Access + Manage Admins)</option>
        </select>
      </div>
    </div>

    <div class="form-row">
      <label class="form-label" for="admin_password_input">
        <?= $editAdmin ? 'New Password (Optional — leave blank to keep current password)' : 'Initial Password' ?>
      </label>
      <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
        <input
          class="form-control"
          type="text"
          id="admin_password_input"
          name="password"
          <?= $editAdmin ? '' : 'required' ?>
          minlength="10"
          style="flex: 1; min-width: 240px;"
          placeholder="<?= $editAdmin ? 'Leave blank unless resetting password' : 'Minimum 10 characters with upper, lower, number, symbol' ?>"
        >
        <button type="button" class="btn btn-outline" data-generate-password="admin_password_input">Generate Random Password</button>
      </div>
      <span class="form-hint">Each admin can update their own password anytime inside Security &amp; Password.</span>
    </div>

    <div class="form-row" id="admin_permissions_box">
      <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
        <label class="form-label" style="margin: 0;">Allowed Admin Panel Permissions</label>
        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
          <button type="button" class="btn btn-outline btn-sm" data-perm-preset="posts">Posts Only</button>
          <button type="button" class="btn btn-outline btn-sm" data-perm-preset="gallery">Photos Only</button>
          <button type="button" class="btn btn-outline btn-sm" data-perm-preset="both">Posts + Photos</button>
          <button type="button" class="btn btn-outline btn-sm" data-perm-preset="all">All Content</button>
          <button type="button" class="btn btn-outline btn-sm" data-perm-preset="none">Clear</button>
        </div>
      </div>

      <div class="adm-grid-2" style="gap: 10px;">
        <?php foreach ($allPermissions as $permKey => $permMeta): ?>
          <label class="form-check" style="align-items: flex-start; border: 1px solid var(--adm-border); border-radius: 8px; padding: 10px 12px; background: #fbfdfb;">
            <input
              type="checkbox"
              class="adm-perm-checkbox"
              name="permissions[]"
              value="<?= e($permKey) ?>"
              <?= in_array($permKey, $editPerms, true) ? 'checked' : '' ?>
              style="margin-top: 3px;"
            >
            <span>
              <strong style="display: block; font-size: 0.9rem;"><?= e($permMeta['label']) ?></strong>
              <span style="display: block; font-size: 0.78rem; color: var(--adm-muted);"><?= e($permMeta['description']) ?></span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-row" style="display: flex; gap: 20px; flex-wrap: wrap;">
      <label class="form-check">
        <input type="checkbox" name="is_active" value="1" <?= (!$editAdmin || !empty($editAdmin['is_active'])) ? 'checked' : '' ?>>
        <span>Account Active (Can Sign In)</span>
      </label>
      <label class="form-check">
        <input type="checkbox" name="must_change_password" value="1" <?= (!$editAdmin || !empty($editAdmin['must_change_password'])) ? 'checked' : '' ?>>
        <span>Prompt Password Change After Login</span>
      </label>
    </div>

    <div style="display: flex; gap: 10px;">
      <button type="submit" class="btn btn-green"><?= $editAdmin ? 'Save Admin & Permissions' : 'Create Admin Account' ?></button>
      <?php if ($editAdmin): ?>
        <a href="<?= e(admin_url('admins.php')) ?>" class="btn btn-outline">Cancel Edit</a>
      <?php endif; ?>
    </div>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">Configured Administrators (<?= count($admins) ?>)</h2>
  <div class="table-wrap">
    <table class="adm-table">
      <thead>
        <tr>
          <th>Admin</th>
          <th>Role</th>
          <th>Allowed Capabilities</th>
          <th>Status</th>
          <th>Last Login</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($admins as $adm): ?>
          <?php
            $isSuper = ($adm['role'] === 'super_admin');
            $perms = $isSuper ? array_keys($allPermissions) : decode_admin_permissions($adm['permissions'] ?? '[]');
            $permLabels = array_map(static fn (string $k) => $allPermissions[$k]['label'] ?? $k, $perms);
          ?>
          <tr>
            <td>
              <strong><?= e($adm['full_name']) ?></strong>
              <div style="font-size: 0.8rem; color: var(--adm-muted);">@<?= e($adm['username']) ?> · <?= e($adm['email']) ?></div>
            </td>
            <td><?= e($isSuper ? 'Super Admin' : ucfirst((string) $adm['role'])) ?></td>
            <td style="max-width: 280px; font-size: 0.84rem;">
              <?= $isSuper ? 'Full Access (All Modules &amp; Users)' : e($permLabels ? implode(', ', $permLabels) : 'Dashboard &amp; Password Only') ?>
            </td>
            <td>
              <?= !empty($adm['is_active']) ? 'Active' : 'Disabled' ?>
              <?php if (!empty($adm['must_change_password'])): ?>
                <div style="font-size: 0.76rem; color: var(--adm-muted);">Temp password</div>
              <?php endif; ?>
            </td>
            <td class="num-tab"><?= e($adm['last_login_at'] ? format_date($adm['last_login_at'], 'M j, Y H:i') : 'Never') ?></td>
            <td>
              <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                <a href="<?= e(admin_url('admins.php')) ?>?edit=<?= (int) $adm['id'] ?>" class="btn btn-outline btn-sm">Edit Permissions</a>
                <?php if ((int) $adm['id'] !== (int) $currentAdmin['id']): ?>
                  <form method="post" action="<?= e(admin_url('admins.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="admin_id" value="<?= (int) $adm['id'] ?>">
                    <button type="submit" class="btn btn-outline btn-sm">
                      <?= !empty($adm['is_active']) ? 'Disable' : 'Enable' ?>
                    </button>
                  </form>
                  <form method="post" action="<?= e(admin_url('admins.php')) ?>" data-confirm="Delete admin account '@<?= e($adm['username']) ?>' permanently?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="admin_id" value="<?= (int) $adm['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
