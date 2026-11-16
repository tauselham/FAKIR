<?php
declare(strict_types=1);

$সক্রিয়Page = 'popups';
$adminPageTitle = 'পপআপ ব্যবস্থাপনা';
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
        $date = new DateTimeImmutable($value, new DateTimeZone('Asia/Dhaka'));
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    } catch (Throwable) {
        throw new RuntimeException('সঠিক তারিখ ও সময় লিখুন।');
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
            $imageLink = trim((string) ($_POST['image_link_url'] ?? ''));
            $buttonলেখা = trim((string) ($_POST['button_text'] ?? ''));
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
            $সক্রিয় = !empty($_POST['is_সক্রিয়']);

            if ($title !== '' && mb_strlen($title) > 180) throw new RuntimeException('শিরোনাম সর্বোচ্চ ১৮০ অক্ষরের হতে পারবে।');
            if ($type === 'text' && $body === '') throw new RuntimeException('পপআপের লেখা লিখুন।');
            if (mb_strlen($body) > 10000) throw new RuntimeException('পপআপের লেখা অনেক বড় (সর্বোচ্চ ১০,০০০ অক্ষর)।');
            if ($starts && $ends && strtotime($ends) <= strtotime($starts)) throw new RuntimeException('শেষের সময় শুরুর সময়ের পরে হতে হবে।');
            if ($buttonলেখা !== '' && $buttonUrl === '') throw new RuntimeException('বাটনের লেখা দিলে তার লিংকও দিন, নতুবা লেখাটি ফাঁকা রাখুন।');

            if ($imageLink !== '' && safe_url($imageLink, '') === '') throw new RuntimeException('ছবির জন্য নিরাপদ আপেক্ষিক অথবা http/https লিংক দিন।');
            if ($buttonUrl !== '' && safe_url($buttonUrl, '') === '') throw new RuntimeException('বাটনের জন্য নিরাপদ আপেক্ষিক অথবা http/https লিংক দিন।');
            $imageLink = $imageLink !== '' ? safe_url($imageLink, '') : '';
            $buttonUrl = $buttonUrl !== '' ? safe_url($buttonUrl, '') : '';

            $existing = null;
            if ($id > 0) {
                $st = $pdo->prepare('SELECT * FROM public.site_popups WHERE id = :id');
                $st->execute([':id' => $id]);
                $existing = $st->fetch();
                if (!$existing) throw new RuntimeException('পপআপটি পাওয়া যায়নি।');
            }

            $bucket = $existing['image_bucket'] ?? null;
            $path = $existing['image_path'] ?? null;
            $url = $existing['image_url'] ?? null;
            $newUpload = null;
            $oldImage = null;

            if ($type === 'image') {
                if (!empty($_FILES['popup_image']['name'])) {
                    $newUpload = $storage->upload($_FILES['popup_image'], BUCKET_WEBSITE_ASSETS, ALLOWED_ASSET_MIMES, MAX_ASSET_UPLOAD_BYTES, 'popups');
                    $oldImage = $existing && !empty($existing['image_path']) ? [$bucket, $path] : null;
                    $bucket = $newUpload['bucket']; $path = $newUpload['path']; $url = $newUpload['url'];
                } elseif (!$existing || empty($existing['image_url'])) {
                    throw new RuntimeException('এই পপআপের জন্য একটি ছবি নির্বাচন করুন।');
                }
            } else {
                $oldImage = $existing && !empty($existing['image_path']) ? [$bucket, $path] : null;
                $bucket = $path = $url = null;
            }

            try {
                $params = [
                    ':title' => $title !== '' ? $title : null,
                    ':type' => $type,
                    ':body' => $type === 'text' ? $body : null,
                    ':bucket' => $bucket, ':path' => $path, ':url' => $url,
                    ':image_link' => $type === 'image' && $imageLink !== '' ? $imageLink : null,
                    ':image_target' => $imageTarget,
                    ':button_text' => $buttonলেখা !== '' ? $buttonলেখা : null,
                    ':button_url' => $buttonUrl !== '' ? $buttonUrl : null,
                    ':button_target' => $buttonTarget,
                    ':starts' => $starts, ':ends' => $ends,
                    ':delay' => $delay, ':frequency' => $frequency,
                    ':scope' => $scope, ':device' => $device,
                    ':priority' => $priority, ':সক্রিয়' => $সক্রিয়,
                ];
                if ($existing) {
                    $params[':id'] = $id;
                    $st = $pdo->prepare('UPDATE public.site_popups SET title=:title,popup_type=:type,body_text=:body,image_bucket=:bucket,image_path=:path,image_url=:url,image_link_url=:image_link,image_link_target=:image_target,button_text=:button_text,button_url=:button_url,button_target=:button_target,starts_at=:starts,ends_at=:ends,delay_seconds=:delay,frequency=:frequency,display_scope=:scope,device_target=:device,priority=:priority,is_সক্রিয়=:সক্রিয় WHERE id=:id');
                    $st->execute($params);
                    log_admin_activity((int) $currentAdmin['id'], 'popup_updated', 'site_popup', $id);
                } else {
                    $params[':aid'] = (int) $currentAdmin['id'];
                    $st = $pdo->prepare('INSERT INTO public.site_popups (title,popup_type,body_text,image_bucket,image_path,image_url,image_link_url,image_link_target,button_text,button_url,button_target,starts_at,ends_at,delay_seconds,frequency,display_scope,device_target,priority,is_সক্রিয়,admin_id) VALUES (:title,:type,:body,:bucket,:path,:url,:image_link,:image_target,:button_text,:button_url,:button_target,:starts,:ends,:delay,:frequency,:scope,:device,:priority,:সক্রিয়,:aid) RETURNING id');
                    $st->execute($params);
                    $id = (int) $st->fetchColumn();
                    log_admin_activity((int) $currentAdmin['id'], 'popup_created', 'site_popup', $id);
                }
            } catch (Throwable $e) {
                if ($newUpload) $storage->delete($newUpload['bucket'], $newUpload['path']);
                throw $e;
            }

            if ($oldImage && $oldImage[0] && $oldImage[1]) $storage->delete((string) $oldImage[0], (string) $oldImage[1]);
            set_flash('success', $existing ? 'পপআপ হালনাগাদ হয়েছে।' : 'পপআপ তৈরি হয়েছে।');
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
                log_admin_activity((int) $currentAdmin['id'], 'popup_deleted', 'site_popup', $id);
            }
            set_flash('success', 'পপআপ মুছে ফেলা হয়েছে।');
            redirect_to(admin_url('popups.php'));
        }

        if ($action === 'toggle') {
            $id = (int) ($_POST['popup_id'] ?? 0);
            $pdo->prepare('UPDATE public.site_popups SET is_সক্রিয় = NOT is_সক্রিয় WHERE id = :id')->execute([':id' => $id]);
            log_admin_activity((int) $currentAdmin['id'], 'popup_toggled', 'site_popup', $id);
            set_flash('success', 'পপআপের অবস্থা হালনাগাদ হয়েছে।');
            redirect_to(admin_url('popups.php'));
        }
    } catch (Throwable $e) {
        error_log('পপআপ admin action failed: ' . $e->getMessage());
        set_flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'পপআপ সংরক্ষণ করা যায়নি। ফর্মের তথ্য যাচাই করে আবার চেষ্টা করুন।');
        redirect_to(admin_url('popups.php'));
    }
}

$popups = $pdo->query('SELECT * FROM public.site_popups ORDER BY priority DESC, created_at DESC')->fetchAll();
$edit = null;
if (!empty($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM public.site_popups WHERE id = :id');
    $st->execute([':id' => (int) $_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$fmtLocal = static function (?string $value): string {
    if (!$value) return '';
    try { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('Y-m-d\TH:i'); }
    catch (Throwable) { return ''; }
};
?>

<section class="adm-panel">
  <h2 class="adm-panel-title"><?= $edit ? 'পপআপ সম্পাদনা #' . (int) $edit['id'] : 'নতুন পপআপ তৈরি করুন' ?></h2>
  <p class="form-hint">বাংলাদেশ সময় (এশিয়া/ঢাকা) অনুযায়ী সময় নির্ধারণ করুন। সময় ও প্রদর্শনের শর্ত মিললে পপআপ স্বয়ংক্রিয়ভাবে দেখাবে।</p>
  <form method="post" action="<?= e(admin_url('popups.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="popup_id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="popup_type">পপআপ ধরন</label>
        <select class="form-control" id="popup_type" name="popup_type">
          <option value="text" <?= ($edit['popup_type'] ?? 'text') === 'text' ? 'selected' : '' ?>>লেখার পপআপ</option>
          <option value="image" <?= ($edit['popup_type'] ?? '') === 'image' ? 'selected' : '' ?>>ছবির পপআপ (ক্লিকযোগ্য)</option>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label" for="popup_title">শিরোনাম (ঐচ্ছিক)</label>
        <input class="form-control" id="popup_title" name="title" maxlength="180" value="<?= e($edit['title'] ?? '') ?>" placeholder="ঘোষণার শিরোনাম">
      </div>
    </div>
    <div class="form-row" id="popup-text-field">
      <label class="form-label" for="popup_body">পপআপের বার্তা</label>
      <textarea class="form-control" id="popup_body" name="body_text" rows="5" maxlength="10000" placeholder="আপনার ঘোষণা লিখুন..."><?= e($edit['body_text'] ?? '') ?></textarea>
    </div>
    <div class="form-row" id="popup-image-field">
      <label class="form-label" for="popup_image">পপআপের ছবি</label>
      <input class="form-control" type="file" id="popup_image" name="popup_image" accept="image/jpeg,image/png,image/webp,image/x-icon" data-preview-target="popup-image-preview">
      <span class="form-hint">JPG, PNG অথবা WebP ছবি ব্যবহার করুন। সর্বোচ্চ ৫ মেগাবাইট। ছবিটি Supabase Storage-এ সংরক্ষিত হবে।</span>
      <div class="popup-preview" id="popup-image-preview"><?php if ($edit && !empty($edit['image_url'])): ?><img src="<?= e(safe_url($edit['image_url'])) ?>" alt="বর্তমান পপআপের ছবি"><?php endif; ?></div>
      <div class="form-row">
        <label class="form-label" for="image_link_url">ছবিতে ক্লিক করলে যে লিংকে যাবে (ঐচ্ছিক)</label>
        <input class="form-control" id="image_link_url" name="image_link_url" type="text" value="<?= e($edit['image_link_url'] ?? '') ?>" placeholder="https://example.com">
      </div>
      <div class="form-row">
        <label class="form-label" for="image_link_target">ছবির লিংক খুলবে</label>
        <select class="form-control" id="image_link_target" name="image_link_target">
          <option value="_self" <?= ($edit['image_link_target'] ?? '_self') === '_self' ? 'selected' : '' ?>>একই ট্যাবে</option>
          <option value="_blank" <?= ($edit['image_link_target'] ?? '') === '_blank' ? 'selected' : '' ?>>নতুন ট্যাবে</option>
        </select>
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="button_text">বাটনের লেখা (ঐচ্ছিক)</label>
        <input class="form-control" id="button_text" name="button_text" maxlength="80" value="<?= e($edit['button_text'] ?? '') ?>" placeholder="আরও জানুন">
      </div>
      <div class="form-row">
        <label class="form-label" for="button_url">বাটনের লিংক</label>
        <input class="form-control" id="button_url" name="button_url" value="<?= e($edit['button_url'] ?? '') ?>" placeholder="/gallery.php অথবা https://...">
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="starts_at">শুরুর সময় (বাংলাদেশ)</label>
        <input class="form-control" type="datetime-local" id="starts_at" name="starts_at" value="<?= e($fmtLocal($edit['starts_at'] ?? null)) ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="ends_at">শেষ হওয়ার সময় (বাংলাদেশ)</label>
        <input class="form-control" type="datetime-local" id="ends_at" name="ends_at" value="<?= e($fmtLocal($edit['ends_at'] ?? null)) ?>">
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="delay_seconds">কত সেকেন্ড পরে দেখাবে</label>
        <input class="form-control" type="number" min="0" max="60" id="delay_seconds" name="delay_seconds" value="<?= (int) ($edit['delay_seconds'] ?? 3) ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="frequency">কতবার দেখাবে</label>
        <select class="form-control" id="frequency" name="frequency">
          <option value="once_session" <?= ($edit['frequency'] ?? 'once_session') === 'once_session' ? 'selected' : '' ?>>প্রতি ব্রাউজার সেশনে একবার</option>
          <option value="once_day" <?= ($edit['frequency'] ?? '') === 'once_day' ? 'selected' : '' ?>>প্রতিদিন একবার</option>
          <option value="every_page" <?= ($edit['frequency'] ?? '') === 'every_page' ? 'selected' : '' ?>>প্রতিটি উপযুক্ত পেজ দেখার সময়</option>
        </select>
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="display_scope">কোথায় দেখাবে</label>
        <select class="form-control" id="display_scope" name="display_scope">
          <option value="all" <?= ($edit['display_scope'] ?? 'all') === 'all' ? 'selected' : '' ?>>সব পাবলিক পেজে</option>
          <option value="home" <?= ($edit['display_scope'] ?? '') === 'home' ? 'selected' : '' ?>>শুধু মূল পাতায়</option>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label" for="device_target">ডিভাইস</label>
        <select class="form-control" id="device_target" name="device_target">
          <option value="all" <?= ($edit['device_target'] ?? 'all') === 'all' ? 'selected' : '' ?>>সব ডিভাইসে</option>
          <option value="desktop" <?= ($edit['device_target'] ?? '') === 'desktop' ? 'selected' : '' ?>>শুধু কম্পিউটারে</option>
          <option value="mobile" <?= ($edit['device_target'] ?? '') === 'mobile' ? 'selected' : '' ?>>শুধু মোবাইলে</option>
        </select>
      </div>
    </div>
    <div class="popup-admin-grid">
      <div class="form-row">
        <label class="form-label" for="priority">অগ্রাধিকার (বেশি হলে আগে দেখাবে)</label>
        <input class="form-control" type="number" min="-1000" max="1000" id="priority" name="priority" value="<?= (int) ($edit['priority'] ?? 0) ?>">
      </div>
      <div class="form-row">
        <label class="form-check"><input type="checkbox" name="is_সক্রিয়" value="1" <?= (!$edit || !empty($edit['is_সক্রিয়'])) ? 'checked' : '' ?>><span>এই পপআপ চালু করুন</span></label>
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-green" type="submit"><?= $edit ? 'পপআপ সংরক্ষণ করুন' : 'নতুন পপআপ তৈরি করুন' ?></button>
      <?php if ($edit): ?><a class="btn btn-outline" href="<?= e(admin_url('popups.php')) ?>">সম্পাদনা বাতিল</a><?php endif; ?>
    </div>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">পপআপ ব্যবস্থাপনা</h2>
  <div class="table-wrap">
    <table class="adm-table">
      <thead><tr><th>পপআপ</th><th>ধরন</th><th>সময়সূচি</th><th>লক্ষ্য</th><th>প্রদর্শনের হার</th><th>অবস্থা</th><th>করণীয়</th></tr></thead>
      <tbody>
      <?php foreach ($popups as $p): ?>
        <tr>
          <td><strong><?= e($p['title'] ?: 'শিরোনামহীন পপআপ') ?></strong><br><span class="form-hint">অগ্রাধিকার <?= (int) $p['priority'] ?></span></td>
          <td><?= e($p['popup_type'] === 'image' ? 'ছবি' : 'লেখা') ?></td>
          <td><?= e($fmtLocal($p['starts_at']) ?: 'সঙ্গে সঙ্গে') ?><br><span class="form-hint"><?= e($fmtLocal($p['ends_at']) ?: 'শেষের সময় নির্ধারিত নয়') ?></span></td>
          <td><?= e($p['display_scope'] === 'home' ? 'শুধু মূল পাতা' : 'সব পাবলিক পেজ') ?> / <?= e($p['device_target'] === 'desktop' ? 'শুধু কম্পিউটার' : ($p['device_target'] === 'mobile' ? 'শুধু মোবাইল' : 'সব ডিভাইস')) ?></td>
          <td><?= e($p['frequency'] === 'once_day' ? 'প্রতিদিন একবার' : ($p['frequency'] === 'every_page' ? 'প্রতিটি পেজে' : 'প্রতি সেশনে একবার')) ?></td>
          <td><span class="popup-status <?= !empty($p['is_সক্রিয়']) ? 'is-সক্রিয়' : 'is-off' ?>"><?= !empty($p['is_সক্রিয়']) ? 'চালু' : 'বন্ধ' ?></span></td>
          <td><div style="display:flex;gap:6px;flex-wrap:wrap">
            <a class="btn btn-outline btn-sm" href="<?= e(admin_url('popups.php')) ?>?edit=<?= (int) $p['id'] ?>">সম্পাদনা</a>
            <form method="post" action="<?= e(admin_url('popups.php')) ?>">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="popup_id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn-outline btn-sm" type="submit"><?= !empty($p['is_সক্রিয়']) ? 'বন্ধ করুন' : 'চালু করুন' ?></button>
            </form>
            <form method="post" action="<?= e(admin_url('popups.php')) ?>" data-confirm="এই পপআপটি স্থায়ীভাবে মুছে ফেলবেন?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="popup_id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">মুছুন</button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$popups): ?><tr><td colspan="7">এখনো কোনো পপআপ তৈরি করা হয়নি।</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<script src="<?= e(admin_url('assets/js/popups.js')) ?>?v=1" defer></script>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
