/**
 * Greenfield Village — Pure Vanilla JS Interactive Preview Data & Filtering
 * Used by root index.html when running in the Node/Vite preview sandbox where PHP is absent.
 */
(() => {
  'use strict';

  const DEMO_PHOTOS = [
    {
      id: 1,
      title: 'Morning Light Across the Valley',
      description: 'Early mist lifting over the stone cottages along Meadow Lane.',
      category: 'Village Life',
      slug: 'village-life',
      date: 'Nov 12, 2026',
      image_url: '/assets/images/hero-meadow.jpg'
    },
    {
      id: 2,
      title: 'Saturday Morning Market',
      description: 'Fresh greens, heirloom tomatoes, and wildflowers at the village green.',
      category: 'Local Events',
      slug: 'local-events',
      date: 'Nov 10, 2026',
      image_url: '/assets/images/market-morning.jpg'
    },
    {
      id: 3,
      title: 'Raised Beds in Full Bloom',
      description: 'Neighbors tending herbs and vegetables in the shared allotment.',
      category: 'Community Activities',
      slug: 'community-activities',
      date: 'Nov 8, 2026',
      image_url: '/assets/images/community-garden.jpg'
    },
    {
      id: 4,
      title: 'Old Mossy Footbridge',
      description: 'The 19th-century stone arch crossing Willow Brook.',
      category: 'Nature',
      slug: 'nature',
      date: 'Nov 6, 2026',
      image_url: '/assets/images/stone-bridge.jpg'
    },
    {
      id: 5,
      title: '',
      description: 'Lanterns and craft stalls along the main footpath during the harvest gathering.',
      category: 'Festivals',
      slug: 'festivals',
      date: 'Nov 4, 2026',
      image_url: '/assets/images/hero-festival.jpg'
    },
    {
      id: 6,
      title: 'Valley Cottages at Sunset',
      description: 'Golden hour over the western hillside and parish steeple.',
      category: 'Memories',
      slug: 'memories',
      date: 'Oct 29, 2026',
      image_url: '/assets/images/hero-meadow.jpg'
    },
    {
      id: 7,
      title: 'Willow Brook Morning Walk',
      description: 'Wildflowers and clear stream water along the north footpath.',
      category: 'Nature',
      slug: 'nature',
      date: 'Oct 25, 2026',
      image_url: '/assets/images/stone-bridge.jpg'
    },
    {
      id: 8,
      title: 'Heirloom Harvest Baskets',
      description: 'Locally grown produce brought in by valley growers.',
      category: 'Local Events',
      slug: 'local-events',
      date: 'Oct 20, 2026',
      image_url: '/assets/images/market-morning.jpg'
    }
  ];

  const DEMO_POSTS = {
    1: {
      title: 'Autumn Farmers Market & Craft Fair Schedule',
      category: 'Local Events',
      date: 'Nov 12, 2026',
      type: 'image',
      media: '/assets/images/market-morning.jpg',
      body: 'Every Saturday from 8:00 AM to 1:00 PM, local growers, bakers, and weavers gather on the Village Green.\n\nBring reusable baskets for seasonal vegetables, orchard apples, wildflower honey, and warm sourdough loaves. Hot herbal tea is served near the timber pavilion.'
    },
    2: {
      title: 'Community Garden Winter Mulching Day',
      category: 'Community Activities',
      date: 'Nov 9, 2026',
      type: 'image',
      media: '/assets/images/community-garden.jpg',
      body: 'Thank you to the twenty-four neighbors who joined us at the shared allotment beds this morning!\n\nTogether we cleared spent summer vines, layered leaf compost across twelve cedar beds, and planted winter garlic for next spring.'
    },
    3: {
      title: '',
      category: 'Village Announcements',
      date: 'Nov 5, 2026',
      type: 'text',
      media: '',
      body: 'Footpath maintenance along the Willow Brook stone bridge has been completed ahead of schedule. The trail is now dry, clear of fallen branches, and open for morning walkers and cyclists.'
    }
  };

  window.addEventListener('DOMContentLoaded', () => {
    const grid = document.getElementById('ig-gallery-grid');
    const catBar = document.getElementById('gallery-category-bar');

    if (grid && catBar) {
      const render = (slug) => {
        const list = slug === 'all' ? DEMO_PHOTOS : DEMO_PHOTOS.filter((p) => p.slug === slug);
        grid.innerHTML = list.map((p) => `
          <button
            type="button"
            class="ig-photo-card"
            data-lightbox-item
            data-src="${p.image_url}"
            data-title="${p.title}"
            data-desc="${p.description}"
            data-category="${p.category}"
            data-date="${p.date}"
            aria-label="${p.title || 'View community photograph'}"
          >
            <img src="${p.image_url}" alt="${p.title || 'Community photograph'}" loading="lazy" referrerpolicy="no-referrer">
            <span class="ig-photo-overlay">
              ${p.title ? `<span class="ig-photo-caption">${p.title}</span>` : ''}
            </span>
          </button>
        `).join('');
        if (window.communityLightbox) {
          window.communityLightbox.refreshItems();
        }
      };

      catBar.addEventListener('click', (e) => {
        const tab = e.target.closest('.cat-tab');
        if (!tab) return;
        catBar.querySelectorAll('.cat-tab').forEach((t) => t.classList.toggle('is-active', t === tab));
        render(tab.dataset.category || 'all');
      });

      render('all');
    }

    // Post detail modal in static preview
    const postModal = document.getElementById('preview-post-modal');
    const postModalBody = document.getElementById('preview-post-body');
    if (postModal && postModalBody) {
      document.querySelectorAll('[data-preview-post]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const post = DEMO_POSTS[btn.dataset.previewPost];
          if (!post) return;
          postModalBody.innerHTML = `
            <div class="post-meta" style="margin-bottom: 10px;">
              <span>${post.category}</span><span aria-hidden="true">·</span><span>${post.date}</span>
            </div>
            ${post.title ? `<h2 class="section-title" style="font-size: 1.7rem; margin-bottom: 14px;">${post.title}</h2>` : ''}
            ${post.media ? `<div class="post-detail-media"><img src="${post.media}" alt="${post.title || 'Post image'}"></div>` : ''}
            <div class="post-detail-content">${post.body}</div>
          `;
          postModal.hidden = false;
          document.body.style.overflow = 'hidden';
        });
      });

      postModal.querySelectorAll('[data-close-post-modal]').forEach((el) => {
        el.addEventListener('click', () => {
          postModal.hidden = true;
          document.body.style.overflow = '';
        });
      });
    }
  });
})();
