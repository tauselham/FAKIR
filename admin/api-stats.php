<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

if (!defined('ADMIN_ROUTED_ENTRY')) {
    http_response_code(404);
    exit('404 Not Found');
}

send_admin_nocache_headers();
start_secure_session();
$admin = current_admin();
if (!$admin) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

try {
    // Enforce server-side Admin API rate limit (IP + authenticated admin ID)
    enforce_rate_limit('admin_api', [
        'ip'      => client_ip(),
        'account' => 'admin:' . (int) $admin['id'],
    ], true);

    $pdo = db();

    echo json_encode([
        'published_posts' => (int) $pdo->query("SELECT COUNT(*) FROM public.posts WHERE status = 'published'")->fetchColumn(),
        'gallery_images'  => (int) $pdo->query('SELECT COUNT(*) FROM public.gallery_images')->fetchColumn(),
        'video_posts'     => (int) $pdo->query("SELECT COUNT(*) FROM public.posts WHERE post_type = 'video'")->fetchColumn(),
        'hero_banners'    => (int) $pdo->query('SELECT COUNT(*) FROM public.hero_banners')->fetchColumn(),
        'generated_at'    => gmdate('c'),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load dashboard statistics.']);
}
