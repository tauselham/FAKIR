<?php
declare(strict_types=1);

// Admin modules process POST actions after including this layout. Buffer the
// layout output so redirects and other response headers remain available.
if (ob_get_level() === 0) {
    ob_start();
}

require_once __DIR__ . '/auth.php';

if (!defined('ADMIN_ROUTED_ENTRY')) {
    http_response_code(404);
    exit('404 Not Found');
}

send_admin_nocache_headers();
$currentAdmin = require_admin();

if (!empty($requiredSuperAdmin) && !is_super_admin($currentAdmin)) {
    log_admin_activity((int) $currentAdmin['id'], 'unauthorized_access_attempt', 'admin_module', null, [
        'module' => $activePage ?? '',
        'reason' => 'super_admin_required',
    ]);
    http_response_code(403);
    set_flash('error', 'Only a Super Admin can access this section.');
    redirect_to(admin_url('dashboard.php'));
}

if (!empty($requiredPermission) && !admin_has_permission((string) $requiredPermission, $currentAdmin)) {
    log_admin_activity((int) $currentAdmin['id'], 'unauthorized_access_attempt', 'admin_module', null, [
        'module'     => $activePage ?? '',
        'permission' => $requiredPermission,
    ]);
    http_response_code(403);
    set_flash('error', 'You do not have permission to access that section.');
    redirect_to(admin_url('dashboard.php'));
}

// Enforce Admin API rate limit on authenticated state-changing requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enforce_rate_limit('admin_api', [
        'ip'      => client_ip(),
        'account' => 'admin:' . (int) $currentAdmin['id'],
    ]);
}

$siteSettings = get_website_settings();
$flash        = get_flash();
$activePage   = $activePage ?? 'dashboard';
$pageTitle    = $adminPageTitle ?? 'Dashboard';

$allNavItems = [
    ['id' => 'dashboard',   'label' => 'Dashboard',           'href' => admin_url('dashboard.php'),   'perm' => null,                 'super' => false],
    ['id' => 'pages',       'label' => 'Landing Pages',       'href' => admin_url('pages.php'),       'perm' => 'manage_pages',       'super' => false],
    ['id' => 'posts',       'label' => 'Community Posts',     'href' => admin_url('posts.php'),       'perm' => 'manage_posts',       'super' => false],
    ['id' => 'gallery',     'label' => 'Photo Gallery',       'href' => admin_url('gallery.php'),     'perm' => 'manage_gallery',     'super' => false],
    ['id' => 'banners',     'label' => 'Hero Banners',        'href' => admin_url('banners.php'),     'perm' => 'manage_banners',     'super' => false],
    ['id' => 'categories',  'label' => 'Categories',          'href' => admin_url('categories.php'),  'perm' => 'manage_categories',  'super' => false],
    ['id' => 'header',      'label' => 'Header & Menus',      'href' => admin_url('header.php'),      'perm' => 'manage_navigation',  'super' => false],
    ['id' => 'footer',      'label' => 'Footer Settings',     'href' => admin_url('footer.php'),      'perm' => 'manage_navigation',  'super' => false],
    ['id' => 'settings',    'label' => 'Website Settings',    'href' => admin_url('settings.php'),    'perm' => 'manage_settings',    'super' => false],
    ['id' => 'admins',      'label' => 'Admin Users',         'href' => admin_url('admins.php'),      'perm' => null,                 'super' => true],
    ['id' => 'rate_limits', 'label' => 'Rate Limits',         'href' => admin_url('rate-limits.php'), 'perm' => null,                 'super' => true],
    ['id' => 'security',    'label' => 'Security & Password', 'href' => admin_url('security.php'),    'perm' => null,                 'super' => false],
];

$navItems = array_values(array_filter($allNavItems, static function (array $item) use ($currentAdmin): bool {
    if (!empty($item['super'])) {
        return is_super_admin($currentAdmin);
    }
    if (!empty($item['perm'])) {
        return admin_has_permission((string) $item['perm'], $currentAdmin);
    }
    return true;
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?> — Admin Panel</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(admin_url('assets/css/admin.css')) ?>?v=20261115-rich-editor">
</head>
<body>
<div class="adm-shell">
  <aside class="adm-sidebar" id="adm-sidebar" aria-label="Admin Navigation">
    <div class="adm-brand">
      <span><?= e($siteSettings['site_name']) ?> Admin</span>
    </div>

    <nav class="adm-nav">
      <?php foreach ($navItems as $item): ?>
        <a href="<?= e($item['href']) ?>" class="adm-nav-link <?= $activePage === $item['id'] ? 'is-active' : '' ?>">
          <span><?= e($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="adm-sidebar-foot">
      <a href="/" target="_blank" rel="noopener noreferrer" class="adm-nav-link">View Public Site ↗</a>
      <form method="post" action="<?= e(admin_url('logout.php')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline btn-sm" style="width: 100%; background: rgba(255,255,255,0.12); color: #fff; border-color: rgba(255,255,255,0.2);">
          Sign Out (<?= e($currentAdmin['username']) ?>)
        </button>
      </form>
    </div>
  </aside>

  <div class="adm-main">
    <header class="adm-topbar">
      <div class="adm-topbar-left">
        <button type="button" class="adm-menu-btn" id="adm-menu-btn" aria-label="Toggle sidebar">☰</button>
        <h1 class="adm-page-title"><?= e($pageTitle) ?></h1>
      </div>
      <div style="font-size: 0.85rem; color: var(--adm-muted);">
        Signed in as <strong><?= e($currentAdmin['full_name']) ?></strong>
        (<?= e(is_super_admin($currentAdmin) ? 'Super Admin' : ucfirst((string) $currentAdmin['role'])) ?>)
      </div>
    </header>

    <main class="adm-content">
      <?php if (!empty($currentAdmin['must_change_password']) && $activePage !== 'security'): ?>
        <div class="alert alert-error" role="status" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
          <span>You are using an initial or temporary password. Please set your own permanent password now.</span>
          <a href="<?= e(admin_url('security.php')) ?>" class="btn btn-green btn-sm">Change Password</a>
        </div>
      <?php endif; ?>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" role="status">
          <?= e($flash['message']) ?>
        </div>
      <?php endif; ?>
