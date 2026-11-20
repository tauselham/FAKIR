-- ============================================================================
-- Local Village & Community Website — PostgreSQL Schema (Supabase)
-- ============================================================================
-- Run this file first in the Supabase SQL Editor (or via psql), followed by
-- database/policies.sql and database/seed.sql.
-- ============================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- 1. Administrators & Granular Permissions
CREATE TABLE IF NOT EXISTS public.admins (
    id                   BIGSERIAL PRIMARY KEY,
    username             VARCHAR(80)  NOT NULL UNIQUE,
    email                VARCHAR(180) NOT NULL UNIQUE,
    password_hash        TEXT         NOT NULL,
    full_name            VARCHAR(120) NOT NULL,
    role                 VARCHAR(30)  NOT NULL DEFAULT 'admin' CHECK (role IN ('super_admin', 'admin', 'editor')),
    permissions          JSONB        NOT NULL DEFAULT '[]'::jsonb,
    must_change_password BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active            BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by           BIGINT       NULL REFERENCES public.admins(id) ON DELETE SET NULL,
    last_login_at        TIMESTAMPTZ  NULL,
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Idempotent migration for existing databases
ALTER TABLE public.admins ADD COLUMN IF NOT EXISTS permissions JSONB NOT NULL DEFAULT '[]'::jsonb;
ALTER TABLE public.admins ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE public.admins ADD COLUMN IF NOT EXISTS created_by BIGINT NULL REFERENCES public.admins(id) ON DELETE SET NULL;

-- 2. Login Rate Limiting Log
CREATE TABLE IF NOT EXISTS public.login_attempts (
    id              BIGSERIAL PRIMARY KEY,
    ip_address      VARCHAR(45)  NOT NULL,
    identifier      VARCHAR(180) NOT NULL,
    success         BOOLEAN      NOT NULL DEFAULT FALSE,
    attempted_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time ON public.login_attempts (ip_address, attempted_at DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ident_time ON public.login_attempts (identifier, attempted_at DESC);

-- 3. Secure Password Reset Tokens
CREATE TABLE IF NOT EXISTS public.password_resets (
    id              BIGSERIAL PRIMARY KEY,
    admin_id        BIGINT       NOT NULL REFERENCES public.admins(id) ON DELETE CASCADE,
    token_hash      CHAR(64)     NOT NULL UNIQUE,
    expires_at      TIMESTAMPTZ  NOT NULL,
    used_at         TIMESTAMPTZ  NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_password_resets_admin ON public.password_resets (admin_id, expires_at DESC);

-- 4. Post Categories
CREATE TABLE IF NOT EXISTS public.post_categories (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(120) NOT NULL UNIQUE,
    sort_order      INTEGER      NOT NULL DEFAULT 0,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_post_categories_order ON public.post_categories (sort_order ASC, id ASC);

-- 5. Community Posts (Text, Image, Video; Optional Title)
CREATE TABLE IF NOT EXISTS public.posts (
    id              BIGSERIAL PRIMARY KEY,
    category_id     BIGINT       NULL REFERENCES public.post_categories(id) ON DELETE SET NULL,
    admin_id        BIGINT       NULL REFERENCES public.admins(id) ON DELETE SET NULL,
    post_type       VARCHAR(20)  NOT NULL DEFAULT 'text' CHECK (post_type IN ('text', 'image', 'video', 'custom')),
    title           VARCHAR(220) NULL,
    slug            VARCHAR(240) NOT NULL UNIQUE,
    description     TEXT         NOT NULL,
    media_bucket    VARCHAR(80)  NULL,
    media_path      TEXT         NULL,
    media_url       TEXT         NULL,
    media_mime      VARCHAR(100) NULL,
    content_html    TEXT         NULL,
    html_content    TEXT         NULL,
    css_content     TEXT         NULL,
    js_content      TEXT         NULL,
    code_document   TEXT         NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'published' CHECK (status IN ('draft', 'published')),
    published_at    TIMESTAMPTZ  NULL DEFAULT NOW(),
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_posts_status_pub ON public.posts (status, published_at DESC);
CREATE INDEX IF NOT EXISTS idx_posts_category ON public.posts (category_id);


-- 5A. Custom Landing Pages and the editable About Us page
CREATE TABLE IF NOT EXISTS public.content_pages (
    id               BIGSERIAL PRIMARY KEY,
    page_type        VARCHAR(20)  NOT NULL CHECK (page_type IN ('landing', 'about')),
    title            VARCHAR(220) NOT NULL,
    slug             VARCHAR(120) NOT NULL UNIQUE,
    html_content     TEXT         NOT NULL DEFAULT '',
    css_content      TEXT         NOT NULL DEFAULT '',
    js_content       TEXT         NOT NULL DEFAULT '',
    code_document    TEXT         NULL,
    meta_description VARCHAR(300) NULL,
    status           VARCHAR(20)  NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published')),
    admin_id         BIGINT       NULL REFERENCES public.admins(id) ON DELETE SET NULL,
    published_at     TIMESTAMPTZ  NULL,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_content_pages_single_about
    ON public.content_pages (page_type) WHERE page_type = 'about';
CREATE INDEX IF NOT EXISTS idx_content_pages_published_slug
    ON public.content_pages (slug) WHERE status = 'published';

-- 6. Gallery Categories
CREATE TABLE IF NOT EXISTS public.gallery_categories (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(120) NOT NULL UNIQUE,
    description     TEXT         NULL,
    cover_image_url TEXT         NULL,
    sort_order      INTEGER      NOT NULL DEFAULT 0,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_gallery_categories_order ON public.gallery_categories (sort_order ASC, id ASC);

-- 7. Instagram-Style Gallery Images (Optional Title & Description)
CREATE TABLE IF NOT EXISTS public.gallery_images (
    id              BIGSERIAL PRIMARY KEY,
    category_id     BIGINT       NULL REFERENCES public.gallery_categories(id) ON DELETE SET NULL,
    admin_id        BIGINT       NULL REFERENCES public.admins(id) ON DELETE SET NULL,
    title           VARCHAR(200) NULL,
    description     TEXT         NULL,
    storage_bucket  VARCHAR(80)  NOT NULL DEFAULT 'gallery-images',
    storage_path    TEXT         NOT NULL,
    image_url       TEXT         NOT NULL,
    mime_type       VARCHAR(80)  NULL,
    file_size_bytes BIGINT       NULL,
    is_featured     BOOLEAN      NOT NULL DEFAULT FALSE,
    is_slider_item  BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order      INTEGER      NOT NULL DEFAULT 0,
    status          VARCHAR(20)  NOT NULL DEFAULT 'published' CHECK (status IN ('published', 'hidden')),
    uploaded_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_gallery_images_status_order ON public.gallery_images (status, sort_order ASC, uploaded_at DESC);
CREATE INDEX IF NOT EXISTS idx_gallery_images_category ON public.gallery_images (category_id);
CREATE INDEX IF NOT EXISTS idx_gallery_images_featured ON public.gallery_images (is_featured, status);

-- 8. Hero Banners (Optional Title, Subtitle, Button Text & URL)
CREATE TABLE IF NOT EXISTS public.hero_banners (
    id              BIGSERIAL PRIMARY KEY,
    title           VARCHAR(200) NULL,
    subtitle        TEXT         NULL,
    button_text     VARCHAR(80)  NULL,
    button_url      VARCHAR(255) NULL,
    storage_bucket  VARCHAR(80)  NOT NULL DEFAULT 'hero-banners',
    storage_path    TEXT         NOT NULL,
    image_url       TEXT         NOT NULL,
    sort_order      INTEGER      NOT NULL DEFAULT 0,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_hero_banners_active_order ON public.hero_banners (is_active, sort_order ASC, id ASC);

-- 9. Navigation Menus (header, footer)
CREATE TABLE IF NOT EXISTS public.navigation_menus (
    id              BIGSERIAL PRIMARY KEY,
    location        VARCHAR(40)  NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- 10. Navigation Items (Supports standard links, dropdowns, mega menus)
CREATE TABLE IF NOT EXISTS public.navigation_items (
    id               BIGSERIAL PRIMARY KEY,
    menu_id          BIGINT       NOT NULL REFERENCES public.navigation_menus(id) ON DELETE CASCADE,
    parent_id        BIGINT       NULL REFERENCES public.navigation_items(id) ON DELETE CASCADE,
    label            VARCHAR(100) NOT NULL,
    url              VARCHAR(255) NOT NULL DEFAULT '/',
    item_type        VARCHAR(20)  NOT NULL DEFAULT 'link' CHECK (item_type IN ('link', 'dropdown', 'mega')),
    mega_description TEXT         NULL,
    open_in_new_tab  BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order       INTEGER      NOT NULL DEFAULT 0,
    is_visible       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_navigation_items_menu_order ON public.navigation_items (menu_id, parent_id, sort_order ASC, id ASC);

-- 11. Footer Settings (Singleton row id = 1)
CREATE TABLE IF NOT EXISTS public.footer_settings (
    id                SMALLINT     PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    logo_path         TEXT         NULL,
    logo_url          TEXT         NULL,
    site_name         VARCHAR(140) NOT NULL DEFAULT 'Purbo Nijmaona Fakir Bari',
    description       TEXT         NULL,
    contact_address   TEXT         NULL,
    contact_phone     VARCHAR(60)  NULL,
    contact_email     VARCHAR(160) NULL,
    facebook_url      VARCHAR(255) NULL,
    instagram_url     VARCHAR(255) NULL,
    youtube_url       VARCHAR(255) NULL,
    x_url             VARCHAR(255) NULL,
    copyright_text    VARCHAR(255) NOT NULL DEFAULT 'All rights reserved.',
    show_social_links BOOLEAN      NOT NULL DEFAULT TRUE,
    show_contact_info BOOLEAN      NOT NULL DEFAULT TRUE,
    is_visible        BOOLEAN      NOT NULL DEFAULT TRUE,
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- 12. Website Settings (Singleton row id = 1)
CREATE TABLE IF NOT EXISTS public.website_settings (
    id                SMALLINT     PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    site_name         VARCHAR(140) NOT NULL DEFAULT 'Purbo Nijmaona Fakir Bari',
    site_description  TEXT         NULL,
    logo_path         TEXT         NULL,
    logo_url          TEXT         NULL,
    favicon_path      TEXT         NULL,
    favicon_url       TEXT         NULL,
    header_bg_color   VARCHAR(20)  NOT NULL DEFAULT '#14532d',
    header_text_color VARCHAR(20)  NOT NULL DEFAULT '#ffffff',
    footer_bg_color   VARCHAR(20)  NOT NULL DEFAULT '#14532d',
    footer_text_color VARCHAR(20)  NOT NULL DEFAULT '#ecfdf5',
    accent_color      VARCHAR(20)  NOT NULL DEFAULT '#15803d',
    hero_interval_ms  INTEGER      NOT NULL DEFAULT 5000 CHECK (hero_interval_ms BETWEEN 2000 AND 20000),
    seo_title         VARCHAR(200) NULL,
    seo_description   TEXT         NULL,
    social_facebook   VARCHAR(255) NULL,
    social_instagram  VARCHAR(255) NULL,
    social_youtube    VARCHAR(255) NULL,
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- 13. Admin Activity Logs
CREATE TABLE IF NOT EXISTS public.activity_logs (
    id              BIGSERIAL PRIMARY KEY,
    admin_id        BIGINT       NULL REFERENCES public.admins(id) ON DELETE SET NULL,
    action          VARCHAR(120) NOT NULL,
    entity_type     VARCHAR(60)  NULL,
    entity_id       BIGINT       NULL,
    details         JSONB        NULL,
    ip_address      VARCHAR(45)  NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_activity_logs_created ON public.activity_logs (created_at DESC);

-- 14. Configurable Rate Limit Rules (Managed by Super Admin)
CREATE TABLE IF NOT EXISTS public.rate_limit_rules (
    id              BIGSERIAL    PRIMARY KEY,
    scope           VARCHAR(60)  NOT NULL UNIQUE CHECK (scope IN ('admin_login', 'password_recovery', 'public_api', 'gallery_api', 'post_submission', 'admin_api')),
    label           VARCHAR(100) NOT NULL,
    description     TEXT         NULL,
    max_requests    INTEGER      NOT NULL CHECK (max_requests BETWEEN 1 AND 100000),
    window_value    INTEGER      NOT NULL CHECK (window_value BETWEEN 1 AND 100000),
    window_unit     VARCHAR(16)  NOT NULL CHECK (window_unit IN ('seconds', 'minutes', 'hours', 'days')),
    is_enabled      BOOLEAN      NOT NULL DEFAULT TRUE,
    updated_by      BIGINT       NULL REFERENCES public.admins(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- 15. Server-Side Rate Limit Buckets (IP-based and Account-based; never session-based)
CREATE TABLE IF NOT EXISTS public.rate_limit_buckets (
    id                BIGSERIAL   PRIMARY KEY,
    scope             VARCHAR(60) NOT NULL REFERENCES public.rate_limit_rules(scope) ON DELETE CASCADE,
    bucket_type       VARCHAR(20) NOT NULL CHECK (bucket_type IN ('ip', 'account')),
    bucket_key        CHAR(64)    NOT NULL,
    request_count     INTEGER     NOT NULL DEFAULT 0 CHECK (request_count >= 0),
    window_started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    window_expires_at TIMESTAMPTZ NOT NULL,
    last_request_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (scope, bucket_type, bucket_key)
);

CREATE INDEX IF NOT EXISTS idx_rate_limit_buckets_expires ON public.rate_limit_buckets (window_expires_at);
CREATE INDEX IF NOT EXISTS idx_rate_limit_buckets_scope ON public.rate_limit_buckets (scope, window_expires_at);

-- Automatic updated_at trigger
CREATE OR REPLACE FUNCTION public.set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
DECLARE
    tbl TEXT;
BEGIN
    FOREACH tbl IN ARRAY ARRAY[
        'admins', 'post_categories', 'posts', 'content_pages', 'gallery_categories',
        'gallery_images', 'hero_banners', 'navigation_menus',
        'navigation_items', 'footer_settings', 'website_settings',
        'rate_limit_rules'
    ]
    LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_%I_updated_at ON public.%I;', tbl, tbl);
        EXECUTE format(
            'CREATE TRIGGER trg_%I_updated_at BEFORE UPDATE ON public.%I FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();',
            tbl, tbl
        );
    END LOOP;
END $$;
