<?php
declare(strict_types=1);

$activePage = 'header';
$adminPageTitle = 'হেডার ও নেভিগেশন';
$requiredPermission = 'manage_navigation';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/storage.php';

$pdo = db();
$storage = new SupabaseStorage();

$menuStmt = $pdo->prepare("SELECT id FROM public.navigation_menus WHERE location = 'header' LIMIT 1");
$menuStmt->execute();
$headerMenuId = (int) $menuStmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_header_brand') {
            $siteName  = trim((string) ($_POST['site_name'] ?? ''));
            $bgColor   = sanitize_hex_color($_POST['header_bg_color'] ?? null, '#14532d');
            $textColor = sanitize_hex_color($_POST['header_text_color'] ?? null, '#ffffff');
            if ($siteName === '') {
                throw new RuntimeException('ওয়েবসাইটের নাম লিখতে হবে।');
            }

            $current = get_website_settings();
            $logoPath = $current['logo_path'] ?? null;
            $logoUrl  = $current['logo_url'] ?? null;
            $oldLogoPath = null;
            $newUpload   = null;

            if (!empty($_FILES['logo_file']['name'])) {
                $newUpload = $storage->upload($_FILES['logo_file'], BUCKET_WEBSITE_ASSETS, ALLOWED_LOGO_MIMES, MAX_ASSET_UPLOAD_BYTES, 'logo');
                if ($logoPath) {
                    $oldLogoPath = (string) $logoPath;
                }
                $logoPath = $newUpload['path'];
                $logoUrl  = $newUpload['url'];
            }

            try {
                $upd = $pdo->prepare(
                    'UPDATE public.website_settings
                     SET site_name = :name, header_bg_color = :bg, header_text_color = :fg, logo_path = :lp, logo_url = :lu
                     WHERE id = 1'
                );
                $upd->execute([
                    ':name' => $siteName,
                    ':bg'   => $bgColor,
                    ':fg'   => $textColor,
                    ':lp'   => $logoPath,
                    ':lu'   => $logoUrl,
                ]);
                log_admin_activity((int) $currentAdmin['id'], 'header_brand_updated', 'website_settings', 1);
            } catch (Throwable $dbErr) {
                if ($newUpload !== null) {
                    $storage->delete($newUpload['bucket'], $newUpload['path']);
                }
                throw $dbErr;
            }

            if ($oldLogoPath !== null) {
                $storage->delete(BUCKET_WEBSITE_ASSETS, $oldLogoPath);
            }
            set_flash('success', 'হেডারের নকশা ও পরিচিতি হালনাগাদ হয়েছে।');
        } elseif ($action === 'add_nav_item' || $action === 'update_nav_item') {
            $itemId   = (int) ($_POST['item_id'] ?? 0);
            $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;
            $label    = trim((string) ($_POST['label'] ?? ''));
            $url      = trim((string) ($_POST['url'] ?? '/'));
            if ($url === '' || $url === '#') {
                $url = '/';
            }
            $itemType = in_array($_POST['item_type'] ?? '', ['link', 'dropdown', 'mega'], true) ? $_POST['item_type'] : 'link';
            $megaDesc = trim((string) ($_POST['mega_description'] ?? ''));
            $newTab   = !empty($_POST['open_in_new_tab']);
            $visible  = !empty($_POST['is_visible']);
            $order    = (int) ($_POST['sort_order'] ?? 0);

            if ($label === '') {
                throw new RuntimeException('মেনুর নাম লিখতে হবে।');
            }

            if ($action === 'add_nav_item') {
                $ins = $pdo->prepare(
                    'INSERT INTO public.navigation_items (menu_id, parent_id, label, url, item_type, mega_description, open_in_new_tab, sort_order, is_visible)
                     VALUES (:mid, :pid, :lbl, :url, :type, :desc, :tab, :ord, :vis) RETURNING id'
                );
                $ins->execute([
                    ':mid'  => $headerMenuId,
                    ':pid'  => $parentId,
                    ':lbl'  => $label,
                    ':url'  => $url,
                    ':type' => $itemType,
                    ':desc' => $megaDesc !== '' ? $megaDesc : null,
                    ':tab'  => $newTab ? 'true' : 'false',
                    ':ord'  => $order,
                    ':vis'  => $visible ? 'true' : 'false',
                ]);
                log_admin_activity((int) $currentAdmin['id'], 'nav_item_created', 'navigation_items', (int) $ins->fetchColumn());
                set_flash('success', 'মেনুর আইটেম যোগ করা হয়েছে।');
            } else {
                if ($parentId === $itemId) {
                    $parentId = null;
                }
                $upd = $pdo->prepare(
                    'UPDATE public.navigation_items
                     SET parent_id = :pid, label = :lbl, url = :url, item_type = :type,
                         mega_description = :desc, open_in_new_tab = :tab, sort_order = :ord, is_visible = :vis
                     WHERE id = :id AND menu_id = :mid'
                );
                $upd->execute([
                    ':pid'  => $parentId,
                    ':lbl'  => $label,
                    ':url'  => $url,
                    ':type' => $itemType,
                    ':desc' => $megaDesc !== '' ? $megaDesc : null,
                    ':tab'  => $newTab ? 'true' : 'false',
                    ':ord'  => $order,
                    ':vis'  => $visible ? 'true' : 'false',
                    ':id'   => $itemId,
                    ':mid'  => $headerMenuId,
                ]);
                log_admin_activity((int) $currentAdmin['id'], 'nav_item_updated', 'navigation_items', $itemId);
                set_flash('success', 'মেনুর আইটেম হালনাগাদ হয়েছে।');
            }
        } elseif ($action === 'delete_nav_item') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $pdo->prepare('DELETE FROM public.navigation_items WHERE id = :id AND menu_id = :mid')
                ->execute([':id' => $itemId, ':mid' => $headerMenuId]);
            log_admin_activity((int) $currentAdmin['id'], 'nav_item_deleted', 'navigation_items', $itemId);
            set_flash('success', 'মেনুর আইটেম মুছে ফেলা হয়েছে।');
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    redirect_to(admin_url('header.php'));
}

$settings = get_website_settings();
$navStmt = $pdo->prepare('SELECT * FROM public.navigation_items WHERE menu_id = :mid ORDER BY sort_order ASC, id ASC');
$navStmt->execute([':mid' => $headerMenuId]);
$navItems = $navStmt->fetchAll();

$editItem = null;
if (!empty($_GET['edit'])) {
    foreach ($navItems as $ni) {
        if ((int) $ni['id'] === (int) $_GET['edit']) {
            $editItem = $ni;
            break;
        }
    }
}
?>

<section class="adm-panel">
  <h2 class="adm-panel-title">হেডারের পরিচিতি ও রং</h2>
  <form method="post" action="<?= e(admin_url('header.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_header_brand">

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="site_name">ওয়েবসাইটের নাম</label>
        <input class="form-control" type="text" id="site_name" name="site_name" value="<?= e($settings['site_name']) ?>" required>
      </div>
      <div class="form-row">
        <label class="form-label" for="logo_file">ওয়েবসাইটের লোগো আপলোড / পরিবর্তন</label>
        <input class="form-control" type="file" id="logo_file" name="logo_file" data-preview-target="header_logo_preview" accept="image/jpeg,image/png,image/webp">
        <div class="adm-media-preview" id="header_logo_preview">
          <?php if (!empty($settings['logo_url'])): ?>
            <img src="<?= e(safe_url($settings['logo_url'])) ?>" alt="Current header logo" class="adm-preview-media adm-preview-logo">
            <span class="form-hint">বর্তমান লোগো চালু আছে। পরিবর্তন করতে নতুন ফাইল আপলোড করুন।</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="header_bg_color">হেডারের পেছনের রং</label>
        <input class="form-control" type="color" id="header_bg_color" name="header_bg_color" value="<?= e(sanitize_hex_color($settings['header_bg_color'], '#14532d')) ?>" style="height: 44px; padding: 4px;">
      </div>
      <div class="form-row">
        <label class="form-label" for="header_text_color">হেডারের লেখার রং</label>
        <input class="form-control" type="color" id="header_text_color" name="header_text_color" value="<?= e(sanitize_hex_color($settings['header_text_color'], '#ffffff')) ?>" style="height: 44px; padding: 4px;">
      </div>
    </div>

    <button type="submit" class="btn btn-green">হেডারের পরিবর্তন সংরক্ষণ করুন</button>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title"><?= $editItem ? 'মেনুর আইটেম সম্পাদনা' : 'নতুন মেনু আইটেম যোগ করুন' ?></h2>
  <form method="post" action="<?= e(admin_url('header.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editItem ? 'update_nav_item' : 'add_nav_item' ?>">
    <?php if ($editItem): ?>
      <input type="hidden" name="item_id" value="<?= (int) $editItem['id'] ?>">
    <?php endif; ?>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="label">মেনুর নাম</label>
        <input class="form-control" type="text" id="label" name="label" value="<?= e($editItem['label'] ?? '') ?>" required>
      </div>
      <div class="form-row">
        <label class="form-label" for="url">লিংক (ওয়েবসাইটের ভেতরের বা বাইরের)</label>
        <input class="form-control" type="text" id="url" name="url" value="<?= e($editItem['url'] ?? '/') ?>" required placeholder="/gallery.php অথবা https://...">
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="item_type">মেনুর ধরন</label>
        <?php $curStyle = $editItem['item_type'] ?? 'link'; ?>
        <select class="form-control" name="item_type" id="item_type">
          <option value="link" <?= $curStyle === 'link' ? 'selected' : '' ?>>সাধারণ লিংক</option>
          <option value="dropdown" <?= $curStyle === 'dropdown' ? 'selected' : '' ?>>ড্রপডাউন মেনু</option>
          <option value="mega" <?= $curStyle === 'mega' ? 'selected' : '' ?>>মেগা মেনু</option>
        </select>
      </div>

      <div class="form-row">
        <label class="form-label" for="parent_id">মূল মেনু (সাব-মেনুর জন্য ঐচ্ছিক)</label>
        <select class="form-control" name="parent_id" id="parent_id">
          <option value="">প্রধান মেনু আইটেম</option>
          <?php foreach ($navItems as $ni): ?>
            <?php if ($ni['parent_id'] === null && (!$editItem || (int) $ni['id'] !== (int) $editItem['id'])): ?>
              <option value="<?= (int) $ni['id'] ?>" <?= ((int) ($editItem['parent_id'] ?? 0) === (int) $ni['id']) ? 'selected' : '' ?>>
                <?= e($ni['label']) ?>
              </option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="mega_description">মেগা মেনু / সাব-মেনুর বিবরণ (ঐচ্ছিক)</label>
        <input class="form-control" type="text" id="mega_description" name="mega_description" value="<?= e($editItem['mega_description'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="sort_order">ক্রম</label>
        <input class="form-control" type="number" id="sort_order" name="sort_order" value="<?= (int) ($editItem['sort_order'] ?? 0) ?>">
      </div>
    </div>

    <div class="form-row" style="display: flex; gap: 20px; flex-wrap: wrap;">
      <label class="form-check">
        <input type="checkbox" name="is_visible" value="1" <?= (!$editItem || !empty($editItem['is_visible'])) ? 'checked' : '' ?>>
        <span>হেডারে দেখাবে</span>
      </label>
      <label class="form-check">
        <input type="checkbox" name="open_in_new_tab" value="1" <?= !empty($editItem['open_in_new_tab']) ? 'checked' : '' ?>>
        <span>নতুন ট্যাবে খুলবে</span>
      </label>
    </div>

    <div style="display: flex; gap: 10px;">
      <button type="submit" class="btn btn-green"><?= $editItem ? 'মেনুর আইটেম হালনাগাদ করুন' : 'মেনুর আইটেম যোগ করুন' ?></button>
      <?php if ($editItem): ?>
        <a href="<?= e(admin_url('header.php')) ?>" class="btn btn-outline">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">হেডারের মেনু কাঠামো</h2>
  <div class="table-wrap">
    <table class="adm-table">
      <thead>
        <tr>
          <th>নাম</th>
          <th>লিংক</th>
          <th>ধরন</th>
          <th>ক্রম</th>
          <th>দৃশ্যমান</th>
          <th>করণীয়</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($navItems as $ni): ?>
          <tr>
            <td><?= $ni['parent_id'] ? '↳ ' : '' ?><strong><?= e($ni['label']) ?></strong></td>
            <td><?= e($ni['url']) ?></td>
            <td><?= e(['link' => 'লিংক', 'dropdown' => 'ড্রপডাউন মেনু', 'mega' => 'মেগা মেনু'][$ni['item_type']] ?? $ni['item_type']) ?></td>
            <td class="num-tab"><?= (int) $ni['sort_order'] ?></td>
            <td><?= !empty($ni['is_visible']) ? 'দৃশ্যমান' : 'লুকানো' ?></td>
            <td>
              <div style="display: flex; gap: 6px;">
                <a href="<?= e(admin_url('header.php')) ?>?edit=<?= (int) $ni['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                <form method="post" action="<?= e(admin_url('header.php')) ?>" data-confirm="মুছুন this navigation item?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_nav_item">
                  <input type="hidden" name="item_id" value="<?= (int) $ni['id'] ?>">
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
