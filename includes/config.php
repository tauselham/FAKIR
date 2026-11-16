<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

define('ROOT_PATH', dirname(__DIR__));
load_env_file(ROOT_PATH . '/.env');

define('APP_ENV', env_value('APP_ENV', 'production'));
define('APP_DEBUG', filter_var(env_value('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL));
define('APP_URL', rtrim((string) env_value('APP_URL', 'http://localhost:8000'), '/'));
define('ADMIN_BASE_PATH', '/448662');

// Error reporting: never leak stack traces or DB details in production
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// Security headers for web requests
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    if (!defined('SANDBOXED_CONTENT_FRAME')) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob: https:; media-src 'self' blob: https:; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
    }
}

// Session Configuration
define('SESSION_NAME', (string) env_value('SESSION_NAME', 'village_admin_session'));
define('SESSION_IDLE_TIMEOUT', max(300, (int) env_value('SESSION_IDLE_TIMEOUT', '1800')));
define('SESSION_ABSOLUTE_TIMEOUT', max(1800, (int) env_value('SESSION_ABSOLUTE_TIMEOUT', '28800')));

// Upload Size Limits (bytes)
define('MAX_IMAGE_UPLOAD_BYTES', 10 * 1024 * 1024); // 10 MB
define('MAX_VIDEO_UPLOAD_BYTES', 50 * 1024 * 1024); // 50 MB
define('MAX_ASSET_UPLOAD_BYTES', 5 * 1024 * 1024);  // 5 MB

// Allowed MIME types verified via finfo
define('ALLOWED_IMAGE_MIMES', [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
]);

define('ALLOWED_BANNER_MIMES', [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
]);

define('ALLOWED_LOGO_MIMES', [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
]);

define('ALLOWED_FAVICON_MIMES', [
    'image/png'                => 'png',
    'image/webp'               => 'webp',
    'image/x-icon'             => 'ico',
    'image/vnd.microsoft.icon' => 'ico',
]);

define('ALLOWED_ASSET_MIMES', [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/x-icon' => 'ico',
    'image/vnd.microsoft.icon' => 'ico',
]);

define('ALLOWED_VIDEO_MIMES', [
    'video/mp4'  => 'mp4',
    'video/webm' => 'webm',
]);

// Supabase Storage Buckets
define('BUCKET_WEBSITE_ASSETS', (string) env_value('BUCKET_WEBSITE_ASSETS', 'website-assets'));
define('BUCKET_HERO_BANNERS', (string) env_value('BUCKET_HERO_BANNERS', 'hero-banners'));
define('BUCKET_GALLERY_IMAGES', (string) env_value('BUCKET_GALLERY_IMAGES', 'gallery-images'));
define('BUCKET_POST_MEDIA', (string) env_value('BUCKET_POST_MEDIA', 'post-media'));
