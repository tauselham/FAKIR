<?php
declare(strict_types=1);

$activePage = 'dashboard';
$adminPageTitle = 'ড্যাশবোর্ড';
require_once dirname(__DIR__) . '/includes/admin-layout.php';

$pdo = db();

$totalপ্রকাশিতPosts = (int) $pdo->query("SELECT COUNT(*) FROM public.posts WHERE status = 'published'")->fetchColumn();
$totalছবিs = (int) $pdo->query("SELECT COUNT(*) FROM public.gallery_images")->fetchColumn();
$totalVideos = (int) $pdo->query("SELECT COUNT(*) FROM public.posts WHERE post_type = 'video'")->fetchColumn();
$totalবিভাগসমূহ = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM public.gallery_categories) + (SELECT COUNT(*) FROM public.post_categories)")->fetchColumn();
$totalBanners = (int) $pdo->query("SELECT COUNT(*) FROM public.hero_banners")->fetchColumn();

if (is_super_admin($currentঅ্যাডমিন)) {
    $recentLogs = $pdo->query(
        "SELECT al.*, a.username
         FROM public.activity_logs al
         LEFT JOIN public.admins a ON a.id = al.admin_id
         ORDER BY al.created_at DESC
         LIMIT 15"
    )->fetchAll();
} else {
    $logStmt = $pdo->prepare(
        "SELECT al.*, a.username
         FROM public.activity_logs al
         LEFT JOIN public.admins a ON a.id = al.admin_id
         WHERE al.admin_id = :aid
         ORDER BY al.created_at DESC
         LIMIT 15"
    );
    $logStmt->execute([':aid' => (int) $currentঅ্যাডমিন['id']]);
    $recentLogs = $logStmt->fetchAll();
}
?>

<section class="adm-stats-grid" aria-label="Community Overview Metrics">
  <div class="adm-stat-card">
    <p class="adm-stat-label">প্রকাশিত পোস্ট</p>
    <p class="adm-stat-val"><?= $totalপ্রকাশিতPosts ?></p>
  </div>
  <div class="adm-stat-card">
    <p class="adm-stat-label">গ্যালারির ছবি</p>
    <p class="adm-stat-val"><?= $totalছবিs ?></p>
  </div>
  <div class="adm-stat-card">
    <p class="adm-stat-label">ভিডিও পোস্ট</p>
    <p class="adm-stat-val"><?= $totalVideos ?></p>
  </div>
  <div class="adm-stat-card">
    <p class="adm-stat-label">Total বিভাগসমূহ</p>
    <p class="adm-stat-val"><?= $totalবিভাগসমূহ ?></p>
  </div>
  <div class="adm-stat-card">
    <p class="adm-stat-label">প্রধান ব্যানার</p>
    <p class="adm-stat-val"><?= $totalBanners ?></p>
  </div>
</section>

<section class="adm-panel">
  <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
    <h2 class="adm-panel-title" style="margin: 0;">দ্রুত কাজ</h2>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
      <?php if (admin_has_permission('manage_posts', $currentঅ্যাডমিন)): ?>
        <a href="<?= e(admin_url('posts.php')) ?>" class="btn btn-green btn-sm">+ নতুন পোস্ট</a>
      <?php endif; ?>
      <?php if (admin_has_permission('manage_gallery', $currentঅ্যাডমিন)): ?>
        <a href="<?= e(admin_url('gallery.php')) ?>" class="btn btn-outline btn-sm">+ গ্যালারিতে ছবি আপলোড</a>
      <?php endif; ?>
      <?php if (admin_has_permission('manage_banners', $currentঅ্যাডমিন)): ?>
        <a href="<?= e(admin_url('banners.php')) ?>" class="btn btn-outline btn-sm">Manage প্রধান ব্যানার</a>
      <?php endif; ?>
      <?php if (is_super_admin($currentঅ্যাডমিন)): ?>
        <a href="<?= e(admin_url('admins.php')) ?>" class="btn btn-outline btn-sm">Manage অ্যাডমিনs &amp; Permissions</a>
      <?php endif; ?>
      <a href="<?= e(admin_url('security.php')) ?>" class="btn btn-outline btn-sm">পাসওয়ার্ড পরিবর্তন করুন</a>
    </div>
  </div>
  <?php if (!is_super_admin($currentঅ্যাডমিন)): ?>
    <?php
      $allPerms = all_admin_permissions();
      $myনামs = array_map(static fn (string $k) => $allPerms[$k]['label'] ?? $k, $currentঅ্যাডমিন['permissions'] ?? []);
    ?>
    <p style="margin: 0 0 8px; color: var(--adm-ink); font-size: 0.9rem;">
      <strong>আপনার জন্য নির্ধারিত অনুমতি:</strong> <?= e($myনামs ? implode(', ', $myনামs) : 'ড্যাশবোর্ড & পাসওয়ার্ড Only') ?>
    </p>
  <?php endif; ?>
  <p style="margin: 0; color: var(--adm-muted); font-size: 0.9rem;">
    Changes saved in the অ্যাডমিন প্যানেল are stored in Supabase PostgreSQL and Supabase Storage and appear immediately on the public site.
  </p>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">Recent অ্যাডমিন Activity</h2>
  <?php if (empty($recentLogs)): ?>
    <p style="color: var(--adm-muted); margin: 0;">এখনো কোনো কার্যক্রমের তথ্য নেই।</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="adm-table">
        <thead>
          <tr>
            <th>সময়</th>
            <th>অ্যাডমিন</th>
            <th>কার্যক্রম</th>
            <th>বিষয়</th>
            <th>আইপি ঠিকানা</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentLogs as $log): ?>
            <tr>
              <td class="num-tab"><?= e(format_date($log['created_at'], 'M j, Y H:i')) ?></td>
              <td><?= e($log['username'] ?? 'System') ?></td>
              <td><?= e($log['action']) ?></td>
              <td><?= e(($log['entity_type'] ?? '-') . ($log['entity_id'] ? ' #' . $log['entity_id'] : '')) ?></td>
              <td class="num-tab"><?= e($log['ip_address'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
