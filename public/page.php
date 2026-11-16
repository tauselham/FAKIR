<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';
enforce_rate_limit('public_api', ['ip' => client_ip()]);

$slug = trim((string) ($_GET['slug'] ?? ''));
if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
    http_response_code(404);
    $customPageTitle = 'পেজটি পাওয়া যায়নি';
    require_once dirname(__DIR__) . '/includes/header.php';
    echo '<div class="container section"><h1>পেজটি পাওয়া যায়নি</h1><p>আপনার চাওয়া পেজটি পাওয়া যায়নি।</p></div>';
    require_once dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$stmt = db()->prepare("SELECT id, title, slug, meta_description FROM public.content_pages WHERE slug = :slug AND status = 'published' LIMIT 1");
$stmt->execute([':slug' => $slug]);
$page = $stmt->fetch();

if (!$page) {
    http_response_code(404);
    $customPageTitle = 'পেজটি পাওয়া যায়নি';
    require_once dirname(__DIR__) . '/includes/header.php';
    echo '<div class="container section"><div class="empty-state"><h1>পেজটি পাওয়া যায়নি</h1><p>পেজটি হয়তো অপ্রকাশিত করা হয়েছে অথবা সরিয়ে ফেলা হয়েছে।</p><p><a class="btn-primary" href="/">Back to হোম</a></p></div></div>';
    require_once dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$customPageTitle = (string) $page['title'];
$customPageDesc = (string) ($page['meta_description'] ?? '');
require_once dirname(__DIR__) . '/includes/header.php';
?>
<section class="container section custom-page-shell">
  <iframe
    class="custom-content-frame"
    src="/content-frame.php?type=page&amp;id=<?= (int) $page['id'] ?>"
    title="<?= e($page['title']) ?>"
    sandbox="allow-scripts"
    loading="lazy"
    referrerpolicy="no-referrer"
    style="display:block;width:100%;min-height:900px;border:0;background:#fff;"
  ></iframe>
</section>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
