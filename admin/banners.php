<?php
declare(strict_types=1);

$activePage = 'banners';
$adminPageশিরোনাম = 'প্রধান ব্যানার';
$requiredPermission = 'manage_banners';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/storage.php';

$pdo = db();
$storage = new SupabaseStorage();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_interval') {
            $interval = max(2000, min(20000, (int) ($_POST['hero_interval_ms'] ?? 5500)));
            $upd = $pdo->prepare('UPDATE public.website_settings SET hero_interval_ms = :ms WHERE id = 1');
            $upd->execute([':ms' => $interval]);
            log_admin_activity((int) $currentঅ্যাডমিন['id'], 'hero_interval_updated', 'website_settings', 1);
            set_flash('success', 'স্লাইডশোর সময় হালনাগাদ হয়েছে।');
            redirect_to(admin_url('banners.php'));
        } elseif ($action === 'create' || $action === 'update') {
            $bannerId   = (int) ($_POST['banner_id'] ?? 0);
            $title      = trim((string) ($_POST['title'] ?? ''));
            $subtitle   = trim((string) ($_POST['subtitle'] ?? ''));
            $buttonText = trim((string) ($_POST['button_text'] ?? ''));
            $buttonUrl  = trim((string) ($_POST['button_url'] ?? ''));
            $sortক্রম  = (int) ($_POST['sort_order'] ?? 0);
            $isচালু   = !empty($_POST['is_active']);

            if ($action === 'create') {
                if (empty($_FILES['banner_image']['name'])) {
                    throw new RuntimeException('ব্যানারের ছবি নির্বাচন করতে হবে।');
                }
                $res = $storage->upload($_FILES['banner_image'], BUCKET_HERO_BANNERS, ALLOWED_BANNER_MIMES, MAX_IMAGE_UPLOAD_BYTES, 'banners');
                try {
                    $ins = $pdo->prepare(
                        'INSERT INTO public.hero_banners (title, subtitle, button_text, button_url, storage_bucket, storage_path, image_url, sort_order, is_active)
                         VALUES (:title, :sub, :bt, :bu, :bucket, :path, :url, :ord, :act)
                         RETURNING id'
                    );
                    $ins->execute([
                        ':title'  => $title !== '' ? $title : null,
                        ':sub'    => $subtitle !== '' ? $subtitle : null,
                        ':bt'     => $buttonText !== '' ? $buttonText : null,
                        ':bu'     => $buttonUrl !== '' ? $buttonUrl : null,
                        ':bucket' => $res['bucket'],
                        ':path'   => $res['path'],
                        ':url'    => $res['url'],
                        ':ord'    => $sortক্রম,
                        ':act'    => $isচালু ? 'true' : 'false',
                    ]);
                    log_admin_activity((int) $currentঅ্যাডমিন['id'], 'banner_created', 'hero_banner', (int) $ins->fetchColumn());
                } catch (Throwable $dbErr) {
                    $storage->delete($res['bucket'], $res['path']);
                    throw $dbErr;
                }
                set_flash('success', 'ব্যানার যোগ করা হয়েছে।');
            } else {
                $st = $pdo->prepare('SELECT * FROM public.hero_banners WHERE id = :id LIMIT 1');
                $st->execute([':id' => $bannerId]);
                $existing = $st->fetch();
                if (!$existing) {
                    throw new RuntimeException('ব্যানারটি পাওয়া যায়নি।');
                }

                $bucket = $existing['storage_bucket'];
                $path   = $existing['storage_path'];
                $url    = $existing['image_url'];
                $oldBucket = null;
                $oldPath   = null;
                $newUpload = null;

                if (!empty($_FILES['banner_image']['name'])) {
                    $newUpload = $storage->upload($_FILES['banner_image'], BUCKET_HERO_BANNERS, ALLOWED_BANNER_MIMES, MAX_IMAGE_UPLOAD_BYTES, 'banners');
                    $oldBucket = (string) $bucket;
                    $oldPath   = (string) $path;
                    $bucket = $newUpload['bucket'];
                    $path   = $newUpload['path'];
                    $url    = $newUpload['url'];
                }

                try {
                    $upd = $pdo->prepare(
                        'UPDATE public.hero_banners
                         SET title = :title, subtitle = :sub, button_text = :bt, button_url = :bu,
                             sort_order = :ord, is_active = :act,
                             storage_bucket = :bucket, storage_path = :path, image_url = :url
                         WHERE id = :id'
                    );
                    $upd->execute([
                        ':title'  => $title !== '' ? $title : null,
                        ':sub'    => $subtitle !== '' ? $subtitle : null,
                        ':bt'     => $buttonText !== '' ? $buttonText : null,
                        ':bu'     => $buttonUrl !== '' ? $buttonUrl : null,
                        ':ord'    => $sortক্রম,
                        ':act'    => $isচালু ? 'true' : 'false',
                        ':bucket' => $bucket,
                        ':path'   => $path,
                        ':url'    => $url,
                        ':id'     => $bannerId,
                    ]);
                    log_admin_activity((int) $currentঅ্যাডমিন['id'], 'banner_updated', 'hero_banner', $bannerId);
                } catch (Throwable $dbErr) {
                    if ($newUpload !== null) {
                        $storage->delete($newUpload['bucket'], $newUpload['path']);
                    }
                    throw $dbErr;
                }

                if ($oldBucket !== null && $oldPath !== null) {
                    $storage->delete($oldBucket, $oldPath);
                }
                set_flash('success', 'ব্যানার হালনাগাদ হয়েছে।');
            }
            redirect_to(admin_url('banners.php'));
        } elseif ($action === 'delete') {
            $bannerId = (int) ($_POST['banner_id'] ?? 0);
            $st = $pdo->prepare('SELECT storage_bucket, storage_path FROM public.hero_banners WHERE id = :id LIMIT 1');
            $st->execute([':id' => $bannerId]);
            $row = $st->fetch();
            if ($row) {
                $storage->delete((string) $row['storage_bucket'], (string) $row['storage_path']);
                $pdo->prepare('DELETE FROM public.hero_banners WHERE id = :id')->execute([':id' => $bannerId]);
                log_admin_activity((int) $currentঅ্যাডমিন['id'], 'banner_deleted', 'hero_banner', $bannerId);
            }
            set_flash('success', 'ব্যানার মুছে ফেলা হয়েছে।');
            redirect_to(admin_url('banners.php'));
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
        redirect_to(admin_url('banners.php'));
    }
}

$banners = $pdo->query('SELECT * FROM public.hero_banners ORDER BY sort_order ASC, id ASC')->fetchসব();
$editBanner = null;
if (!empty($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM public.hero_banners WHERE id = :id LIMIT 1');
    $st->execute([':id' => (int) $_GET['edit']]);
    $editBanner = $st->fetch() ?: null;
}
?>

<section class="adm-panel">
  <h2 class="adm-panel-title">স্লাইডশোর সময় নির্ধারণ</h2>
  <form method="post" action="<?= e(admin_url('banners.php')) ?>" style="display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_interval">
    <div>
      <label class="form-label" for="hero_interval_ms">স্বয়ংক্রিয় স্লাইড পরিবর্তনের সময় (মিলিসেকেন্ড)</label>
      <input class="form-control" type="number" id="hero_interval_ms" name="hero_interval_ms" min="2000" max="20000" step="500" value="<?= (int) ($siteSettings['hero_interval_ms'] ?? 5500) ?>">
    </div>
    <button type="submit" class="btn btn-green">সময় সংরক্ষণ করুন</button>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title"><?= $editBanner ? 'সম্পাদনা Hero Banner #' . (int) $editBanner['id'] : 'যোগ করুন Hero Banner' ?></h2>
  <form method="post" action="<?= e(admin_url('banners.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editBanner ? 'update' : 'create' ?>">
    <?php if ($editBanner): ?>
      <input type="hidden" name="banner_id" value="<?= (int) $editBanner['id'] ?>">
    <?php endif; ?>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="banner_image"><?= $editBanner ? 'Replace ছবি (Optional)' : 'Banner ছবি (Required)' ?></label>
        <input class="form-control" type="file" id="banner_image" name="banner_image" data-preview-target="banner_image_preview" <?= $editBanner ? '' : 'required' ?> accept="image/jpeg,image/png,image/webp">
        <span class="form-hint">১৬:৯ অনুপাতের আড়াআড়ি ছবি (JPG/PNG/WebP) ব্যবহার করুন। ছবিটি Supabase Storage-এ সংরক্ষিত হবে।</span>
        <div class="adm-media-preview" id="banner_image_preview">
          <?php if ($editBanner && !empty($editBanner['image_url'])): ?>
            <img src="<?= e(safe_url($editBanner['image_url'])) ?>" alt="Current banner" class="adm-preview-media">
          <?php endif; ?>
        </div>
      </div>
      <div class="form-row">
        <label class="form-label" for="sort_order">প্রদর্শনের ক্রম</label>
        <input class="form-control" type="number" id="sort_order" name="sort_order" value="<?= (int) ($editBanner['sort_order'] ?? 0) ?>">
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="title">ব্যানারের শিরোনাম (ঐচ্ছিক)</label>
        <input class="form-control" type="text" id="title" name="title" value="<?= e($editBanner['title'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="subtitle">ব্যানারের উপশিরোনাম (ঐচ্ছিক)</label>
        <input class="form-control" type="text" id="subtitle" name="subtitle" value="<?= e($editBanner['subtitle'] ?? '') ?>">
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="button_text">বাটনের লেখা (ঐচ্ছিক)</label>
        <input class="form-control" type="text" id="button_text" name="button_text" value="<?= e($editBanner['button_text'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="button_url">বাটন লিংক (Optional)</label>
        <input class="form-control" type="text" id="button_url" name="button_url" value="<?= e($editBanner['button_url'] ?? '') ?>" placeholder="/gallery.php or #posts-section">
      </div>
    </div>

    <div class="form-row">
      <label class="form-check">
        <input type="checkbox" name="is_active" value="1" <?= (!$editBanner || !empty($editBanner['is_active'])) ? 'checked' : '' ?>>
        <span>মূল পাতায় ব্যানারটি দেখাবে</span>
      </label>
    </div>

    <div style="display: flex; gap: 10px;">
      <button type="submit" class="btn btn-green"><?= $editBanner ? 'সংরক্ষণ Banner' : 'যোগ করুন Banner' ?></button>
      <?php if ($editBanner): ?>
        <a href="<?= e(admin_url('banners.php')) ?>" class="btn btn-outline">বাতিল</a>
      <?php endif; ?>
    </div>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">সংরক্ষিত ব্যানার</h2>
  <div class="table-wrap">
    <table class="adm-table">
      <thead>
        <tr>
          <th>ছবি</th>
          <th>শিরোনাম</th>
          <th>বাটন</th>
          <th>ক্রম</th>
          <th>অবস্থা</th>
          <th>কার্যক্রম</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($banners as $b): ?>
          <tr>
            <td><img src="<?= e(safe_url($b['image_url'])) ?>" alt="" class="adm-thumb"></td>
            <td><?= e($b['title'] ?: '(ছবি-only banner)') ?></td>
            <td><?= e($b['button_text'] ?: '—') ?></td>
            <td class="num-tab"><?= (int) $b['sort_order'] ?></td>
            <td><?= !empty($b['is_active']) ? 'চালু' : 'Disabled' ?></td>
            <td>
              <div style="display: flex; gap: 6px;">
                <a href="<?= e(admin_url('banners.php')) ?>?edit=<?= (int) $b['id'] ?>" class="btn btn-outline btn-sm">সম্পাদনা</a>
                <form method="post" action="<?= e(admin_url('banners.php')) ?>" data-confirm="মুছুন this banner?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="banner_id" value="<?= (int) $b['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">মুছুন</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
