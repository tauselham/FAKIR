<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

$category = trim((string) ($_GET['category'] ?? 'all'));
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 8;
$offset   = ($page - 1) * $perPage;

try {
    enforce_rate_limit('gallery_api', ['ip' => client_ip()], true);
    $pdo = db();

    if ($category !== '' && $category !== 'all') {
        $stmt = $pdo->prepare(
            "SELECT gi.id, gi.title, gi.description, gi.image_url, gi.uploaded_at, gc.name AS category_name
             FROM public.gallery_images gi
             INNER JOIN public.gallery_categories gc ON gc.id = gi.category_id
             WHERE gi.status = 'published' AND gc.slug = :slug
             ORDER BY gi.sort_order ASC, gi.uploaded_at DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':slug', $category, PDO::PARAM_STR);
    } else {
        $stmt = $pdo->prepare(
            "SELECT gi.id, gi.title, gi.description, gi.image_url, gi.uploaded_at, gc.name AS category_name
             FROM public.gallery_images gi
             LEFT JOIN public.gallery_categories gc ON gc.id = gi.category_id
             WHERE gi.status = 'published'
             ORDER BY gi.sort_order ASC, gi.uploaded_at DESC
             LIMIT :limit OFFSET :offset"
        );
    }

    $stmt->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $hasMore = count($rows) > $perPage;
    $items = array_slice($rows, 0, $perPage);

    foreach ($items as &$item) {
        $item['formatted_date'] = format_date($item['uploaded_at']);
        $item['image_url'] = safe_url($item['image_url'], '');
    }

    echo json_encode([
        'items'    => $items,
        'has_more' => $hasMore,
        'page'     => $page,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'গ্যালারির ছবি লোড করা যায়নি।']);
}
