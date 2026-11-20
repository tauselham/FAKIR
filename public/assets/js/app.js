/**
 * Greenfield Village — Public Interactive Behaviors
 * Mobile drawer, Hero Banner slideshow, Horizontal Auto-Slider, and AJAX Gallery filtering/pagination.
 */
(() => {
  'use strict';

  function initMobileMenu() {
    const toggle = document.getElementById('mobile-menu-toggle');
    const drawer = document.getElementById('mobile-nav-drawer');
    if (!toggle || !drawer) return;

    toggle.addEventListener('click', () => {
      const isHidden = drawer.hidden;
      drawer.hidden = !isHidden;
      toggle.setAttribute('aria-expanded', String(isHidden));
    });

    drawer.querySelectorAll('.mobile-submenu-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const sub = btn.closest('.mobile-nav-item')?.querySelector('.mobile-sublist');
        if (!sub) return;
        const open = sub.hidden;
        sub.hidden = !open;
        btn.setAttribute('aria-expanded', String(open));
        btn.textContent = open ? '−' : '+';
      });
    });

    drawer.querySelectorAll('[data-mobile-parent-toggle]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const subBtn = btn.closest('.mobile-nav-row')?.querySelector('.mobile-submenu-btn');
        if (subBtn) subBtn.click();
      });
    });
  }

  function initCleanInPageNavigation() {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const scrollBehavior = reducedMotion ? 'auto' : 'smooth';
    const drawer = document.getElementById('mobile-nav-drawer');
    const toggle = document.getElementById('mobile-menu-toggle');

    const closeDrawer = () => {
      if (drawer && !drawer.hidden) {
        drawer.hidden = true;
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
      }
    };

    document.addEventListener('click', (e) => {
      const link = e.target.closest('a[href^="#"]');
      if (link) {
        const rawHref = link.getAttribute('href') || '';
        const targetId = rawHref.slice(1);
        if (!targetId) {
          e.preventDefault();
          return;
        }
        const target = document.getElementById(targetId);
        if (!target) return;

        e.preventDefault();
        closeDrawer();
        target.scrollIntoView({
          behavior: link.classList.contains('skip-link') ? 'auto' : scrollBehavior,
          block: 'start',
        });
        if (link.classList.contains('skip-link')) {
          if (!target.hasAttribute('tabindex')) {
            target.setAttribute('tabindex', '-1');
          }
          target.focus({ preventScroll: true });
        }
        return;
      }

      const homeLink = e.target.closest('a[href="/"]');
      if (homeLink && (window.location.pathname === '/' || window.location.pathname === '/index.html')) {
        if (window.location.search === '' && window.location.hash === '') {
          e.preventDefault();
          closeDrawer();
          window.scrollTo({ top: 0, behavior: scrollBehavior });
        }
      }
    });
  }

  function initHeroSlider() {
    const hero = document.getElementById('hero-slider');
    if (!hero) return;

    const track = hero.querySelector('.hero-track');
    const slides = Array.from(hero.querySelectorAll('.hero-slide'));
    if (!track || slides.length <= 1) return;

    const prevBtn = hero.querySelector('.hero-prev');
    const nextBtn = hero.querySelector('.hero-next');
    const dots = Array.from(hero.querySelectorAll('.hero-dot'));
    const intervalMs = Math.max(2000, parseInt(hero.dataset.interval || '5500', 10));

    let index = 0;
    let timer = null;

    const goTo = (nextIndex) => {
      index = (nextIndex + slides.length) % slides.length;
      track.style.transform = `translate3d(-${index * 100}%, 0, 0)`;
      dots.forEach((dot, i) => dot.classList.toggle('is-active', i === index));
    };

    const startAuto = () => {
      stopAuto();
      timer = window.setInterval(() => goTo(index + 1), intervalMs);
    };

    const stopAuto = () => {
      if (timer) {
        window.clearInterval(timer);
        timer = null;
      }
    };

    if (prevBtn) prevBtn.addEventListener('click', () => { goTo(index - 1); startAuto(); });
    if (nextBtn) nextBtn.addEventListener('click', () => { goTo(index + 1); startAuto(); });
    dots.forEach((dot, i) => dot.addEventListener('click', () => { goTo(i); startAuto(); }));

    hero.addEventListener('mouseenter', stopAuto);
    hero.addEventListener('mouseleave', startAuto);
    hero.addEventListener('focusin', stopAuto);
    hero.addEventListener('focusout', startAuto);

    // Touch swipe on hero
    let startX = 0;
    hero.addEventListener('touchstart', (e) => {
      if (e.touches.length === 1) startX = e.touches[0].clientX;
      stopAuto();
    }, { passive: true });
    hero.addEventListener('touchend', (e) => {
      if (e.changedTouches.length === 1) {
        const dx = e.changedTouches[0].clientX - startX;
        if (Math.abs(dx) > 45) goTo(dx > 0 ? index - 1 : index + 1);
      }
      startAuto();
    }, { passive: true });

    startAuto();
  }

  function initHorizontalSlider() {
    const viewport = document.getElementById('hscroll-viewport');
    if (!viewport) return;

    const prevBtn = document.getElementById('hscroll-prev');
    const nextBtn = document.getElementById('hscroll-next');
    const step = 260;

    let paused = false;
    let rafId = null;
    let resumeTimer = null;

    const pauseBriefly = () => {
      paused = true;
      window.clearTimeout(resumeTimer);
      resumeTimer = window.setTimeout(() => { paused = false; }, 3200);
    };

    if (prevBtn) {
      prevBtn.addEventListener('click', () => {
        pauseBriefly();
        viewport.scrollBy({ left: -step, behavior: 'smooth' });
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', () => {
        pauseBriefly();
        if (viewport.scrollLeft + viewport.clientWidth >= viewport.scrollWidth - 8) {
          viewport.scrollTo({ left: 0, behavior: 'smooth' });
        } else {
          viewport.scrollBy({ left: step, behavior: 'smooth' });
        }
      });
    }

    viewport.addEventListener('mouseenter', () => { paused = true; });
    viewport.addEventListener('mouseleave', () => { paused = false; });
    viewport.addEventListener('touchstart', () => { paused = true; }, { passive: true });
    viewport.addEventListener('touchend', pauseBriefly, { passive: true });

    // Gentle auto-scroll using requestAnimationFrame
    let lastTime = 0;
    const tick = (ts) => {
      if (!lastTime) lastTime = ts;
      const dt = ts - lastTime;
      lastTime = ts;

      if (!paused && viewport.scrollWidth > viewport.clientWidth + 4) {
        viewport.scrollLeft += (dt * 0.035);
        if (viewport.scrollLeft + viewport.clientWidth >= viewport.scrollWidth - 2) {
          viewport.scrollLeft = 0;
        }
      }
      rafId = window.requestAnimationFrame(tick);
    };

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reducedMotion) {
      rafId = window.requestAnimationFrame(tick);
    }

    window.addEventListener('beforeunload', () => {
      if (rafId) window.cancelAnimationFrame(rafId);
    });
  }

  function initGalleryAjax() {
    const grid = document.getElementById('ig-gallery-grid');
    const catBar = document.getElementById('gallery-category-bar');
    const loadMoreBtn = document.getElementById('gallery-load-more');
    if (!grid || !catBar || grid.dataset.staticPreview === 'true') return;

    let activeCategory = catBar.dataset.activeCategory || 'all';
    let currentPage = parseInt(grid.dataset.page || '1', 10);

    const escapeHtml = (str) => {
      const div = document.createElement('div');
      div.textContent = str || '';
      return div.innerHTML;
    };

    const renderPhotoCard = (img) => {
      const title = img.title ? `<span class="ig-photo-caption">${escapeHtml(img.title)}</span>` : '';
      return `
        <button
          type="button"
          class="ig-photo-card"
          data-lightbox-item
          data-src="${escapeHtml(img.image_url)}"
          data-title="${escapeHtml(img.title || '')}"
          data-desc="${escapeHtml(img.description || '')}"
          data-category="${escapeHtml(img.category_name || '')}"
          data-date="${escapeHtml(img.formatted_date || '')}"
          aria-label="${escapeHtml(img.title || 'গ্রামের ছবি দেখুন')}"
        >
          <img src="${escapeHtml(img.image_url)}" alt="${escapeHtml(img.title || 'গ্রামের ছবি')}" loading="lazy" referrerpolicy="no-referrer">
          ${title ? `<span class="ig-photo-overlay">${title}</span>` : '<span class="ig-photo-overlay"></span>'}
        </button>
      `;
    };

    const fetchGallery = async (category, page, append) => {
      try {
        if (loadMoreBtn) loadMoreBtn.disabled = true;
        const url = `/api-gallery.php?category=${encodeURIComponent(category)}&page=${page}`;
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          const msg = res.status === 429 && data.error
            ? data.error
            : 'এই মুহূর্তে ছবি লোড করা যাচ্ছে না। কিছুক্ষণ পর আবার চেষ্টা করুন।';
          if (!append) {
            grid.innerHTML = `<div class="empty-state" style="grid-column: 1 / -1;">${escapeHtml(msg)}</div>`;
          } else if (loadMoreBtn) {
            loadMoreBtn.textContent = msg;
          }
          if (loadMoreBtn) loadMoreBtn.disabled = false;
          return;
        }

        const html = (data.items || []).map(renderPhotoCard).join('');
        if (append) {
          grid.insertAdjacentHTML('beforeend', html);
        } else {
          grid.innerHTML = html || '<div class="empty-state" style="grid-column: 1 / -1;">এই বিভাগে এখনো কোনো ছবি পাওয়া যায়নি।</div>';
        }

        if (loadMoreBtn) {
          loadMoreBtn.textContent = 'আরও ছবি দেখুন';
          loadMoreBtn.hidden = !data.has_more;
          loadMoreBtn.disabled = false;
        }
        if (window.communityLightbox) {
          window.communityLightbox.refreshItems();
        }
      } catch (err) {
        console.error(err);
        if (loadMoreBtn) loadMoreBtn.disabled = false;
      }
    };

    catBar.addEventListener('click', (e) => {
      const tab = e.target.closest('.cat-tab');
      if (!tab) return;
      activeCategory = tab.dataset.category || 'all';
      currentPage = 1;
      catBar.querySelectorAll('.cat-tab').forEach((t) => t.classList.toggle('is-active', t === tab));
      fetchGallery(activeCategory, 1, false);
    });

    if (loadMoreBtn) {
      loadMoreBtn.addEventListener('click', () => {
        currentPage += 1;
        fetchGallery(activeCategory, currentPage, true);
      });
    }
  }

  function initCustomContentFrames() {
    const frames = Array.from(document.querySelectorAll('iframe.custom-content-frame[data-auto-resize], iframe#published-page-content, iframe#published-post-content'));
    if (!frames.length) return;

    const requestFrameHeight = (frame) => {
      try {
        if (frame.contentWindow) {
          frame.contentWindow.postMessage({ type: 'fakir:request-height' }, '*');
        }
      } catch (_) {}
    };

    window.addEventListener('message', (event) => {
      const data = event.data;
      if (!data || data.type !== 'fakir:content-height') return;
      const height = Number(data.height);
      if (!Number.isFinite(height) || height <= 0) return;

      for (const frame of frames) {
        if (event.source === frame.contentWindow) {
          const clamped = Math.max(240, Math.min(30000, Math.ceil(height)));
          frame.style.height = clamped + 'px';
          break;
        }
      }
    });

    frames.forEach((frame) => {
      frame.addEventListener('load', () => {
        requestFrameHeight(frame);
        [80, 300, 900].forEach((delay) => window.setTimeout(() => requestFrameHeight(frame), delay));
      });
      requestFrameHeight(frame);
      [100, 400, 1200].forEach((delay) => window.setTimeout(() => requestFrameHeight(frame), delay));
    });

    let resizeTimer = null;
    window.addEventListener('resize', () => {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(() => {
        frames.forEach(requestFrameHeight);
      }, 120);
    });
  }

  window.addEventListener('DOMContentLoaded', () => {
    initMobileMenu();
    initCleanInPageNavigation();
    initHeroSlider();
    initHorizontalSlider();
    initGalleryAjax();
    initCustomContentFrames();
  });
})();
