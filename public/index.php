<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

$pdo = db();
$siteSettings = get_website_settings();

// 1. Active Hero Banners
$banners = $pdo->query(
    'SELECT * FROM public.hero_banners WHERE is_active = TRUE ORDER BY sort_order ASC, id ASC'
)->fetchAll();

// 2. Gallery Categories & Horizontal Slider Items
$galleryCategories = $pdo->query(
    'SELECT * FROM public.gallery_categories WHERE is_active = TRUE ORDER BY sort_order ASC, id ASC'
)->fetchAll();

$sliderItems = $pdo->query(
    "SELECT gi.*, gc.name AS category_name, gc.slug AS category_slug
     FROM public.gallery_images gi
     LEFT JOIN public.gallery_categories gc ON gc.id = gi.category_id
     WHERE gi.status = 'published' AND gi.is_slider_item = TRUE
     ORDER BY gi.sort_order ASC, gi.uploaded_at DESC
     LIMIT 16"
)->fetchAll();

// 3. Recent Published গ্রামের খবর ও পোস্ট
$recentPosts = $pdo->query(
    "SELECT p.*, pc.name AS category_name, pc.slug AS category_slug
     FROM public.posts p
     LEFT JOIN public.post_categories pc ON pc.id = p.category_id
     WHERE p.status = 'published'
     ORDER BY COALESCE(p.published_at, p.created_at) DESC
     LIMIT 6"
)->fetchAll();

// 4. Initial Instagram-Style ছবির গ্যালারি (8 items -> 2 clean rows of 4 on desktop)
$initialCat = trim((string) ($_GET['category'] ?? 'all'));
$perPage = 8;

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
    $galleryRows = $stmt->fetchAll();
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
    $galleryRows = $stmt->fetchAll();
}

$hasMoreGallery = count($galleryRows) > $perPage;
$galleryImages = array_slice($galleryRows, 0, $perPage);

// 5. Featured Images with Optional Titles
$featuredImages = $pdo->query(
    "SELECT gi.*, gc.name AS category_name
     FROM public.gallery_images gi
     LEFT JOIN public.gallery_categories gc ON gc.id = gi.category_id
     WHERE gi.status = 'published' AND gi.is_featured = TRUE
     ORDER BY gi.sort_order ASC, gi.uploaded_at DESC
     LIMIT 4"
)->fetchAll();

require_once dirname(__DIR__) . '/includes/header.php';
?>

<!-- Section 2: Full-Width Hero Banner Slideshow -->
<?php if (!empty($banners)): ?>
<section class="hero-section" id="hero-slider" data-interval="<?= (int) ($siteSettings['hero_interval_ms'] ?? 5500) ?>" aria-label="গ্রামের বিশেষ মুহূর্ত">
  <div class="hero-track">
    <?php foreach ($banners as $banner): ?>
      <?php
        $hasTitle = trim((string) ($banner['title'] ?? '')) !== '';
        $hasSub   = trim((string) ($banner['subtitle'] ?? '')) !== '';
        $hasBtn   = trim((string) ($banner['button_text'] ?? '')) !== '' && trim((string) ($banner['button_url'] ?? '')) !== '';
        $hasCopy  = $hasTitle || $hasSub || $hasBtn;
      ?>
      <div class="hero-slide">
        <img
          src="<?= e(safe_url($banner['image_url'])) ?>"
          alt="<?= e($hasTitle ? $banner['title'] : 'গ্রামের ব্যানার') ?>"
          class="hero-img"
          referrerpolicy="no-referrer"
        >
        <?php if ($hasCopy): ?>
          <div class="hero-scrim" aria-hidden="true"></div>
          <div class="hero-content">
            <div class="container">
              <div class="hero-copy">
                <?php if ($hasTitle): ?>
                  <h1 class="hero-title"><?= e($banner['title']) ?></h1>
                <?php endif; ?>
                <?php if ($hasSub): ?>
                  <p class="hero-subtitle"><?= e($banner['subtitle']) ?></p>
                <?php endif; ?>
                <?php if ($hasBtn): ?>
                  <a href="<?= e(safe_url($banner['button_url'])) ?>" class="hero-btn">
                    <?= e($banner['button_text']) ?>
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (count($banners) > 1): ?>
    <button type="button" class="hero-nav-btn hero-prev" aria-label="আগের ব্যানার">‹</button>
    <button type="button" class="hero-nav-btn hero-next" aria-label="পরের ব্যানার">›</button>
    <div class="hero-dots" aria-label="ব্যানার নির্বাচন">
      <?php foreach ($banners as $idx => $_): ?>
        <button type="button" class="hero-dot <?= $idx === 0 ? 'is-active' : '' ?>" aria-label="এই স্লাইডে যান <?= $idx + 1 ?>"></button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- Section 3: Horizontal Auto-Scrolling Highlights & Categories -->
<?php if (!empty($sliderItems)): ?>
<section class="slider-section" aria-label="গ্রামের দৃশ্য ও বিশেষ মুহূর্ত">
  <div class="container">
    <div class="slider-header">
      <h2 class="slider-heading">গ্রামের পথে-প্রান্তরে</h2>
      <div class="slider-controls">
        <button type="button" class="slider-arrow" id="hscroll-prev" aria-label="বামে সরান">‹</button>
        <button type="button" class="slider-arrow" id="hscroll-next" aria-label="ডানে সরান">›</button>
      </div>
    </div>

    <div class="hscroll-viewport" id="hscroll-viewport">
      <div class="hscroll-track">
        <?php foreach ($sliderItems as $item): ?>
          <?php $catSlug = $item['category_slug'] ?? 'all'; ?>
          <a href="/gallery.php?category=<?= e(urlencode($catSlug)) ?>" class="hscroll-card">
            <div class="hscroll-thumb">
              <img
                src="<?= e(safe_url($item['image_url'])) ?>"
                alt="<?= e($item['title'] ?: ($item['category_name'] ?: 'Village moment')) ?>"
                loading="lazy"
                referrerpolicy="no-referrer"
              >
            </div>
            <div class="hscroll-body">
              <?php if (!empty($item['title'])): ?>
                <p class="hscroll-title"><?= e($item['title']) ?></p>
              <?php endif; ?>
              <?php if (!empty($item['category_name'])): ?>
                <span class="hscroll-cat"><?= e($item['category_name']) ?></span>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Section 4: Recent গ্রামের খবর ও পোস্ট -->
<section class="section" id="posts-section">
  <div class="container">
    <div class="section-header">
      <div>
        <h2 class="section-title">গ্রামের খবর ও পোস্ট</h2>
        <p class="section-subtitle">স্থানীয় ঘোষণা, আয়োজন, গল্প এবং এলাকার ভিডিও।</p>
      </div>
      <a href="/posts.php" class="section-link">সব পোস্ট দেখুন →</a>
    </div>

    <?php if (empty($recentPosts)): ?>
      <div class="empty-state">এখনো কোনো পোস্ট প্রকাশ করা হয়নি।</div>
    <?php else: ?>
      <div class="posts-grid">
        <?php foreach ($recentPosts as $post): ?>
          <article class="post-card">
            <?php if ($post['post_type'] === 'image' && !empty($post['media_url'])): ?>
              <a href="/post/<?= e(rawurlencode((string) $post['slug'])) ?>" class="post-media">
                <img
                  src="<?= e(safe_url($post['media_url'])) ?>"
                  alt="<?= e($post['title'] ?: 'Community post image') ?>"
                  loading="lazy"
                  referrerpolicy="no-referrer"
                >
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
                <h3 class="post-title">
                  <a href="/post/<?= e(rawurlencode((string) $post['slug'])) ?>"><?= e($post['title']) ?></a>
                </h3>
              <?php endif; ?>

              <p class="post-excerpt"><?= e($post['description']) ?></p>

              <a href="/post/<?= e(rawurlencode((string) $post['slug'])) ?>" class="post-read-link">বিস্তারিত পড়ুন →</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- Section 5: Instagram-Style ছবির গ্যালারি (4-col desktop / 2-col mobile) -->
<section class="section section-tinted" id="gallery-section">
  <div class="container">
    <div class="section-header">
      <div>
        <h2 class="section-title">Village ছবির গ্যালারি</h2>
        <p class="section-subtitle">যেকোনো ছবিতে ক্লিক করে বড় করে দেখুন এবং জুম বা সোয়াইপ করে ছবি বদলান।</p>
      </div>
      <a href="/gallery.php" class="section-link">সব ছবি দেখুন →</a>
    </div>

    <div class="category-bar" id="gallery-category-bar" data-active-category="<?= e($initialCat) ?>" role="tablist" aria-label="বিভাগ অনুযায়ী ছবি বাছাই করুন">
      <button type="button" class="cat-tab <?= $initialCat === 'all' ? 'is-active' : '' ?>" data-category="all">সব ছবি</button>
      <?php foreach ($galleryCategories as $cat): ?>
        <button type="button" class="cat-tab <?= $initialCat === $cat['slug'] ? 'is-active' : '' ?>" data-category="<?= e($cat['slug']) ?>">
          <?= e($cat['name']) ?>
        </button>
      <?php endforeach; ?>
    </div>

    <div class="ig-gallery-grid" id="ig-gallery-grid" data-page="1">
      <?php if (empty($galleryImages)): ?>
        <div class="empty-state" style="grid-column: 1 / -1;">এই বিভাগে এখনো কোনো ছবি যোগ করা হয়নি।</div>
      <?php else: ?>
        <?php foreach ($galleryImages as $img): ?>
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
              alt="<?= e($img['title'] ?: 'Village community photograph') ?>"
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
      <button type="button" class="btn-primary" id="gallery-load-more" <?= $hasMoreGallery ? '' : 'hidden' ?>>
        Load More Photos
      </button>
    </div>
  </div>
</section>

<!-- Section 6: Featured Images with Optional Titles -->
<?php if (!empty($featuredImages)): ?>
<section class="section">
  <div class="container">
    <div class="section-header">
      <div>
        <h2 class="section-title">স্মরণীয় মুহূর্ত</h2>
        <p class="section-subtitle">গ্রামের ঐতিহ্য ও মানুষের জীবনযাত্রার নির্বাচিত কিছু ছবি।</p>
      </div>
    </div>

    <div class="featured-grid">
      <?php foreach ($featuredImages as $feat): ?>
        <figure class="featured-card" style="margin: 0;">
          <button
            type="button"
            class="featured-frame"
            data-lightbox-item
            data-src="<?= e(safe_url($feat['image_url'])) ?>"
            data-title="<?= e($feat['title'] ?? '') ?>"
            data-desc="<?= e($feat['description'] ?? '') ?>"
            data-category="<?= e($feat['category_name'] ?? '') ?>"
            data-date="<?= e(format_date($feat['uploaded_at'])) ?>"
            aria-label="<?= e($feat['title'] ?: 'বিশেষ ছবিটি দেখুন') ?>"
          >
            <img
              src="<?= e(safe_url($feat['image_url'])) ?>"
              alt="<?= e($feat['title'] ?: 'Featured village photograph') ?>"
              loading="lazy"
              referrerpolicy="no-referrer"
            >
          </button>
          <?php if (!empty($feat['title']) || !empty($feat['category_name'])): ?>
            <figcaption>
              <?php if (!empty($feat['title'])): ?>
                <p class="featured-title"><?= e($feat['title']) ?></p>
              <?php endif; ?>
              <?php if (!empty($feat['category_name'])): ?>
                <span class="featured-cat"><?= e($feat['category_name']) ?></span>
              <?php endif; ?>
            </figcaption>
          <?php endif; ?>
        </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
