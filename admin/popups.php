<?php
declare(strict_types=1);

$activePage = 'popups';
$adminPageশিরোনাম = 'পপআপ ব্যবস্থাপনা';
$requiredPermission = 'manage_popups';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/storage.php';

$pdo = db();
$storage = new SupabaseStorage();

function popup_local_datetime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') return null;
    try {
        $date = new তারিখসময়Immutable($value, new তারিখসময়Zone('Asia/Dhaka'));
        return $date->setসময়zone(new তারিখসময়Zone('UTC'))->format('Y-m-d H:i:sP');
    } catch (Throwable) {
        throw new RuntimeException('Please enter a valid schedule date and time.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save') {
            $id = (int) ($_POST['popup_id'] ?? 0);
            $type = ($_POST['popup_type'] ?? 'text') === 'image' ? 'image' : 'text';
            $title = trim((string) ($_POST['title'] ?? ''));
            $body = trim((string) ($_POST['body_text'] ?? ''));
            $imageলিংক = trim((string) ($_POST['image_link_url'] ?? ''));
            $buttonText = trim((string) ($_POST['button_text'] ?? ''));
            $buttonUrl = trim((string) ($_POST['button_url'] ?? ''));
            $imageTarget = ($_POST['image_link_target'] ?? '_self') === '_blank' ? '_blank' : '_self';
            $buttonTarget = ($_POST['button_target'] ?? '_self') === '_blank' ? '_blank' : '_self';
            $frequency = in_array($_POST['frequency'] ?? '', ['every_page', 'once_session', 'once_day'], true) ? $_POST['frequency'] : 'once_session';
            $scope = ($_POST['display_scope'] ?? 'all') === 'home' ? 'home' : 'all';
            $device = in_array($_POST['device_target'] ?? '', ['all', 'desktop', 'mobile'], true) ? $_POST['device_target'] : 'all';
            $delay = max(0, min(60, (int) ($_POST['delay_seconds'] ?? 3)));
            $priority = max(-1000, min(1000, (int) ($_POST['priority'] ?? 0)));
            $starts = popup_local_datetime($_POST['starts_at'] ?? null);
            $ends = popup_local_datetime($_POST['ends_at'] ?? null);
            $active = !empty($_POST['is_active']);

            if ($title !== '' && mb_strlen($title) > 180) throw new RuntimeException('শিরোনাম must be 180 characters or fewer.');
            if ($type === 'text' && $body === '') throw new RuntimeException('Please enter popup text.');
            if (mb_strlen($body) > 10000) throw new RuntimeException('Popup text is too long (maximum 10,000 characters).');
            if ($starts && $ends && strtotime($ends) <= strtotime($starts)) throw new RuntimeException('End time must be later than start time.');
            if ($buttonText !== '' && $buttonUrl === '') throw new RuntimeException('যোগ করুন a button লিংক or leave the button text empty.');

            if ($imageলিংক !== '' && safe_url($imageলিংক, '') === '') throw new RuntimeException('ছবি link must be a safe relative or http/https লিংক.');
            if ($buttonUrl !== '' && safe_url($buttonUrl, '') === '') throw new RuntimeException('বাটন link must be a safe relative or http/https লিংক.');
            $imageলিংক = $imageলিংক !== '' ? safe_url($imageলিংক, '') : '';
            $buttonUrl = $buttonUrl !== '' ? safe_url($buttonUrl, '') : '';

            $existing = null;
            if ($id > 0) {
                $st = $pdo->prepare('SELECT * FROM public.site_popups WHERE id = :id');
                $st->execute([':id' => $id]);
                $existing = $st->fetch();
                if (!$existing) throw new RuntimeException('Popup not found.');
            }

            $bucket = $existing['image_bucket'] ?? null;
            $path = $existing['image_path'] ?? null;
            $url = $existing['image_url'] ?? null;
            $newUpload = null;
            $oldছবি = null;

            if ($type === 'image') {
                if (!empty($_FILES['popup_image']['name'])) {
                    $newUpload = $storage->upload($_FILES['popup_image'], BUCKET_WEBSITE_ASSETS, ALLOWED_ASSET_MIMES, MAX_ASSET_UPLOAD_BYTES, 'popups');
                    $oldছবি = $existing && !empty($existing['image_path']) ? [$bucket, $path] : null;
                    $bucket = $newUpload['bucket']; $path = $newUpload['path']; $url = $newUpload['url'];
                } elseif (!$existing || empty($existing['image_url'])) {
                    throw new RuntimeException('Please select an image for this popup.');
                }
            } else {
                $oldছবি = $existing && !empty($existing['image_path']) ? [$bucket, $path] : null;
                $bucket = $path = $url = null;
            }

            try {
                $params = [
                    ':title' => $title !== '' ? $title : null,
                    ':type' => $type,
                    ':body' => $type === 'text' ? $body : null,
                    ':bucket' => $bucket, ':path' => $path, ':url' => $url,
                    ':image_link' => $type === 'image' && $imageলিংক !== '' ? $imageলিংক : null,
                    ':image_target' => $imageTarget,
                    ':button_text' => $buttonText !== '' ? $buttonText : null,
                    ':button_url' => $buttonUrl !== '' ? $buttonUrl : null,
                    ':button_target' => $buttonTarget,
                    ':starts' => $starts, ':ends' => $ends,
                    ':delay' => $delay, ':frequency' => $frequency,
                    ':scope' => $scope, ':device' => $device,
                    ':priority' => $priority, ':active' => $active,
                ];
                if ($existing) {
                    $params[':id'] = $id;
                    $st = $pdo->prepare('UPDATE public.site_popups SET title=:title,popup_type=:type,body_text=:body,image_bucket=:bucket,image_path=:path,image_url=:url,image_link_url=:image_link,image_link_target=:image_target,button_text=:button_text,button_url=:button_url,button_target=:button_target,starts_at=:starts,ends_at=:ends,delay_seconds=:delay,frequency=:frequency,display_scope=:scope,device_target=:device,priority=:priority,is_active=:active WHERE id=:id');
                    $st->execute($params);
                    log_admin_activity((int) $currentঅ্যাডমিন['id'], 'popup_updated', 'site_popup', $id);
                } else {
                    $params[':aid'] = (int) $currentঅ্যাডমিন['id'];
                    $st = $pdo->prepare('INSERT INTO public.site_popups (title,popup_type,body_text,image_bucket,image_path,image_url,image_link_url,image_link_target,button_text,button_url,button_target,starts_at,ends_at,delay_seconds,frequency,display_scope,device_target,priority,is_active,admin_id) VALUES (:title,:type,:body,:bucket,:path,:url,:image_link,:image_target,:button_text,:button_url,:button_target,:starts,:ends,:delay,:frequency,:scope,:device,:priority,:active,:aid) RETURNING id');
                    $st->execute($params);
                    $id = (int) $st->fetchColumn();
                    log_admin_activity((int) $currentঅ্যাডমিন['id'], 'popup_created', 'site_popup', $id);
                }
            } catch (Throwable $e) {
                if ($newUpload) $storage->delete($newUpload['bucket'], $newUpload['path']);
                throw $e;
            }

            if ($oldছবি && $oldছবি[0] && $oldছবি[1]) $storage->delete((string) $oldছবি[0], (string) $oldছবি[1]);
            set_flash('success', $existing ? 'Popup updated successfully.' : 'Popup created successfully.');
            redirect_to(admin_url('popups.php'));
        }

        if ($action === 'delete') {
            $id = (int) ($_POST['popup_id'] ?? 0);
            $st = $pdo->prepare('SELECT image_bucket,image_path FROM public.site_popups WHERE id=:id');
            $st->execute([':id' => $id]);
            $row = $st->fetch();
            if ($row) {
                $pdo->prepare('DELETE FROM public.site_popups WHERE id=:id')->execute([':id' => $id]);
                if (!empty($row['image_bucket']) && !empty($row['image_path'])) $storage->delete((string) $row['image_bucket'], (string) $row['image_path']);
                log_admin_activity((int) $currentঅ্যাডমিন['id'], 'popup_deleted', 'site_popup', $id);
            }
            set_flash('success', 'Popup deleted.');
            redirect_to(admin_url('popups.php'));
        }

        if ($action === 'toggle') {
            $id = (int) ($_POST['popup_id'] ?? 0);
            $pdo->prepare('UPDATE public.site_popups SET is_active = NOT is_active WHERE id = :id')->execute([':id' => $id]);
            log_admin_activity((int) $currentঅ্যাডমিন['id'], 'popup_toggled', 'site_popup', $id);
            set_flash('success', 'Popup status updated.');
            redirect_to(admin_url('popups.php'));
        }
    } catch (Throwable $e) {
        error_log('Popup admin action failed: ' . $e->getMessage());
        set_flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Could not save popup. Please check the form and try again.');
        redirect_to(admin_url('popups.php'));
    }
}

$popups = $pdo->query('SELECT * FROM public.site_popups ORDER BY priority DESC, created_at DESC')->fetchসব();
$edit = null;
if (!empty($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM public.site_popups WHERE id = :id');
    $st->execute([':id' => (int) $_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$fmtLocal = static function (?string $value): string {
    if (!$value) return '';
    try { return (new তারিখসময়Immutable($value))->setসময়zone(new তারিখসময়Zone('Asia/Dhaka'))->format('Y-m-d\TH:i'); }
    catch (Throwable) { return ''; }
};
?>

<section class="adm-panel">
  <h2 class="adm-panel-title"><?= $edit ? 'সম্পাদনা Popup #' . (int) $edit['id'] : 'Create Popup Campaign' ?></h2>
  <p class="form-hint">Schedule in Bangladesh time (Asia/Dhaka). Popups appear automatically when their schedule and targeting rules match.</p>
  <form method="post" action="<?= e(admin_url('popups.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="popup_id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="popup_type">Popup ধরন</label>
        <select class="form-control" id="popup_type" name="popup_type">
          <option value="text" <?= ($edit['popup_type'] ?? 'text') === 'text' ? 'selected' : '' ?>>Text Popup</option>
          <option value="image" <?= ($edit['popup_type'] ?? '') === 'image' ? 'selected' : '' ?>>ছবি Popup (clickable)</option>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label" for="popup_title">শিরোনাম (optional)</label>
        <input class="form-control" id="popup_title" name="title" maxlength="180" value="<?= e($edit['title'] ?? '') ?>" placeholder="Announcement title">
      </div>
    </div>
    <div class="form-row" id="popup-text-field">
      <label class="form-label" for="popup_body">Popup Message</label>
      <textarea class="form-control" id="popup_body" name="body_text" rows="5" maxlength="10000" placeholder="Write your announcement..."><?= e($edit['body_text'] ?? '') ?></textarea>
    </div>
    <div class="form-row" id="popup-image-field">
      <label class="form-label" for="popup_image">Popup ছবি</label>
      <input class="form-control" type="file" id="popup_image" name="popup_image" accept="image/jpeg,image/png,image/webp,image/x-icon" data-preview-target="popup-image-preview">
      <span class="form-hint">JPG, PNG or WebP. Maximum 5 MB. ছবি is stored privately on the server-side upload flow in Supabase Storage.</span>
      <div class="popup-preview" id="popup-image-preview"><?php if ($edit && !empty($edit['image_url'])): ?><img src="<?= e(safe_url($edit['image_url'])) ?>" alt="Current popup image"><?php endif; ?></div>
      <div class="form-row">
        <label class="form-label" for="image_link_url">ছবি Click লিংক (optional)</label>
        <input class="form-control" id="image_link_url" name="image_link_url" type="text" value="<?= e($edit['image_link_url'] ?? '') ?>" placeholder="https://example.com">
      </div>
      <div class="form-row">
        <label class="form-label" for="image_link_target">Open image link</label>
        <select class="form-control" id="image_link_target" name="image_link_target">
          <option value="_self" <?= ($edit['image_link_target'] ?? '_self') === '_self' ? 'selected' : '' ?>>Same tab</option>
          <option value="_blank" <?= ($edit['image_link_target'] ?? '') === '_blank' ? 'selected' : '' ?>>New tab</option>
        </select>
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="button_text">বাটন label (optional)</label>
        <input class="form-control" id="button_text" name="button_text" maxlength="80" value="<?= e($edit['button_text'] ?? '') ?>" placeholder="Learn more">
      </div>
      <div class="form-row">
        <label class="form-label" for="button_url">বাটন লিংক</label>
        <input class="form-control" id="button_url" name="button_url" value="<?= e($edit['button_url'] ?? '') ?>" placeholder="/gallery.php or https://...">
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="starts_at">Start time (Bangladesh)</label>
        <input class="form-control" type="datetime-local" id="starts_at" name="starts_at" value="<?= e($fmtLocal($edit['starts_at'] ?? null)) ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="ends_at">End time (Bangladesh)</label>
        <input class="form-control" type="datetime-local" id="ends_at" name="ends_at" value="<?= e($fmtLocal($edit['ends_at'] ?? null)) ?>">
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="delay_seconds">Show after (seconds)</label>
        <input class="form-control" type="number" min="0" max="60" id="delay_seconds" name="delay_seconds" value="<?= (int) ($edit['delay_seconds'] ?? 3) ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="frequency">Display frequency</label>
        <select class="form-control" id="frequency" name="frequency">
          <option value="once_session" <?= ($edit['frequency'] ?? 'once_session') === 'once_session' ? 'selected' : '' ?>>Once per browser session</option>
          <option value="once_day" <?= ($edit['frequency'] ?? '') === 'once_day' ? 'selected' : '' ?>>Once per day</option>
          <option value="every_page" <?= ($edit['frequency'] ?? '') === 'every_page' ? 'selected' : '' ?>>Every eligible page view</option>
        </select>
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="display_scope">Where to show</label>
        <select class="form-control" id="display_scope" name="display_scope">
          <option value="all" <?= ($edit['display_scope'] ?? 'all') === 'all' ? 'selected' : '' ?>>সব public pages</option>
          <option value="home" <?= ($edit['display_scope'] ?? '') === 'home' ? 'selected' : '' ?>>Homepage only</option>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label" for="device_target">Devices</label>
        <select class="form-control" id="device_target" name="device_target">
          <option value="all" <?= ($edit['device_target'] ?? 'all') === 'all' ? 'selected' : '' ?>>সব devices</option>
          <option value="desktop" <?= ($edit['device_target'] ?? '') === 'desktop' ? 'selected' : '' ?>>Desktop only</option>
          <option value="mobile" <?= ($edit['device_target'] ?? '') === 'mobile' ? 'selected' : '' ?>>Mobile only</option>
        </select>
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="priority">Priority (higher shows first)</label>
        <input class="form-control" type="number" min="-1000" max="1000" id="priority" name="priority" value="<?= (int) ($edit['priority'] ?? 0) ?>">
      </div>
      <div class="form-row">
        <label class="form-check"><input type="checkbox" name="is_active" value="1" <?= (!$edit || !empty($edit['is_active'])) ? 'checked' : '' ?>><span>Enable this popup campaign</span></label>
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-green" type="submit"><?= $edit ? 'সংরক্ষণ Popup' : 'Create Popup' ?></button>
      <?php if ($edit): ?><a class="btn btn-outline" href="<?= e(admin_url('popups.php')) ?>">বাতিল edit</a><?php endif; ?>
    </div>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">পপআপ ব্যবস্থাপনা</h2>
  <div class="table-wrap">
    <table class="adm-table">
      <thead><tr><th>Popup</th><th>ধরন</th><th>Schedule</th><th>Target</th><th>Frequency</th><th>অবস্থা</th><th>কার্যক্রম</th></tr></thead>
      <tbody>
      <?php foreach ($popups as $p): ?>
        <tr>
          <td><strong><?= e($p['title'] ?: 'Untitled popup') ?></strong><br><span class="form-hint">Priority <?= (int) $p['priority'] ?></span></td>
          <td><?= e(ucfirst($p['popup_type'])) ?></td>
          <td><?= e($fmtLocal($p['starts_at']) ?: 'Immediately') ?><br><span class="form-hint"><?= e($fmtLocal($p['ends_at']) ?: 'No end time') ?></span></td>
          <td><?= e(ucfirst($p['display_scope'])) ?> / <?= e(ucfirst($p['device_target'])) ?></td>
          <td><?= e(str_replace('_', ' ', $p['frequency'])) ?></td>
          <td><span class="popup-status <?= !empty($p['is_active']) ? 'is-active' : 'is-off' ?>"><?= !empty($p['is_active']) ? 'চালু' : 'Disabled' ?></span></td>
          <td><div style="display:flex;gap:6px;flex-wrap:wrap">
            <a class="btn btn-outline btn-sm" href="<?= e(admin_url('popups.php')) ?>?edit=<?= (int) $p['id'] ?>">সম্পাদনা</a>
            <form method="post" action="<?= e(admin_url('popups.php')) ?>">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="popup_id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn-outline btn-sm" type="submit"><?= !empty($p['is_active']) ? 'Disable' : 'Enable' ?></button>
            </form>
            <form method="post" action="<?= e(admin_url('popups.php')) ?>" data-confirm="Permanently delete this popup?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="popup_id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">মুছুন</button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$popups): ?><tr><td colspan="7">No popup campaigns created yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<script src="<?= e(admin_url('assets/js/popups.js')) ?>?v=1" defer></script>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
