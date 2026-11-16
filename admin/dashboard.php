<?php
declare(strict_types=1);

$activePage = 'dashboard';
$adminPageTitle = 'Dashboard';
require_once dirname(__DIR__) . '/includes/admin-layout.php';

$pdo = db();

$totalPublishedPosts = (int) $pdo->query("SELECT COUNT(*) FROM public.posts WHERE status = 'published'")->fetchColumn();
$totalImages = (int) $pdo->query("SELECT COUNT(*) FROM public.gallery_images")->fetchColumn();
$totalVideos = (int) $pdo->query("SELECT COUNT(*) FROM public.posts WHERE post_type = 'video'")->fetchColumn();
$totalCategories = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM public.gallery_categories) + (SELECT COUNT(*) FROM public.post_categories)")->fetchColumn();
$totalBanners = (int) $pdo->query("SELECT COUNT(*) FROM public.hero_banners")->fetchColumn();

if (is_super_admin($currentAdmin)) {
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
    $logStmt->execute([':aid' => (int) $currentAdmin['id']]);
    $recentLogs = $logStmt->fetchAll();
}
?>

<section class="adm-stats-grid" aria-label="Community Overview Metrics">
  <div class="adm-stat-card">
    <p class="adm-stat-label">Published Posts</p>
    <p class="adm-stat-val"><?= $totalPublishedPosts ?></p>
  </div>
  <div class="adm-stat-card">
    <p class="adm-stat-label">Gallery Images</p>
    <p class="adm-stat-val"><?= $totalImages ?></p>
  </div>
  <div class="adm-stat-card">
    <p class="adm-stat-label">Video Posts</p>
    <p class="adm-stat-val"><?= $totalVideos ?></p>
  </div>
  <div class="adm-stat-card">
    <p class="adm-stat-label">Total Categories</p>
    <p class="adm-stat-val"><?= $totalCategories ?></p>
  </div>
  <div class="adm-stat-card">
    <p class="adm-stat-label">Hero Banners</p>
    <p class="adm-stat-val"><?= $totalBanners ?></p>
  </div>
</section>

<section class="adm-panel">
  <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
    <h2 class="adm-panel-title" style="margin: 0;">Quick Actions</h2>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
      <?php if (admin_has_permission('manage_posts', $currentAdmin)): ?>
        <a href="<?= e(admin_url('posts.php')) ?>" class="btn btn-green btn-sm">+ New Community Post</a>
      <?php endif; ?>
      <?php if (admin_has_permission('manage_gallery', $currentAdmin)): ?>
        <a href="<?= e(admin_url('gallery.php')) ?>" class="btn btn-outline btn-sm">+ Upload Gallery Photos</a>
      <?php endif; ?>
      <?php if (admin_has_permission('manage_banners', $currentAdmin)): ?>
        <a href="<?= e(admin_url('banners.php')) ?>" class="btn btn-outline btn-sm">Manage Hero Banners</a>
      <?php endif; ?>
      <?php if (is_super_admin($currentAdmin)): ?>
        <a href="<?= e(admin_url('admins.php')) ?>" class="btn btn-outline btn-sm">Manage Admins &amp; Permissions</a>
      <?php endif; ?>
      <a href="<?= e(admin_url('security.php')) ?>" class="btn btn-outline btn-sm">Change Password</a>
    </div>
  </div>
  <?php if (!is_super_admin($currentAdmin)): ?>
    <?php
      $allPerms = all_admin_permissions();
      $myLabels = array_map(static fn (string $k) => $allPerms[$k]['label'] ?? $k, $currentAdmin['permissions'] ?? []);
    ?>
    <p style="margin: 0 0 8px; color: var(--adm-ink); font-size: 0.9rem;">
      <strong>Your Assigned Permissions:</strong> <?= e($myLabels ? implode(', ', $myLabels) : 'Dashboard & Password Only') ?>
    </p>
  <?php endif; ?>
  <p style="margin: 0; color: var(--adm-muted); font-size: 0.9rem;">
    Changes saved in the Admin Panel are stored in Supabase PostgreSQL and Supabase Storage and appear immediately on the public site.
  </p>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">Recent Admin Activity</h2>
  <?php if (empty($recentLogs)): ?>
    <p style="color: var(--adm-muted); margin: 0;">No activity recorded yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="adm-table">
        <thead>
          <tr>
            <th>Time</th>
            <th>Admin</th>
            <th>Action</th>
            <th>Entity</th>
            <th>IP Address</th>
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
