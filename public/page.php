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
<style>
  .site-header { position: static !important; }
  .published-custom-page {
    padding-block: 10px 14px;
    margin: 0;
  }
  .published-custom-page > .container {
    padding-inline: 20px;
  }
  .published-page-heading {
    margin: 0 0 10px;
    color: var(--text-primary, #141e16);
    font-family: var(--font-display, 'Noto Sans Bengali', sans-serif);
    font-size: clamp(1.45rem, 2.5vw, 2.1rem);
    font-weight: 700;
    line-height: 1.3;
  }
  .custom-content-frame {
    display: block;
    width: 100%;
    min-height: 240px;
    height: 320px;
    border: 0;
    background: transparent;
    overflow: visible;
  }
  @media (max-width: 640px) {
    .published-custom-page { padding-block: 6px 10px; }
    .published-custom-page > .container { padding-inline: 12px; }
    .published-page-heading { margin-bottom: 8px; }
  }
</style>
<main class="published-custom-page">
  <div class="container">
    <h1 class="published-page-heading"><?= e((string) $page['title']) ?></h1>
    <script>
    (() => {
      window.addEventListener('message', (event) => {
        const frame = document.getElementById('published-page-content');
        if (!frame || event.source !== frame.contentWindow) return;
        const data = event.data;
        if (!data || data.type !== 'fakir:content-height') return;
        const height = Number(data.height);
        if (!Number.isFinite(height) || height <= 0) return;
        frame.style.height = Math.max(240, Math.min(30000, Math.ceil(height))) + 'px';
      });
    })();
    </script>
    <iframe
      id="published-page-content"
      class="custom-content-frame"
      src="/content-frame.php?type=page&amp;id=<?= (int) $page['id'] ?>"
      title="<?= e((string) $page['title']) ?>"
      sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"
      loading="eager"
      scrolling="no"
      onload="this.contentWindow.postMessage({type:'fakir:request-height'}, '*')"
      referrerpolicy="no-referrer"
    ></iframe>
  </div>
</main>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
