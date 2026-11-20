import type { Express, NextFunction, Request, Response } from 'express';
import { store } from './store.ts';
import type { NavigationItem } from './types.ts';
import {
  applyRateLimitHeaders,
  checkRateLimit,
  cleanNavUrl,
  clientIp,
  e,
  formatDate,
  getNavigationTree,
  safeUrl,
  sanitizeHexColor,
} from './utils.ts';

function renderSitePopup(currentPath: string): string {
  const nowMs = Date.now();
  const isHome = currentPath === '/' || currentPath === '';
  const eligible = store.site_popups
    .filter((row) => {
      if (!row.is_active) return false;
      if (row.starts_at && new Date(row.starts_at).getTime() > nowMs) return false;
      if (row.ends_at && new Date(row.ends_at).getTime() <= nowMs) return false;
      if (row.display_scope === 'home' && !isHome) return false;
      if (row.popup_type === 'image' && !row.image_url) return false;
      if (row.popup_type === 'text' && !(row.body_text || '').trim()) return false;
      return true;
    })
    .sort((a, b) => b.priority - a.priority || b.created_at.localeCompare(a.created_at))
    .slice(0, 20)
    .map((row) => ({
      id: row.id,
      title: row.title,
      popup_type: row.popup_type,
      body_text: row.body_text,
      image_url: row.image_url,
      image_link_url: row.image_link_url,
      image_link_target: row.image_link_target,
      button_text: row.button_text,
      button_url: row.button_url,
      button_target: row.button_target,
      delay_seconds: row.delay_seconds,
      frequency: row.frequency,
      display_scope: row.display_scope,
      device_target: row.device_target,
    }));

  if (eligible.length === 0) return '';
  const json = JSON.stringify(eligible)
    .replace(/</g, '\\u003C')
    .replace(/>/g, '\\u003E')
    .replace(/&/g, '\\u0026')
    .replace(/'/g, '\\u0027');
  return `<script type="application/json" id="site-popup-data">${json}</script>`;
}

export function renderPublicHeader(options?: {
  customPageTitle?: string;
  customPageDesc?: string;
}): string {
  const siteSettings = store.website_settings;
  const headerNav = getNavigationTree('header');

  const pageTitle =
    options?.customPageTitle && options.customPageTitle !== ''
      ? `${options.customPageTitle} — ${siteSettings.site_name}`
      : siteSettings.seo_title || siteSettings.site_name;

  const pageDesc =
    options?.customPageDesc && options.customPageDesc !== ''
      ? options.customPageDesc
      : siteSettings.seo_description || siteSettings.site_description || '';

  const headerBg = sanitizeHexColor(siteSettings.header_bg_color, '#14532d');
  const headerText = sanitizeHexColor(siteSettings.header_text_color, '#ffffff');
  const accentHex = sanitizeHexColor(siteSettings.accent_color, '#15803d');

  const renderDesktopNavItem = (item: NavigationItem): string => {
    const children = item.children || [];
    const hasChildren = children.length > 0;
    const isMega = item.item_type === 'mega';
    const isDropdown = item.item_type === 'dropdown' || hasChildren;
    const rawItemUrl = String(item.url ?? '').trim();
    const hasRealDest = rawItemUrl !== '' && rawItemUrl !== '#';
    const itemUrl = hasRealDest ? cleanNavUrl(rawItemUrl) : '';
    const target = item.open_in_new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';
    const liClass = isMega ? 'has-mega' : isDropdown ? 'has-dropdown' : '';

    const chevron = `<svg class="nav-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>`;

    const triggerHtml =
      !hasRealDest && (hasChildren || isMega || isDropdown)
        ? `<button type="button" class="nav-link nav-link-btn" aria-haspopup="true"><span>${e(item.label)}</span>${chevron}</button>`
        : `<a href="${e(itemUrl !== '' ? itemUrl : '/')}" class="nav-link"${target}><span>${e(item.label)}</span>${
            hasChildren || isMega || isDropdown ? chevron : ''
          }</a>`;

    let submenuHtml = '';
    if (isMega) {
      const linksHtml = hasChildren
        ? children
            .map(
              (child) => `
                <a href="${e(cleanNavUrl(child.url))}" class="mega-link-card"${
                  child.open_in_new_tab ? ' target="_blank" rel="noopener noreferrer"' : ''
                }>
                  <span class="mega-link-title">${e(child.label)}</span>
                  ${child.mega_description ? `<span class="mega-link-sub">${e(child.mega_description)}</span>` : ''}
                </a>`
            )
            .join('')
        : `
            <a href="/gallery.php" class="mega-link-card">
              <span class="mega-link-title">সব ছবি</span>
              <span class="mega-link-sub">গ্রামের সব ছবি ও স্মৃতির সংগ্রহ দেখুন</span>
            </a>
            <a href="/posts.php" class="mega-link-card">
              <span class="mega-link-title">গ্রামের খবর ও পোস্ট</span>
              <span class="mega-link-sub">স্থানীয় ঘোষণা, গল্প ও ভিডিও পড়ুন</span>
            </a>`;

      submenuHtml = `
        <div class="mega-menu" role="region" aria-label="উপমেনু: ${e(item.label)}">
          <div class="mega-menu-grid">
            <div class="mega-intro">
              <h3 class="mega-heading">${e(item.label)}</h3>
              ${item.mega_description ? `<p class="mega-desc">${e(item.mega_description)}</p>` : ''}
            </div>
            <div class="mega-links">${linksHtml}</div>
          </div>
        </div>`;
    } else if (hasChildren) {
      const itemsHtml = children
        .map(
          (child) => `
            <li>
              <a href="${e(cleanNavUrl(child.url))}" class="dropdown-link"${
                child.open_in_new_tab ? ' target="_blank" rel="noopener noreferrer"' : ''
              }>${e(child.label)}</a>
            </li>`
        )
        .join('');
      submenuHtml = `<ul class="dropdown-menu">${itemsHtml}</ul>`;
    }

    return `<li class="nav-item ${liClass}">${triggerHtml}${submenuHtml}</li>`;
  };

  const renderMobileNavItem = (item: NavigationItem): string => {
    const children = item.children || [];
    const rawMobileUrl = String(item.url ?? '').trim();
    const hasMobileDest = rawMobileUrl !== '' && rawMobileUrl !== '#';
    const mobileUrl = hasMobileDest ? cleanNavUrl(rawMobileUrl) : '/';

    const rowLeft =
      !hasMobileDest && children.length > 0
        ? `<button type="button" class="mobile-nav-link mobile-nav-btn" data-mobile-parent-toggle>${e(item.label)}</button>`
        : `<a href="${e(mobileUrl)}" class="mobile-nav-link"${
            item.open_in_new_tab ? ' target="_blank" rel="noopener noreferrer"' : ''
          }>${e(item.label)}</a>`;

    const toggleBtn =
      children.length > 0
        ? `<button type="button" class="mobile-submenu-btn" aria-expanded="false" aria-label="${e(item.label)} উপমেনু খুলুন বা বন্ধ করুন">+</button>`
        : '';

    const sublist =
      children.length > 0
        ? `<ul class="mobile-sublist" hidden>${children
            .map(
              (child) => `
                <li>
                  <a href="${e(cleanNavUrl(child.url))}" class="mobile-sublink"${
                    child.open_in_new_tab ? ' target="_blank" rel="noopener noreferrer"' : ''
                  }>${e(child.label)}</a>
                </li>`
            )
            .join('')}</ul>`
        : '';

    return `<li class="mobile-nav-item"><div class="mobile-nav-row">${rowLeft}${toggleBtn}</div>${sublist}</li>`;
  };

  const brandLogoHtml = siteSettings.logo_url
    ? `<img src="${e(safeUrl(siteSettings.logo_url))}" alt="${e(siteSettings.site_name)} logo" class="brand-logo" referrerpolicy="no-referrer">`
    : `<span class="brand-monogram" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"></path><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"></path></svg></span>`;

  return `<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>${e(pageTitle)}</title>
  <meta name="description" content="${e(pageDesc)}">
  <meta property="og:title" content="${e(pageTitle)}">
  <meta property="og:description" content="${e(pageDesc)}">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="bn_BD">
  ${siteSettings.favicon_url ? `<link rel="icon" href="${e(safeUrl(siteSettings.favicon_url))}">` : ''}
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700&family=Hind+Siliguri:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    :root {
      --header-bg: ${e(headerBg)};
      --header-fg: ${e(headerText)};
      --accent-green: ${e(accentHex)};
    }
  </style>
</head>
<body>
  <a class="skip-link" href="#main-content">মূল লেখায় যান</a>

  <header class="site-header" id="site-header">
    <div class="container header-inner">
      <a href="/" class="brand-link">
        ${brandLogoHtml}
        <span class="brand-name">${e(siteSettings.site_name)}</span>
      </a>

      <nav class="primary-nav" aria-label="প্রধান নেভিগেশন">
        <ul class="nav-list">
          ${headerNav.map(renderDesktopNavItem).join('')}
        </ul>
      </nav>

      <div class="header-actions">
        <a href="/gallery.php" class="header-cta">Gallery</a>
        <button type="button" class="mobile-menu-toggle" id="mobile-menu-toggle" aria-expanded="false" aria-controls="mobile-nav-drawer" aria-label="নেভিগেশন মেনু খুলুন">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
          </svg>
        </button>
      </div>
    </div>

    <div class="mobile-drawer" id="mobile-nav-drawer" hidden>
      <nav aria-label="মোবাইল নেভিগেশন">
        <ul class="mobile-nav-list">
          ${headerNav.map(renderMobileNavItem).join('')}
        </ul>
      </nav>
    </div>
  </header>

  <main id="main-content" tabindex="-1">`;
}

export function renderPublicFooter(currentPath = '/'): string {
  const siteSettings = store.website_settings;
  const footerSettings = store.footer_settings;
  const footerLinks = getNavigationTree('footer');

  const footerBg = sanitizeHexColor(siteSettings.footer_bg_color, '#14532d');
  const footerText = sanitizeHexColor(siteSettings.footer_text_color, '#ecfdf5');

  let footerHtml = '';
  if (footerSettings.is_visible) {
    const logoHtml = footerSettings.logo_url
      ? `<img src="${e(safeUrl(footerSettings.logo_url))}" alt="${e(footerSettings.site_name)} logo" class="footer-logo" referrerpolicy="no-referrer">`
      : `<span class="brand-monogram" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"></path><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"></path></svg></span>`;

    const linksListHtml =
      footerLinks.length > 0
        ? footerLinks
            .map(
              (link) => `<li><a href="${e(cleanNavUrl(link.url))}"${
                link.open_in_new_tab ? ' target="_blank" rel="noopener noreferrer"' : ''
              }>${e(link.label)}</a></li>`
            )
            .join('')
        : `<li><a href="/">হোম</a></li>
           <li><a href="/posts.php">গ্রামের খবর ও পোস্ট</a></li>
           <li><a href="/gallery.php">ছবির গ্যালারি</a></li>`;

    const contactColHtml = footerSettings.show_contact_info
      ? `<div class="footer-contact-col">
          <h3 class="footer-heading">যোগাযোগ</h3>
          <ul class="footer-contact-list">
            ${footerSettings.contact_address ? `<li>${e(footerSettings.contact_address)}</li>` : ''}
            ${
              footerSettings.contact_phone
                ? `<li><a href="tel:${e(footerSettings.contact_phone.replace(/[^0-9+]/g, ''))}">${e(
                    footerSettings.contact_phone
                  )}</a></li>`
                : ''
            }
            ${
              footerSettings.contact_email
                ? `<li><a href="mailto:${e(footerSettings.contact_email)}">${e(footerSettings.contact_email)}</a></li>`
                : ''
            }
          </ul>
        </div>`
      : '';

    const socialColHtml = footerSettings.show_social_links
      ? `<div class="footer-social-col">
          <h3 class="footer-heading">আমাদের সঙ্গে থাকুন</h3>
          <div class="footer-social-links">
            ${
              footerSettings.facebook_url
                ? `<a href="${e(safeUrl(footerSettings.facebook_url))}" target="_blank" rel="noopener noreferrer" aria-label="Facebook">Facebook</a>`
                : ''
            }
            ${
              footerSettings.instagram_url
                ? `<a href="${e(safeUrl(footerSettings.instagram_url))}" target="_blank" rel="noopener noreferrer" aria-label="Instagram">Instagram</a>`
                : ''
            }
            ${
              footerSettings.youtube_url
                ? `<a href="${e(safeUrl(footerSettings.youtube_url))}" target="_blank" rel="noopener noreferrer" aria-label="YouTube">YouTube</a>`
                : ''
            }
            ${
              footerSettings.x_url
                ? `<a href="${e(safeUrl(footerSettings.x_url))}" target="_blank" rel="noopener noreferrer" aria-label="X">X</a>`
                : ''
            }
          </div>
        </div>`
      : '';

    footerHtml = `
      <footer class="site-footer" style="--footer-bg: ${e(footerBg)}; --footer-fg: ${e(footerText)};">
        <div class="container footer-grid">
          <div class="footer-brand-col">
            <a href="/" class="footer-brand">
              ${logoHtml}
              <span class="footer-site-name">${e(footerSettings.site_name)}</span>
            </a>
            ${footerSettings.description ? `<p class="footer-description">${e(footerSettings.description)}</p>` : ''}
          </div>
          <div class="footer-links-col">
            <h3 class="footer-heading">গুরুত্বপূর্ণ লিংক</h3>
            <ul class="footer-link-list">${linksListHtml}</ul>
          </div>
          ${contactColHtml}
          ${socialColHtml}
        </div>
        <div class="footer-bottom">
          <div class="container footer-bottom-inner">
            <p class="footer-copyright">${e(footerSettings.copyright_text)}</p>
          </div>
        </div>
      </footer>`;
  }

  return `
  </main>
  ${footerHtml}

  <div class="lightbox" id="gallery-lightbox" role="dialog" aria-modal="true" aria-label="ছবি দেখুন" hidden>
    <div class="lightbox-backdrop" data-lb-close></div>
    <div class="lightbox-topbar">
      <div class="lightbox-counter" id="lb-counter">1 / 1</div>
      <div class="lightbox-controls">
        <button type="button" class="lb-btn" id="lb-zoom-out" aria-label="ছোট করুন" title="ছোট করুন (-)">−</button>
        <button type="button" class="lb-btn" id="lb-zoom-reset" aria-label="আকার আগের মতো করুন" title="আকার আগের মতো করুন (0)">100%</button>
        <button type="button" class="lb-btn" id="lb-zoom-in" aria-label="বড় করুন" title="বড় করুন (+)">+</button>
        <button type="button" class="lb-btn lb-close" id="lb-close" data-lb-close aria-label="ছবি বন্ধ করুন" title="বন্ধ করুন (Esc)">✕</button>
      </div>
    </div>
    <button type="button" class="lb-nav lb-prev" id="lb-prev" aria-label="আগের ছবি">‹</button>
    <button type="button" class="lb-nav lb-next" id="lb-next" aria-label="পরের ছবি">›</button>
    <div class="lightbox-stage" id="lb-stage">
      <img src="" alt="" id="lb-image" class="lightbox-image" draggable="false">
    </div>
    <div class="lightbox-caption" id="lb-caption">
      <h4 class="lb-title" id="lb-title"></h4>
      <p class="lb-desc" id="lb-desc"></p>
      <div class="lb-meta" id="lb-meta"></div>
    </div>
  </div>

  ${renderSitePopup(currentPath)}
  <link rel="stylesheet" href="/assets/css/popup.css?v=1">
  <script src="/assets/js/popup.js?v=1" defer></script>
  <script src="/assets/js/lightbox.js" defer></script>
  <script src="/assets/js/app.js?v=20261120" defer></script>
</body>
</html>`;
}

function getCategoryMap<T extends { id: number; name: string; slug: string }>(rows: T[]): Map<number, T> {
  const m = new Map<number, T>();
  for (const r of rows) m.set(r.id, r);
  return m;
}

export function registerPublicRoutes(app: Express): void {
  // Redirect /index.php to /
  app.get('/index.php', (req: Request, res: Response) => {
    const qIdx = req.originalUrl.indexOf('?');
    const qs = qIdx >= 0 ? req.originalUrl.slice(qIdx) : '';
    res.redirect(301, '/' + qs);
  });

  // Homepage
  app.get('/', (req: Request, res: Response) => {
    const siteSettings = store.website_settings;
    const banners = store.hero_banners
      .filter((b) => b.is_active)
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);

    const galleryCategories = store.gallery_categories
      .filter((c) => c.is_active)
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    const galleryCatById = getCategoryMap(store.gallery_categories);
    const postCatById = getCategoryMap(store.post_categories);

    const sliderItems = store.gallery_images
      .filter((gi) => gi.status === 'published' && gi.is_slider_item)
      .sort((a, b) => a.sort_order - b.sort_order || b.uploaded_at.localeCompare(a.uploaded_at))
      .slice(0, 16)
      .map((gi) => ({
        ...gi,
        category_name: gi.category_id ? galleryCatById.get(gi.category_id)?.name ?? null : null,
        category_slug: gi.category_id ? galleryCatById.get(gi.category_id)?.slug ?? null : null,
      }));

    const recentPosts = store.posts
      .filter((p) => p.status === 'published')
      .sort((a, b) => (b.published_at || b.created_at).localeCompare(a.published_at || a.created_at))
      .slice(0, 6)
      .map((p) => ({
        ...p,
        category_name: p.category_id ? postCatById.get(p.category_id)?.name ?? null : null,
        category_slug: p.category_id ? postCatById.get(p.category_id)?.slug ?? null : null,
      }));

    const initialCat = String(req.query.category ?? 'all').trim() || 'all';
    const perPage = 8;

    const filteredGallery = store.gallery_images
      .filter((gi) => {
        if (gi.status !== 'published') return false;
        if (initialCat !== '' && initialCat !== 'all') {
          const cat = gi.category_id ? galleryCatById.get(gi.category_id) : null;
          return cat?.slug === initialCat;
        }
        return true;
      })
      .sort((a, b) => a.sort_order - b.sort_order || b.uploaded_at.localeCompare(a.uploaded_at))
      .map((gi) => ({
        ...gi,
        category_name: gi.category_id ? galleryCatById.get(gi.category_id)?.name ?? null : null,
        category_slug: gi.category_id ? galleryCatById.get(gi.category_id)?.slug ?? null : null,
      }));

    const hasMoreGallery = filteredGallery.length > perPage;
    const galleryImages = filteredGallery.slice(0, perPage);

    const featuredImages = store.gallery_images
      .filter((gi) => gi.status === 'published' && gi.is_featured)
      .sort((a, b) => a.sort_order - b.sort_order || b.uploaded_at.localeCompare(a.uploaded_at))
      .slice(0, 4)
      .map((gi) => ({
        ...gi,
        category_name: gi.category_id ? galleryCatById.get(gi.category_id)?.name ?? null : null,
      }));

    const bannersSection =
      banners.length > 0
        ? `<section class="hero-section" id="hero-slider" data-interval="${Number(siteSettings.hero_interval_ms || 5500)}" aria-label="গ্রামের বিশেষ মুহূর্ত">
            <div class="hero-track">
              ${banners
                .map((banner) => {
                  const hasTitle = String(banner.title ?? '').trim() !== '';
                  const hasSub = String(banner.subtitle ?? '').trim() !== '';
                  const hasBtn =
                    String(banner.button_text ?? '').trim() !== '' &&
                    String(banner.button_url ?? '').trim() !== '';
                  const hasCopy = hasTitle || hasSub || hasBtn;
                  return `
                    <div class="hero-slide">
                      <img src="${e(safeUrl(banner.image_url))}" alt="${e(hasTitle ? banner.title : 'গ্রামের ব্যানার')}" class="hero-img" referrerpolicy="no-referrer">
                      ${
                        hasCopy
                          ? `<div class="hero-scrim" aria-hidden="true"></div>
                             <div class="hero-content"><div class="container"><div class="hero-copy">
                               ${hasTitle ? `<h1 class="hero-title">${e(banner.title)}</h1>` : ''}
                               ${hasSub ? `<p class="hero-subtitle">${e(banner.subtitle)}</p>` : ''}
                               ${hasBtn ? `<a href="${e(safeUrl(banner.button_url))}" class="hero-btn">${e(banner.button_text)}</a>` : ''}
                             </div></div></div>`
                          : ''
                      }
                    </div>`;
                })
                .join('')}
            </div>
            ${
              banners.length > 1
                ? `<button type="button" class="hero-nav-btn hero-prev" aria-label="আগের ব্যানার">‹</button>
                   <button type="button" class="hero-nav-btn hero-next" aria-label="পরের ব্যানার">›</button>
                   <div class="hero-dots" aria-label="ব্যানার নির্বাচন">
                     ${banners
                       .map(
                         (_, idx) =>
                           `<button type="button" class="hero-dot ${idx === 0 ? 'is-active' : ''}" aria-label="এই স্লাইডে যান ${idx + 1}"></button>`
                       )
                       .join('')}
                   </div>`
                : ''
            }
          </section>`
        : '';

    const sliderSection =
      sliderItems.length > 0
        ? `<section class="slider-section" aria-label="গ্রামের দৃশ্য ও বিশেষ মুহূর্ত">
            <div class="container">
              <div class="slider-header">
                <h2 class="slider-heading">গ্রামের পথে-প্রান্তরে</h2>
                <div class="slider-controls">
                  <button type="button" class="slider-arrow" id="hscroll-prev" aria-label="বামে সরান">‹</button>
                  <button type="button" class="slider-arrow" id="hscroll-next" aria-label="ডানে সরান">›</button>
                </div>
              </div>
              <div class="hscroll-viewport" id="hscroll-viewport">
                <div class="hscroll-track">
                  ${sliderItems
                    .map((item) => {
                      const catSlug = item.category_slug || 'all';
                      return `
                        <a href="/gallery.php?category=${e(encodeURIComponent(catSlug))}" class="hscroll-card">
                          <div class="hscroll-thumb">
                            <img src="${e(safeUrl(item.image_url))}" alt="${e(item.title || item.category_name || 'Village moment')}" loading="lazy" referrerpolicy="no-referrer">
                          </div>
                          <div class="hscroll-body">
                            ${item.title ? `<p class="hscroll-title">${e(item.title)}</p>` : ''}
                            ${item.category_name ? `<span class="hscroll-cat">${e(item.category_name)}</span>` : ''}
                          </div>
                        </a>`;
                    })
                    .join('')}
                </div>
              </div>
            </div>
          </section>`
        : '';

    const postsSection = `
      <section class="section" id="posts-section">
        <div class="container">
          <div class="section-header">
            <div>
              <h2 class="section-title">গ্রামের খবর ও পোস্ট</h2>
              <p class="section-subtitle">স্থানীয় ঘোষণা, আয়োজন, গল্প এবং এলাকার ভিডিও।</p>
            </div>
            <a href="/posts.php" class="section-link">সব পোস্ট দেখুন →</a>
          </div>
          ${
            recentPosts.length === 0
              ? '<div class="empty-state">এখনো কোনো পোস্ট প্রকাশ করা হয়নি।</div>'
              : `<div class="posts-grid">
                  ${recentPosts
                    .map((post) => {
                      const mediaHtml =
                        post.post_type === 'image' && post.media_url
                          ? `<a href="/post/${e(encodeURIComponent(post.slug))}" class="post-media"><img src="${e(
                              safeUrl(post.media_url)
                            )}" alt="${e(post.title || 'Community post image')}" loading="lazy" referrerpolicy="no-referrer"></a>`
                          : post.post_type === 'video' && post.media_url
                            ? `<div class="post-media"><video src="${e(
                                safeUrl(post.media_url)
                              )}" preload="metadata" controls playsinline></video><span class="post-video-badge">ভিডিও</span></div>`
                            : '';
                      return `
                        <article class="post-card">
                          ${mediaHtml}
                          <div class="post-body">
                            <div class="post-meta">
                              ${post.category_name ? `<span>${e(post.category_name)}</span><span aria-hidden="true">·</span>` : ''}
                              <time datetime="${e(post.published_at || post.created_at)}">${e(
                                formatDate(post.published_at || post.created_at)
                              )}</time>
                            </div>
                            ${
                              post.title
                                ? `<h3 class="post-title"><a href="/post/${e(encodeURIComponent(post.slug))}">${e(post.title)}</a></h3>`
                                : ''
                            }
                            <p class="post-excerpt">${e(post.description)}</p>
                            <a href="/post/${e(encodeURIComponent(post.slug))}" class="post-read-link">বিস্তারিত পড়ুন →</a>
                          </div>
                        </article>`;
                    })
                    .join('')}
                </div>`
          }
        </div>
      </section>`;

    const gallerySection = `
      <section class="section section-tinted" id="gallery-section">
        <div class="container">
          <div class="section-header">
            <div>
              <h2 class="section-title">Village ছবির গ্যালারি</h2>
              <p class="section-subtitle">যেকোনো ছবিতে ক্লিক করে বড় করে দেখুন এবং জুম বা সোয়াইপ করে ছবি বদলান।</p>
            </div>
            <a href="/gallery.php" class="section-link">সব ছবি দেখুন →</a>
          </div>

          <div class="category-bar" id="gallery-category-bar" data-active-category="${e(initialCat)}" role="tablist" aria-label="বিভাগ অনুযায়ী ছবি বাছাই করুন">
            <button type="button" class="cat-tab ${initialCat === 'all' ? 'is-active' : ''}" data-category="all">সব ছবি</button>
            ${galleryCategories
              .map(
                (cat) =>
                  `<button type="button" class="cat-tab ${initialCat === cat.slug ? 'is-active' : ''}" data-category="${e(cat.slug)}">${e(
                    cat.name
                  )}</button>`
              )
              .join('')}
          </div>

          <div class="ig-gallery-grid" id="ig-gallery-grid" data-page="1">
            ${
              galleryImages.length === 0
                ? '<div class="empty-state" style="grid-column: 1 / -1;">এই বিভাগে এখনো কোনো ছবি যোগ করা হয়নি।</div>'
                : galleryImages
                    .map(
                      (img) => `
                        <button
                          type="button"
                          class="ig-photo-card"
                          data-lightbox-item
                          data-src="${e(safeUrl(img.image_url))}"
                          data-title="${e(img.title ?? '')}"
                          data-desc="${e(img.description ?? '')}"
                          data-category="${e(img.category_name ?? '')}"
                          data-date="${e(formatDate(img.uploaded_at))}"
                          aria-label="${e(img.title || 'গ্রামের ছবি দেখুন')}"
                        >
                          <img src="${e(safeUrl(img.image_url))}" alt="${e(img.title || 'Village community photograph')}" loading="lazy" referrerpolicy="no-referrer">
                          <span class="ig-photo-overlay">
                            ${img.title ? `<span class="ig-photo-caption">${e(img.title)}</span>` : ''}
                          </span>
                        </button>`
                    )
                    .join('')
            }
          </div>

          <div class="gallery-actions">
            <button type="button" class="btn-primary" id="gallery-load-more" ${hasMoreGallery ? '' : 'hidden'}>
              Load More Photos
            </button>
          </div>
        </div>
      </section>`;

    const featuredSection =
      featuredImages.length > 0
        ? `<section class="section">
            <div class="container">
              <div class="section-header">
                <div>
                  <h2 class="section-title">স্মরণীয় মুহূর্ত</h2>
                  <p class="section-subtitle">গ্রামের ঐতিহ্য ও মানুষের জীবনযাত্রার নির্বাচিত কিছু ছবি।</p>
                </div>
              </div>
              <div class="featured-grid">
                ${featuredImages
                  .map(
                    (feat) => `
                      <figure class="featured-card" style="margin: 0;">
                        <button
                          type="button"
                          class="featured-frame"
                          data-lightbox-item
                          data-src="${e(safeUrl(feat.image_url))}"
                          data-title="${e(feat.title ?? '')}"
                          data-desc="${e(feat.description ?? '')}"
                          data-category="${e(feat.category_name ?? '')}"
                          data-date="${e(formatDate(feat.uploaded_at))}"
                          aria-label="${e(feat.title || 'বিশেষ ছবিটি দেখুন')}"
                        >
                          <img src="${e(safeUrl(feat.image_url))}" alt="${e(feat.title || 'Featured village photograph')}" loading="lazy" referrerpolicy="no-referrer">
                        </button>
                        ${
                          feat.title || feat.category_name
                            ? `<figcaption>
                                ${feat.title ? `<p class="featured-title">${e(feat.title)}</p>` : ''}
                                ${feat.category_name ? `<span class="featured-cat">${e(feat.category_name)}</span>` : ''}
                              </figcaption>`
                            : ''
                        }
                      </figure>`
                  )
                  .join('')}
              </div>
            </div>
          </section>`
        : '';

    const html =
      renderPublicHeader() +
      bannersSection +
      sliderSection +
      postsSection +
      gallerySection +
      featuredSection +
      renderPublicFooter('/');

    res.type('html').send(html);
  });

  // Posts archive page
  app.get('/posts.php', (req: Request, res: Response) => {
    const rl = checkRateLimit('public_api', { ip: clientIp(req) });
    if (!rl.allowed) {
      applyRateLimitHeaders(res, rl);
      res.type('html').send(
        renderPublicHeader({ customPageTitle: 'অনেকগুলো অনুরোধ' }) +
          `<div class="container section"><div class="empty-state"><h1>অনুগ্রহ করে কিছুক্ষণ অপেক্ষা করুন</h1><p>${e(rl.retry_message)}</p></div></div>` +
          renderPublicFooter('/posts.php')
      );
      return;
    }

    const categories = store.post_categories
      .filter((c) => c.is_active)
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    const catById = getCategoryMap(store.post_categories);

    const catSlug = String(req.query.category ?? 'all').trim() || 'all';
    const postType = String(req.query.type ?? 'all').trim() || 'all';
    const page = Math.max(1, Number.parseInt(String(req.query.page ?? '1'), 10) || 1);
    const perPage = 9;
    const offset = (page - 1) * perPage;

    const filtered = store.posts
      .filter((p) => {
        if (p.status !== 'published') return false;
        if (catSlug !== '' && catSlug !== 'all') {
          const cat = p.category_id ? catById.get(p.category_id) : null;
          if (!cat || cat.slug !== catSlug) return false;
        }
        if (['text', 'image', 'video', 'custom'].includes(postType)) {
          if (p.post_type !== postType) return false;
        }
        return true;
      })
      .sort((a, b) => (b.published_at || b.created_at).localeCompare(a.published_at || a.created_at));

    const totalPosts = filtered.length;
    const totalPages = Math.max(1, Math.ceil(totalPosts / perPage));
    const posts = filtered.slice(offset, offset + perPage).map((p) => ({
      ...p,
      category_name: p.category_id ? catById.get(p.category_id)?.name ?? null : null,
      category_slug: p.category_id ? catById.get(p.category_id)?.slug ?? null : null,
    }));

    const typeLabels: Record<string, string> = {
      all: 'সব ধরন',
      text: 'লেখা',
      image: 'ছবি',
      video: 'ভিডিও',
      custom: 'কাস্টম কোড',
    };

    const body = `
      <section class="section">
        <div class="container">
          <div class="section-header">
            <div>
              <h1 class="section-title">গ্রামের খবর ও পোস্ট</h1>
              <p class="section-subtitle">আমাদের গ্রামের গল্প, ঘোষণা, আয়োজনের খবর এবং ভিডিও।</p>
            </div>
          </div>

          <div class="category-bar" aria-label="বিভাগ অনুযায়ী পোস্ট বাছাই করুন">
            <a href="/posts.php?category=all&amp;type=${e(encodeURIComponent(postType))}" class="cat-tab ${catSlug === 'all' ? 'is-active' : ''}">সব বিভাগ</a>
            ${categories
              .map(
                (cat) =>
                  `<a href="/posts.php?category=${e(encodeURIComponent(cat.slug))}&amp;type=${e(encodeURIComponent(postType))}" class="cat-tab ${
                    catSlug === cat.slug ? 'is-active' : ''
                  }">${e(cat.name)}</a>`
              )
              .join('')}
          </div>

          <div class="category-bar" aria-label="পোস্টের ধরন অনুযায়ী বাছাই করুন" style="margin-top: -12px;">
            ${Object.entries(typeLabels)
              .map(
                ([key, label]) =>
                  `<a href="/posts.php?category=${e(encodeURIComponent(catSlug))}&amp;type=${e(key)}" class="cat-tab ${
                    postType === key ? 'is-active' : ''
                  }">${e(label)}</a>`
              )
              .join('')}
          </div>

          ${
            posts.length === 0
              ? '<div class="empty-state">আপনার নির্বাচিত বিভাগে কোনো পোস্ট পাওয়া যায়নি।</div>'
              : `<div class="posts-grid">
                  ${posts
                    .map((post) => {
                      const mediaHtml =
                        post.post_type === 'image' && post.media_url
                          ? `<a href="/post/${e(encodeURIComponent(post.slug))}" class="post-media"><img src="${e(
                              safeUrl(post.media_url)
                            )}" alt="${e(post.title || 'পোস্টের ছবি')}" loading="lazy" referrerpolicy="no-referrer"></a>`
                          : post.post_type === 'video' && post.media_url
                            ? `<div class="post-media"><video src="${e(
                                safeUrl(post.media_url)
                              )}" preload="metadata" controls playsinline></video><span class="post-video-badge">ভিডিও</span></div>`
                            : '';
                      return `
                        <article class="post-card">
                          ${mediaHtml}
                          <div class="post-body">
                            <div class="post-meta">
                              ${post.category_name ? `<span>${e(post.category_name)}</span><span aria-hidden="true">·</span>` : ''}
                              <time datetime="${e(post.published_at || post.created_at)}">${e(
                                formatDate(post.published_at || post.created_at)
                              )}</time>
                            </div>
                            ${
                              post.title
                                ? `<h2 class="post-title"><a href="/post/${e(encodeURIComponent(post.slug))}">${e(post.title)}</a></h2>`
                                : ''
                            }
                            <p class="post-excerpt">${e(post.description)}</p>
                            <a href="/post/${e(encodeURIComponent(post.slug))}" class="post-read-link">বিস্তারিত পড়ুন →</a>
                          </div>
                        </article>`;
                    })
                    .join('')}
                </div>
                ${
                  totalPages > 1
                    ? `<nav class="gallery-actions" aria-label="পোস্টের পৃষ্ঠা" style="gap: 8px;">
                        ${
                          page > 1
                            ? `<a class="btn-secondary" href="/posts.php?category=${e(encodeURIComponent(catSlug))}&amp;type=${e(
                                encodeURIComponent(postType)
                              )}&amp;page=${page - 1}">← আগের পৃষ্ঠা</a>`
                            : ''
                        }
                        <span style="padding: 10px 14px; font-size: 0.9rem; color: var(--text-muted);">Page ${page} of ${totalPages}</span>
                        ${
                          page < totalPages
                            ? `<a class="btn-secondary" href="/posts.php?category=${e(encodeURIComponent(catSlug))}&amp;type=${e(
                                encodeURIComponent(postType)
                              )}&amp;page=${page + 1}">পরের পৃষ্ঠা</a>`
                            : ''
                        }
                      </nav>`
                    : ''
                }`
          }
        </div>
      </section>`;

    res
      .type('html')
      .send(renderPublicHeader({ customPageTitle: 'গ্রামের খবর ও পোস্ট' }) + body + renderPublicFooter('/posts.php'));
  });

  // Single post handler (/post.php and /post/:slug)
  const handlePostDetail = (req: Request, res: Response) => {
    const slug = String(req.params.slug ?? req.query.slug ?? '')
      .toLowerCase()
      .trim();
    const id = Math.max(0, Number.parseInt(String(req.query.id ?? '0'), 10) || 0);

    if (slug === '' && id <= 0) {
      res.redirect(302, '/posts.php');
      return;
    }

    const post = store.posts.find((p) => {
      if (p.status !== 'published') return false;
      return slug !== '' ? p.slug === slug : p.id === id;
    });

    if (!post) {
      res
        .status(404)
        .type('html')
        .send(
          renderPublicHeader({ customPageTitle: 'পোস্টটি পাওয়া যায়নি' }) +
            '<div class="container section"><div class="empty-state"><h1>পোস্টটি পাওয়া যায়নি</h1><p>পোস্টটি হয়তো অপ্রকাশিত করা হয়েছে অথবা সরিয়ে ফেলা হয়েছে।</p><p><a class="btn-primary" href="/posts.php">পোস্টে ফিরে যান</a></p></div></div>' +
            renderPublicFooter(req.path)
        );
      return;
    }

    const category = post.category_id ? store.post_categories.find((c) => c.id === post.category_id) : null;
    const customPageTitle = post.title || 'গ্রামের খবর';
    const customPageDesc = String(post.description || '').trim().slice(0, 155);

    let mediaOrCustomHtml = '';
    if (post.post_type === 'custom') {
      mediaOrCustomHtml = `
        <iframe
          class="custom-content-frame"
          data-auto-resize="true"
          src="/content-frame.php?type=post&amp;id=${post.id}"
          title="${e(post.title || 'কমিউনিটির পোস্ট')}"
          sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"
          scrolling="no"
          loading="eager"
          referrerpolicy="no-referrer"
          id="published-post-content"
        ></iframe>`;
    } else if (post.post_type === 'image' && post.media_url) {
      mediaOrCustomHtml = `
        <div class="post-detail-media">
          <img src="${e(safeUrl(post.media_url))}" alt="${e(post.title || 'পোস্টের ছবি')}" referrerpolicy="no-referrer">
        </div>`;
    } else if (post.post_type === 'video' && post.media_url) {
      mediaOrCustomHtml = `
        <div class="post-detail-media">
          <video src="${e(safeUrl(post.media_url))}" controls playsinline preload="metadata"></video>
        </div>`;
    }

    const contentHtml =
      post.post_type !== 'custom'
        ? `<div class="post-detail-content">${
            post.content_html ? post.content_html : e(post.description).replace(/\r?\n/g, '<br>\n')
          }</div>`
        : '';

    const body = `
      <article class="post-detail-wrap">
        <p style="margin: 0 0 18px;">
          <a href="/posts.php" class="section-link">← Back to গ্রামের খবর ও পোস্ট</a>
        </p>
        <div class="post-meta" style="margin-bottom: 10px;">
          ${category ? `<span>${e(category.name)}</span><span aria-hidden="true">·</span>` : ''}
          <time datetime="${e(post.published_at || post.created_at)}">${e(
            formatDate(post.published_at || post.created_at, 'd/m/Y')
          )}</time>
        </div>
        ${post.title ? `<h1 class="section-title" style="margin-bottom: 14px;">${e(post.title)}</h1>` : ''}
        ${mediaOrCustomHtml}
        ${contentHtml}
      </article>`;

    res
      .type('html')
      .send(renderPublicHeader({ customPageTitle, customPageDesc }) + body + renderPublicFooter(req.path));
  };

  app.get('/post.php', handlePostDetail);
  app.get('/post/:slug', handlePostDetail);

  // Gallery archive page
  app.get('/gallery.php', (req: Request, res: Response) => {
    const categories = store.gallery_categories
      .filter((c) => c.is_active)
      .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    const catById = getCategoryMap(store.gallery_categories);

    const initialCat = String(req.query.category ?? 'all').trim() || 'all';
    const perPage = 16;

    const rows = store.gallery_images
      .filter((gi) => {
        if (gi.status !== 'published') return false;
        if (initialCat !== '' && initialCat !== 'all') {
          const cat = gi.category_id ? catById.get(gi.category_id) : null;
          return cat?.slug === initialCat;
        }
        return true;
      })
      .sort((a, b) => a.sort_order - b.sort_order || b.uploaded_at.localeCompare(a.uploaded_at))
      .map((gi) => ({
        ...gi,
        category_name: gi.category_id ? catById.get(gi.category_id)?.name ?? null : null,
      }));

    const hasMore = rows.length > perPage;
    const images = rows.slice(0, perPage);

    const body = `
      <section class="section">
        <div class="container">
          <div class="section-header">
            <div>
              <h1 class="section-title">Village ছবির গ্যালারি</h1>
              <p class="section-subtitle">বিভাগ অনুযায়ী গ্রামের স্মৃতির ছবি দেখুন। যেকোনো ছবিতে ক্লিক করে বড় করুন অথবা সোয়াইপ করে অন্য ছবি দেখুন।</p>
            </div>
          </div>

          <div class="category-bar" id="gallery-category-bar" data-active-category="${e(initialCat)}" role="tablist" aria-label="বিভাগ অনুযায়ী ছবি বাছাই করুন">
            <button type="button" class="cat-tab ${initialCat === 'all' ? 'is-active' : ''}" data-category="all">সব ছবি</button>
            ${categories
              .map(
                (cat) =>
                  `<button type="button" class="cat-tab ${initialCat === cat.slug ? 'is-active' : ''}" data-category="${e(
                    cat.slug
                  )}">${e(cat.name)}</button>`
              )
              .join('')}
          </div>

          <div class="ig-gallery-grid" id="ig-gallery-grid" data-page="1">
            ${
              images.length === 0
                ? '<div class="empty-state" style="grid-column: 1 / -1;">এই বিভাগে এখনো কোনো ছবি নেই।</div>'
                : images
                    .map(
                      (img) => `
                        <button
                          type="button"
                          class="ig-photo-card"
                          data-lightbox-item
                          data-src="${e(safeUrl(img.image_url))}"
                          data-title="${e(img.title ?? '')}"
                          data-desc="${e(img.description ?? '')}"
                          data-category="${e(img.category_name ?? '')}"
                          data-date="${e(formatDate(img.uploaded_at))}"
                          aria-label="${e(img.title || 'গ্রামের ছবি দেখুন')}"
                        >
                          <img src="${e(safeUrl(img.image_url))}" alt="${e(img.title || 'Community photograph')}" loading="lazy" referrerpolicy="no-referrer">
                          <span class="ig-photo-overlay">
                            ${img.title ? `<span class="ig-photo-caption">${e(img.title)}</span>` : ''}
                          </span>
                        </button>`
                    )
                    .join('')
            }
          </div>

          <div class="gallery-actions">
            <button type="button" class="btn-primary" id="gallery-load-more" ${hasMore ? '' : 'hidden'}>
              Load More Photos
            </button>
          </div>
        </div>
      </section>`;

    res
      .type('html')
      .send(renderPublicHeader({ customPageTitle: 'ছবির গ্যালারি' }) + body + renderPublicFooter('/gallery.php'));
  });

  // Gallery JSON API
  app.get('/api-gallery.php', (req: Request, res: Response) => {
    const rl = checkRateLimit('gallery_api', { ip: clientIp(req) });
    if (!rl.allowed) {
      applyRateLimitHeaders(res, rl);
      res.json({ error: rl.retry_message });
      return;
    }

    const category = String(req.query.category ?? 'all').trim() || 'all';
    const page = Math.max(1, Number.parseInt(String(req.query.page ?? '1'), 10) || 1);
    const perPage = 8;
    const offset = (page - 1) * perPage;

    const catById = getCategoryMap(store.gallery_categories);
    const rows = store.gallery_images
      .filter((gi) => {
        if (gi.status !== 'published') return false;
        if (category !== '' && category !== 'all') {
          const cat = gi.category_id ? catById.get(gi.category_id) : null;
          return cat?.slug === category;
        }
        return true;
      })
      .sort((a, b) => a.sort_order - b.sort_order || b.uploaded_at.localeCompare(a.uploaded_at));

    const slice = rows.slice(offset, offset + perPage + 1);
    const hasMore = slice.length > perPage;
    const items = slice.slice(0, perPage).map((gi) => ({
      id: gi.id,
      title: gi.title,
      description: gi.description,
      image_url: safeUrl(gi.image_url, ''),
      uploaded_at: gi.uploaded_at,
      category_name: gi.category_id ? catById.get(gi.category_id)?.name ?? null : null,
      formatted_date: formatDate(gi.uploaded_at),
    }));

    res.json({ items, has_more: hasMore, page });
  });

  // Posts JSON API
  app.get('/api-posts.php', (req: Request, res: Response) => {
    const rl = checkRateLimit('public_api', { ip: clientIp(req) });
    if (!rl.allowed) {
      applyRateLimitHeaders(res, rl);
      res.json({ error: rl.retry_message });
      return;
    }

    const category = String(req.query.category ?? 'all').trim() || 'all';
    const type = String(req.query.type ?? 'all').trim() || 'all';
    const page = Math.max(1, Number.parseInt(String(req.query.page ?? '1'), 10) || 1);
    const perPage = 9;
    const offset = (page - 1) * perPage;

    const catById = getCategoryMap(store.post_categories);
    const rows = store.posts
      .filter((p) => {
        if (p.status !== 'published') return false;
        if (category !== '' && category !== 'all') {
          const cat = p.category_id ? catById.get(p.category_id) : null;
          if (!cat || cat.slug !== category) return false;
        }
        if (['text', 'image', 'video'].includes(type) && p.post_type !== type) {
          return false;
        }
        return true;
      })
      .sort((a, b) => (b.published_at || b.created_at).localeCompare(a.published_at || a.created_at));

    const slice = rows.slice(offset, offset + perPage + 1);
    const hasMore = slice.length > perPage;
    const items = slice.slice(0, perPage).map((p) => ({
      id: p.id,
      post_type: p.post_type,
      title: p.title,
      slug: p.slug,
      description: p.description,
      media_url: p.media_url ? safeUrl(p.media_url, '') : null,
      published_at: p.published_at,
      created_at: p.created_at,
      category_name: p.category_id ? catById.get(p.category_id)?.name ?? null : null,
      category_slug: p.category_id ? catById.get(p.category_id)?.slug ?? null : null,
      formatted_date: formatDate(p.published_at || p.created_at),
    }));

    res.json({ items, has_more: hasMore, page });
  });

  // Sandboxed content iframe for custom code posts and custom pages
  app.get('/content-frame.php', (req: Request, res: Response) => {
    res.setHeader(
      'Content-Security-Policy',
      "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: blob: http: https:; media-src 'self' data: blob: http: https:; font-src 'self' data: https://fonts.gstatic.com; connect-src 'none'; form-action 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'self'; worker-src 'none'; manifest-src 'none'"
    );
    res.setHeader('Cache-Control', 'no-store, max-age=0');
    res.setHeader('Referrer-Policy', 'no-referrer');

    const type = String(req.query.type ?? '');
    const id = Math.max(0, Number.parseInt(String(req.query.id ?? '0'), 10) || 0);
    if (id < 1 || (type !== 'page' && type !== 'post')) {
      res.status(404).type('text').send('Content not found.');
      return;
    }

    const content =
      type === 'page'
        ? store.content_pages.find((pg) => pg.id === id && pg.status === 'published')
        : store.posts.find((p) => p.id === id && p.post_type === 'custom' && p.status === 'published');

    if (!content) {
      res.status(404).type('text').send('Content not found.');
      return;
    }

    const layoutFix = `<style id="fakir-frame-layout-fix">
html, body {
  width: 100% !important;
  height: auto !important;
  min-height: 0 !important;
  max-height: none !important;
  overflow: visible !important;
  overflow-x: hidden !important;
  overflow-y: visible !important;
  margin: 0 !important;
}
body {
  padding-bottom: 1px !important;
}
#__fakir_height_end__ {
  display: block !important;
  clear: both !important;
  height: 1px !important;
  min-height: 0 !important;
  margin: 0 !important;
  padding: 0 !important;
  border: 0 !important;
  visibility: hidden !important;
  pointer-events: none !important;
}
</style>`;

    const reporter = `<div id="__fakir_height_end__" aria-hidden="true"></div>
<script>
(() => {
  let lastHeight = 0;
  let prevInnerHeight = window.innerHeight || 0;
  let lastSentTime = 0;
  let mutationSeq = 0;
  let lastMutationSeq = 0;
  let vhLoopCount = 0;
  let scheduled = false;
  let resizeObserver = null;
  const measure = (force) => {
    const root = document.documentElement;
    const body = document.body;
    if (!root || !body) return;
    let contentBottom = 0;
    const endNode = document.getElementById('__fakir_height_end__');
    if (endNode) {
      const endRect = endNode.getBoundingClientRect();
      contentBottom = Math.max(contentBottom, endRect.bottom + window.scrollY);
    }
    for (const element of Array.from(body.children)) {
      if (element.id === '__fakir_height_end__' || element.tagName === 'SCRIPT' || element.tagName === 'STYLE') continue;
      const cs = window.getComputedStyle(element);
      if (cs.display === 'none' || cs.position === 'fixed') continue;
      const rect = element.getBoundingClientRect();
      const mb = parseFloat(cs.marginBottom) || 0;
      contentBottom = Math.max(contentBottom, rect.bottom + window.scrollY + Math.max(0, mb));
    }
    const bodyStyle = window.getComputedStyle(body);
    const bodyPb = parseFloat(bodyStyle.paddingBottom) || 0;
    const height = Math.ceil(Math.max(240, contentBottom + bodyPb));
    const curInnerHeight = window.innerHeight || 0;
    if (
      !force &&
      lastHeight > 0 &&
      mutationSeq === lastMutationSeq &&
      Date.now() - lastSentTime < 500 &&
      curInnerHeight > prevInnerHeight + 15 &&
      height > lastHeight + 15 &&
      Math.abs((height - lastHeight) - (curInnerHeight - prevInnerHeight)) < 40
    ) {
      vhLoopCount += 1;
      prevInnerHeight = curInnerHeight;
      if (vhLoopCount >= 2) return;
    } else if (mutationSeq !== lastMutationSeq || Math.abs(curInnerHeight - prevInnerHeight) <= 15) {
      vhLoopCount = 0;
    }
    prevInnerHeight = curInnerHeight;
    lastMutationSeq = mutationSeq;
    if (height > 0 && (force || Math.abs(height - lastHeight) > 1)) {
      lastHeight = height;
      lastSentTime = Date.now();
      parent.postMessage({ type: 'fakir:content-height', height }, '*');
    }
  };
  const report = (force) => {
    if (scheduled && !force) return;
    scheduled = true;
    requestAnimationFrame(() => { scheduled = false; measure(Boolean(force)); });
  };
  const attachMediaListeners = (scope) => {
    (scope || document).querySelectorAll('img, video, iframe').forEach((element) => {
      if (element.dataset.fakirBound) return;
      element.dataset.fakirBound = '1';
      ['load', 'loadedmetadata', 'error'].forEach((eventName) => element.addEventListener(eventName, () => { mutationSeq += 1; report(false); }));
      if (element.tagName === 'IMG' && element.complete) report(false);
    });
  };
  const observeChildren = () => {
    if (!resizeObserver || !document.body) return;
    document.querySelectorAll('body > *').forEach((element) => {
      if (element.id !== '__fakir_height_end__') {
        try { resizeObserver.observe(element); } catch (_) {}
      }
    });
  };
  const start = () => {
    report(true);
    if ('ResizeObserver' in window) {
      resizeObserver = new ResizeObserver(() => report(false));
      if (document.body) resizeObserver.observe(document.body);
      observeChildren();
    }
    if ('MutationObserver' in window) {
      const observer = new MutationObserver(() => {
        mutationSeq += 1;
        attachMediaListeners(document);
        observeChildren();
        report(false);
      });
      observer.observe(document.documentElement, { childList: true, subtree: true, attributes: true, characterData: true });
    }
    attachMediaListeners(document);
    window.addEventListener('load', () => { mutationSeq += 1; report(true); });
    window.addEventListener('resize', () => report(false));
    window.addEventListener('message', (event) => {
      if (event.data && event.data.type === 'fakir:request-height') {
        report(true);
      }
    });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { mutationSeq += 1; report(false); }).catch(() => {});
    [0, 60, 180, 500, 1200, 2500].forEach((delay) => window.setTimeout(() => report(false), delay));
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
</script>`;

    let codeDoc = String(content.code_document ?? '');
    if (codeDoc !== '') {
      if (/<\/head>/i.test(codeDoc)) {
        codeDoc = codeDoc.replace(/<\/head>/i, `${layoutFix}</head>`);
      } else {
        codeDoc = layoutFix + codeDoc;
      }
      if (/<\/body>/i.test(codeDoc)) {
        codeDoc = codeDoc.replace(/<\/body>/i, `${reporter}</body>`);
      } else if (/<\/html>/i.test(codeDoc)) {
        codeDoc = codeDoc.replace(/<\/html>/i, `${reporter}</html>`);
      } else {
        codeDoc += reporter;
      }
      res.type('html').send(codeDoc);
      return;
    }

    const title = e(content.title || 'Custom Content');
    const css = String(content.css_content ?? '');
    const htmlPart = String(content.html_content ?? '');
    const js = String(content.js_content ?? '').replace(/<\/script/gi, '<\\/script');

    res.type('html').send(`<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>${title}</title>
<style>${css}</style>
${layoutFix}
</head>
<body>
${htmlPart}
${js !== '' ? `<script>${js}</script>` : ''}
${reporter}
</body>
</html>`);
  });

  // Direct /page.php?slug=... access
  app.get('/page.php', (req: Request, res: Response) => {
    renderPublishedPageBySlug(String(req.query.slug ?? ''), req, res);
  });
}

function renderPublishedPageBySlug(rawSlug: string, req: Request, res: Response): void {
  const slug = rawSlug.toLowerCase().trim();
  if (slug === '' || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
    res
      .status(404)
      .type('html')
      .send(
        renderPublicHeader({ customPageTitle: 'পেজটি পাওয়া যায়নি' }) +
          '<section class="container section"><div class="empty-state"><h1>পেজটি পাওয়া যায়নি</h1><p>লিংকটি সঠিক নয় অথবা পেজটি আর প্রকাশিত নেই।</p><p><a class="btn-primary" href="/">মূল পাতায় ফিরে যান</a></p></div></section>' +
          renderPublicFooter(req.path)
      );
    return;
  }

  const page = store.content_pages.find((pg) => pg.slug === slug && pg.status === 'published');
  if (!page) {
    res
      .status(404)
      .type('html')
      .send(
        renderPublicHeader({ customPageTitle: 'পেজটি পাওয়া যায়নি' }) +
          '<section class="container section"><div class="empty-state"><h1>পেজটি পাওয়া যায়নি</h1><p>পেজটি প্রকাশিত নয়, সরিয়ে ফেলা হয়েছে অথবা লিংকটি ভুল।</p><p><a class="btn-primary" href="/">মূল পাতায় ফিরে যান</a></p></div></section>' +
          renderPublicFooter(req.path)
      );
    return;
  }

  const body = `
<section class="published-custom-page">
  <div class="container">
    <h1 class="published-page-heading">${e(page.title)}</h1>
    <iframe
      id="published-page-content"
      class="custom-content-frame"
      data-auto-resize="true"
      src="/content-frame.php?type=page&amp;id=${page.id}"
      title="${e(page.title)}"
      sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"
      loading="eager"
      scrolling="no"
      referrerpolicy="no-referrer"
    ></iframe>
  </div>
</section>`;

  res
    .type('html')
    .send(
      renderPublicHeader({
        customPageTitle: page.title,
        customPageDesc: String(page.meta_description ?? '').trim(),
      }) +
        body +
        renderPublicFooter(req.path)
    );
}

export function handleCustomPageRoute(req: Request, res: Response, next: NextFunction): void {
  const m = /^\/([a-z0-9]+(?:-[a-z0-9]+)*)\/?$/i.exec(req.path);
  if (!m) {
    next();
    return;
  }
  renderPublishedPageBySlug(m[1], req, res);
}
