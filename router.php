<?php
declare(strict_types=1);

/**
 * Router for PHP built-in development/staging server:
 *   php -S 127.0.0.1:8000 router.php
 *
 * Maps "/" -> public/, "/448662/*" -> protected admin/ dispatcher,
 * returns 404 for legacy "/admin/*" paths, and blocks direct web access
 * to private directories (includes/, database/, scripts/, tests/, storage/) and dotfiles.
 */

$uriPath = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));

// Normalize path traversal attempts
if (str_contains($uriPath, "\0") || str_contains($uriPath, '..')) {
    http_response_code(400);
    exit('Bad request.');
}

// Block private directories, dotfiles, SQL/Markdown/config files
if (
    preg_match('#^/(includes|database|scripts|tests|storage|node_modules)(/|$)#i', $uriPath)
    || preg_match('#(^|/)\.#', $uriPath)
    || preg_match('#\.(sql|md|env|log|json|ts|tsx)$#i', $uriPath)
    || $uriPath === '/router.php'
    || preg_match('#^/448662\.php(/|$)#i', $uriPath)
) {
    http_response_code(404);
    exit('404 Not Found');
}

// Permanently disable old /admin and /admin/* entry points
if (preg_match('#^/admin(/|$)#i', $uriPath)) {
    http_response_code(404);
    exit('404 Not Found');
}

// Official Admin Panel route (/448662 and /448662/*)
if ($uriPath === '/448662' || str_starts_with($uriPath, '/448662/')) {
    require __DIR__ . '/includes/admin-router.php';
    return true;
}

$serveStatic = static function (string $filePath): bool {
    if (!is_file($filePath)) {
        return false;
    }
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        return false;
    }
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
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
    ];
    if (isset($mimeMap[$ext])) {
        header('Content-Type: ' . $mimeMap[$ext]);
    }
    readfile($filePath);
    return true;
};

// Redirect direct /index.php requests to the canonical clean homepage '/'
if ($uriPath === '/index.php') {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /' . $qs, true, 301);
    exit;
}

// Public routes (/)
$publicRoot = realpath(__DIR__ . '/public');
$mappedPath = $uriPath === '/' ? '/index.php' : $uriPath;
$candidate = $publicRoot !== false ? realpath($publicRoot . $mappedPath) : false;

if ($publicRoot !== false && $candidate !== false && str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)) {
    if (str_ends_with($candidate, '.php') && is_file($candidate)) {
        require $candidate;
        return true;
    }
    if ($serveStatic($candidate)) {
        return true;
    }
}

// Resolve clean public post URLs by their stable database slug.
if (preg_match('#^/post/([a-z0-9]+(?:-[a-z0-9]+)*)/?$#i', $uriPath, $postMatch)) {
    $_GET['slug'] = strtolower($postMatch[1]);
    require $publicRoot . '/post.php';
    return true;
}

// Resolve clean one-segment custom page slugs from the content_pages table.
if (preg_match('#^/[a-z0-9]+(?:-[a-z0-9]+)*/?$#', $uriPath)) {
    $_GET['slug'] = trim($uriPath, '/');
    require $publicRoot . '/page.php';
    return true;
}

http_response_code(404);
echo '404 Not Found';
return true;
