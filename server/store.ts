import bcrypt from 'bcryptjs';
import crypto from 'node:crypto';
import type {
  ActivityLog,
  AdminRecord,
  AdminSession,
  ContentPage,
  FooterSettings,
  GalleryCategory,
  GalleryImage,
  HeroBanner,
  LoginAttempt,
  NavigationItem,
  NavigationMenu,
  PasswordReset,
  PostCategory,
  PostRecord,
  RateLimitBucket,
  RateLimitRule,
  SitePopup,
  StoredObject,
  WebsiteSettings,
} from './types.ts';

const nowIso = () => new Date().toISOString();
const daysAgoIso = (days: number) => new Date(Date.now() - days * 86400 * 1000).toISOString();

const demoPassword = process.env.ADMIN_DEMO_PASSWORD || 'FakirBari@2026';
const demoPasswordHash = bcrypt.hashSync(demoPassword, 10);

export interface InMemoryStore {
  admins: AdminRecord[];
  login_attempts: LoginAttempt[];
  password_resets: PasswordReset[];
  post_categories: PostCategory[];
  posts: PostRecord[];
  content_pages: ContentPage[];
  gallery_categories: GalleryCategory[];
  gallery_images: GalleryImage[];
  hero_banners: HeroBanner[];
  navigation_menus: NavigationMenu[];
  navigation_items: NavigationItem[];
  footer_settings: FooterSettings;
  website_settings: WebsiteSettings;
  activity_logs: ActivityLog[];
  rate_limit_rules: RateLimitRule[];
  rate_limit_buckets: RateLimitBucket[];
  site_popups: SitePopup[];
  storage: Map<string, StoredObject>;
  sessions: Map<string, AdminSession>;
  nextId: Record<string, number>;
}

const seedCreatedAt = nowIso();

export const store: InMemoryStore = {
  admins: [
    {
      id: 1,
      username: 'tauselhamtamjid',
      email: 'tauselhamtamjid@purbonijmaonafakirbari.local',
      password_hash: demoPasswordHash,
      full_name: 'Tausel Ham Tamjid',
      role: 'super_admin',
      permissions: [
        'manage_posts',
        'manage_pages',
        'manage_gallery',
        'manage_banners',
        'manage_popups',
        'manage_categories',
        'manage_navigation',
        'manage_settings',
      ],
      must_change_password: true,
      is_active: true,
      created_by: null,
      last_login_at: null,
      created_at: seedCreatedAt,
      updated_at: seedCreatedAt,
    },
  ],
  login_attempts: [],
  password_resets: [],
  post_categories: [
    { id: 1, name: 'Local Events', slug: 'local-events', sort_order: 1, is_active: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 2, name: 'Village Announcements', slug: 'village-announcements', sort_order: 2, is_active: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 3, name: 'Seasonal Harvest', slug: 'seasonal-harvest', sort_order: 3, is_active: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 4, name: 'Heritage & Stories', slug: 'heritage-stories', sort_order: 4, is_active: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
  ],
  posts: [
    {
      id: 1,
      category_id: 1,
      admin_id: 1,
      post_type: 'image',
      title: 'Autumn Farmers Market & Craft Fair Schedule',
      slug: 'autumn-farmers-market-craft-fair',
      description: 'Every Saturday from 8:00 AM to 1:00 PM, local growers, bakers, and weavers gather on the Village Green with seasonal vegetables, honey, and handmade crafts.',
      content_html: '<p>Every Saturday from 8:00 AM to 1:00 PM, local growers, bakers, and weavers gather on the Village Green with seasonal vegetables, honey, and handmade crafts.</p>',
      code_document: null,
      media_bucket: 'post-media',
      media_path: 'starter/market-morning.jpg',
      media_url: '/assets/images/market-morning.jpg',
      media_mime: 'image/jpeg',
      html_content: null,
      css_content: null,
      js_content: null,
      status: 'published',
      published_at: daysAgoIso(1),
      created_at: daysAgoIso(1),
      updated_at: daysAgoIso(1),
    },
    {
      id: 2,
      category_id: 3,
      admin_id: 1,
      post_type: 'image',
      title: 'Community Garden Winter Mulching Day',
      slug: 'community-garden-winter-mulching',
      description: 'Neighbors came together at the shared allotment beds this weekend to layer leaf compost across twelve cedar beds and plant winter garlic for next spring.',
      content_html: '<p>Neighbors came together at the shared allotment beds this weekend to layer leaf compost across twelve cedar beds and plant winter garlic for next spring.</p>',
      code_document: null,
      media_bucket: 'post-media',
      media_path: 'starter/community-garden.jpg',
      media_url: '/assets/images/community-garden.jpg',
      media_mime: 'image/jpeg',
      html_content: null,
      css_content: null,
      js_content: null,
      status: 'published',
      published_at: daysAgoIso(3),
      created_at: daysAgoIso(3),
      updated_at: daysAgoIso(3),
    },
    {
      id: 3,
      category_id: 2,
      admin_id: 1,
      post_type: 'text',
      title: null,
      slug: 'willow-brook-footpath-reopened',
      description: 'Footpath maintenance along the Willow Brook stone bridge has been completed ahead of schedule. The trail is now dry, clear of fallen branches, and open for morning walkers.',
      content_html: '<p>Footpath maintenance along the Willow Brook stone bridge has been completed ahead of schedule. The trail is now dry, clear of fallen branches, and open for morning walkers.</p>',
      code_document: null,
      media_bucket: null,
      media_path: null,
      media_url: null,
      media_mime: null,
      html_content: null,
      css_content: null,
      js_content: null,
      status: 'published',
      published_at: daysAgoIso(5),
      created_at: daysAgoIso(5),
      updated_at: daysAgoIso(5),
    },
  ],
  content_pages: [
    {
      id: 1,
      page_type: 'landing',
      title: 'পূর্ব নিজমাওনা ফকির বাড়ি — আমাদের গ্রাম',
      slug: 'our-village',
      html_content: '',
      css_content: '',
      js_content: '',
      code_document: `<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>পূর্ব নিজমাওনা ফকির বাড়ি — আমাদের গ্রাম</title>
  <style>
    :root {
      --bg: #ffffff;
      --tint: #f6f8f6;
      --border: #dce6dd;
      --green-900: #14532d;
      --green-700: #15803d;
      --ink: #141e16;
      --muted: #4a594e;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: 'Noto Sans Bengali', 'Hind Siliguri', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      color: var(--ink);
      background: var(--bg);
      line-height: 1.7;
    }
    .lp-wrap { width: 100%; margin: 0 auto; padding: 8px 0 28px; }
    .lp-hero {
      border-radius: 16px; overflow: hidden; background: var(--green-900); color: #ffffff;
      min-height: 340px; display: grid; grid-template-columns: 1.15fr 0.85fr; align-items: stretch;
      border: 1px solid rgba(20, 83, 45, 0.16);
    }
    .lp-hero-copy { padding: clamp(24px, 4vw, 48px); display: flex; flex-direction: column; justify-content: center; gap: 14px; }
    .lp-badge {
      display: inline-block; align-self: flex-start; padding: 5px 12px; border-radius: 999px;
      background: rgba(255, 255, 255, 0.14); border: 1px solid rgba(255, 255, 255, 0.24); font-size: 0.85rem; font-weight: 600;
    }
    .lp-hero-title { margin: 0; font-size: clamp(1.65rem, 3vw, 2.45rem); line-height: 1.25; font-weight: 700; }
    .lp-hero-lead { margin: 0; font-size: 1rem; color: rgba(255, 255, 255, 0.92); max-width: 56ch; }
    .lp-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 6px; }
    .lp-btn {
      display: inline-flex; align-items: center; justify-content: center; padding: 10px 18px; border-radius: 8px;
      font-weight: 600; font-size: 0.94rem; text-decoration: none;
    }
    .lp-btn-light { background: #ffffff; color: var(--green-900); }
    .lp-btn-light:hover { background: #ecfdf5; }
    .lp-btn-ghost { background: rgba(255, 255, 255, 0.12); color: #ffffff; border: 1px solid rgba(255, 255, 255, 0.35); }
    .lp-btn-ghost:hover { background: rgba(255, 255, 255, 0.22); }
    .lp-hero-media { position: relative; min-height: 240px; }
    .lp-hero-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .lp-section {
      margin-top: 28px; padding: clamp(20px, 3vw, 32px); background: var(--tint);
      border: 1px solid var(--border); border-radius: 14px;
    }
    .lp-section-plain { margin-top: 28px; }
    .lp-sec-title { margin: 0 0 6px; font-size: clamp(1.25rem, 2.2vw, 1.6rem); color: var(--green-900); font-weight: 700; }
    .lp-sec-sub { margin: 0 0 18px; color: var(--muted); font-size: 0.96rem; }
    .lp-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
    .lp-card { background: #ffffff; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; }
    .lp-card-img { width: 100%; aspect-ratio: 16 / 10; object-fit: cover; display: block; }
    .lp-card-body { padding: 16px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
    .lp-card-tag { font-size: 0.78rem; font-weight: 600; color: var(--green-700); }
    .lp-card-title { margin: 0; font-size: 1.06rem; color: var(--ink); }
    .lp-card-text { margin: 0; color: var(--muted); font-size: 0.92rem; }
    .lp-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
    .lp-tab {
      border: 1px solid var(--border); background: #ffffff; color: var(--ink);
      padding: 8px 14px; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer;
    }
    .lp-tab.is-active { background: var(--green-700); border-color: var(--green-700); color: #ffffff; }
    .lp-notice {
      background: #ffffff; border: 1px dashed #86efac; border-left: 4px solid var(--green-700);
      border-radius: 10px; padding: 16px 18px; margin-top: 20px;
    }
    .lp-notice strong { color: var(--green-900); }
    .lp-faq-list { display: flex; flex-direction: column; gap: 10px; }
    .lp-faq-item { background: #ffffff; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
    .lp-faq-btn {
      width: 100%; text-align: left; background: transparent; border: 0; padding: 14px 16px;
      font-size: 0.98rem; font-weight: 600; color: var(--ink); display: flex; justify-content: space-between;
      align-items: center; gap: 12px; cursor: pointer;
    }
    .lp-faq-panel { padding: 0 16px 14px; color: var(--muted); font-size: 0.94rem; border-top: 1px solid #eef2ee; }
    .lp-faq-panel[hidden] { display: none; }
    .lp-link-inline { color: var(--green-700); font-weight: 600; text-decoration: none; }
    .lp-link-inline:hover { text-decoration: underline; }
    @media (max-width: 900px) {
      .lp-hero { grid-template-columns: 1fr; }
      .lp-hero-media { min-height: 220px; max-height: 300px; }
      .lp-grid-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 600px) { .lp-grid-3 { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="lp-wrap">
    <section class="lp-hero" aria-label="পূর্ব নিজমাওনা ফকির বাড়ি পরিচিতি">
      <div class="lp-hero-copy">
        <span class="lp-badge">পূর্ব নিজমাওনা ফকির বাড়ি কমিউনিটি</span>
        <h2 class="lp-hero-title">আমাদের গ্রামের গল্প, খবর এবং সুন্দর মুহূর্তের ডিজিটাল ঠিকানা</h2>
        <p class="lp-hero-lead">পূর্ব নিজমাওনা ফকির বাড়ির মানুষের মিলনমেলা, স্থানীয় ঘোষণা, সামাজিক আয়োজন ও স্মৃতিময় ছবির সংগ্রহ এক জায়গায় সবার জন্য উন্মুক্ত।</p>
        <div class="lp-actions">
          <a href="/posts.php" target="_top" class="lp-btn lp-btn-light">গ্রামের খবর ও পোস্ট দেখুন</a>
          <a href="/gallery.php" target="_top" class="lp-btn lp-btn-ghost">ছবির গ্যালারি ঘুরুন</a>
        </div>
      </div>
      <div class="lp-hero-media">
        <img src="/assets/images/hero-meadow.jpg" alt="পূর্ব নিজমাওনা ফকির বাড়ি — সবুজ প্রান্তর ও গ্রামের দৃশ্য">
      </div>
    </section>

    <section class="lp-section" aria-label="কমিউনিটি বিভাগের ঝলক">
      <h2 class="lp-sec-title">কমিউনিটির বিশেষ দিকগুলো</h2>
      <p class="lp-sec-sub">বিভাগ অনুযায়ী বেছে নিয়ে গ্রামের ছবি, অনুষ্ঠান ও প্রতিদিনের গল্প দেখুন।</p>
      <div class="lp-tabs" role="tablist" aria-label="বিষয় নির্বাচন করুন">
        <button type="button" class="lp-tab is-active" data-filter="all">সব বিষয়</button>
        <button type="button" class="lp-tab" data-filter="life">গ্রাম ও বাজার</button>
        <button type="button" class="lp-tab" data-filter="nature">প্রকৃতি ও বাগান</button>
        <button type="button" class="lp-tab" data-filter="festival">উৎসব ও আয়োজন</button>
      </div>
      <div class="lp-grid-3" id="lp-card-grid">
        <article class="lp-card" data-topic="life">
          <img class="lp-card-img" src="/assets/images/market-morning.jpg" alt="সাপ্তাহিক গ্রামীণ বাজার ও হস্তশিল্প">
          <div class="lp-card-body">
            <span class="lp-card-tag">স্থানীয় আয়োজন</span>
            <h3 class="lp-card-title">সাপ্তাহিক বাজার ও স্থানীয় মেলা</h3>
            <p class="lp-card-text">মৌসুমি শাকসবজি, গৃহস্থালি পণ্য ও এলাকার কারিগরদের অংশগ্রহণে জমে ওঠা সকালের মিলনমেলা।</p>
            <a href="/posts.php?category=local-events" target="_top" class="lp-link-inline">বিস্তারিত খবর →</a>
          </div>
        </article>
        <article class="lp-card" data-topic="nature">
          <img class="lp-card-img" src="/assets/images/community-garden.jpg" alt="কমিউনিটি বাগান পরিচর্যা">
          <div class="lp-card-body">
            <span class="lp-card-tag">যৌথ উদ্যোগ</span>
            <h3 class="lp-card-title">কমিউনিটি বাগান ও চাষাবাদ</h3>
            <p class="lp-card-text">প্রতিবেশীদের সম্মিলিত অংশগ্রহণে সবুজ বাগান পরিচর্যা ও মৌসুমি বীজ বপনের উদ্যোগ।</p>
            <a href="/gallery.php?category=community-activities" target="_top" class="lp-link-inline">বাগানের ছবি →</a>
          </div>
        </article>
        <article class="lp-card" data-topic="nature">
          <img class="lp-card-img" src="/assets/images/stone-bridge.jpg" alt="প্রকৃতি ও সেতুর পাড়">
          <div class="lp-card-body">
            <span class="lp-card-tag">প্রকৃতি ও ঐতিহ্য</span>
            <h3 class="lp-card-title">হাঁটাপথ ও প্রকৃতির প্রশান্তি</h3>
            <p class="lp-card-text">গ্রামের শান্ত হাঁটাপথ, ছায়াঘেরা পাড় এবং প্রকৃতির নির্মল দৃশ্যপট।</p>
            <a href="/gallery.php?category=nature" target="_top" class="lp-link-inline">প্রকৃতির গ্যালারি →</a>
          </div>
        </article>
        <article class="lp-card" data-topic="festival">
          <img class="lp-card-img" src="/assets/images/hero-festival.jpg" alt="উৎসব ও আলোকসজ্জা">
          <div class="lp-card-body">
            <span class="lp-card-tag">উৎসব ও স্মৃতি</span>
            <h3 class="lp-card-title">উৎসব ও সাংস্কৃতিক মিলনমেলা</h3>
            <p class="lp-card-text">মৌসুমি উৎসব, আলোকসজ্জা ও সবাই মিলে কাটানো আনন্দঘন সন্ধ্যার ছবি ও বিবরণ।</p>
            <a href="/gallery.php?category=festivals" target="_top" class="lp-link-inline">উৎসবের ছবি →</a>
          </div>
        </article>
      </div>
      <div class="lp-notice">
        <strong>তথ্য হালনাগাদের স্থান:</strong>
        [এখানে অ্যাডমিন প্যানেল (<code>/448662/pages.php</code>) থেকে পূর্ব নিজমাওনা ফকির বাড়ির পূর্ণাঙ্গ ইতিহাস, ঐতিহ্যবাহী স্থাপনা এবং এলাকার গুরুত্বপূর্ণ যোগাযোগের তথ্য যোগ করুন।]
      </div>
    </section>

    <section class="lp-section-plain" aria-label="সাধারণ প্রশ্নোত্তর">
      <h2 class="lp-sec-title">পোর্টাল ব্যবহারের তথ্য</h2>
      <p class="lp-sec-sub">ক্লিক করে বিস্তারিত খুলুন বা বন্ধ করুন।</p>
      <div class="lp-faq-list">
        <div class="lp-faq-item">
          <button type="button" class="lp-faq-btn" aria-expanded="false"><span>গ্রামের নতুন খবর ও ঘোষণা কোথায় পাওয়া যাবে?</span><span aria-hidden="true">+</span></button>
          <div class="lp-faq-panel" hidden>সব প্রকাশিত খবর, সামাজিক ঘোষণা ও ভিডিও দেখতে <a href="/posts.php" target="_top" class="lp-link-inline">গ্রামের খবর ও পোস্ট</a> পাতায় যান।</div>
        </div>
        <div class="lp-faq-item">
          <button type="button" class="lp-faq-btn" aria-expanded="false"><span>ছবির গ্যালারিতে কীভাবে বিভাগ অনুযায়ী ছবি দেখবেন?</span><span aria-hidden="true">+</span></button>
          <div class="lp-faq-panel" hidden><a href="/gallery.php" target="_top" class="lp-link-inline">ছবির গ্যালারি</a> পাতায় গিয়ে বিভাগ বেছে নিন এবং যেকোনো ছবিতে ক্লিক করে জুম ও সোয়াইপ করে দেখুন।</div>
        </div>
        <div class="lp-faq-item">
          <button type="button" class="lp-faq-btn" aria-expanded="false"><span>অ্যাডমিন কীভাবে এই ল্যান্ডিং পেজ সম্পাদনা করবেন?</span><span aria-hidden="true">+</span></button>
          <div class="lp-faq-panel" hidden>অ্যাডমিন প্যানেলের <strong>পেজ ব্যবস্থাপনা</strong> (<code>/448662/pages.php</code>) থেকে একটি একক HTML/CSS/JS কোড এডিটরের মাধ্যমে এই পেজটি পরিবর্তন করা যাবে।</div>
        </div>
      </div>
    </section>
  </div>
  <script>
    (() => {
      const tabs = document.querySelectorAll('.lp-tab');
      const cards = document.querySelectorAll('#lp-card-grid .lp-card');
      tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
          const filter = tab.getAttribute('data-filter') || 'all';
          tabs.forEach((t) => t.classList.toggle('is-active', t === tab));
          cards.forEach((card) => {
            const topic = card.getAttribute('data-topic') || '';
            card.style.display = (filter === 'all' || topic === filter) ? 'flex' : 'none';
          });
        });
      });
      document.querySelectorAll('.lp-faq-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
          const panel = btn.nextElementSibling;
          if (!panel) return;
          const isOpen = btn.getAttribute('aria-expanded') === 'true';
          btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
          panel.hidden = isOpen;
          const sign = btn.lastElementChild;
          if (sign) sign.textContent = isOpen ? '+' : '−';
        });
      });
    })();
  </script>
</body>
</html>`,
      meta_description: 'পূর্ব নিজমাওনা ফকির বাড়ির পরিচিতি, গ্রামের পরিবেশ, স্থানীয় আয়োজন, গল্প এবং ছবির সংগ্রহ দেখুন।',
      status: 'published',
      admin_id: 1,
      published_at: seedCreatedAt,
      created_at: seedCreatedAt,
      updated_at: seedCreatedAt,
    },
  ],
  gallery_categories: [
    { id: 1, name: 'Village Life', slug: 'village-life', description: 'Everyday warmth along our cottages, lanes, and village green.', cover_image_url: null, sort_order: 1, is_active: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 2, name: 'Nature', slug: 'nature', description: 'Streams, stone bridges, wildflowers, and wooded trails.', cover_image_url: null, sort_order: 2, is_active: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 3, name: 'Local Events', slug: 'local-events', description: 'Farmers markets, neighborhood fairs, and seasonal gatherings.', cover_image_url: null, sort_order: 3, is_active: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 4, name: 'Festivals', slug: 'festivals', description: 'Harvest celebrations, lantern nights, and spring parades.', cover_image_url: null, sort_order: 4, is_active: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 5, name: 'Community Activities', slug: 'community-activities', description: 'Shared garden beds, clean-up mornings, and workshops.', cover_image_url: null, sort_order: 5, is_active: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 6, name: 'Memories', slug: 'memories', description: 'Cherished moments from years past across the valley.', cover_image_url: null, sort_order: 6, is_active: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
  ],
  gallery_images: [
    {
      id: 1,
      category_id: 1,
      admin_id: 1,
      title: 'Morning Light Across the Valley',
      description: 'Early mist lifting over the stone cottages along Meadow Lane.',
      storage_bucket: 'gallery-images',
      storage_path: 'starter/hero-meadow.jpg',
      image_url: '/assets/images/hero-meadow.jpg',
      mime_type: 'image/jpeg',
      file_size_bytes: null,
      is_featured: true,
      is_slider_item: true,
      sort_order: 1,
      status: 'published',
      uploaded_at: daysAgoIso(1),
      updated_at: daysAgoIso(1),
    },
    {
      id: 2,
      category_id: 3,
      admin_id: 1,
      title: 'Saturday Morning Market',
      description: 'Fresh greens, heirloom tomatoes, and wildflowers at the green.',
      storage_bucket: 'gallery-images',
      storage_path: 'starter/market-morning.jpg',
      image_url: '/assets/images/market-morning.jpg',
      mime_type: 'image/jpeg',
      file_size_bytes: null,
      is_featured: true,
      is_slider_item: true,
      sort_order: 2,
      status: 'published',
      uploaded_at: daysAgoIso(2),
      updated_at: daysAgoIso(2),
    },
    {
      id: 3,
      category_id: 5,
      admin_id: 1,
      title: 'Raised Beds in Full Bloom',
      description: 'Neighbors tending herbs and vegetables in the shared allotment.',
      storage_bucket: 'gallery-images',
      storage_path: 'starter/community-garden.jpg',
      image_url: '/assets/images/community-garden.jpg',
      mime_type: 'image/jpeg',
      file_size_bytes: null,
      is_featured: true,
      is_slider_item: true,
      sort_order: 3,
      status: 'published',
      uploaded_at: daysAgoIso(3),
      updated_at: daysAgoIso(3),
    },
    {
      id: 4,
      category_id: 2,
      admin_id: 1,
      title: 'Old Mossy Footbridge',
      description: 'The 19th-century stone arch crossing Willow Brook.',
      storage_bucket: 'gallery-images',
      storage_path: 'starter/stone-bridge.jpg',
      image_url: '/assets/images/stone-bridge.jpg',
      mime_type: 'image/jpeg',
      file_size_bytes: null,
      is_featured: true,
      is_slider_item: true,
      sort_order: 4,
      status: 'published',
      uploaded_at: daysAgoIso(4),
      updated_at: daysAgoIso(4),
    },
    {
      id: 5,
      category_id: 4,
      admin_id: 1,
      title: null,
      description: 'Lanterns and craft stalls along the main footpath during the harvest gathering.',
      storage_bucket: 'gallery-images',
      storage_path: 'starter/hero-festival.jpg',
      image_url: '/assets/images/hero-festival.jpg',
      mime_type: 'image/jpeg',
      file_size_bytes: null,
      is_featured: false,
      is_slider_item: true,
      sort_order: 5,
      status: 'published',
      uploaded_at: daysAgoIso(5),
      updated_at: daysAgoIso(5),
    },
  ],
  hero_banners: [
    {
      id: 1,
      title: 'Welcome to Purbo Nijmaona Fakir Bari',
      subtitle: 'Neighbors, shared harvests, and quiet village lanes captured through the seasons.',
      button_text: 'Explore Photo Gallery',
      button_url: '/gallery.php',
      storage_bucket: 'hero-banners',
      storage_path: 'starter/hero-meadow.jpg',
      image_url: '/assets/images/hero-meadow.jpg',
      sort_order: 1,
      is_active: true,
      created_at: seedCreatedAt,
      updated_at: seedCreatedAt,
    },
    {
      id: 2,
      title: 'Community Gatherings & Village Life',
      subtitle: 'Stay updated with local announcements, celebrations, and shared neighborhood memories.',
      button_text: 'Read Community Posts',
      button_url: '/posts.php',
      storage_bucket: 'hero-banners',
      storage_path: 'starter/hero-festival.jpg',
      image_url: '/assets/images/hero-festival.jpg',
      sort_order: 2,
      is_active: true,
      created_at: seedCreatedAt,
      updated_at: seedCreatedAt,
    },
  ],
  navigation_menus: [
    { id: 1, location: 'header', name: 'Primary Header Menu', created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 2, location: 'footer', name: 'Footer Quick Links', created_at: seedCreatedAt, updated_at: seedCreatedAt },
  ],
  navigation_items: [
    { id: 1, menu_id: 1, parent_id: null, label: 'Home', url: '/', item_type: 'link', mega_description: null, open_in_new_tab: false, sort_order: 1, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 2, menu_id: 1, parent_id: null, label: 'Explore', url: '/gallery.php', item_type: 'mega', mega_description: 'Browse local photography collections and village stories by theme.', open_in_new_tab: false, sort_order: 2, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 3, menu_id: 1, parent_id: null, label: 'Community Posts', url: '/posts.php', item_type: 'dropdown', mega_description: null, open_in_new_tab: false, sort_order: 3, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 4, menu_id: 1, parent_id: null, label: 'Photo Gallery', url: '/gallery.php', item_type: 'link', mega_description: null, open_in_new_tab: false, sort_order: 4, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 15, menu_id: 1, parent_id: null, label: 'আমাদের গ্রাম', url: '/our-village', item_type: 'link', mega_description: null, open_in_new_tab: false, sort_order: 5, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    // Explore mega children
    { id: 5, menu_id: 1, parent_id: 2, label: 'Village Life', url: '/gallery.php?category=village-life', item_type: 'link', mega_description: 'Everyday lanes, cottages, and neighborhood warmth.', open_in_new_tab: false, sort_order: 1, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 6, menu_id: 1, parent_id: 2, label: 'Local Events', url: '/gallery.php?category=local-events', item_type: 'link', mega_description: 'Farmers markets, gatherings, and seasonal fairs.', open_in_new_tab: false, sort_order: 2, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 7, menu_id: 1, parent_id: 2, label: 'Nature & Streams', url: '/gallery.php?category=nature', item_type: 'link', mega_description: 'Footbridges, trails, and greenery across the valley.', open_in_new_tab: false, sort_order: 3, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    // Community Posts dropdown children
    { id: 8, menu_id: 1, parent_id: 3, label: 'All Updates', url: '/posts.php', item_type: 'link', mega_description: null, open_in_new_tab: false, sort_order: 1, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 9, menu_id: 1, parent_id: 3, label: 'Local Events', url: '/posts.php?category=local-events', item_type: 'link', mega_description: null, open_in_new_tab: false, sort_order: 2, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 10, menu_id: 1, parent_id: 3, label: 'Announcements', url: '/posts.php?category=village-announcements', item_type: 'link', mega_description: null, open_in_new_tab: false, sort_order: 3, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 11, menu_id: 1, parent_id: 3, label: 'Community Videos', url: '/posts.php?type=video', item_type: 'link', mega_description: null, open_in_new_tab: false, sort_order: 4, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    // Footer links
    { id: 12, menu_id: 2, parent_id: null, label: 'Home', url: '/', item_type: 'link', mega_description: null, open_in_new_tab: false, sort_order: 1, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 13, menu_id: 2, parent_id: null, label: 'Community Posts', url: '/posts.php', item_type: 'link', mega_description: null, open_in_new_tab: false, sort_order: 2, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 14, menu_id: 2, parent_id: null, label: 'Photo Gallery', url: '/gallery.php', item_type: 'link', mega_description: null, open_in_new_tab: false, sort_order: 3, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
    { id: 16, menu_id: 2, parent_id: null, label: 'আমাদের গ্রাম', url: '/our-village', item_type: 'link', mega_description: null, open_in_new_tab: false, sort_order: 4, is_visible: true, created_at: seedCreatedAt, updated_at: seedCreatedAt },
  ],
  footer_settings: {
    id: 1,
    logo_path: null,
    logo_url: null,
    site_name: 'Purbo Nijmaona Fakir Bari',
    description: 'Sharing stories, harvests, neighborhood gatherings, and everyday beauty from Purbo Nijmaona Fakir Bari.',
    contact_address: 'Purbo Nijmaona Fakir Bari',
    contact_phone: '+880 1700-000000',
    contact_email: 'contact@purbonijmaonafakirbari.local',
    facebook_url: '',
    instagram_url: '',
    youtube_url: '',
    x_url: '',
    copyright_text: '© Purbo Nijmaona Fakir Bari Community. Built with care for neighbors.',
    show_social_links: true,
    show_contact_info: true,
    is_visible: true,
    updated_at: seedCreatedAt,
  },
  website_settings: {
    id: 1,
    site_name: 'Purbo Nijmaona Fakir Bari',
    site_description: 'A warm digital gathering place for Purbo Nijmaona Fakir Bari news, seasonal events, local memories, and community photography.',
    logo_path: null,
    logo_url: null,
    favicon_path: null,
    favicon_url: null,
    header_bg_color: '#14532d',
    header_text_color: '#ffffff',
    footer_bg_color: '#14532d',
    footer_text_color: '#ecfdf5',
    accent_color: '#15803d',
    hero_interval_ms: 5500,
    seo_title: 'Purbo Nijmaona Fakir Bari — Local Community & Photo Archive',
    seo_description: 'Stay connected with Purbo Nijmaona Fakir Bari events, community announcements, stories, and local photo memories.',
    social_facebook: '',
    social_instagram: '',
    social_youtube: '',
    updated_at: seedCreatedAt,
  },
  activity_logs: [],
  rate_limit_rules: [
    {
      id: 1,
      scope: 'admin_login',
      label: 'অ্যাডমিন লগইন',
      description: 'আইপি ঠিকানা ও অ্যাকাউন্ট অনুযায়ী অতিরিক্ত লগইন চেষ্টার বিরুদ্ধে সুরক্ষা দেয়।',
      max_requests: 5,
      window_value: 15,
      window_unit: 'minutes',
      is_enabled: true,
      updated_by: null,
      created_at: seedCreatedAt,
      updated_at: seedCreatedAt,
    },
    {
      id: 2,
      scope: 'password_recovery',
      label: 'পাসওয়ার্ড পুনরুদ্ধার',
      description: 'আইপি ঠিকানা ও ইমেইল অনুযায়ী পাসওয়ার্ড রিসেটের অনুরোধ এবং টোকেন জমা দেওয়ার সংখ্যা সীমিত রাখে।',
      max_requests: 3,
      window_value: 1,
      window_unit: 'hours',
      is_enabled: true,
      updated_by: null,
      created_at: seedCreatedAt,
      updated_at: seedCreatedAt,
    },
    {
      id: 3,
      scope: 'public_api',
      label: 'পাবলিক এপিআই',
      description: 'অতিরিক্ত অনুরোধ ও তথ্য সংগ্রহের অপব্যবহার থেকে পাবলিক পোস্ট এবং সার্চ সুবিধা সুরক্ষিত রাখে।',
      max_requests: 60,
      window_value: 1,
      window_unit: 'minutes',
      is_enabled: true,
      updated_by: null,
      created_at: seedCreatedAt,
      updated_at: seedCreatedAt,
    },
    {
      id: 4,
      scope: 'gallery_api',
      label: 'গ্যালারি এপিআই',
      description: 'প্রতি ব্যবহারকারীর আইপি অনুযায়ী গ্যালারির পৃষ্ঠা ও বিভাগ ফিল্টারের অনুরোধ সীমিত রাখে।',
      max_requests: 45,
      window_value: 1,
      window_unit: 'minutes',
      is_enabled: true,
      updated_by: null,
      created_at: seedCreatedAt,
      updated_at: seedCreatedAt,
    },
    {
      id: 5,
      scope: 'post_submission',
      label: 'পোস্ট জমা',
      description: 'আইপি ঠিকানা ও লগইন করা অ্যাডমিন অনুযায়ী পোস্ট তৈরি এবং হালনাগাদের অনুরোধ সীমিত রাখে।',
      max_requests: 20,
      window_value: 10,
      window_unit: 'minutes',
      is_enabled: true,
      updated_by: null,
      created_at: seedCreatedAt,
      updated_at: seedCreatedAt,
    },
    {
      id: 6,
      scope: 'admin_api',
      label: 'অ্যাডমিন এপিআই',
      description: 'লগইন করা অ্যাডমিনের কার্যক্রম এবং ড্যাশবোর্ডের এপিআই অনুরোধ সুরক্ষিত রাখে।',
      max_requests: 120,
      window_value: 1,
      window_unit: 'minutes',
      is_enabled: true,
      updated_by: null,
      created_at: seedCreatedAt,
      updated_at: seedCreatedAt,
    },
  ],
  rate_limit_buckets: [],
  site_popups: [],
  storage: new Map(),
  sessions: new Map(),
  nextId: {
    admins: 2,
    login_attempts: 1,
    password_resets: 1,
    post_categories: 5,
    posts: 4,
    content_pages: 2,
    gallery_categories: 7,
    gallery_images: 6,
    hero_banners: 3,
    navigation_menus: 3,
    navigation_items: 17,
    activity_logs: 1,
    rate_limit_buckets: 1,
    site_popups: 1,
  },
};

export function nextIdFor(table: string): number {
  const cur = store.nextId[table] ?? 1;
  store.nextId[table] = cur + 1;
  return cur;
}

export function getDemoAdminCredentials(): { username: string; password: string } {
  return {
    username: 'tauselhamtamjid',
    password: demoPassword,
  };
}

export function storeUploadedFile(
  file: Express.Multer.File,
  bucket: string,
  allowedMimes: Record<string, string>,
  maxBytes: number,
  prefix = ''
): { bucket: string; path: string; url: string; mime: string; size: number } {
  if (!file || !file.buffer || file.size <= 0) {
    throw new Error('No file was selected for upload.');
  }
  if (file.size > maxBytes) {
    const maxMb = (maxBytes / (1024 * 1024)).toFixed(1);
    throw new Error(`File exceeds maximum allowed size of ${maxMb} MB.`);
  }
  const mime = (file.mimetype || '').toLowerCase();
  const ext = allowedMimes[mime];
  if (!ext) {
    throw new Error(`Unsupported or unsafe file type (${mime || 'unknown'}).`);
  }

  const randomName = crypto.randomBytes(16).toString('hex') + '.' + ext;
  const now = new Date();
  const dateFolder = `${now.getUTCFullYear()}/${String(now.getUTCMonth() + 1).padStart(2, '0')}`;
  const cleanPrefix = prefix.replace(/[^a-zA-Z0-9_-]/g, '').replace(/^\/+|\/+$/g, '');
  const objectPath = (cleanPrefix ? cleanPrefix + '/' : '') + dateFolder + '/' + randomName;
  const key = `${bucket}/${objectPath}`;

  store.storage.set(key, {
    bucket,
    path: objectPath,
    mime,
    size: file.size,
    buffer: file.buffer,
  });

  return {
    bucket,
    path: objectPath,
    url: `/uploads/${encodeURIComponent(bucket)}/${objectPath}`,
    mime,
    size: file.size,
  };
}

export function deleteStoredFile(bucket: string | null | undefined, objectPath: string | null | undefined): void {
  if (!bucket || !objectPath || objectPath.startsWith('starter/')) return;
  store.storage.delete(`${bucket}/${objectPath}`);
}
