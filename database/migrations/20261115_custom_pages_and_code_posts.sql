-- Custom landing/about pages and isolated-code posts.
-- Apply once in Supabase SQL Editor after the existing schema.sql.

CREATE TABLE IF NOT EXISTS public.content_pages (
    id BIGSERIAL PRIMARY KEY,
    page_type VARCHAR(20) NOT NULL CHECK (page_type IN ('landing', 'about')),
    title VARCHAR(220) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    html_content TEXT NOT NULL DEFAULT '',
    css_content TEXT NOT NULL DEFAULT '',
    js_content TEXT NOT NULL DEFAULT '',
    code_document TEXT NULL,
    meta_description VARCHAR(300) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published')),
    admin_id BIGINT NULL REFERENCES public.admins(id) ON DELETE SET NULL,
    published_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_content_pages_single_about
    ON public.content_pages (page_type) WHERE page_type = 'about';
CREATE INDEX IF NOT EXISTS idx_content_pages_published_slug
    ON public.content_pages (slug) WHERE status = 'published';

ALTER TABLE public.posts
    ADD COLUMN IF NOT EXISTS html_content TEXT NULL,
    ADD COLUMN IF NOT EXISTS css_content TEXT NULL,
    ADD COLUMN IF NOT EXISTS js_content TEXT NULL,
    ADD COLUMN IF NOT EXISTS content_html TEXT NULL,
    ADD COLUMN IF NOT EXISTS code_document TEXT NULL;

ALTER TABLE public.content_pages
    ADD COLUMN IF NOT EXISTS code_document TEXT NULL;

ALTER TABLE public.posts DROP CONSTRAINT IF EXISTS posts_post_type_check;
ALTER TABLE public.posts
    ADD CONSTRAINT posts_post_type_check
    CHECK (post_type IN ('text', 'image', 'video', 'custom'));

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
