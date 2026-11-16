/**
 * Greenfield Village — Instagram-Style Photo Lightbox
 * Supports zoom-in, zoom-out, reset zoom, pan, keyboard navigation,
 * Escape close, touch swipe navigation, and scroll position restoration.
 */
(() => {
  'use strict';

  class CommunityLightbox {
    constructor() {
      this.modal = document.getElementById('gallery-lightbox');
      if (!this.modal) return;

      this.img = document.getElementById('lb-image');
      this.stage = document.getElementById('lb-stage');
      this.counter = document.getElementById('lb-counter');
      this.titleEl = document.getElementById('lb-title');
      this.descEl = document.getElementById('lb-desc');
      this.metaEl = document.getElementById('lb-meta');

      this.btnPrev = document.getElementById('lb-prev');
      this.btnNext = document.getElementById('lb-next');
      this.btnZoomIn = document.getElementById('lb-zoom-in');
      this.btnZoomOut = document.getElementById('lb-zoom-out');
      this.btnZoomReset = document.getElementById('lb-zoom-reset');

      this.items = [];
      this.currentIndex = 0;
      this.scale = 1;
      this.translateX = 0;
      this.translateY = 0;
      this.lastFocused = null;
      this.savedScrollY = 0;

      this.touchStartX = 0;
      this.touchStartY = 0;
      this.isDragging = false;
      this.dragStartX = 0;
      this.dragStartY = 0;

      this.bindEvents();
      this.refreshItems();
    }

    refreshItems() {
      this.items = Array.from(document.querySelectorAll('[data-lightbox-item]'));
    }

    bindEvents() {
      document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-lightbox-item]');
        if (!trigger) return;
        e.preventDefault();
        this.refreshItems();
        const idx = this.items.indexOf(trigger);
        if (idx >= 0) {
          this.open(idx, trigger);
        }
      });

      this.modal.querySelectorAll('[data-lb-close]').forEach((el) => {
        el.addEventListener('click', () => this.close());
      });

      if (this.btnPrev) this.btnPrev.addEventListener('click', () => this.prev());
      if (this.btnNext) this.btnNext.addEventListener('click', () => this.next());
      if (this.btnZoomIn) this.btnZoomIn.addEventListener('click', () => this.adjustZoom(0.35));
      if (this.btnZoomOut) this.btnZoomOut.addEventListener('click', () => this.adjustZoom(-0.35));
      if (this.btnZoomReset) this.btnZoomReset.addEventListener('click', () => this.resetZoom());

      // Keyboard navigation
      document.addEventListener('keydown', (e) => {
        if (this.modal.hidden) return;
        if (e.key === 'Escape') {
          e.preventDefault();
          this.close();
        } else if (e.key === 'ArrowLeft') {
          e.preventDefault();
          this.prev();
        } else if (e.key === 'ArrowRight') {
          e.preventDefault();
          this.next();
        } else if (e.key === '+' || e.key === '=') {
          e.preventDefault();
          this.adjustZoom(0.35);
        } else if (e.key === '-') {
          e.preventDefault();
          this.adjustZoom(-0.35);
        } else if (e.key === '0') {
          e.preventDefault();
          this.resetZoom();
        }
      });

      // Mouse wheel zoom
      if (this.stage) {
        this.stage.addEventListener('wheel', (e) => {
          if (this.modal.hidden) return;
          e.preventDefault();
          this.adjustZoom(e.deltaY < 0 ? 0.2 : -0.2);
        }, { passive: false });

        // Touch swipe navigation (when not zoomed in)
        this.stage.addEventListener('touchstart', (e) => {
          if (e.touches.length !== 1) return;
          this.touchStartX = e.touches[0].clientX;
          this.touchStartY = e.touches[0].clientY;
        }, { passive: true });

        this.stage.addEventListener('touchend', (e) => {
          if (this.scale > 1.05 || e.changedTouches.length !== 1) return;
          const dx = e.changedTouches[0].clientX - this.touchStartX;
          const dy = e.changedTouches[0].clientY - this.touchStartY;
          if (Math.abs(dx) > 48 && Math.abs(dx) > Math.abs(dy) * 1.3) {
            if (dx > 0) this.prev();
            else this.next();
          }
        }, { passive: true });
      }

      // Pan zoomed image with pointer
      if (this.img) {
        this.img.addEventListener('dblclick', () => {
          if (this.scale > 1) this.resetZoom();
          else this.adjustZoom(1);
        });

        this.img.addEventListener('mousedown', (e) => {
          if (this.scale <= 1) return;
          this.isDragging = true;
          this.dragStartX = e.clientX - this.translateX;
          this.dragStartY = e.clientY - this.translateY;
          this.img.style.cursor = 'grabbing';
        });

        window.addEventListener('mousemove', (e) => {
          if (!this.isDragging) return;
          this.translateX = e.clientX - this.dragStartX;
          this.translateY = e.clientY - this.dragStartY;
          this.applyTransform();
        });

        window.addEventListener('mouseup', () => {
          if (!this.isDragging) return;
          this.isDragging = false;
          this.img.style.cursor = this.scale > 1 ? 'grab' : 'default';
        });
      }
    }

    open(index, trigger) {
      this.savedScrollY = window.scrollY;
      this.lastFocused = trigger || document.activeElement;
      this.currentIndex = index;
      this.modal.hidden = false;
      document.body.style.overflow = 'hidden';
      this.render();
      const closeBtn = document.getElementById('lb-close');
      if (closeBtn) closeBtn.focus();
    }

    close() {
      this.modal.hidden = true;
      document.body.style.overflow = '';
      this.resetZoom();
      window.scrollTo({ top: this.savedScrollY, behavior: 'instant' });
      if (this.lastFocused && typeof this.lastFocused.focus === 'function') {
        this.lastFocused.focus({ preventScroll: true });
      }
    }

    prev() {
      if (this.items.length === 0) return;
      this.currentIndex = (this.currentIndex - 1 + this.items.length) % this.items.length;
      this.render();
    }

    next() {
      if (this.items.length === 0) return;
      this.currentIndex = (this.currentIndex + 1) % this.items.length;
      this.render();
    }

    render() {
      const item = this.items[this.currentIndex];
      if (!item || !this.img) return;

      this.resetZoom();
      const src = item.dataset.src || '';
      const title = item.dataset.title || '';
      const desc = item.dataset.desc || '';
      const cat = item.dataset.category || '';
      const date = item.dataset.date || '';

      this.img.src = src;
      this.img.alt = title || 'গ্রামের ছবি';

      if (this.counter) {
        this.counter.textContent = `${this.currentIndex + 1} / ${this.items.length}`;
      }

      if (this.titleEl) {
        this.titleEl.textContent = title;
        this.titleEl.style.display = title ? '' : 'none';
      }

      if (this.descEl) {
        this.descEl.textContent = desc;
        this.descEl.style.display = desc ? '' : 'none';
      }

      if (this.metaEl) {
        const parts = [cat, date].filter(Boolean);
        this.metaEl.textContent = parts.join(' · ');
        this.metaEl.style.display = parts.length ? '' : 'none';
      }

      const showNav = this.items.length > 1;
      if (this.btnPrev) this.btnPrev.style.display = showNav ? '' : 'none';
      if (this.btnNext) this.btnNext.style.display = showNav ? '' : 'none';
    }

    adjustZoom(delta) {
      this.scale = Math.min(3.5, Math.max(1, Number((this.scale + delta).toFixed(2))));
      if (this.scale === 1) {
        this.translateX = 0;
        this.translateY = 0;
      }
      this.applyTransform();
    }

    resetZoom() {
      this.scale = 1;
      this.translateX = 0;
      this.translateY = 0;
      this.applyTransform();
    }

    applyTransform() {
      if (!this.img) return;
      this.img.style.transform = `translate3d(${this.translateX}px, ${this.translateY}px, 0) scale(${this.scale})`;
      this.img.style.cursor = this.scale > 1 ? 'grab' : 'default';
      if (this.btnZoomReset) {
        this.btnZoomReset.textContent = `${Math.round(this.scale * 100)}%`;
      }
    }
  }

  window.addEventListener('DOMContentLoaded', () => {
    window.communityLightbox = new CommunityLightbox();
  });
})();
