<?php
declare(strict_types=1);

$activePage = 'settings';
$adminPageTitle = 'Website Settings';
$requiredPermission = 'manage_settings';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/storage.php';

$pdo = db();
$storage = new SupabaseStorage();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    try {
        $current = get_website_settings();
        $siteName = trim((string) ($_POST['site_name'] ?? ''));
        if ($siteName === '') {
            throw new RuntimeException('Website name is required.');
        }

        $logoPath    = $current['logo_path'] ?? null;
        $logoUrl     = $current['logo_url'] ?? null;
        $faviconPath = $current['favicon_path'] ?? null;
        $faviconUrl  = $current['favicon_url'] ?? null;

        $oldAssetsToDelete = [];
        $newAssetsUploaded = [];

        try {
            if (!empty($_FILES['logo_file']['name'])) {
                $res = $storage->upload($_FILES['logo_file'], BUCKET_WEBSITE_ASSETS, ALLOWED_LOGO_MIMES, MAX_ASSET_UPLOAD_BYTES, 'logo');
                $newAssetsUploaded[] = $res;
                if ($logoPath) {
                    $oldAssetsToDelete[] = (string) $logoPath;
                }
                $logoPath = $res['path'];
                $logoUrl  = $res['url'];
            }

            if (!empty($_FILES['favicon_file']['name'])) {
                $res = $storage->upload($_FILES['favicon_file'], BUCKET_WEBSITE_ASSETS, ALLOWED_FAVICON_MIMES, MAX_ASSET_UPLOAD_BYTES, 'favicon');
                $newAssetsUploaded[] = $res;
                if ($faviconPath) {
                    $oldAssetsToDelete[] = (string) $faviconPath;
                }
                $faviconPath = $res['path'];
                $faviconUrl  = $res['url'];
            }

            $upd = $pdo->prepare(
                'UPDATE public.website_settings
                 SET site_name = :name, site_description = :desc,
                     logo_path = :lp, logo_url = :lu, favicon_path = :fp, favicon_url = :fu,
                     accent_color = :accent, seo_title = :st, seo_description = :sd,
                     social_facebook = :fb, social_instagram = :ig, social_youtube = :yt
                 WHERE id = 1'
            );
            $upd->execute([
                ':name'   => $siteName,
                ':desc'   => trim((string) ($_POST['site_description'] ?? '')),
                ':lp'     => $logoPath,
                ':lu'     => $logoUrl,
                ':fp'     => $faviconPath,
                ':fu'     => $faviconUrl,
                ':accent' => sanitize_hex_color($_POST['accent_color'] ?? null, '#15803d'),
                ':st'     => trim((string) ($_POST['seo_title'] ?? '')),
                ':sd'     => trim((string) ($_POST['seo_description'] ?? '')),
                ':fb'     => trim((string) ($_POST['social_facebook'] ?? '')),
                ':ig'     => trim((string) ($_POST['social_instagram'] ?? '')),
                ':yt'     => trim((string) ($_POST['social_youtube'] ?? '')),
            ]);
            log_admin_activity((int) $currentAdmin['id'], 'website_settings_updated', 'website_settings', 1);
        } catch (Throwable $err) {
            foreach ($newAssetsUploaded as $uploaded) {
                $storage->delete($uploaded['bucket'], $uploaded['path']);
            }
            throw $err;
        }

        foreach ($oldAssetsToDelete as $oldPath) {
            $storage->delete(BUCKET_WEBSITE_ASSETS, $oldPath);
        }
        set_flash('success', 'Website settings saved.');
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    redirect_to(admin_url('settings.php'));
}

$settings = get_website_settings();
?>

<section class="adm-panel">
  <h2 class="adm-panel-title">General, Branding &amp; SEO Settings</h2>
  <form method="post" action="<?= e(admin_url('settings.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="site_name">Website Name</label>
        <input class="form-control" type="text" id="site_name" name="site_name" value="<?= e($settings['site_name']) ?>" required>
      </div>
      <div class="form-row">
        <label class="form-label" for="accent_color">Primary Green Accent Color</label>
        <input class="form-control" type="color" id="accent_color" name="accent_color" value="<?= e(sanitize_hex_color($settings['accent_color'], '#15803d')) ?>" style="height: 44px; padding: 4px;">
      </div>
    </div>

    <div class="form-row">
      <label class="form-label" for="site_description">Website Description</label>
      <textarea class="form-control" id="site_description" name="site_description"><?= e($settings['site_description'] ?? '') ?></textarea>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="logo_file">Website Logo</label>
        <input class="form-control" type="file" id="logo_file" name="logo_file" data-preview-target="settings_logo_preview" accept="image/jpeg,image/png,image/webp">
        <div class="adm-media-preview" id="settings_logo_preview">
          <?php if (!empty($settings['logo_url'])): ?>
            <img src="<?= e(safe_url($settings['logo_url'])) ?>" alt="Current website logo" class="adm-preview-media adm-preview-logo">
          <?php endif; ?>
        </div>
      </div>
      <div class="form-row">
        <label class="form-label" for="favicon_file">Favicon (.ico / .png / .webp)</label>
        <input class="form-control" type="file" id="favicon_file" name="favicon_file" data-preview-target="settings_favicon_preview" accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/webp">
        <div class="adm-media-preview" id="settings_favicon_preview">
          <?php if (!empty($settings['favicon_url'])): ?>
            <img src="<?= e(safe_url($settings['favicon_url'])) ?>" alt="Current favicon" class="adm-preview-media adm-preview-favicon">
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="seo_title">SEO Title</label>
        <input class="form-control" type="text" id="seo_title" name="seo_title" value="<?= e($settings['seo_title'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="seo_description">SEO Meta Description</label>
        <input class="form-control" type="text" id="seo_description" name="seo_description" value="<?= e($settings['seo_description'] ?? '') ?>">
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="social_facebook">Facebook Page URL</label>
        <input class="form-control" type="url" id="social_facebook" name="social_facebook" value="<?= e($settings['social_facebook'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="social_instagram">Instagram Profile URL</label>
        <input class="form-control" type="url" id="social_instagram" name="social_instagram" value="<?= e($settings['social_instagram'] ?? '') ?>">
      </div>
    </div>

    <div class="form-row">
      <label class="form-label" for="social_youtube">YouTube Channel URL</label>
      <input class="form-control" type="url" id="social_youtube" name="social_youtube" value="<?= e($settings['social_youtube'] ?? '') ?>">
    </div>

    <button type="submit" class="btn btn-green">Save Website Settings</button>
  </form>
</section>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
