<?php
declare(strict_types=1);

$activePage = 'rate_limits';
$adminPageTitle = 'Rate Limiting Settings';
$requiredSuperAdmin = true;
require_once dirname(__DIR__) . '/includes/admin-layout.php';

$limiter = rate_limiter();
$isSuperAdmin = (($currentAdmin['role'] ?? '') === 'super_admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    if (!$isSuperAdmin) {
        http_response_code(403);
        set_flash('error', 'Only a Super Admin can modify rate limiting rules or reset active buckets.');
        redirect_to(admin_url('rate-limits.php'));
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_rule') {
            $scope       = trim((string) ($_POST['scope'] ?? ''));
            $maxRequests = (int) ($_POST['max_requests'] ?? 1);
            $windowValue = (int) ($_POST['window_value'] ?? 1);
            $windowUnit  = trim((string) ($_POST['window_unit'] ?? 'minutes'));
            $isEnabled   = !empty($_POST['is_enabled']);

            $limiter->updateRule(
                $scope,
                $maxRequests,
                $windowValue,
                $windowUnit,
                $isEnabled,
                (int) $currentAdmin['id']
            );

            log_admin_activity((int) $currentAdmin['id'], 'rate_limit_rule_updated', 'rate_limit_rules', null, [
                'scope'        => $scope,
                'max_requests' => $maxRequests,
                'window_value' => $windowValue,
                'window_unit'  => $windowUnit,
                'is_enabled'   => $isEnabled,
            ]);

            set_flash('success', "Rate limit for '{$scope}' updated ({$maxRequests} requests per {$windowValue} {$windowUnit}).");
        } elseif ($action === 'reset_scope') {
            $scope = trim((string) ($_POST['scope'] ?? ''));
            $cleared = $limiter->resetScope($scope);
            log_admin_activity((int) $currentAdmin['id'], 'rate_limit_buckets_reset', 'rate_limit_buckets', null, [
                'scope'   => $scope,
                'cleared' => $cleared,
            ]);
            set_flash('success', "Cleared {$cleared} active rate-limit bucket(s) for '{$scope}'.");
        } elseif ($action === 'purge_expired') {
            $purged = $limiter->purgeExpired();
            set_flash('success', "Purged {$purged} expired rate-limit record(s).");
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }

    redirect_to(admin_url('rate-limits.php'));
}

$rules = $limiter->getAllRulesWithStats();
?>

<section class="adm-panel">
  <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
    <div>
      <h2 class="adm-panel-title" style="margin-bottom: 4px;">Configurable Server-Side Rate Limits</h2>
      <p style="margin: 0; color: var(--adm-muted); font-size: 0.9rem;">
        Enforced in PostgreSQL by IP address and account identifier (independent of browser sessions). Expired windows reset automatically.
      </p>
    </div>
    <?php if ($isSuperAdmin): ?>
      <form method="post" action="<?= e(admin_url('rate-limits.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="purge_expired">
        <button type="submit" class="btn btn-outline btn-sm">Purge Expired Records</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$isSuperAdmin): ?>
    <div class="alert alert-error" style="margin-top: 16px; margin-bottom: 0;">
      Your account role is <strong><?= e($currentAdmin['role']) ?></strong>. Only a <strong>Super Admin</strong> can edit rate-limit thresholds or reset active blocks.
    </div>
  <?php endif; ?>
</section>

<section class="adm-panel">
  <div class="table-wrap">
    <table class="adm-table">
      <thead>
        <tr>
          <th>Endpoint / Scope</th>
          <th>Max Requests</th>
          <th>Time Window</th>
          <th>Time Unit</th>
          <th>Status</th>
          <th>Active / Blocked</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rules as $rule): ?>
          <tr>
            <td style="min-width: 220px;">
              <strong><?= e($rule['label']) ?></strong>
              <div style="font-size: 0.78rem; color: var(--adm-muted);"><?= e($rule['description'] ?? '') ?></div>
            </td>
            <td>
              <form method="post" action="<?= e(admin_url('rate-limits.php')) ?>" id="rl-form-<?= e($rule['scope']) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_rule">
                <input type="hidden" name="scope" value="<?= e($rule['scope']) ?>">
              </form>
              <input
                class="form-control num-tab"
                type="number"
                name="max_requests"
                form="rl-form-<?= e($rule['scope']) ?>"
                min="1"
                max="100000"
                value="<?= (int) $rule['max_requests'] ?>"
                style="width: 96px;"
                <?= $isSuperAdmin ? '' : 'disabled' ?>
              >
            </td>
            <td>
              <input
                class="form-control num-tab"
                type="number"
                name="window_value"
                form="rl-form-<?= e($rule['scope']) ?>"
                min="1"
                max="100000"
                value="<?= (int) $rule['window_value'] ?>"
                style="width: 90px;"
                <?= $isSuperAdmin ? '' : 'disabled' ?>
              >
            </td>
            <td>
              <select
                class="form-control"
                name="window_unit"
                form="rl-form-<?= e($rule['scope']) ?>"
                style="width: 120px;"
                <?= $isSuperAdmin ? '' : 'disabled' ?>
              >
                <?php foreach (RateLimiter::VALID_UNITS as $unit): ?>
                  <option value="<?= e($unit) ?>" <?= $rule['window_unit'] === $unit ? 'selected' : '' ?>>
                    <?= e(ucfirst($unit)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <label class="form-check">
                <input
                  type="checkbox"
                  name="is_enabled"
                  value="1"
                  form="rl-form-<?= e($rule['scope']) ?>"
                  <?= !empty($rule['is_enabled']) ? 'checked' : '' ?>
                  <?= $isSuperAdmin ? '' : 'disabled' ?>
                >
                <span>Enabled</span>
              </label>
            </td>
            <td class="num-tab">
              <?= (int) ($rule['active_buckets'] ?? 0) ?> active
              <?php if ((int) ($rule['blocked_buckets'] ?? 0) > 0): ?>
                <div style="font-size: 0.78rem; color: var(--adm-danger); font-weight: 600;">
                  <?= (int) $rule['blocked_buckets'] ?> blocked
                </div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isSuperAdmin): ?>
                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                  <button type="submit" form="rl-form-<?= e($rule['scope']) ?>" class="btn btn-green btn-sm">Save</button>
                  <form method="post" action="<?= e(admin_url('rate-limits.php')) ?>" data-confirm="Reset all active rate-limit counters for <?= e($rule['label']) ?>?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_scope">
                    <input type="hidden" name="scope" value="<?= e($rule['scope']) ?>">
                    <button type="submit" class="btn btn-outline btn-sm">Reset</button>
                  </form>
                </div>
              <?php else: ?>
                <span style="font-size: 0.8rem; color: var(--adm-muted);">Super Admin only</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
