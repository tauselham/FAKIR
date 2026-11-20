-- ============================================================================
-- Initial Seed Data
-- ============================================================================
-- Run AFTER schema.sql and policies.sql.
-- Seeds the initial Super Admin (@tauselhamtamjid) with a precomputed bcrypt
-- hash and must_change_password = TRUE (change immediately after login).
-- Additional CLI admins can also be created via: php scripts/create-admin.php
-- ============================================================================

INSERT INTO public.website_settings (
    id, site_name, site_description,
    header_bg_color, header_text_color,
    footer_bg_color, footer_text_color,
    accent_color, hero_interval_ms,
    seo_title, seo_description
) VALUES (
    1,
    'Purbo Nijmaona Fakir Bari',
    'A warm digital gathering place for Purbo Nijmaona Fakir Bari news, seasonal events, local memories, and community photography.',
    '#14532d', '#ffffff',
    '#14532d', '#ecfdf5',
    '#15803d', 5500,
    'Purbo Nijmaona Fakir Bari — Local Community & Photo Archive',
    'Stay connected with Purbo Nijmaona Fakir Bari events, community announcements, stories, and local photo memories.'
) ON CONFLICT (id) DO NOTHING;

INSERT INTO public.footer_settings (
    id, site_name, description, contact_address, contact_phone, contact_email,
    copyright_text, show_social_links, show_contact_info, is_visible
) VALUES (
    1,
    'Purbo Nijmaona Fakir Bari',
    'Sharing stories, harvests, neighborhood gatherings, and everyday beauty from Purbo Nijmaona Fakir Bari.',
    'Purbo Nijmaona Fakir Bari',
    '+880 1700-000000',
    'contact@purbonijmaonafakirbari.local',
    '© Purbo Nijmaona Fakir Bari Community. Built with care for neighbors.',
    TRUE, TRUE, TRUE
) ON CONFLICT (id) DO NOTHING;

-- Navigation Menus
INSERT INTO public.navigation_menus (location, name)
VALUES
    ('header', 'Primary Header Menu'),
    ('footer', 'Footer Quick Links')
ON CONFLICT (location) DO NOTHING;

-- Default Header Navigation Items (editable/deletable from Admin Panel)
WITH hdr AS (SELECT id FROM public.navigation_menus WHERE location = 'header' LIMIT 1)
INSERT INTO public.navigation_items (menu_id, parent_id, label, url, item_type, mega_description, sort_order, is_visible)
SELECT hdr.id, NULL, v.label, v.url, v.item_type, v.mega_description, v.sort_order, TRUE
FROM hdr, (VALUES
    ('Home', '/', 'link', NULL, 1),
    ('Explore', '/gallery.php', 'mega', 'Browse local photography collections and village stories by theme.', 2),
    ('Community Posts', '/posts.php', 'dropdown', NULL, 3),
    ('Photo Gallery', '/gallery.php', 'link', NULL, 4),
    ('আমাদের গ্রাম', '/our-village', 'link', NULL, 5)
) AS v(label, url, item_type, mega_description, sort_order)
WHERE NOT EXISTS (SELECT 1 FROM public.navigation_items WHERE menu_id = hdr.id);

-- Default Child Items for Explore (Mega Menu) and Community Posts (Dropdown)
WITH hdr AS (SELECT id FROM public.navigation_menus WHERE location = 'header' LIMIT 1),
     explore_parent AS (
         SELECT ni.id, ni.menu_id FROM public.navigation_items ni
         JOIN hdr ON hdr.id = ni.menu_id
         WHERE ni.parent_id IS NULL AND ni.label = 'Explore' LIMIT 1
     )
INSERT INTO public.navigation_items (menu_id, parent_id, label, url, item_type, mega_description, sort_order, is_visible)
SELECT explore_parent.menu_id, explore_parent.id, v.label, v.url, 'link', v.mega_description, v.sort_order, TRUE
FROM explore_parent, (VALUES
    ('Village Life', '/gallery.php?category=village-life', 'Everyday lanes, cottages, and neighborhood warmth.', 1),
    ('Local Events', '/gallery.php?category=local-events', 'Farmers markets, gatherings, and seasonal fairs.', 2),
    ('Nature & Streams', '/gallery.php?category=nature', 'Footbridges, trails, and greenery across the valley.', 3)
) AS v(label, url, mega_description, sort_order)
WHERE NOT EXISTS (SELECT 1 FROM public.navigation_items WHERE parent_id = explore_parent.id);

WITH hdr AS (SELECT id FROM public.navigation_menus WHERE location = 'header' LIMIT 1),
     posts_parent AS (
         SELECT ni.id, ni.menu_id FROM public.navigation_items ni
         JOIN hdr ON hdr.id = ni.menu_id
         WHERE ni.parent_id IS NULL AND ni.label = 'Community Posts' LIMIT 1
     )
INSERT INTO public.navigation_items (menu_id, parent_id, label, url, item_type, mega_description, sort_order, is_visible)
SELECT posts_parent.menu_id, posts_parent.id, v.label, v.url, 'link', NULL, v.sort_order, TRUE
FROM posts_parent, (VALUES
    ('All Updates', '/posts.php', 1),
    ('Local Events', '/posts.php?category=local-events', 2),
    ('Announcements', '/posts.php?category=village-announcements', 3),
    ('Community Videos', '/posts.php?type=video', 4)
) AS v(label, url, sort_order)
WHERE NOT EXISTS (SELECT 1 FROM public.navigation_items WHERE parent_id = posts_parent.id);

-- Default Footer Quick Links
WITH ftr AS (SELECT id FROM public.navigation_menus WHERE location = 'footer' LIMIT 1)
INSERT INTO public.navigation_items (menu_id, parent_id, label, url, item_type, sort_order, is_visible)
SELECT ftr.id, NULL, v.label, v.url, 'link', v.sort_order, TRUE
FROM ftr, (VALUES
    ('Home', '/', 1),
    ('Community Posts', '/posts.php', 2),
    ('Photo Gallery', '/gallery.php', 3),
    ('আমাদের গ্রাম', '/our-village', 4)
) AS v(label, url, sort_order)
WHERE NOT EXISTS (SELECT 1 FROM public.navigation_items WHERE menu_id = ftr.id);

-- Default Post Categories
INSERT INTO public.post_categories (name, slug, sort_order, is_active) VALUES
    ('Local Events', 'local-events', 1, TRUE),
    ('Village Announcements', 'village-announcements', 2, TRUE),
    ('Seasonal Harvest', 'seasonal-harvest', 3, TRUE),
    ('Heritage & Stories', 'heritage-stories', 4, TRUE)
ON CONFLICT (slug) DO NOTHING;

-- Sample Community Posts (replaceable/manageable in Admin Panel)
WITH pcats AS (
    SELECT id, slug FROM public.post_categories
)
INSERT INTO public.posts (category_id, post_type, title, slug, description, media_bucket, media_path, media_url, media_mime, status, published_at)
SELECT
    (SELECT id FROM pcats WHERE slug = v.cat_slug LIMIT 1),
    v.post_type,
    v.title,
    v.slug,
    v.description,
    v.media_bucket,
    v.media_path,
    v.media_url,
    v.media_mime,
    'published',
    NOW() - (v.days_ago || ' days')::INTERVAL
FROM (VALUES
    ('local-events', 'image', 'Autumn Farmers Market & Craft Fair Schedule', 'autumn-farmers-market-craft-fair', 'Every Saturday from 8:00 AM to 1:00 PM, local growers, bakers, and weavers gather on the Village Green with seasonal vegetables, honey, and handmade crafts.', 'post-media', 'starter/market-morning.jpg', '/assets/images/market-morning.jpg', 'image/jpeg', '1'),
    ('seasonal-harvest', 'image', 'Community Garden Winter Mulching Day', 'community-garden-winter-mulching', 'Neighbors came together at the shared allotment beds this weekend to layer leaf compost across twelve cedar beds and plant winter garlic for next spring.', 'post-media', 'starter/community-garden.jpg', '/assets/images/community-garden.jpg', 'image/jpeg', '3'),
    ('village-announcements', 'text', NULL, 'willow-brook-footpath-reopened', 'Footpath maintenance along the Willow Brook stone bridge has been completed ahead of schedule. The trail is now dry, clear of fallen branches, and open for morning walkers.', NULL, NULL, NULL, NULL, '5')
) AS v(cat_slug, post_type, title, slug, description, media_bucket, media_path, media_url, media_mime, days_ago)
WHERE NOT EXISTS (SELECT 1 FROM public.posts);

-- Default Gallery Categories
INSERT INTO public.gallery_categories (name, slug, description, sort_order, is_active) VALUES
    ('Village Life', 'village-life', 'Everyday warmth along our cottages, lanes, and village green.', 1, TRUE),
    ('Nature', 'nature', 'Streams, stone bridges, wildflowers, and wooded trails.', 2, TRUE),
    ('Local Events', 'local-events', 'Farmers markets, neighborhood fairs, and seasonal gatherings.', 3, TRUE),
    ('Festivals', 'festivals', 'Harvest celebrations, lantern nights, and spring parades.', 4, TRUE),
    ('Community Activities', 'community-activities', 'Shared garden beds, clean-up mornings, and workshops.', 5, TRUE),
    ('Memories', 'memories', 'Cherished moments from years past across the valley.', 6, TRUE)
ON CONFLICT (slug) DO NOTHING;

-- Sample Hero Banners (using bundled starter photography until Admin uploads custom banners)
INSERT INTO public.hero_banners (title, subtitle, button_text, button_url, storage_bucket, storage_path, image_url, sort_order, is_active)
SELECT v.title, v.subtitle, v.button_text, v.button_url, 'hero-banners', v.storage_path, v.image_url, v.sort_order, TRUE
FROM (VALUES
    ('Welcome to Purbo Nijmaona Fakir Bari', 'Neighbors, shared harvests, and quiet village lanes captured through the seasons.', 'Explore Photo Gallery', '/gallery.php', 'starter/hero-meadow.jpg', '/assets/images/hero-meadow.jpg', 1),
    ('Community Gatherings & Village Life', 'Stay updated with local announcements, celebrations, and shared neighborhood memories.', 'Read Community Posts', '/posts.php', 'starter/hero-festival.jpg', '/assets/images/hero-festival.jpg', 2)
) AS v(title, subtitle, button_text, button_url, storage_bucket, storage_path, image_url, sort_order)
WHERE NOT EXISTS (SELECT 1 FROM public.hero_banners);

-- Sample Gallery Images (replaceable/manageable in Admin Panel)
WITH cats AS (
    SELECT id, slug FROM public.gallery_categories
)
INSERT INTO public.gallery_images (category_id, title, description, storage_bucket, storage_path, image_url, is_featured, is_slider_item, sort_order, status)
SELECT
    (SELECT id FROM cats WHERE slug = v.cat_slug LIMIT 1),
    v.title,
    v.description,
    'gallery-images',
    v.storage_path,
    v.image_url,
    v.is_featured,
    v.is_slider_item,
    v.sort_order,
    'published'
FROM (VALUES
    ('village-life', 'Morning Light Across the Valley', 'Early mist lifting over the stone cottages along Meadow Lane.', 'starter/hero-meadow.jpg', '/assets/images/hero-meadow.jpg', TRUE, TRUE, 1),
    ('local-events', 'Saturday Morning Market', 'Fresh greens, heirloom tomatoes, and wildflowers at the green.', 'starter/market-morning.jpg', '/assets/images/market-morning.jpg', TRUE, TRUE, 2),
    ('community-activities', 'Raised Beds in Full Bloom', 'Neighbors tending herbs and vegetables in the shared allotment.', 'starter/community-garden.jpg', '/assets/images/community-garden.jpg', TRUE, TRUE, 3),
    ('nature', 'Old Mossy Footbridge', 'The 19th-century stone arch crossing Willow Brook.', 'starter/stone-bridge.jpg', '/assets/images/stone-bridge.jpg', TRUE, TRUE, 4),
    ('festivals', NULL, 'Lanterns and craft stalls along the main footpath during the harvest gathering.', 'starter/hero-festival.jpg', '/assets/images/hero-festival.jpg', FALSE, TRUE, 5)
) AS v(cat_slug, title, description, storage_path, image_url, is_featured, is_slider_item, sort_order)
WHERE NOT EXISTS (SELECT 1 FROM public.gallery_images);

-- Default Server-Side Rate Limiting Rules (Configurable by Super Admin)
INSERT INTO public.rate_limit_rules (scope, label, description, max_requests, window_value, window_unit, is_enabled) VALUES
    ('admin_login',       'Admin Login',       'Protects administrator sign-in against brute-force attempts by IP and account.', 5,   15, 'minutes', TRUE),
    ('password_recovery', 'Password Recovery', 'Limits password reset requests and token submissions by IP and email.',          3,   1,  'hours',   TRUE),
    ('public_api',        'Public API',        'Protects public post feeds and query endpoints against scraping/flooding.',       60,  1,  'minutes', TRUE),
    ('gallery_api',       'Gallery API',       'Limits gallery AJAX pagination and category filter calls per client IP.',          45,  1,  'minutes', TRUE),
    ('post_submission',   'Post Submission',   'Limits post creation and updates by IP and authenticated administrator.',         20,  10, 'minutes', TRUE),
    ('admin_api',         'Admin API',         'Protects authenticated admin actions and dashboard API endpoints.',               120, 1,  'minutes', TRUE)
ON CONFLICT (scope) DO NOTHING;

-- Initial Super Admin (@tauselhamtamjid) — temporary password hashed with bcrypt ($2y$12$);
-- must_change_password = TRUE prompts immediate update inside /448662/security.php.
INSERT INTO public.admins (
    username, email, password_hash, full_name, role, permissions, must_change_password, is_active
) VALUES (
    'tauselhamtamjid',
    'tauselhamtamjid@purbonijmaonafakirbari.local',
    '$2y$12$mZrw84wAIe85QxeEzXIst.zzO6xz04AOvx1l38Qh/C0c4bHoANJ7i',
    'Tausel Ham Tamjid',
    'super_admin',
    '["manage_posts","manage_pages","manage_gallery","manage_banners","manage_categories","manage_navigation","manage_settings"]'::jsonb,
    TRUE,
    TRUE
) ON CONFLICT (username) DO NOTHING;

-- Initial Published Bengali Landing Page (/our-village)
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
</html>$landing_doc$,
    'পূর্ব নিজমাওনা ফকির বাড়ির পরিচিতি, গ্রামের পরিবেশ, স্থানীয় আয়োজন, গল্প এবং ছবির সংগ্রহ দেখুন।',
    'published',
    NOW()
) ON CONFLICT (slug) DO NOTHING;
