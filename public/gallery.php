<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

$pdo = db();
$categories = $pdo->query(
    'SELECT * FROM public.gallery_categories WHERE is_active = TRUE ORDER BY sort_order ASC, id ASC'
)->fetchAll();

$initialCat = trim((string) ($_GET['category'] ?? 'all'));
$perPage = 16;

if ($initialCat !== '' && $initialCat !== 'all') {
    $stmt = $pdo->prepare(
        "SELECT gi.*, gc.name AS category_name, gc.slug AS category_slug
         FROM public.gallery_images gi
         INNER JOIN public.gallery_categories gc ON gc.id = gi.category_id
         WHERE gi.status = 'published' AND gc.slug = :slug
         ORDER BY gi.sort_order ASC, gi.uploaded_at DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':slug', $initialCat, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare(
        "SELECT gi.*, gc.name AS category_name, gc.slug AS category_slug
         FROM public.gallery_images gi
         LEFT JOIN public.gallery_categories gc ON gc.id = gi.category_id
         WHERE gi.status = 'published'
         ORDER BY gi.sort_order ASC, gi.uploaded_at DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

$hasMore = count($rows) > $perPage;
$images = array_slice($rows, 0, $perPage);

$customPageTitle = 'ছবির গ্যালারি';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<section class="section">
  <div class="container">
    <div class="section-header">
      <div>
        <h1 class="section-title">Village ছবির গ্যালারি</h1>
        <p class="section-subtitle">বিভাগ অনুযায়ী গ্রামের স্মৃতির ছবি দেখুন। যেকোনো ছবিতে ক্লিক করে বড় করুন অথবা সোয়াইপ করে অন্য ছবি দেখুন।</p>
      </div>
    </div>

    <div class="category-bar" id="gallery-category-bar" data-active-category="<?= e($initialCat) ?>" role="tablist" aria-label="বিভাগ অনুযায়ী ছবি বাছাই করুন">
      <button type="button" class="cat-tab <?= $initialCat === 'all' ? 'is-active' : '' ?>" data-category="all">সব ছবি</button>
      <?php foreach ($categories as $cat): ?>
        <button type="button" class="cat-tab <?= $initialCat === $cat['slug'] ? 'is-active' : '' ?>" data-category="<?= e($cat['slug']) ?>">
          <?= e($cat['name']) ?>
        </button>
      <?php endforeach; ?>
    </div>

    <div class="ig-gallery-grid" id="ig-gallery-grid" data-page="1">
      <?php if (empty($images)): ?>
        <div class="empty-state" style="grid-column: 1 / -1;">এই বিভাগে এখনো কোনো ছবি নেই।</div>
      <?php else: ?>
        <?php foreach ($images as $img): ?>
          <button
            type="button"
            class="ig-photo-card"
            data-lightbox-item
            data-src="<?= e(safe_url($img['image_url'])) ?>"
            data-title="<?= e($img['title'] ?? '') ?>"
            data-desc="<?= e($img['description'] ?? '') ?>"
            data-category="<?= e($img['category_name'] ?? '') ?>"
            data-date="<?= e(format_date($img['uploaded_at'])) ?>"
            aria-label="<?= e($img['title'] ?: 'গ্রামের ছবি দেখুন') ?>"
          >
            <img
              src="<?= e(safe_url($img['image_url'])) ?>"
              alt="<?= e($img['title'] ?: 'Community photograph') ?>"
              loading="lazy"
              referrerpolicy="no-referrer"
            >
            <span class="ig-photo-overlay">
              <?php if (!empty($img['title'])): ?>
                <span class="ig-photo-caption"><?= e($img['title']) ?></span>
              <?php endif; ?>
            </span>
          </button>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="gallery-actions">
      <button type="button" class="btn-primary" id="gallery-load-more" <?= $hasMore ? '' : 'hidden' ?>>
        Load More Photos
      </button>
    </div>
  </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
