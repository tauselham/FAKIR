-- ============================================================================
-- Complete Landing Page Migration & Initial Bengali Landing Page Seed
-- Safe, idempotent, repeatable SQL for Supabase PostgreSQL.
-- Never drops existing tables or overwrites existing records.
-- ============================================================================

-- 1. Ensure schema columns exist on posts and content_pages
ALTER TABLE public.posts
    ADD COLUMN IF NOT EXISTS content_html TEXT NULL,
    ADD COLUMN IF NOT EXISTS html_content TEXT NULL,
    ADD COLUMN IF NOT EXISTS css_content TEXT NULL,
    ADD COLUMN IF NOT EXISTS js_content TEXT NULL,
    ADD COLUMN IF NOT EXISTS code_document TEXT NULL;

ALTER TABLE public.content_pages
    ADD COLUMN IF NOT EXISTS code_document TEXT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_content_pages_single_about
    ON public.content_pages (page_type) WHERE page_type = 'about';
CREATE INDEX IF NOT EXISTS idx_content_pages_published_slug
    ON public.content_pages (slug) WHERE status = 'published';

-- 2. Ensure automatic updated_at trigger exists on content_pages
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'set_updated_at') THEN
        DROP TRIGGER IF EXISTS trg_content_pages_updated_at ON public.content_pages;
        CREATE TRIGGER trg_content_pages_updated_at
            BEFORE UPDATE ON public.content_pages
            FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
    END IF;
END $$;

-- 3. Ensure Row Level Security & role privileges are configured cleanly
ALTER TABLE public.content_pages ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.content_pages FROM anon, authenticated;
GRANT SELECT ON public.content_pages TO anon, authenticated;

DROP POLICY IF EXISTS public_read_published_content_pages ON public.content_pages;
CREATE POLICY public_read_published_content_pages ON public.content_pages
    FOR SELECT TO anon, authenticated USING (status = 'published');

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'community_app_role') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON public.content_pages TO community_app_role;
        GRANT USAGE, SELECT ON SEQUENCE public.content_pages_id_seq TO community_app_role;
        DROP POLICY IF EXISTS app_role_all_content_pages ON public.content_pages;
        CREATE POLICY app_role_all_content_pages ON public.content_pages
            FOR ALL TO community_app_role USING (TRUE) WITH CHECK (TRUE);
    END IF;
END $$;

-- 4. Seed initial published Bengali Landing Page (/our-village) if absent
INSERT INTO public.content_pages (
    page_type, title, slug, html_content, css_content, js_content, code_document,
    meta_description, status, published_at
) VALUES (
    'landing',
    'পূর্ব নিজমাওনা ফকির বাড়ি — আমাদের গ্রাম',
    'our-village',
    '',
    '',
    '',
    $landing_doc$<!DOCTYPE html>
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
      --green-800: #166534;
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
    .lp-wrap {
      width: 100%;
      margin: 0 auto;
      padding: 8px 0 28px;
    }
    .lp-hero {
      position: relative;
      border-radius: 16px;
      overflow: hidden;
      background: var(--green-900);
      color: #ffffff;
      min-height: 340px;
      display: grid;
      grid-template-columns: 1.15fr 0.85fr;
      align-items: stretch;
      border: 1px solid rgba(20, 83, 45, 0.16);
    }
    .lp-hero-copy {
      padding: clamp(24px, 4vw, 48px);
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 14px;
    }
    .lp-badge {
      display: inline-block;
      align-self: flex-start;
      padding: 5px 12px;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.14);
      border: 1px solid rgba(255, 255, 255, 0.24);
      font-size: 0.85rem;
      font-weight: 600;
    }
    .lp-hero-title {
      margin: 0;
      font-size: clamp(1.65rem, 3vw, 2.45rem);
      line-height: 1.25;
      font-weight: 700;
    }
    .lp-hero-lead {
      margin: 0;
      font-size: 1rem;
      color: rgba(255, 255, 255, 0.92);
      max-width: 56ch;
    }
    .lp-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 6px;
    }
    .lp-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 10px 18px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 0.94rem;
      text-decoration: none;
      transition: background-color 180ms ease, transform 180ms ease;
    }
    .lp-btn-light {
      background: #ffffff;
      color: var(--green-900);
    }
    .lp-btn-light:hover { background: #ecfdf5; }
    .lp-btn-ghost {
      background: rgba(255, 255, 255, 0.12);
      color: #ffffff;
      border: 1px solid rgba(255, 255, 255, 0.35);
    }
    .lp-btn-ghost:hover { background: rgba(255, 255, 255, 0.22); }
    .lp-hero-media {
      position: relative;
      min-height: 240px;
    }
    .lp-hero-media img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .lp-section {
      margin-top: 28px;
      padding: clamp(20px, 3vw, 32px);
      background: var(--tint);
      border: 1px solid var(--border);
      border-radius: 14px;
    }
    .lp-section-plain {
      margin-top: 28px;
    }
    .lp-sec-title {
      margin: 0 0 6px;
      font-size: clamp(1.25rem, 2.2vw, 1.6rem);
      color: var(--green-900);
      font-weight: 700;
    }
    .lp-sec-sub {
      margin: 0 0 18px;
      color: var(--muted);
      font-size: 0.96rem;
    }
    .lp-grid-3 {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 16px;
    }
    .lp-card {
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    .lp-card-img {
      width: 100%;
      aspect-ratio: 16 / 10;
      object-fit: cover;
      display: block;
    }
    .lp-card-body {
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      flex: 1;
    }
    .lp-card-tag {
      font-size: 0.78rem;
      font-weight: 600;
      color: var(--green-700);
    }
    .lp-card-title {
      margin: 0;
      font-size: 1.06rem;
      color: var(--ink);
    }
    .lp-card-text {
      margin: 0;
      color: var(--muted);
      font-size: 0.92rem;
    }
    .lp-tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 16px;
    }
    .lp-tab {
      border: 1px solid var(--border);
      background: #ffffff;
      color: var(--ink);
      padding: 8px 14px;
      border-radius: 8px;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
    }
    .lp-tab.is-active {
      background: var(--green-700);
      border-color: var(--green-700);
      color: #ffffff;
    }
    .lp-notice {
      background: #ffffff;
      border: 1px dashed #86efac;
      border-left: 4px solid var(--green-700);
      border-radius: 10px;
      padding: 16px 18px;
      margin-top: 20px;
    }
    .lp-notice strong { color: var(--green-900); }
    .lp-faq-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .lp-faq-item {
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow: hidden;
    }
    .lp-faq-btn {
      width: 100%;
      text-align: left;
      background: transparent;
      border: 0;
      padding: 14px 16px;
      font-size: 0.98rem;
      font-weight: 600;
      color: var(--ink);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      cursor: pointer;
    }
    .lp-faq-panel {
      padding: 0 16px 14px;
      color: var(--muted);
      font-size: 0.94rem;
      border-top: 1px solid #eef2ee;
    }
    .lp-faq-panel[hidden] { display: none; }
    .lp-link-inline {
      color: var(--green-700);
      font-weight: 600;
      text-decoration: none;
    }
    .lp-link-inline:hover { text-decoration: underline; }
    @media (max-width: 900px) {
      .lp-hero { grid-template-columns: 1fr; }
      .lp-hero-media { min-height: 220px; max-height: 300px; }
      .lp-grid-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 600px) {
      .lp-grid-3 { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="lp-wrap">
    <section class="lp-hero" aria-label="পূর্ব নিজমাওনা ফকির বাড়ি পরিচিতি">
      <div class="lp-hero-copy">
        <span class="lp-badge">পূর্ব নিজমাওনা ফকির বাড়ি কমিউনিটি</span>
        <h2 class="lp-hero-title">আমাদের গ্রামের গল্প, খবর এবং সুন্দর মুহূর্তের ডিজিটাল ঠিকানা</h2>
        <p class="lp-hero-lead">
          পূর্ব নিজমাওনা ফকির বাড়ির মানুষের মিলনমেলা, স্থানীয় ঘোষণা, সামাজিক আয়োজন ও স্মৃতিময় ছবির সংগ্রহ এক জায়গায় সবার জন্য উন্মুক্ত।
        </p>
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
          <button type="button" class="lp-faq-btn" aria-expanded="false">
            <span>গ্রামের নতুন খবর ও ঘোষণা কোথায় পাওয়া যাবে?</span>
            <span aria-hidden="true">+</span>
          </button>
          <div class="lp-faq-panel" hidden>
            সব প্রকাশিত খবর, সামাজিক ঘোষণা ও ভিডিও দেখতে উপরের মেনু থেকে অথবা
            <a href="/posts.php" target="_top" class="lp-link-inline">গ্রামের খবর ও পোস্ট</a> পাতায় যান।
          </div>
        </div>

        <div class="lp-faq-item">
          <button type="button" class="lp-faq-btn" aria-expanded="false">
            <span>ছবির গ্যালারিতে কীভাবে বিভাগ অনুযায়ী ছবি দেখবেন?</span>
            <span aria-hidden="true">+</span>
          </button>
          <div class="lp-faq-panel" hidden>
            <a href="/gallery.php" target="_top" class="lp-link-inline">ছবির গ্যালারি</a> পাতায় গিয়ে পছন্দমতো বিভাগ বেছে নিন এবং যেকোনো ছবিতে ক্লিক করে বড় আকারে জুম ও সোয়াইপ করে দেখুন।
          </div>
        </div>

        <div class="lp-faq-item">
          <button type="button" class="lp-faq-btn" aria-expanded="false">
            <span>অ্যাডমিন কীভাবে এই ল্যান্ডিং পেজ সম্পাদনা করবেন?</span>
            <span aria-hidden="true">+</span>
          </button>
          <div class="lp-faq-panel" hidden>
            অ্যাডমিন প্যানেলের <strong>পেজ ব্যবস্থাপনা</strong> (<code>/448662/pages.php</code>) থেকে একটি একক HTML/CSS/JS কোড এডিটরের মাধ্যমে এই পেজটি সরাসরি পরিবর্তন ও হালনাগাদ করা যাবে।
          </div>
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
</html>$landing_doc$,
    'পূর্ব নিজমাওনা ফকির বাড়ির পরিচিতি, গ্রামের পরিবেশ, স্থানীয় আয়োজন, গল্প এবং ছবির সংগ্রহ দেখুন।',
    'published',
    NOW()
) ON CONFLICT (slug) DO NOTHING;

-- 5. Add clean navigation links to the seeded Landing Page if missing
WITH hdr AS (SELECT id FROM public.navigation_menus WHERE location = 'header' LIMIT 1)
INSERT INTO public.navigation_items (menu_id, parent_id, label, url, item_type, mega_description, sort_order, is_visible)
SELECT hdr.id, NULL, 'আমাদের গ্রাম', '/our-village', 'link', NULL, 5, TRUE
FROM hdr
WHERE NOT EXISTS (
    SELECT 1 FROM public.navigation_items ni
    WHERE ni.menu_id = hdr.id AND ni.url = '/our-village'
);

WITH ftr AS (SELECT id FROM public.navigation_menus WHERE location = 'footer' LIMIT 1)
INSERT INTO public.navigation_items (menu_id, parent_id, label, url, item_type, sort_order, is_visible)
SELECT ftr.id, NULL, 'আমাদের গ্রাম', '/our-village', 'link', 4, TRUE
FROM ftr
WHERE NOT EXISTS (
    SELECT 1 FROM public.navigation_items ni
    WHERE ni.menu_id = ftr.id AND ni.url = '/our-village'
);
