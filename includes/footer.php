<?php
declare(strict_types=1);

$siteSettings   = get_website_settings();
$footerSettings = get_footer_settings();
$footerLinks    = get_navigation_tree('footer');

$footerBg   = sanitize_hex_color($siteSettings['footer_bg_color'] ?? null, '#14532d');
$footerText = sanitize_hex_color($siteSettings['footer_text_color'] ?? null, '#ecfdf5');
?>
  </main>

  <?php if (!empty($footerSettings['is_visible'])): ?>
  <footer class="site-footer" style="--footer-bg: <?= e($footerBg) ?>; --footer-fg: <?= e($footerText) ?>;">
    <div class="container footer-grid">
      <div class="footer-brand-col">
        <a href="/" class="footer-brand">
          <?php if (!empty($footerSettings['logo_url'])): ?>
            <img
              src="<?= e(safe_url($footerSettings['logo_url'])) ?>"
              alt="<?= e($footerSettings['site_name']) ?> logo"
              class="footer-logo"
              referrerpolicy="no-referrer"
            >
          <?php else: ?>
            <span class="brand-monogram" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"></path>
                <path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"></path>
              </svg>
            </span>
          <?php endif; ?>
          <span class="footer-site-name"><?= e($footerSettings['site_name']) ?></span>
        </a>
        <?php if (!empty($footerSettings['description'])): ?>
          <p class="footer-description"><?= e($footerSettings['description']) ?></p>
        <?php endif; ?>
      </div>

      <div class="footer-links-col">
        <h3 class="footer-heading">Community Links</h3>
        <ul class="footer-link-list">
          <?php if (!empty($footerLinks)): ?>
            <?php foreach ($footerLinks as $link): ?>
              <li>
                <a href="<?= e(clean_nav_url($link['url'])) ?>"<?= !empty($link['open_in_new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                  <?= e($link['label']) ?>
                </a>
              </li>
            <?php endforeach; ?>
          <?php else: ?>
            <li><a href="/">Home</a></li>
            <li><a href="/posts.php">Community Posts</a></li>
            <li><a href="/gallery.php">Photo Gallery</a></li>
          <?php endif; ?>
        </ul>
      </div>

      <?php if (!empty($footerSettings['show_contact_info'])): ?>
      <div class="footer-contact-col">
        <h3 class="footer-heading">Local Contact</h3>
        <ul class="footer-contact-list">
          <?php if (!empty($footerSettings['contact_address'])): ?>
            <li><?= e($footerSettings['contact_address']) ?></li>
          <?php endif; ?>
          <?php if (!empty($footerSettings['contact_phone'])): ?>
            <li><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $footerSettings['contact_phone'])) ?>"><?= e($footerSettings['contact_phone']) ?></a></li>
          <?php endif; ?>
          <?php if (!empty($footerSettings['contact_email'])): ?>
            <li><a href="mailto:<?= e($footerSettings['contact_email']) ?>"><?= e($footerSettings['contact_email']) ?></a></li>
          <?php endif; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if (!empty($footerSettings['show_social_links'])): ?>
      <div class="footer-social-col">
        <h3 class="footer-heading">Follow Along</h3>
        <div class="footer-social-links">
          <?php if (!empty($footerSettings['facebook_url'])): ?>
            <a href="<?= e(safe_url($footerSettings['facebook_url'])) ?>" target="_blank" rel="noopener noreferrer" aria-label="Facebook">Facebook</a>
          <?php endif; ?>
          <?php if (!empty($footerSettings['instagram_url'])): ?>
            <a href="<?= e(safe_url($footerSettings['instagram_url'])) ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram">Instagram</a>
          <?php endif; ?>
          <?php if (!empty($footerSettings['youtube_url'])): ?>
            <a href="<?= e(safe_url($footerSettings['youtube_url'])) ?>" target="_blank" rel="noopener noreferrer" aria-label="YouTube">YouTube</a>
          <?php endif; ?>
          <?php if (!empty($footerSettings['x_url'])): ?>
            <a href="<?= e(safe_url($footerSettings['x_url'])) ?>" target="_blank" rel="noopener noreferrer" aria-label="X">X</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="footer-bottom">
      <div class="container footer-bottom-inner">
        <p class="footer-copyright"><?= e($footerSettings['copyright_text']) ?></p>
      </div>
    </div>
  </footer>
  <?php endif; ?>

  <!-- Global Instagram-Style Image Lightbox -->
  <div class="lightbox" id="gallery-lightbox" role="dialog" aria-modal="true" aria-label="Image Viewer" hidden>
    <div class="lightbox-backdrop" data-lb-close></div>
    <div class="lightbox-topbar">
      <div class="lightbox-counter" id="lb-counter">1 / 1</div>
      <div class="lightbox-controls">
        <button type="button" class="lb-btn" id="lb-zoom-out" aria-label="Zoom out" title="Zoom out (-)">−</button>
        <button type="button" class="lb-btn" id="lb-zoom-reset" aria-label="Reset zoom" title="Reset zoom (0)">100%</button>
        <button type="button" class="lb-btn" id="lb-zoom-in" aria-label="Zoom in" title="Zoom in (+)">+</button>
        <button type="button" class="lb-btn lb-close" id="lb-close" data-lb-close aria-label="Close viewer" title="Close (Esc)">✕</button>
      </div>
    </div>

    <button type="button" class="lb-nav lb-prev" id="lb-prev" aria-label="Previous image">‹</button>
    <button type="button" class="lb-nav lb-next" id="lb-next" aria-label="Next image">›</button>

    <div class="lightbox-stage" id="lb-stage">
      <img src="" alt="" id="lb-image" class="lightbox-image" draggable="false">
    </div>

    <div class="lightbox-caption" id="lb-caption">
      <h4 class="lb-title" id="lb-title"></h4>
      <p class="lb-desc" id="lb-desc"></p>
      <div class="lb-meta" id="lb-meta"></div>
    </div>
  </div>

  <?php require_once __DIR__ . '/popup-render.php'; render_site_popup(); ?>
  <link rel="stylesheet" href="/assets/css/popup.css?v=1">
  <script src="/assets/js/popup.js?v=1" defer></script>
  <script src="/assets/js/lightbox.js" defer></script>
  <script src="/assets/js/app.js" defer></script>
</body>
</html>
