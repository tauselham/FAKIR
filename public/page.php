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
    <script>
    (() => {
      const frameId = 'published-page-content';
      let galleryImages = [];
      let galleryIndex = 0;
      let overlay = null;
      let image = null;
      let caption = null;

      function ensureGalleryPopup() {
        if (overlay) return;
        const style = document.createElement('style');
        style.textContent = `
          #fakir-gallery-popup { position:fixed; inset:0; z-index:2147483000; display:flex; align-items:center; justify-content:center; padding:64px 76px 54px; background:rgba(0,0,0,.94); opacity:0; visibility:hidden; pointer-events:none; transition:opacity .2s ease,visibility .2s ease; }
          #fakir-gallery-popup.is-open { opacity:1; visibility:visible; pointer-events:auto; }
          #fakir-gallery-popup .fg-image-wrap { width:min(100%,1200px); height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px; }
          #fakir-gallery-popup img { display:block; max-width:100%; max-height:calc(100% - 34px); width:auto; height:auto; object-fit:contain; border:2px solid rgba(255,255,255,.2); border-radius:10px; box-shadow:0 12px 45px rgba(0,0,0,.65); }
          #fakir-gallery-popup .fg-caption { color:#fff; font:14px system-ui,sans-serif; text-align:center; }
          #fakir-gallery-popup button { position:absolute; display:grid; place-items:center; color:#fff; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.3); cursor:pointer; transition:background .2s,transform .2s; }
          #fakir-gallery-popup button:hover { background:rgba(22,128,68,.85); }
          #fakir-gallery-popup .fg-close { top:16px; right:20px; width:46px; height:46px; border-radius:50%; font-size:32px; }
          #fakir-gallery-popup .fg-prev,#fakir-gallery-popup .fg-next { top:50%; width:48px; height:48px; border-radius:50%; transform:translateY(-50%); font-size:22px; }
          #fakir-gallery-popup .fg-prev { left:18px; } #fakir-gallery-popup .fg-next { right:18px; }
          @media(max-width:768px) { #fakir-gallery-popup { padding:58px 46px 42px; } #fakir-gallery-popup .fg-prev { left:6px; } #fakir-gallery-popup .fg-next { right:6px; } #fakir-gallery-popup .fg-prev,#fakir-gallery-popup .fg-next { width:38px;height:38px;font-size:18px; } #fakir-gallery-popup .fg-close { top:10px;right:10px;width:38px;height:38px;font-size:26px; } }
        `;
        document.head.appendChild(style);
        overlay = document.createElement('div');
        overlay.id = 'fakir-gallery-popup';
        overlay.setAttribute('role','dialog');
        overlay.setAttribute('aria-modal','true');
        overlay.setAttribute('aria-label','Gallery image viewer');
        overlay.innerHTML = '<button type="button" class="fg-close" aria-label="Close">&times;</button><button type="button" class="fg-prev" aria-label="Previous">&#10094;</button><div class="fg-image-wrap"><img alt="Gallery photograph"><div class="fg-caption"></div></div><button type="button" class="fg-next" aria-label="Next">&#10095;</button>';
        document.body.appendChild(overlay);
        image = overlay.querySelector('img');
        caption = overlay.querySelector('.fg-caption');
        overlay.querySelector('.fg-close').addEventListener('click', closeGalleryPopup);
        overlay.querySelector('.fg-prev').addEventListener('click', () => showGalleryImage(-1));
        overlay.querySelector('.fg-next').addEventListener('click', () => showGalleryImage(1));
        overlay.addEventListener('click', event => { if (event.target === overlay) closeGalleryPopup(); });
      }
      function showGalleryImage(direction) {
        if (!galleryImages.length) return;
        galleryIndex = (galleryIndex + direction + galleryImages.length) % galleryImages.length;
        image.src = galleryImages[galleryIndex];
        caption.textContent = 'ছবি ' + (galleryIndex + 1) + ' / ' + galleryImages.length;
      }
      function closeGalleryPopup() {
        if (!overlay) return;
        overlay.classList.remove('is-open');
        document.body.style.overflow = '';
        image.removeAttribute('src');
      }
      window.addEventListener('keydown', event => {
        if (!overlay || !overlay.classList.contains('is-open')) return;
        if (event.key === 'Escape') closeGalleryPopup();
        if (event.key === 'ArrowLeft') showGalleryImage(-1);
        if (event.key === 'ArrowRight') showGalleryImage(1);
      });
      window.addEventListener('message', event => {
        const frame = document.getElementById(frameId);
        if (!frame || event.source !== frame.contentWindow) return;
        const message = event.data;
        if (!message || message.type !== 'fakir:gallery-open' || !Array.isArray(message.images)) return;
        const safeImages = message.images.filter(url => typeof url === 'string' && /^https:\/\//i.test(url));
        if (!safeImages.length) return;
        galleryImages = safeImages;
        galleryIndex = Math.max(0, Math.min(galleryImages.length - 1, Number(message.index) || 0));
        ensureGalleryPopup();
        showGalleryImage(0);
        overlay.classList.add('is-open');
        document.body.style.overflow = 'hidden';
      });
      window.addEventListener('message', event => {
        const frame = document.getElementById(frameId);
        if (!frame || event.source !== frame.contentWindow) return;
        const message = event.data;
        if (!message || message.type !== 'fakir:content-height') return;
        const height = Number(message.height);
        if (!Number.isFinite(height) || height < 1) return;
        frame.style.height = Math.ceil(Math.min(50000, Math.max(240, height))) + 'px';
      });
    })();
    </script>
    <iframe
      id="published-page-content"
      class="custom-content-frame"
      data-auto-resize="true"
      src="/content-frame.php?type=page&amp;id=<?= (int) $page['id'] ?><?= $framePreviewQuery ?>"
      title="<?= e((string) $page['title']) ?>"
      sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"
      loading="eager"
      scrolling="no"
      onload="this.contentWindow.postMessage({type:'fakir:request-height'}, '*')"
      referrerpolicy="no-referrer"
    ></iframe>
  </div>
</section>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>