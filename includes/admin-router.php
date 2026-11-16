<?php
declare(strict_types=1);

/**
 * Server-Side Admin Panel Dispatcher (/448662).
 *
 * Exposes the internal admin/ directory exclusively under ADMIN_BASE_PATH (/448662),
 * prevents path traversal and direct file execution, and serves admin static assets.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$rawUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$uriPath = rawurldecode((string) (parse_url($rawUri, PHP_URL_PATH) ?: '/'));

if (str_contains($uriPath, "\0") || str_contains($uriPath, '..')) {
    http_response_code(400);
    exit('Bad request.');
}

$base = rtrim(ADMIN_BASE_PATH, '/');
if ($uriPath !== $base && !str_starts_with($uriPath, $base . '/')) {
    http_response_code(404);
    exit('404 Not Found');
}

$subpath = ltrim(substr($uriPath, strlen($base)), '/');
$adminRoot = realpath(ROOT_PATH . '/admin');
if ($adminRoot === false) {
    http_response_code(404);
    exit('404 Not Found');
}

if (!defined('ADMIN_ROUTED_ENTRY')) {
    define('ADMIN_ROUTED_ENTRY', true);
}

// Serve static Admin CSS/JS assets under /448662/assets/*
if (str_starts_with($subpath, 'assets/')) {
    $assetsRoot = realpath($adminRoot . '/assets');
    $candidate = realpath($adminRoot . '/' . $subpath);

    if (
        $assetsRoot === false
        || $candidate === false
        || !str_starts_with($candidate, $assetsRoot . DIRECTORY_SEPARATOR)
        || !is_file($candidate)
    ) {
        http_response_code(404);
        exit('404 Not Found');
    }

    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    $mimeMap = [
        'css'  => 'text/css; charset=UTF-8',
        'js'   => 'application/javascript; charset=UTF-8',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
    ];
    if (!isset($mimeMap[$ext])) {
        http_response_code(404);
        exit('404 Not Found');
    }

    header('Content-Type: ' . $mimeMap[$ext]);
    header('Cache-Control: private, max-age=3600');
    readfile($candidate);
    exit;
}

// Entry route: /448662 or /448662/ renders the Admin Login page (or redirects to dashboard if signed in)
if ($subpath === '' || $subpath === 'index.php') {
    $_SERVER['SCRIPT_NAME'] = admin_url('login.php');
    $_SERVER['PHP_SELF']    = admin_url('login.php');
    require $adminRoot . '/login.php';
    exit;
}

// Strict allowlist of internal Admin PHP handlers
$allowedEndpoints = [
    'login.php',
    'logout.php',
    'forgot-password.php',
    'dashboard.php',
    'api-stats.php',
    'posts.php',
    'editor-image-upload.php',
    'pages.php',
    'gallery.php',
    'banners.php',
    'popups.php',
    'categories.php',
    'header.php',
    'footer.php',
    'settings.php',
    'rate-limits.php',
    'admins.php',
    'security.php',
];

if (!in_array($subpath, $allowedEndpoints, true)) {
    http_response_code(404);
    exit('404 Not Found');
}

$targetFile = $adminRoot . DIRECTORY_SEPARATOR . $subpath;
if (!is_file($targetFile)) {
    http_response_code(404);
    exit('404 Not Found');
}

$_SERVER['SCRIPT_NAME'] = admin_url($subpath);
$_SERVER['PHP_SELF']    = admin_url($subpath);
require $targetFile;
exit;
