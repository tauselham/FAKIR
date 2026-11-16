<?php
declare(strict_types=1);

$activePage = 'footer';
$adminPageTitle = 'Footer Settings';
$requiredPermission = 'manage_navigation';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/storage.php';

$pdo = db();
$storage = new SupabaseStorage();

$menuStmt = $pdo->prepare("SELECT id FROM public.navigation_menus WHERE location = 'footer' LIMIT 1");
$menuStmt->execute();
$footerMenuId = (int) $menuStmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_footer') {
            $current = get_footer_settings();
            $logoPath = $current['logo_path'] ?? null;
            $logoUrl  = $current['logo_url'] ?? null;
            $oldLogoPath = null;
            $newUpload   = null;

            if (!empty($_FILES['footer_logo']['name'])) {
                $newUpload = $storage->upload($_FILES['footer_logo'], BUCKET_WEBSITE_ASSETS, ALLOWED_LOGO_MIMES, MAX_ASSET_UPLOAD_BYTES, 'footer');
                if ($logoPath) {
                    $oldLogoPath = (string) $logoPath;
                }
                $logoPath = $newUpload['path'];
                $logoUrl  = $newUpload['url'];
            }

            try {
                db_transaction(function (PDO $pdo) use ($logoPath, $logoUrl, $currentAdmin): void {
                    $upd = $pdo->prepare(
                        'UPDATE public.footer_settings
                         SET logo_path = :lp, logo_url = :lu, site_name = :name, description = :desc,
                             contact_address = :addr, contact_phone = :phone, contact_email = :email,
                             facebook_url = :fb, instagram_url = :ig, youtube_url = :yt, x_url = :x,
                             copyright_text = :copy, show_social_links = :ss, show_contact_info = :sc, is_visible = :vis
                         WHERE id = 1'
                    );
                    $upd->execute([
                        ':lp'    => $logoPath,
                        ':lu'    => $logoUrl,
                        ':name'  => trim((string) ($_POST['site_name'] ?? 'Purbo Nijmaona Fakir Bari')),
                        ':desc'  => trim((string) ($_POST['description'] ?? '')),
                        ':addr'  => trim((string) ($_POST['contact_address'] ?? '')),
                        ':phone' => trim((string) ($_POST['contact_phone'] ?? '')),
                        ':email' => trim((string) ($_POST['contact_email'] ?? '')),
                        ':fb'    => trim((string) ($_POST['facebook_url'] ?? '')),
                        ':ig'    => trim((string) ($_POST['instagram_url'] ?? '')),
                        ':yt'    => trim((string) ($_POST['youtube_url'] ?? '')),
                        ':x'     => trim((string) ($_POST['x_url'] ?? '')),
                        ':copy'  => trim((string) ($_POST['copyright_text'] ?? '')),
                        ':ss'    => !empty($_POST['show_social_links']) ? 'true' : 'false',
                        ':sc'    => !empty($_POST['show_contact_info']) ? 'true' : 'false',
                        ':vis'   => !empty($_POST['is_visible']) ? 'true' : 'false',
                    ]);

                    $footerBg = sanitize_hex_color($_POST['footer_bg_color'] ?? null, '#14532d');
                    $footerFg = sanitize_hex_color($_POST['footer_text_color'] ?? null, '#ecfdf5');
                    $pdo->prepare('UPDATE public.website_settings SET footer_bg_color = :bg, footer_text_color = :fg WHERE id = 1')
                        ->execute([':bg' => $footerBg, ':fg' => $footerFg]);

                    log_admin_activity((int) $currentAdmin['id'], 'footer_settings_updated', 'footer_settings', 1);
                });
            } catch (Throwable $dbErr) {
                if ($newUpload !== null) {
                    $storage->delete($newUpload['bucket'], $newUpload['path']);
                }
                throw $dbErr;
            }

            if ($oldLogoPath !== null) {
                $storage->delete(BUCKET_WEBSITE_ASSETS, $oldLogoPath);
            }
            set_flash('success', 'Footer settings updated.');
        } elseif ($action === 'add_link' || $action === 'update_link') {
            $id    = (int) ($_POST['item_id'] ?? 0);
            $label = trim((string) ($_POST['label'] ?? ''));
            $url   = trim((string) ($_POST['url'] ?? '/'));
            $order = (int) ($_POST['sort_order'] ?? 0);
            if ($label === '') {
                throw new RuntimeException('Link label is required.');
            }

            if ($action === 'add_link') {
                $pdo->prepare(
                    "INSERT INTO public.navigation_items (menu_id, label, url, item_type, sort_order, is_visible)
                     VALUES (:mid, :lbl, :url, 'link', :ord, TRUE)"
                )->execute([':mid' => $footerMenuId, ':lbl' => $label, ':url' => $url, ':ord' => $order]);
                set_flash('success', 'Footer link added.');
            } else {
                $pdo->prepare(
                    'UPDATE public.navigation_items SET label = :lbl, url = :url, sort_order = :ord WHERE id = :id AND menu_id = :mid'
                )->execute([':lbl' => $label, ':url' => $url, ':ord' => $order, ':id' => $id, ':mid' => $footerMenuId]);
                set_flash('success', 'Footer link updated.');
            }
            log_admin_activity((int) $currentAdmin['id'], 'footer_links_updated', 'navigation_items', $id ?: null);
        } elseif ($action === 'delete_link') {
            $id = (int) ($_POST['item_id'] ?? 0);
            $pdo->prepare('DELETE FROM public.navigation_items WHERE id = :id AND menu_id = :mid')
                ->execute([':id' => $id, ':mid' => $footerMenuId]);
            set_flash('success', 'Footer link deleted.');
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    redirect_to(admin_url('footer.php'));
}

$footer = get_footer_settings();
$site   = get_website_settings();
$linksStmt = $pdo->prepare('SELECT * FROM public.navigation_items WHERE menu_id = :mid ORDER BY sort_order ASC, id ASC');
$linksStmt->execute([':mid' => $footerMenuId]);
$footerLinks = $linksStmt->fetchAll();
?>

<section class="adm-panel">
  <h2 class="adm-panel-title">Footer Content, Colors &amp; Visibility</h2>
  <form method="post" action="<?= e(admin_url('footer.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_footer">

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="site_name">Footer Website Name</label>
        <input class="form-control" type="text" id="site_name" name="site_name" value="<?= e($footer['site_name']) ?>" required>
      </div>
      <div class="form-row">
        <label class="form-label" for="footer_logo">Footer Logo (Optional)</label>
        <input class="form-control" type="file" id="footer_logo" name="footer_logo" data-preview-target="footer_logo_preview" accept="image/jpeg,image/png,image/webp">
        <div class="adm-media-preview" id="footer_logo_preview">
          <?php if (!empty($footer['logo_url'])): ?>
            <img src="<?= e(safe_url($footer['logo_url'])) ?>" alt="Current footer logo" class="adm-preview-media adm-preview-logo">
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="form-row">
      <label class="form-label" for="description">Short Footer Description</label>
      <textarea class="form-control" id="description" name="description"><?= e($footer['description'] ?? '') ?></textarea>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="contact_address">Community Address</label>
        <input class="form-control" type="text" id="contact_address" name="contact_address" value="<?= e($footer['contact_address'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="contact_phone">Contact Phone</label>
        <input class="form-control" type="text" id="contact_phone" name="contact_phone" value="<?= e($footer['contact_phone'] ?? '') ?>">
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="contact_email">Contact Email</label>
        <input class="form-control" type="email" id="contact_email" name="contact_email" value="<?= e($footer['contact_email'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="copyright_text">Copyright Text</label>
        <input class="form-control" type="text" id="copyright_text" name="copyright_text" value="<?= e($footer['copyright_text']) ?>" required>
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="facebook_url">Facebook URL</label>
        <input class="form-control" type="url" id="facebook_url" name="facebook_url" value="<?= e($footer['facebook_url'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="instagram_url">Instagram URL</label>
        <input class="form-control" type="url" id="instagram_url" name="instagram_url" value="<?= e($footer['instagram_url'] ?? '') ?>">
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="youtube_url">YouTube URL</label>
        <input class="form-control" type="url" id="youtube_url" name="youtube_url" value="<?= e($footer['youtube_url'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="x_url">X URL</label>
        <input class="form-control" type="url" id="x_url" name="x_url" value="<?= e($footer['x_url'] ?? '') ?>">
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="footer_bg_color">Footer Background Color</label>
        <input class="form-control" type="color" id="footer_bg_color" name="footer_bg_color" value="<?= e(sanitize_hex_color($site['footer_bg_color'], '#14532d')) ?>" style="height: 44px; padding: 4px;">
      </div>
      <div class="form-row">
        <label class="form-label" for="footer_text_color">Footer Text Color</label>
        <input class="form-control" type="color" id="footer_text_color" name="footer_text_color" value="<?= e(sanitize_hex_color($site['footer_text_color'], '#ecfdf5')) ?>" style="height: 44px; padding: 4px;">
      </div>
    </div>

    <div class="form-row" style="display: flex; gap: 20px; flex-wrap: wrap;">
      <label class="form-check"><input type="checkbox" name="is_visible" value="1" <?= !empty($footer['is_visible']) ? 'checked' : '' ?>> <span>Display Footer</span></label>
      <label class="form-check"><input type="checkbox" name="show_contact_info" value="1" <?= !empty($footer['show_contact_info']) ? 'checked' : '' ?>> <span>Show Contact Info</span></label>
      <label class="form-check"><input type="checkbox" name="show_social_links" value="1" <?= !empty($footer['show_social_links']) ? 'checked' : '' ?>> <span>Show Social Links</span></label>
    </div>

    <button type="submit" class="btn btn-green">Save Footer Settings</button>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">Footer Quick Links</h2>
  <form method="post" action="<?= e(admin_url('footer.php')) ?>" style="display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_link">
    <input class="form-control" type="text" name="label" placeholder="Link label" required style="flex: 1; min-width: 150px;">
    <input class="form-control" type="text" name="url" placeholder="/posts.php" required style="flex: 1; min-width: 150px;">
    <input class="form-control" type="number" name="sort_order" value="0" style="width: 84px;">
    <button type="submit" class="btn btn-green">Add Link</button>
  </form>

  <div class="table-wrap">
    <table class="adm-table">
      <thead><tr><th>Label &amp; URL</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($footerLinks as $fl): ?>
          <tr>
            <td>
              <form method="post" action="<?= e(admin_url('footer.php')) ?>" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_link">
                <input type="hidden" name="item_id" value="<?= (int) $fl['id'] ?>">
                <input class="form-control" type="text" name="label" value="<?= e($fl['label']) ?>" style="max-width: 180px;">
                <input class="form-control" type="text" name="url" value="<?= e($fl['url']) ?>" style="max-width: 220px;">
                <input class="form-control" type="number" name="sort_order" value="<?= (int) $fl['sort_order'] ?>" style="width: 74px;">
                <button type="submit" class="btn btn-outline btn-sm">Save</button>
              </form>
            </td>
            <td>
              <form method="post" action="<?= e(admin_url('footer.php')) ?>" data-confirm="Remove this footer link?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_link">
                <input type="hidden" name="item_id" value="<?= (int) $fl['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
