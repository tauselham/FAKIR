<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

$slug = strtolower(trim((string) ($_GET['slug'] ?? '')));
if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
    http_response_code(404);
    $customPageTitle = 'পেজটি পাওয়া যায়নি';
    require_once dirname(__DIR__) . '/includes/header.php';
    echo '<main class="container section"><div class="empty-state"><h1>পেজটি পাওয়া যায়নি</h1><p>লিংকটি সঠিক নয় অথবা পেজটি আর প্রকাশিত নেই।</p><a class="btn-primary" href="/">মূল পাতায় ফিরে যান</a></div></main>';
    require_once dirname(__DIR__) . '/includes/footer.php';
    exit;
}

try {
    enforce_rate_limit('public_api', ['ip' => client_ip()]);
    $stmt = db()->prepare(
        "SELECT id, title, slug, meta_description
         FROM public.content_pages
         WHERE slug = :slug AND status = 'published'
         LIMIT 1"
    );
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
    echo '<main class="container section"><div class="empty-state"><h1>পেজটি পাওয়া যায়নি</h1><p>পেজটি প্রকাশিত নয়, সরিয়ে ফেলা হয়েছে অথবা লিংকটি ভুল।</p><a class="btn-primary" href="/">মূল পাতায় ফিরে যান</a></div></main>';
    require_once dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$customPageTitle = (string) $page['title'];
$customPageDesc = trim((string) ($page['meta_description'] ?? ''));
require_once dirname(__DIR__) . '/includes/header.php';
?>
<main class="section published-custom-page">
  <div class="container">
    <iframe
      class="custom-content-frame"
      src="/content-frame.php?type=page&amp;id=<?= (int) $page['id'] ?>"
      title="<?= e((string) $page['title']) ?>"
      sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"
      loading="eager"
      referrerpolicy="no-referrer"
      style="display:block;width:100%;min-height:1400px;border:0;background:#fff;"
    ></iframe>
  </div>
</main>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
