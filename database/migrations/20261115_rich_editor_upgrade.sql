-- Safe, repeatable upgrade for the WordPress-style post editor and
-- one-file HTML/CSS/JavaScript editor modes added after the initial pages migration.
-- Run this in Supabase SQL Editor after replacing the application files.

ALTER TABLE public.posts
    ADD COLUMN IF NOT EXISTS content_html TEXT NULL,
    ADD COLUMN IF NOT EXISTS code_document TEXT NULL;

ALTER TABLE public.content_pages
    ADD COLUMN IF NOT EXISTS code_document TEXT NULL;
