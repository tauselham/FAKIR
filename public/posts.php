<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

enforce_rate_limit('public_api', ['ip' => client_ip()]);

$pdo = db();
$categories = $pdo->query(
    'SELECT * FROM public.post_categories WHERE is_active = TRUE ORDER BY sort_order ASC, id ASC'
)->fetchAll();

$catSlug = trim((string) ($_GET['category'] ?? 'all'));
$postType = trim((string) ($_GET['type'] ?? 'all'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 9;
$offset = ($page - 1) * $perPage;

$where = ["p.status = 'published'"];
$params = [];

if ($catSlug !== '' && $catSlug !== 'all') {
    $where[] = 'pc.slug = :cat_slug';
    $params[':cat_slug'] = $catSlug;
}

if (in_array($postType, ['text', 'image', 'video', 'custom'], true)) {
    $where[] = 'p.post_type = :post_type';
    $params[':post_type'] = $postType;
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM public.posts p LEFT JOIN public.post_categories pc ON pc.id = p.category_id WHERE {$whereSql}"
);
$countStmt->execute($params);
$totalPosts = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalPosts / $perPage));

$sql = "SELECT p.*, pc.name AS category_name, pc.slug AS category_slug
        FROM public.posts p
        LEFT JOIN public.post_categories pc ON pc.id = p.category_id
        WHERE {$whereSql}
        ORDER BY COALESCE(p.published_at, p.created_at) DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll();

$customPageTitle = 'গ্রামের খবর ও পোস্ট';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<section class="section">
  <div class="container">
    <div class="section-header">
      <div>
        <h1 class="section-title">গ্রামের খবর ও পোস্ট</h1>
        <p class="section-subtitle">আমাদের গ্রামের গল্প, ঘোষণা, আয়োজনের খবর এবং ভিডিও।</p>
      </div>
    </div>

    <div class="category-bar" aria-label="বিভাগ অনুযায়ী পোস্ট বাছাই করুন">
      <a href="/posts.php?category=all&amp;type=<?= e(urlencode($postType)) ?>" class="cat-tab <?= $catSlug === 'all' ? 'is-active' : '' ?>">সব বিভাগ</a>
      <?php foreach ($categories as $cat): ?>
        <a href="/posts.php?category=<?= e(urlencode($cat['slug'])) ?>&amp;type=<?= e(urlencode($postType)) ?>" class="cat-tab <?= $catSlug === $cat['slug'] ? 'is-active' : '' ?>">
          <?= e($cat['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="category-bar" aria-label="পোস্টের ধরন অনুযায়ী বাছাই করুন" style="margin-top: -12px;">
      <?php foreach (['all' => 'সব ধরন', 'text' => 'লেখা', 'image' => 'ছবি', 'video' => 'ভিডিও', 'custom' => 'কাস্টম কোড'] as $key => $label): ?>
        <a href="/posts.php?category=<?= e(urlencode($catSlug)) ?>&amp;type=<?= e($key) ?>" class="cat-tab <?= $postType === $key ? 'is-active' : '' ?>">
          <?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($posts)): ?>
      <div class="empty-state">আপনার নির্বাচিত বিভাগে কোনো পোস্ট পাওয়া যায়নি।</div>
    <?php else: ?>
      <div class="posts-grid">
        <?php foreach ($posts as $post): ?>
          <article class="post-card">
            <?php if ($post['post_type'] === 'image' && !empty($post['media_url'])): ?>
              <a href="/post.php?id=<?= (int) $post['id'] ?>" class="post-media">
                <img src="<?= e(safe_url($post['media_url'])) ?>" alt="<?= e($post['title'] ?: 'পোস্টের ছবি') ?>" loading="lazy" referrerpolicy="no-referrer">
              </a>
            <?php elseif ($post['post_type'] === 'video' && !empty($post['media_url'])): ?>
              <div class="post-media">
                <video src="<?= e(safe_url($post['media_url'])) ?>" preload="metadata" controls playsinline></video>
                <span class="post-video-badge">ভিডিও</span>
              </div>
            <?php endif; ?>

            <div class="post-body">
              <div class="post-meta">
                <?php if (!empty($post['category_name'])): ?>
                  <span><?= e($post['category_name']) ?></span>
                  <span aria-hidden="true">·</span>
                <?php endif; ?>
                <time datetime="<?= e((string) ($post['published_at'] ?? $post['created_at'])) ?>">
                  <?= e(format_date($post['published_at'] ?? $post['created_at'])) ?>
                </time>
              </div>
              <?php if (!empty($post['title'])): ?>
                <h2 class="post-title"><a href="/post.php?id=<?= (int) $post['id'] ?>"><?= e($post['title']) ?></a></h2>
              <?php endif; ?>
              <p class="post-excerpt"><?= e($post['description']) ?></p>
              <a href="/post.php?id=<?= (int) $post['id'] ?>" class="post-read-link">বিস্তারিত পড়ুন →</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <nav class="gallery-actions" aria-label="পোস্টের পৃষ্ঠা" style="gap: 8px;">
          <?php if ($page > 1): ?>
            <a class="btn-secondary" href="/posts.php?category=<?= e(urlencode($catSlug)) ?>&amp;type=<?= e(urlencode($postType)) ?>&amp;page=<?= $page - 1 ?>">← আগের পৃষ্ঠা</a>
          <?php endif; ?>
          <span style="padding: 10px 14px; font-size: 0.9rem; color: var(--text-muted);">
            Page <?= $page ?> of <?= $totalPages ?>
          </span>
          <?php if ($page < $totalPages): ?>
            <a class="btn-secondary" href="/posts.php?category=<?= e(urlencode($catSlug)) ?>&amp;type=<?= e(urlencode($postType)) ?>&amp;page=<?= $page + 1 ?>">পরের পৃষ্ঠা</a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
