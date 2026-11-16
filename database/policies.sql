-- ============================================================================
-- Supabase Row Level Security (RLS), Restricted Backend Role & Storage Policies
-- ============================================================================
-- Run AFTER database/schema.sql.
-- IMPORTANT: Replace 'CHANGE_ME_STRONG_DB_PASSWORD' before executing in production.
-- ============================================================================

-- 1. Create a restricted server-side PostgreSQL role for PHP PDO
--    This avoids connecting PHP as the postgres superuser or exposing service_role.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'community_app_role') THEN
        CREATE ROLE community_app_role LOGIN PASSWORD 'CHANGE_ME_STRONG_DB_PASSWORD' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
    END IF;
END $$;

GRANT USAGE ON SCHEMA public TO community_app_role;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO community_app_role;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO community_app_role;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO community_app_role;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO community_app_role;

-- 2. Enable Row Level Security (RLS) on every application table
ALTER TABLE public.admins             ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.login_attempts     ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.password_resets    ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.post_categories    ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.posts              ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.content_pages      ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.gallery_categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.gallery_images     ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.hero_banners       ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.navigation_menus   ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.navigation_items   ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.footer_settings    ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.website_settings   ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.activity_logs      ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.rate_limit_rules   ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.rate_limit_buckets ENABLE ROW LEVEL SECURITY;

-- 3. Revoke direct anonymous write access and sensitive table reads
REVOKE ALL ON public.admins, public.login_attempts, public.password_resets, public.activity_logs,
    public.rate_limit_rules, public.rate_limit_buckets FROM anon, authenticated;
REVOKE INSERT, UPDATE, DELETE ON public.post_categories, public.posts, public.gallery_categories,
    public.gallery_images, public.hero_banners, public.navigation_menus, public.navigation_items,
    public.footer_settings, public.website_settings, public.content_pages FROM anon, authenticated;

-- 4. Public read-only RLS policies (for published/visible content only)
DROP POLICY IF EXISTS public_read_post_categories ON public.post_categories;
CREATE POLICY public_read_post_categories ON public.post_categories
    FOR SELECT TO anon, authenticated
    USING (is_active = TRUE);

DROP POLICY IF EXISTS public_read_published_content_pages ON public.content_pages;
CREATE POLICY public_read_published_content_pages ON public.content_pages
    FOR SELECT TO anon, authenticated USING (status = 'published');
GRANT SELECT ON public.content_pages TO anon, authenticated;

DROP POLICY IF EXISTS public_read_posts ON public.posts;
CREATE POLICY public_read_posts ON public.posts
    FOR SELECT TO anon, authenticated
    USING (status = 'published');

DROP POLICY IF EXISTS public_read_gallery_categories ON public.gallery_categories;
CREATE POLICY public_read_gallery_categories ON public.gallery_categories
    FOR SELECT TO anon, authenticated
    USING (is_active = TRUE);

DROP POLICY IF EXISTS public_read_gallery_images ON public.gallery_images;
CREATE POLICY public_read_gallery_images ON public.gallery_images
    FOR SELECT TO anon, authenticated
    USING (status = 'published');

DROP POLICY IF EXISTS public_read_hero_banners ON public.hero_banners;
CREATE POLICY public_read_hero_banners ON public.hero_banners
    FOR SELECT TO anon, authenticated
    USING (is_active = TRUE);

DROP POLICY IF EXISTS public_read_navigation_menus ON public.navigation_menus;
CREATE POLICY public_read_navigation_menus ON public.navigation_menus
    FOR SELECT TO anon, authenticated
    USING (TRUE);

DROP POLICY IF EXISTS public_read_navigation_items ON public.navigation_items;
CREATE POLICY public_read_navigation_items ON public.navigation_items
    FOR SELECT TO anon, authenticated
    USING (is_visible = TRUE);

DROP POLICY IF EXISTS public_read_footer_settings ON public.footer_settings;
CREATE POLICY public_read_footer_settings ON public.footer_settings
    FOR SELECT TO anon, authenticated
    USING (is_visible = TRUE);

DROP POLICY IF EXISTS public_read_website_settings ON public.website_settings;
CREATE POLICY public_read_website_settings ON public.website_settings
    FOR SELECT TO anon, authenticated
    USING (TRUE);

-- 5. Backend PHP Role RLS policies (explicitly scoped to community_app_role)
DO $$
DECLARE
    tbl TEXT;
BEGIN
    FOREACH tbl IN ARRAY ARRAY[
        'admins', 'login_attempts', 'password_resets', 'post_categories', 'posts', 'content_pages',
        'gallery_categories', 'gallery_images', 'hero_banners', 'navigation_menus',
        'navigation_items', 'footer_settings', 'website_settings', 'activity_logs',
        'rate_limit_rules', 'rate_limit_buckets'
    ]
    LOOP
        EXECUTE format('DROP POLICY IF EXISTS app_role_all_%I ON public.%I;', tbl, tbl);
        EXECUTE format(
            'CREATE POLICY app_role_all_%I ON public.%I FOR ALL TO community_app_role USING (TRUE) WITH CHECK (TRUE);',
            tbl, tbl
        );
    END LOOP;
END $$;

-- ============================================================================
-- 6. Supabase Storage Buckets & Policies
-- ============================================================================
INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
VALUES
    ('website-assets',  'website-assets',  TRUE, 5242880,  ARRAY['image/jpeg','image/png','image/webp','image/svg+xml','image/x-icon','image/vnd.microsoft.icon']),
    ('hero-banners',    'hero-banners',    TRUE, 10485760, ARRAY['image/jpeg','image/png','image/webp']),
    ('gallery-images',  'gallery-images',  TRUE, 10485760, ARRAY['image/jpeg','image/png','image/webp','image/gif']),
    ('post-media',      'post-media',      TRUE, 52428800, ARRAY['image/jpeg','image/png','image/webp','image/gif','video/mp4','video/webm'])
ON CONFLICT (id) DO UPDATE SET
    public = EXCLUDED.public,
    file_size_limit = EXCLUDED.file_size_limit,
    allowed_mime_types = EXCLUDED.allowed_mime_types;

-- Public read access ONLY on these 4 community media buckets
DROP POLICY IF EXISTS public_read_community_storage ON storage.objects;
CREATE POLICY public_read_community_storage ON storage.objects
    FOR SELECT TO anon, authenticated
    USING (bucket_id IN ('website-assets', 'hero-banners', 'gallery-images', 'post-media'));

-- Prevent anonymous browser uploads, updates, or deletions
DROP POLICY IF EXISTS deny_anon_insert_storage ON storage.objects;
DROP POLICY IF EXISTS deny_anon_update_storage ON storage.objects;
DROP POLICY IF EXISTS deny_anon_delete_storage ON storage.objects;
