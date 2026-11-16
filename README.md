# Purbo Nijmaona Fakir Bari — Local Community Website & Secure Admin Panel

A responsive, green-themed local village and community platform built with **HTML5, CSS3, Vanilla JavaScript (ES6+), PHP 8.x (PDO_PGSQL), Supabase PostgreSQL, and Supabase Storage**.

---

## Project Structure

```text
public/                   # Public web document root
  index.php               # Homepage (Header, Hero, Horizontal Slider, Posts, 4-col/2-col Gallery, Featured, Footer)
  posts.php               # Community posts archive (Text, Image, Video)
  post.php                # Dedicated post detail view with HTML5 video playback
  gallery.php             # Full Instagram-style gallery with category filtering
  api-gallery.php         # JSON pagination & category filter endpoint (rate-limited: gallery_api)
  api-posts.php           # JSON public posts feed endpoint (rate-limited: public_api)
  assets/
    css/style.css         # Public responsive green theme & lightbox styles
    js/app.js             # Hero slider, horizontal auto-scroller, mobile drawer, AJAX gallery
    js/lightbox.js        # Zoom/pan, keyboard nav, touch swipe, aspect-ratio lightbox
    images/               # Starter community images
  448662.php              # Front controller dispatching /448662/* to includes/admin-router.php
admin/                    # Internal Admin PHP handlers (blocked from direct web access; served via /448662)
  index.php               # Routed session entry helper
  login.php               # Official login handler at /448662 and /448662/login.php (rate-limited: admin_login)
  logout.php              # CSRF-protected sign out
  forgot-password.php     # Token-based password recovery (rate-limited: password_recovery)
  dashboard.php           # Live PostgreSQL counts & activity logs
  api-stats.php           # Authenticated dashboard JSON stats (rate-limited: admin_api)
  posts.php               # Create, edit, publish/unpublish, delete Text/Image/Video posts (post_submission)
  gallery.php             # Multi-image upload, optional titles, ordering, featured/slider flags
  banners.php             # Hero banner management & slideshow interval
  categories.php          # Gallery & Post category CRUD & ordering
  header.php              # Logo, header colors, link/dropdown/mega menu builder
  footer.php              # Footer logo, links, contact info, social links, copyright
  settings.php            # Site name, favicon, SEO metadata, theme accent color
  rate-limits.php         # Super Admin configurable rate-limiting rules & bucket resets
  admins.php              # Super Admin multi-admin accounts & granular permission assignments
  security.php            # Change password (current/new/confirm + strength rules) & security logs
includes/                 # Non-public PHP backend modules
  env.php                 # .env parser
  config.php              # Security headers, MIME & upload limits, bucket constants
  database.php            # PDO_PGSQL connector & transaction wrapper
  auth.php                # Session hardening, password_hash(), reset tokens, login rate limit integration
  rate_limiter.php        # Configurable PostgreSQL-backed RateLimiter engine
  csrf.php                # Per-session CSRF tokens
  storage.php             # Server-side Supabase Storage REST client (finfo MIME checks)
  functions.php           # XSS escaping, URL sanitization, dynamic query helpers
  header.php / footer.php # Dynamic public header & footer templates
  admin-router.php        # Server-side dispatcher for /448662/* (enforces ADMIN_ROUTED_ENTRY)
  admin-layout.php / admin-footer.php
database/
  schema.sql              # Normalized PostgreSQL tables (including rate_limit_rules & rate_limit_buckets)
  policies.sql            # Row Level Security (RLS), restricted backend role, storage buckets
  seed.sql                # Initial categories, navigation, site defaults, and default rate limits
scripts/
  create-admin.php          # Interactive CLI script to create the first Super Admin
  create-password-reset.php # CLI utility to generate a single-use password reset link
  reset-rate-limits.php     # Emergency CLI utility to reset rate-limit buckets
tests/
  rate_limiter_test.php     # Automated verification test suite for RateLimiter
.env.example                # Environment variable template (no real secrets)
```

---

## Server-Side Rate Limiting System

All rate limits are enforced strictly on the PHP backend and persisted in Supabase PostgreSQL (`public.rate_limit_rules` and `public.rate_limit_buckets`), keyed by hashed client IP address and account identifier (never PHP session ID, so changing sessions cannot bypass limits).

### Default Rate Limit Rules

| Scope | Admin Label | Default Limit | Time Window | Time Unit | Keys Enforced | Behavior |
|---|---|---:|---:|---|---|---|
| `admin_login` | Admin Login | 5 | 15 | `minutes` | IP + Username/Email | Counts failed attempts; clears automatically on successful login; returns HTTP `429` + `Retry-After`. |
| `password_recovery` | Password Recovery | 3 | 1 | `hours` | IP + Email / Token | Limits reset requests and token submissions; returns HTTP `429` + `Retry-After`. |
| `public_api` | Public API | 60 | 1 | `minutes` | IP | Protects `public/api-posts.php`, `public/posts.php`, `public/post.php`; returns HTTP `429` + `Retry-After`. |
| `gallery_api` | Gallery API | 45 | 1 | `minutes` | IP | Protects `public/api-gallery.php` JSON requests; returns HTTP `429` JSON + `Retry-After`. |
| `post_submission` | Post Submission | 20 | 10 | `minutes` | IP + Admin ID | Protects post creation and updates in `admin/posts.php`; returns HTTP `429` + `Retry-After`. |
| `admin_api` | Admin API | 120 | 1 | `minutes` | IP + Admin ID | Protects authenticated Admin Panel POST actions & `admin/api-stats.php`; returns HTTP `429` + `Retry-After`. |

### Super Admin Configuration Options (`/448662/rate-limits.php`)
- **Max Requests**: Integer between `1` and `100,000`.
- **Time Window & Unit**: Integer (`1`–`100,000`) paired with `seconds`, `minutes`, `hours`, or `days`.
- **Enable / Disable Toggle**: Individually toggle enforcement per scope.
- **Automatic Expiration & Recovery**: Expired windows (`window_expires_at <= NOW()`) reset automatically on the next request. Super Admins can also reset active buckets per scope from `/448662/rate-limits.php`, or via CLI: `php scripts/reset-rate-limits.php`.

---

## 1. Supabase Setup Guide

1. Open your Supabase project and navigate to **SQL Editor**.
2. Execute `database/schema.sql`.
3. Open `database/policies.sql`, replace `'CHANGE_ME_STRONG_DB_PASSWORD'` for `community_app_role` with a strong password, and execute it.
   - Enables **Row Level Security (RLS)** on all tables (including `rate_limit_rules` and `rate_limit_buckets`), blocks anonymous writes and sensitive reads, and creates the 4 Supabase Storage buckets (`website-assets`, `hero-banners`, `gallery-images`, `post-media`).
4. Execute `database/seed.sql` to populate initial categories, navigation menus, **Purbo Nijmaona Fakir Bari** defaults, starter media, default rate limit rules, and the initial Super Admin (`tauselhamtamjid`).

## 2. Server Requirements (`PHP 8.1+` & `php.ini`)

- **PHP Version:** `PHP 8.1` or higher (recommended `PHP 8.2` or `PHP 8.3`).
- **Required PHP Extensions:** `pdo_pgsql`, `pgsql`, `curl`, `fileinfo`, `mbstring`, `openssl`, `json`, `session` (plus `pdo_sqlite` to run the offline unit test suite `tests/rate_limiter_test.php`).
- **Recommended `php.ini` Settings:**
  ```ini
  file_uploads = On
  upload_max_filesize = 64M
  post_max_size = 64M
  max_file_uploads = 20
  memory_limit = 256M
  ```

## 3. Configure `.env`, Sign In & Manage Multiple Admins

```bash
cp .env.example .env
# Edit .env:
#   - Set SUPABASE_DB_* credentials for community_app_role
#     (Transaction Pooler port 6543 with user=postgres.PROJECT_REF, or Direct port 5432 with user=postgres)
#   - Set SUPABASE_URL and SUPABASE_SECRET_KEY (server-side sb_secret_ key; never expose in JS). Legacy service_role JWT is also supported via SUPABASE_STORAGE_KEY.
#   - Optionally set MAIL_ENABLED="true" and MAIL_FROM_* for password recovery emails
```

- After executing `database/seed.sql`, sign in at `/448662` using username `tauselhamtamjid` and update the temporary password immediately under **Security & Password** (`/448662/security.php`).
- You can create additional CLI Super Admins anytime with `php scripts/create-admin.php`, or add/edit multiple Admin & Editor accounts directly inside the Admin Panel under **Admin Users** (`/448662/admins.php`) with granular permissions (`Community Posts`, `Photo Gallery`, `Hero Banners`, `Categories`, `Header & Footer`, `Website Settings`).

If `MAIL_ENABLED="false"` in staging, generate a one-time recovery link from the server terminal via:

```bash
php scripts/create-password-reset.php admin@example.com
```

## 4. Running Locally / Admin Panel URL & Web Server Routing

The official Admin Panel URL is **`/448662`** (e.g., `https://YOUR-DOMAIN.com/448662`). Direct requests to `/admin/` or internal `admin/*.php` file paths return HTTP `404`. Because `admin/`, `includes/`, `database/`, `scripts/`, `tests/`, and `.env` live outside the public web root (`public/`), configure one of the following:

- **PHP 8.x built-in server (local/staging check):**
  ```bash
  php -S 127.0.0.1:8000 router.php
  ```
  - Public site: `http://127.0.0.1:8000/`
  - Admin Login: `http://127.0.0.1:8000/448662`
- **Apache VirtualHost:** Set `DocumentRoot /path/to/app/public` with `AllowOverride All` (with `mod_rewrite` enabled). The included `public/.htaccess` automatically rewrites `/448662` and `/448662/*` internally to `/448662.php` while returning `404` for `/admin/*` and direct external requests to `/448662.php`.
- **Nginx:** Set `root /path/to/app/public;` and route `/448662` (including `/448662/assets/*`) internally to `/448662.php` while returning `404` for `/admin/` and direct external `/448662.php` requests:
  ```nginx
  location ^~ /admin { return 404; }
  location ~ ^/448662\.php/ { return 404; }
  if ($request_uri ~ "^/index\.php(\?.*)?$") { return 301 /$1; }

  location = /448662.php {
      internal;
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root/448662.php;
      fastcgi_pass unix:/run/php/php8.2-fpm.sock;
  }

  location ^~ /448662 {
      rewrite ^/448662(/.*)?$ /448662.php last;
  }

  location ~ \.php$ {
      try_files $uri =404;
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
      fastcgi_pass unix:/run/php/php8.2-fpm.sock;
  }

  location ~* \.(sql|md|env)$ { deny all; }
  ```

## 5. Running Automated Rate Limiter Tests

```bash
php tests/rate_limiter_test.php
```


## Custom Landing Pages, About Us, and Code Posts

After applying the original schema and policies, run
`database/migrations/20261115_custom_pages_and_code_posts.sql` once in the Supabase SQL Editor.
The migration adds the `content_pages` table and the custom-code fields/type for posts.
The main `database/schema.sql` and `database/policies.sql` also include these additions for fresh installs.

In the Admin Panel, users with the `manage_pages` permission can open **Landing Pages**.
Create either a Landing Page or the single About Us page, choose its URL slug, and save HTML, CSS,
and JavaScript. Published pages are available at `https://your-domain/<slug>`.

Community Posts now also support **Custom HTML / CSS / JavaScript Post**. Keep the normal
description field as the post excerpt; the custom HTML/CSS/JS is rendered on the post detail page.

Custom code is rendered in a separate iframe with `sandbox="allow-scripts"` and a restrictive
Content Security Policy. It cannot access the parent page or Admin Panel DOM/session, submit forms,
open popups, or make fetch/XHR/WebSocket requests. External images/stylesheets may still load over
HTTPS. Treat any custom code as executable code and only grant `manage_pages` / `manage_posts`
permissions to trusted administrators.

For Apache hosting, ensure `public/` is the document root and its `.htaccess` rewrite rules are enabled.
The built-in PHP development router also resolves one-segment custom page slugs.


## Latest content editor upgrade (15 November 2026)

After applying the original custom-pages migration, run
`database/migrations/20261115_rich_editor_upgrade.sql` in Supabase SQL Editor.
This repeatable upgrade adds the rich post body and one-file code document columns.
The Community Posts admin page now shows the post list first, with a separate Add Post
screen, a WordPress-style rich-text editor (including server-side HTML allowlisting and
Supabase Storage image uploads), and a single-document HTML/CSS/JavaScript mode.
Landing Pages also supports either separate code fields or one complete HTML document.
