<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

enforce_rate_limit('public_api', ['ip' => client_ip()]);

$slug = strtolower(trim((string) ($_GET['slug'] ?? '')));
$id = max(0, (int) ($_GET['id'] ?? 0));
if ($slug === '' && $id <= 0) {
    redirect_to('/posts.php');
}

if ($slug !== '') {
    $stmt = db()->prepare(
        "SELECT p.*, pc.name AS category_name, pc.slug AS category_slug
         FROM public.posts p
         LEFT JOIN public.post_categories pc ON pc.id = p.category_id
         WHERE p.slug = :slug AND p.status = 'published'
         LIMIT 1"
    );
    $stmt->execute([':slug' => $slug]);
} else {
    $stmt = db()->prepare(
        "SELECT p.*, pc.name AS category_name, pc.slug AS category_slug
         FROM public.posts p
         LEFT JOIN public.post_categories pc ON pc.id = p.category_id
         WHERE p.id = :id AND p.status = 'published'
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
}
$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    $customPageTitle = 'পোস্টটি পাওয়া যায়নি';
    require_once dirname(__DIR__) . '/includes/header.php';
    echo '<div class="container section"><div class="empty-state"><h1>পোস্টটি পাওয়া যায়নি</h1><p>পোস্টটি হয়তো অপ্রকাশিত করা হয়েছে অথবা সরিয়ে ফেলা হয়েছে।</p><p><a class="btn-primary" href="/posts.php">পোস্টে ফিরে যান</a></p></div></div>';
    require_once dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$customPageTitle = $post['title'] ?: 'গ্রামের খবর';
$customPageDesc = mb_substr(trim((string) $post['description']), 0, 155);
require_once dirname(__DIR__) . '/includes/header.php';
?>

<article class="post-detail-wrap">
  <p style="margin: 0 0 18px;">
    <a href="/posts.php" class="section-link">← Back to গ্রামের খবর ও পোস্ট</a>
  </p>

  <div class="post-meta" style="margin-bottom: 10px;">
    <?php if (!empty($post['category_name'])): ?>
      <span><?= e($post['category_name']) ?></span>
      <span aria-hidden="true">·</span>
    <?php endif; ?>
    <time datetime="<?= e((string) ($post['published_at'] ?? $post['created_at'])) ?>">
      <?= e(format_date($post['published_at'] ?? $post['created_at'], 'd/m/Y')) ?>
    </time>
  </div>

  <?php if (!empty($post['title'])): ?>
    <h1 class="section-title" style="margin-bottom: 14px;"><?= e($post['title']) ?></h1>
  <?php endif; ?>

  <?php if ($post['post_type'] === 'custom'): ?>
    <iframe
      class="custom-content-frame"
      src="/content-frame.php?type=post&amp;id=<?= (int) $post['id'] ?>"
      title="<?= e($post['title'] ?: 'কমিউনিটির পোস্ট') ?>"
      sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation"
      loading="lazy"
      referrerpolicy="no-referrer"
      style="display:block;width:100%;min-height:900px;border:0;background:#fff;"
    ></iframe>
  <?php elseif ($post['post_type'] === 'image' && !empty($post['media_url'])): ?>
    <div class="post-detail-media">
      <img src="<?= e(safe_url($post['media_url'])) ?>" alt="<?= e($post['title'] ?: 'পোস্টের ছবি') ?>" referrerpolicy="no-referrer">
    </div>
  <?php elseif ($post['post_type'] === 'video' && !empty($post['media_url'])): ?>
    <div class="post-detail-media">
      <video src="<?= e(safe_url($post['media_url'])) ?>" controls playsinline preload="metadata"></video>
    </div>
  <?php endif; ?>

  <?php if ($post['post_type'] !== 'custom'): ?>
    <div class="post-detail-content"><?php if (!empty($post['content_html'])): ?><?= $post['content_html'] ?><?php else: ?><?= nl2br(e($post['description'])) ?><?php endif; ?></div>
  <?php endif; ?>
</article>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
