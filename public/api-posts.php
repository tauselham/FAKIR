<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

$category = trim((string) ($_GET['category'] ?? 'all'));
$type     = trim((string) ($_GET['type'] ?? 'all'));
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 9;
$offset   = ($page - 1) * $perPage;

try {
    // Enforce server-side Public API rate limit (IP-based, independent of session)
    enforce_rate_limit('public_api', ['ip' => client_ip()], true);
    $pdo = db();
    $where = ["p.status = 'published'"];
    $params = [];

    if ($category !== '' && $category !== 'all') {
        $where[] = 'pc.slug = :cat_slug';
        $params[':cat_slug'] = $category;
    }

    if (in_array($type, ['text', 'image', 'video'], true)) {
        $where[] = 'p.post_type = :post_type';
        $params[':post_type'] = $type;
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT p.id, p.post_type, p.title, p.slug, p.description, p.media_url, p.published_at, p.created_at,
                   pc.name AS category_name, pc.slug AS category_slug
            FROM public.posts p
            LEFT JOIN public.post_categories pc ON pc.id = p.category_id
            WHERE {$whereSql}
            ORDER BY COALESCE(p.published_at, p.created_at) DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $perPage;
    $items = array_slice($rows, 0, $perPage);

    foreach ($items as &$item) {
        $item['formatted_date'] = format_date($item['published_at'] ?? $item['created_at']);
        $item['media_url']      = !empty($item['media_url']) ? safe_url($item['media_url'], '') : null;
    }

    echo json_encode([
        'items'    => $items,
        'has_more' => $hasMore,
        'page'     => $page,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'গ্রামের পোস্ট লোড করা যায়নি।']);
}
