<?php
declare(strict_types=1);

$activePage = 'gallery';
$adminPageTitle = 'ছবির গ্যালারি';
$requiredPermission = 'manage_gallery';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/storage.php';

$pdo = db();
$storage = new SupabaseStorage();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'upload_multi') {
            $categoryId  = (int) ($_POST['category_id'] ?? 0) ?: null;
            $title       = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $isFeatured  = !empty($_POST['is_featured']);
            $isSlider    = !empty($_POST['is_slider_item']);

            if (empty($_FILES['images']['name'][0])) {
                throw new RuntimeException('আপলোড করার জন্য অন্তত একটি ছবি নির্বাচন করুন।');
            }

            $filesToUpload = [];
            $count = count($_FILES['images']['name']);
            for ($i = 0; $i < $count; $i++) {
                $single = [
                    'name'     => $_FILES['images']['name'][$i],
                    'type'     => $_FILES['images']['type'][$i],
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'error'    => $_FILES['images']['error'][$i],
                    'size'     => $_FILES['images']['size'][$i],
                ];
                if ($single['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $storage->validateFile($single, ALLOWED_IMAGE_MIMES, MAX_IMAGE_UPLOAD_BYTES);
                $filesToUpload[] = $single;
            }

            if ($filesToUpload === []) {
                throw new RuntimeException('আপলোড করার জন্য অন্তত একটি সঠিক ছবি নির্বাচন করুন।');
            }

            $uploadedCount = 0;
            foreach ($filesToUpload as $single) {
                $res = $storage->upload($single, BUCKET_GALLERY_IMAGES, ALLOWED_IMAGE_MIMES, MAX_IMAGE_UPLOAD_BYTES, 'photos');
                try {
                    db_transaction(function (PDO $pdo) use ($categoryId, $currentAdmin, $title, $description, $res, $isFeatured, $isSlider): void {
                        $ins = $pdo->prepare(
                            'INSERT INTO public.gallery_images
                             (category_id, admin_id, title, description, storage_bucket, storage_path, image_url, mime_type, file_size_bytes, is_featured, is_slider_item, status)
                             VALUES (:cat, :aid, :title, :desc, :bucket, :path, :url, :mime, :size, :feat, :slider, \'published\')
                             RETURNING id'
                        );
                        $ins->execute([
                            ':cat'    => $categoryId,
                            ':aid'    => (int) $currentAdmin['id'],
                            ':title'  => $title !== '' ? $title : null,
                            ':desc'   => $description !== '' ? $description : null,
                            ':bucket' => $res['bucket'],
                            ':path'   => $res['path'],
                            ':url'    => $res['url'],
                            ':mime'   => $res['mime'],
                            ':size'   => $res['size'],
                            ':feat'   => $isFeatured ? 'true' : 'false',
                            ':slider' => $isSlider ? 'true' : 'false',
                        ]);
                        $id = (int) $ins->fetchColumn();
                        log_admin_activity((int) $currentAdmin['id'], 'gallery_image_uploaded', 'gallery_image', $id);
                    });
                } catch (Throwable $dbErr) {
                    $storage->delete($res['bucket'], $res['path']);
                    throw $dbErr;
                }
                $uploadedCount++;
            }

            set_flash('success', "গ্যালারিতে {$uploadedCount}টি ছবি আপলোড হয়েছে।");
            redirect_to(admin_url('gallery.php'));
        } elseif ($action === 'update') {
            $imgId       = (int) ($_POST['image_id'] ?? 0);
            $categoryId  = (int) ($_POST['category_id'] ?? 0) ?: null;
            $title       = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $sortOrder   = (int) ($_POST['sort_order'] ?? 0);
            $isFeatured  = !empty($_POST['is_featured']);
            $isSlider    = !empty($_POST['is_slider_item']);
            $status      = ($_POST['status'] ?? 'published') === 'hidden' ? 'hidden' : 'published';

            $st = $pdo->prepare('SELECT * FROM public.gallery_images WHERE id = :id LIMIT 1');
            $st->execute([':id' => $imgId]);
            $existing = $st->fetch();
            if (!$existing) {
                throw new RuntimeException('ছবির তথ্য পাওয়া যায়নি।');
            }

            $bucket = $existing['storage_bucket'];
            $path   = $existing['storage_path'];
            $url    = $existing['image_url'];
            $mime   = $existing['mime_type'] ?? null;
            $size   = $existing['file_size_bytes'] ?? null;
            $oldBucket = null;
            $oldPath   = null;
            $newUpload = null;

            if (!empty($_FILES['replace_image']['name'])) {
                $newUpload = $storage->upload($_FILES['replace_image'], BUCKET_GALLERY_IMAGES, ALLOWED_IMAGE_MIMES, MAX_IMAGE_UPLOAD_BYTES, 'photos');
                $oldBucket = (string) $bucket;
                $oldPath   = (string) $path;
                $bucket = $newUpload['bucket'];
                $path   = $newUpload['path'];
                $url    = $newUpload['url'];
                $mime   = $newUpload['mime'];
                $size   = $newUpload['size'];
            }

            try {
                $upd = $pdo->prepare(
                    'UPDATE public.gallery_images
                     SET category_id = :cat, title = :title, description = :desc, sort_order = :ord,
                         is_featured = :feat, is_slider_item = :slider, status = :status,
                         storage_bucket = :bucket, storage_path = :path, image_url = :url,
                         mime_type = :mime, file_size_bytes = :size
                     WHERE id = :id'
                );
                $upd->execute([
                    ':cat'    => $categoryId,
                    ':title'  => $title !== '' ? $title : null,
                    ':desc'   => $description !== '' ? $description : null,
                    ':ord'    => $sortOrder,
                    ':feat'   => $isFeatured ? 'true' : 'false',
                    ':slider' => $isSlider ? 'true' : 'false',
                    ':status' => $status,
                    ':bucket' => $bucket,
                    ':path'   => $path,
                    ':url'    => $url,
                    ':mime'   => $mime,
                    ':size'   => $size,
                    ':id'     => $imgId,
                ]);
                log_admin_activity((int) $currentAdmin['id'], 'gallery_image_updated', 'gallery_image', $imgId);
            } catch (Throwable $dbErr) {
                if ($newUpload !== null) {
                    $storage->delete($newUpload['bucket'], $newUpload['path']);
                }
                throw $dbErr;
            }

            if ($oldBucket !== null && $oldPath !== null) {
                $storage->delete($oldBucket, $oldPath);
            }
            set_flash('success', 'গ্যালারির ছবি হালনাগাদ হয়েছে।');
            redirect_to(admin_url('gallery.php'));
        } elseif ($action === 'delete') {
            $imgId = (int) ($_POST['image_id'] ?? 0);
            $st = $pdo->prepare('SELECT storage_bucket, storage_path FROM public.gallery_images WHERE id = :id LIMIT 1');
            $st->execute([':id' => $imgId]);
            $row = $st->fetch();
            if ($row) {
                $storage->delete((string) $row['storage_bucket'], (string) $row['storage_path']);
                $del = $pdo->prepare('DELETE FROM public.gallery_images WHERE id = :id');
                $del->execute([':id' => $imgId]);
                log_admin_activity((int) $currentAdmin['id'], 'gallery_image_deleted', 'gallery_image', $imgId);
            }
            set_flash('success', 'গ্যালারির ছবি মুছে ফেলা হয়েছে।');
            redirect_to(admin_url('gallery.php'));
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
        redirect_to(admin_url('gallery.php'));
    }
}

$categories = $pdo->query('SELECT * FROM public.gallery_categories ORDER BY sort_order ASC, id ASC')->fetchAll();
$images = $pdo->query(
    'SELECT gi.*, gc.name AS category_name
     FROM public.gallery_images gi
     LEFT JOIN public.gallery_categories gc ON gc.id = gi.category_id
     ORDER BY gi.sort_order ASC, gi.uploaded_at DESC'
)->fetchAll();

$editImage = null;
if (!empty($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM public.gallery_images WHERE id = :id LIMIT 1');
    $st->execute([':id' => (int) $_GET['edit']]);
    $editImage = $st->fetch() ?: null;
}
?>

<section class="adm-panel">
  <h2 class="adm-panel-title"><?= $editImage ? 'ছবি সম্পাদনা #' . (int) $editImage['id'] : 'গ্যালারিতে ছবি আপলোড (একাধিক ছবি দেওয়া যাবে)' ?></h2>

  <?php if ($editImage): ?>
    <form method="post" action="<?= e(admin_url('gallery.php')) ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="image_id" value="<?= (int) $editImage['id'] ?>">

      <div class="adm-grid-2">
        <div class="form-row">
          <label class="form-label" for="edit_title">Title (Optional)</label>
          <input class="form-control" type="text" id="edit_title" name="title" value="<?= e($editImage['title'] ?? '') ?>">
        </div>
        <div class="form-row">
          <label class="form-label" for="edit_cat">Category</label>
          <select class="form-control" name="category_id" id="edit_cat">
            <option value="">বিভাগবিহীন</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int) $cat['id'] ?>" <?= ((int) ($editImage['category_id'] ?? 0) === (int) $cat['id']) ? 'selected' : '' ?>>
                <?= e($cat['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
        <label class="form-label" for="edit_desc">Description (Optional)</label>
        <textarea class="form-control" id="edit_desc" name="description"><?= e($editImage['description'] ?? '') ?></textarea>
      </div>

      <div class="adm-grid-2">
        <div class="form-row">
          <label class="form-label" for="edit_order">ক্রম</label>
          <input class="form-control" type="number" id="edit_order" name="sort_order" value="<?= (int) $editImage['sort_order'] ?>">
        </div>
        <div class="form-row">
          <label class="form-label" for="edit_status">দৃশ্যমানতা</label>
          <select class="form-control" name="status" id="edit_status">
            <option value="published" <?= $editImage['status'] === 'published' ? 'selected' : '' ?>>প্রকাশিত</option>
            <option value="hidden" <?= $editImage['status'] === 'hidden' ? 'selected' : '' ?>>লুকানো</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <label class="form-label" for="replace_image">Replace Image File (Optional)</label>
        <input class="form-control" type="file" id="replace_image" name="replace_image" data-preview-target="gallery_edit_preview" accept="image/jpeg,image/png,image/webp,image/gif">
        <div class="adm-media-preview" id="gallery_edit_preview">
          <img src="<?= e(safe_url($editImage['image_url'])) ?>" alt="Current gallery photo" class="adm-preview-media">
        </div>
      </div>

      <div class="form-row" style="display: flex; gap: 20px; flex-wrap: wrap;">
        <label class="form-check">
          <input type="checkbox" name="is_featured" value="1" <?= !empty($editImage['is_featured']) ? 'checked' : '' ?>>
          <span>বিশেষ স্মৃতিতে দেখাবে</span>
        </label>
        <label class="form-check">
          <input type="checkbox" name="is_slider_item" value="1" <?= !empty($editImage['is_slider_item']) ? 'checked' : '' ?>>
          <span>অনুভূমিক স্লাইডারে দেখাবে</span>
        </label>
      </div>

      <div style="display: flex; gap: 10px;">
        <button type="submit" class="btn btn-green">ছবির তথ্য সংরক্ষণ করুন</button>
        <a href="<?= e(admin_url('gallery.php')) ?>" class="btn btn-outline">Cancel</a>
      </div>
    </form>
  <?php else: ?>
    <form method="post" action="<?= e(admin_url('gallery.php')) ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_multi">

      <div class="adm-grid-2">
        <div class="form-row">
          <label class="form-label" for="images">Select Image(s)</label>
          <input class="form-control" type="file" id="images" name="images[]" multiple required data-preview-target="gallery_multi_preview" accept="image/jpeg,image/png,image/webp,image/gif">
          <span class="form-hint">You can select multiple photos at once. Uploaded directly to Supabase Storage (gallery-images).</span>
          <div class="adm-media-preview" id="gallery_multi_preview"></div>
        </div>

        <div class="form-row">
          <label class="form-label" for="category_id">Category (Optional)</label>
          <select class="form-control" name="category_id" id="category_id">
            <option value="">সব ছবি / বিভাগবিহীন</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int) $cat['id'] ?>"><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="adm-grid-2">
        <div class="form-row">
          <label class="form-label" for="title">Title (Optional — leave blank if untitled)</label>
          <input class="form-control" type="text" id="title" name="title" placeholder="ছবির শিরোনাম (ঐচ্ছিক)">
        </div>
        <div class="form-row">
          <label class="form-label" for="description">Description (Optional)</label>
          <input class="form-control" type="text" id="description" name="description" placeholder="ছবির ক্যাপশন (ঐচ্ছিক)">
        </div>
      </div>

      <div class="form-row" style="display: flex; gap: 20px; flex-wrap: wrap;">
        <label class="form-check">
          <input type="checkbox" name="is_featured" value="1">
          <span>মূল পাতায় দেখাবে</span>
        </label>
        <label class="form-check">
          <input type="checkbox" name="is_slider_item" value="1" checked>
          <span>অনুভূমিক স্লাইডারে রাখুন</span>
        </label>
      </div>

      <button type="submit" class="btn btn-green">গ্যালারিতে আপলোড করুন</button>
    </form>
  <?php endif; ?>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">গ্যালারির ছবি (<?= count($images) ?>)</h2>
  <?php if (empty($images)): ?>
    <p style="color: var(--adm-muted); margin: 0;">No gallery images uploaded yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="adm-table">
        <thead>
          <tr>
            <th>ছবি</th>
            <th>শিরোনাম</th>
            <th>বিভাগ</th>
            <th>ক্রম</th>
            <th>অবস্থান</th>
            <th>অবস্থা</th>
            <th>করণীয়</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($images as $img): ?>
            <tr>
              <td><img src="<?= e(safe_url($img['image_url'])) ?>" alt="" class="adm-thumb"></td>
              <td><?= e($img['title'] ?: '(Untitled)') ?></td>
              <td><?= e($img['category_name'] ?? '—') ?></td>
              <td class="num-tab"><?= (int) $img['sort_order'] ?></td>
              <td>
                <?php
                  $flags = [];
                  if (!empty($img['is_featured'])) $flags[] = 'Featured';
                  if (!empty($img['is_slider_item'])) $flags[] = 'Slider';
                  echo e($flags ? implode(', ', $flags) : 'Gallery');
                ?>
              </td>
              <td><?= e(ucfirst($img['status'])) ?></td>
              <td>
                <div style="display: flex; gap: 6px;">
                  <a href="<?= e(admin_url('gallery.php')) ?>?edit=<?= (int) $img['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                  <form method="post" action="<?= e(admin_url('gallery.php')) ?>" data-confirm="Delete this photo from the gallery and Supabase Storage?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="image_id" value="<?= (int) $img['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
