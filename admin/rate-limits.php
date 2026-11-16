<?php
declare(strict_types=1);

$activePage = 'rate_limits';
$adminPageTitle = 'অনুরোধ সীমা সেটিংস';
$requiredSuperAdmin = true;
require_once dirname(__DIR__) . '/includes/admin-layout.php';

$limiter = rate_limiter();
$isSuperAdmin = (($currentAdmin['role'] ?? '') === 'super_admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    if (!$isSuperAdmin) {
        http_response_code(403);
        set_flash('error', 'শুধুমাত্র সুপার অ্যাডমিন অনুরোধ সীমার নিয়ম পরিবর্তন অথবা সক্রিয় সীমা পুনরায় সেট করতে পারবেন।');
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

            set_flash('success', "'{$scope}' ক্ষেত্রের অনুরোধ সীমা হালনাগাদ হয়েছে (প্রতি {$windowValue} {$windowUnit}-এ {$maxRequests}টি অনুরোধ)।");
        } elseif ($action === 'reset_scope') {
            $scope = trim((string) ($_POST['scope'] ?? ''));
            $cleared = $limiter->resetScope($scope);
            log_admin_activity((int) $currentAdmin['id'], 'rate_limit_buckets_reset', 'rate_limit_buckets', null, [
                'scope'   => $scope,
                'cleared' => $cleared,
            ]);
            set_flash('success', "{$cleared}টি সক্রিয় অনুরোধ সীমার হিসাব '{$scope}' ক্ষেত্র থেকে মুছে ফেলা হয়েছে।");
        } elseif ($action === 'purge_expired') {
            $purged = $limiter->purgeExpired();
            set_flash('success', "{$purged}টি মেয়াদোত্তীর্ণ অনুরোধ সীমার তথ্য মুছে ফেলা হয়েছে।");
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
      <h2 class="adm-panel-title" style="margin-bottom: 4px;">সার্ভারভিত্তিক অনুরোধ সীমা</h2>
      <p style="margin: 0; color: var(--adm-muted); font-size: 0.9rem;">
        ব্রাউজার সেশনের বাইরে IP ঠিকানা ও অ্যাকাউন্ট পরিচয়ের ভিত্তিতে PostgreSQL-এ অনুরোধের সীমা কার্যকর হয়। নির্ধারিত সময় শেষ হলে সীমা স্বয়ংক্রিয়ভাবে পুনরায় চালু হয়।
      </p>
    </div>
    <?php if ($isSuperAdmin): ?>
      <form method="post" action="<?= e(admin_url('rate-limits.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="purge_expired">
        <button type="submit" class="btn btn-outline btn-sm">মেয়াদোত্তীর্ণ তথ্য মুছুন</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$isSuperAdmin): ?>
    <div class="alert alert-error" style="margin-top: 16px; margin-bottom: 0;">
      আপনার অ্যাকাউন্টের ভূমিকা <strong><?= e($currentAdmin['role']) ?></strong>। শুধু <strong>সুপার অ্যাডমিন</strong> অনুরোধ সীমার মান পরিবর্তন বা সক্রিয় বাধা পুনরায় সেট করতে পারবেন।
    </div>
  <?php endif; ?>
</section>

<section class="adm-panel">
  <div class="table-wrap">
    <table class="adm-table">
      <thead>
        <tr>
          <th>এন্ডপয়েন্ট / ক্ষেত্র</th>
          <th>সর্বোচ্চ অনুরোধ</th>
          <th>সময়সীমা</th>
          <th>সময়ের একক</th>
          <th>অবস্থা</th>
          <th>সক্রিয় / অবরুদ্ধ</th>
          <th>করণীয়</th>
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
                    <?= e(['seconds' => 'সেকেন্ড', 'minutes' => 'মিনিট', 'hours' => 'ঘণ্টা', 'days' => 'দিন'][$unit] ?? $unit) ?>
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
                <span>চালু</span>
              </label>
            </td>
            <td class="num-tab">
              <?= (int) ($rule['active_buckets'] ?? 0) ?> সক্রিয়
              <?php if ((int) ($rule['blocked_buckets'] ?? 0) > 0): ?>
                <div style="font-size: 0.78rem; color: var(--adm-danger); font-weight: 600;">
                  <?= (int) $rule['blocked_buckets'] ?> অবরুদ্ধ
                </div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isSuperAdmin): ?>
                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                  <button type="submit" form="rl-form-<?= e($rule['scope']) ?>" class="btn btn-green btn-sm">সংরক্ষণ</button>
                  <form method="post" action="<?= e(admin_url('rate-limits.php')) ?>" data-confirm="সব সক্রিয় অনুরোধ সীমার হিসাব পুনরায় সেট করুন: <?= e($rule['label']) ?>?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_scope">
                    <input type="hidden" name="scope" value="<?= e($rule['scope']) ?>">
                    <button type="submit" class="btn btn-outline btn-sm">পুনরায় সেট</button>
                  </form>
                </div>
              <?php else: ?>
                <span style="font-size: 0.8rem; color: var(--adm-muted);">শুধু সুপার অ্যাডমিন</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
