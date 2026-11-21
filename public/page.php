<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

$slug = strtolower(trim((string) ($_GET['slug'] ?? '')));
if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
    http_response_code(404);
    $customPageTitle = 'পেজটি পাওয়া যায়নি';
    require_once dirname(__DIR__) . '/includes/header.php';
    echo '<section class="container section"><div class="empty-state"><h1>পেজটি পাওয়া যায়নি</h1><p>লিংকটি সঠিক নয় অথবা পেজটি আর প্রকাশিত নেই।</p><p><a class="btn-primary" href="/">মূল পাতায় ফিরে যান</a></p></div></section>';
    require_once dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$isPreview = (($_GET['preview'] ?? '') === '1');
$allowDraftPreview = false;
if ($isPreview) {
    require_once dirname(__DIR__) . '/includes/auth.php';
    start_secure_session();
    $previewAdmin = current_admin();
    if ($previewAdmin && (($previewAdmin['role'] ?? '') === 'super_admin' || in_array('manage_pages', $previewAdmin['permissions'] ?? [], true))) {
        $allowDraftPreview = true;
        send_admin_nocache_headers();
    }
}

try {
    $sql = $allowDraftPreview
        ? 'SELECT id, title, slug, meta_description, status FROM public.content_pages WHERE slug = :slug LIMIT 1'
        : "SELECT id, title, slug, meta_description, status FROM public.content_pages WHERE slug = :slug AND status = 'published' LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute([':slug' => $slug]);
    $page = $stmt->fetch();
} catch (Throwable $e) {
    error_log('Published page lookup failed: ' . $e->getMessage());
    http_response_code(503);
    $page = null;
}

if (!$page) {
    if (http_response_code() !== 503) http_response_code(404);
    $customPageTitle = 'পেজটি পাওয়া যায়নি';
    require_once dirname(__DIR__) . '/includes/header.php';
    echo '<section class="container section"><div class="empty-state"><h1>পেজটি পাওয়া যায়নি</h1><p>পেজটি প্রকাশিত নয়, সরিয়ে ফেলা হয়েছে অথবা লিংকটি ভুল।</p><p><a class="btn-primary" href="/">মূল পাতায় ফিরে যান</a></p></div></section>';
    require_once dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$customPageTitle = (string) $page['title'];
$customPageDesc = trim((string) ($page['meta_description'] ?? ''));
$framePreviewQuery = ($allowDraftPreview && ($page['status'] ?? '') !== 'published') ? '&amp;preview=1' : '';
require_once dirname(__DIR__) . '/includes/header.php';
?>
<section class="published-custom-page">
  <div class="container">
    <h1 class="published-page-heading"><?= e((string) $page['title']) ?></h1>
    <iframe
      id="published-page-content"
      class="custom-content-frame"
      data-auto-resize="true"
      src="/content-frame.php?type=page&amp;id=<?= (int) $page['id'] ?><?= $framePreviewQuery ?>"
      title="<?= e((string) $page['title']) ?>"
      sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"
      loading="eager"
      scrolling="no"
      referrerpolicy="no-referrer"
    ></iframe>
  </div>
</section>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
