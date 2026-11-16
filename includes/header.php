<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$siteSettings = get_website_settings();
$headerNav    = get_navigation_tree('header');

$pageTitle = isset($customPageTitle) && $customPageTitle !== ''
    ? $customPageTitle . ' — ' . $siteSettings['site_name']
    : ($siteSettings['seo_title'] ?: $siteSettings['site_name']);

$pageDesc = isset($customPageDesc) && $customPageDesc !== ''
    ? $customPageDesc
    : ($siteSettings['seo_description'] ?: $siteSettings['site_description']);

$headerBg   = sanitize_hex_color($siteSettings['header_bg_color'] ?? null, '#14532d');
$headerText = sanitize_hex_color($siteSettings['header_text_color'] ?? null, '#ffffff');
$accentHex  = sanitize_hex_color($siteSettings['accent_color'] ?? null, '#15803d');
?>
<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="<?= e($pageDesc) ?>">
  <meta property="og:title" content="<?= e($pageTitle) ?>">
  <meta property="og:description" content="<?= e($pageDesc) ?>">
  <meta property="og:type" content="website">
  <?php if (!empty($siteSettings['favicon_url'])): ?>
    <link rel="icon" href="<?= e(safe_url($siteSettings['favicon_url'])) ?>">
  <?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700&family=Hind+Siliguri:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    :root {
      --header-bg: <?= e($headerBg) ?>;
      --header-fg: <?= e($headerText) ?>;
      --accent-green: <?= e($accentHex) ?>;
    }
  </style>
</head>
<body>
  <a class="skip-link" href="#main-content">মূল লেখায় যান</a>

  <header class="site-header" id="site-header">
    <div class="container header-inner">
      <a href="/" class="brand-link">
        <?php if (!empty($siteSettings['logo_url'])): ?>
          <img
            src="<?= e(safe_url($siteSettings['logo_url'])) ?>"
            alt="<?= e($siteSettings['site_name']) ?> logo"
            class="brand-logo"
            referrerpolicy="no-referrer"
          >
        <?php else: ?>
          <span class="brand-monogram" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"></path>
              <path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"></path>
            </svg>
          </span>
        <?php endif; ?>
        <span class="brand-name"><?= e($siteSettings['site_name']) ?></span>
      </a>

      <nav class="primary-nav" aria-label="প্রধান নেভিগেশন">
        <ul class="nav-list">
          <?php foreach ($headerNav as $item): ?>
            <?php
              $hasChildren = !empty($item['children']);
              $isMega = ($item['item_type'] === 'mega');
              $isDropdown = ($item['item_type'] === 'dropdown' || $hasChildren);
              $rawItemUrl = trim((string) ($item['url'] ?? ''));
              $hasRealDest = ($rawItemUrl !== '' && $rawItemUrl !== '#');
              $itemUrl = $hasRealDest ? clean_nav_url($rawItemUrl) : '';
              $target = !empty($item['open_in_new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '';
            ?>
            <li class="nav-item <?= $isMega ? 'has-mega' : ($isDropdown ? 'has-dropdown' : '') ?>">
              <?php if (!$hasRealDest && ($hasChildren || $isMega || $isDropdown)): ?>
                <button type="button" class="nav-link nav-link-btn" aria-haspopup="true">
                  <span><?= e($item['label']) ?></span>
                  <svg class="nav-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <polyline points="6 9 12 15 18 9"></polyline>
                  </svg>
                </button>
              <?php else: ?>
                <a href="<?= e($itemUrl !== '' ? $itemUrl : '/') ?>" class="nav-link"<?= $target ?>>
                  <span><?= e($item['label']) ?></span>
                  <?php if ($hasChildren || $isMega || $isDropdown): ?>
                    <svg class="nav-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                  <?php endif; ?>
                </a>
              <?php endif; ?>

              <?php if ($isMega): ?>
                <div class="mega-menu" role="region" aria-label="<?= e($item['label']) ?> menu">
                  <div class="mega-menu-grid">
                    <div class="mega-intro">
                      <h3 class="mega-heading"><?= e($item['label']) ?></h3>
                      <?php if (!empty($item['mega_description'])): ?>
                        <p class="mega-desc"><?= e($item['mega_description']) ?></p>
                      <?php endif; ?>
                    </div>
                    <div class="mega-links">
                      <?php if ($hasChildren): ?>
                        <?php foreach ($item['children'] as $child): ?>
                          <a href="<?= e(clean_nav_url($child['url'])) ?>" class="mega-link-card"<?= !empty($child['open_in_new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                            <span class="mega-link-title"><?= e($child['label']) ?></span>
                            <?php if (!empty($child['mega_description'])): ?>
                              <span class="mega-link-sub"><?= e($child['mega_description']) ?></span>
                            <?php endif; ?>
                          </a>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <a href="/gallery.php" class="mega-link-card">
                          <span class="mega-link-title">সব ছবি</span>
                          <span class="mega-link-sub">গ্রামের সব ছবি ও স্মৃতির সংগ্রহ দেখুন</span>
                        </a>
                        <a href="/posts.php" class="mega-link-card">
                          <span class="mega-link-title">গ্রামের খবর ও পোস্ট</span>
                          <span class="mega-link-sub">স্থানীয় ঘোষণা, গল্প ও ভিডিও পড়ুন</span>
                        </a>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php elseif ($hasChildren): ?>
                <ul class="dropdown-menu">
                  <?php foreach ($item['children'] as $child): ?>
                    <li>
                      <a href="<?= e(clean_nav_url($child['url'])) ?>" class="dropdown-link"<?= !empty($child['open_in_new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                        <?= e($child['label']) ?>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </nav>

      <div class="header-actions">
        <a href="/gallery.php" class="header-cta">Gallery</a>
        <button
          type="button"
          class="mobile-menu-toggle"
          id="mobile-menu-toggle"
          aria-expanded="false"
          aria-controls="mobile-nav-drawer"
          aria-label="নেভিগেশন মেনু খুলুন"
        >
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
          </svg>
        </button>
      </div>
    </div>

    <!-- Mobile Responsive Drawer -->
    <div class="mobile-drawer" id="mobile-nav-drawer" hidden>
      <nav aria-label="মোবাইল নেভিগেশন">
        <ul class="mobile-nav-list">
          <?php foreach ($headerNav as $item): ?>
            <?php
              $rawMobileUrl = trim((string) ($item['url'] ?? ''));
              $hasMobileDest = ($rawMobileUrl !== '' && $rawMobileUrl !== '#');
              $mobileUrl = $hasMobileDest ? clean_nav_url($rawMobileUrl) : '/';
            ?>
            <li class="mobile-nav-item">
              <div class="mobile-nav-row">
                <?php if (!$hasMobileDest && !empty($item['children'])): ?>
                  <button type="button" class="mobile-nav-link mobile-nav-btn" data-mobile-parent-toggle><?= e($item['label']) ?></button>
                <?php else: ?>
                  <a href="<?= e($mobileUrl) ?>" class="mobile-nav-link"<?= !empty($item['open_in_new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <?= e($item['label']) ?>
                  </a>
                <?php endif; ?>
                <?php if (!empty($item['children'])): ?>
                  <button type="button" class="mobile-submenu-btn" aria-expanded="false" aria-label="Toggle <?= e($item['label']) ?> submenu">+</button>
                <?php endif; ?>
              </div>
              <?php if (!empty($item['children'])): ?>
                <ul class="mobile-sublist" hidden>
                  <?php foreach ($item['children'] as $child): ?>
                    <li>
                      <a href="<?= e(clean_nav_url($child['url'])) ?>" class="mobile-sublink"<?= !empty($child['open_in_new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                        <?= e($child['label']) ?>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </nav>
    </div>
  </header>

  <main id="main-content" tabindex="-1">
