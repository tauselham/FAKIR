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
    ('Photo Gallery', '/gallery.php', 'link', NULL, 4)
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
    ('Photo Gallery', '/gallery.php', 3)
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
