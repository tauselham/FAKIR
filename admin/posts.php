<?php
declare(strict_types=1);

$activePage = 'posts';
$adminPageTitle = 'গ্রামের খবর ও পোস্ট';
$requiredPermission = 'manage_posts';
require_once dirname(__DIR__) . '/includes/admin-layout.php';
require_once dirname(__DIR__) . '/includes/storage.php';
require_once dirname(__DIR__) . '/includes/content-sanitizer.php';

$pdo = db();
$storage = new SupabaseStorage();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create' || $action === 'update') {
            enforce_rate_limit('post_submission', [
                'ip' => client_ip(),
                'account' => 'admin:' . (int) $currentAdmin['id'],
            ]);

            $postId = (int) ($_POST['post_id'] ?? 0);
            $postType = in_array($_POST['post_type'] ?? '', ['text', 'image', 'video', 'custom'], true) ? (string) $_POST['post_type'] : 'text';
            $title = trim((string) ($_POST['title'] ?? ''));
            $categoryId = (int) ($_POST['category_id'] ?? 0) ?: null;
            $status = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
            $richHtml = sanitize_rich_html((string) ($_POST['content_html'] ?? ''));
            $customCode = (string) ($_POST['code_document'] ?? '');
            $customExcerpt = trim((string) ($_POST['custom_excerpt'] ?? ''));

            if ($title !== '' && mb_strlen($title) > 220) {
                throw new RuntimeException('পোস্টের শিরোনাম সর্বোচ্চ ২২০ অক্ষরের হতে পারবে।');
            }
            if (strlen($richHtml) > 1048576 || strlen($customCode) > 2097152) {
                throw new RuntimeException('কনটেন্টের আকার নির্ধারিত সীমা ছাড়িয়েছে। সমৃদ্ধ লেখা সর্বোচ্চ ১ মেগাবাইট এবং কাস্টম HTML ডকুমেন্ট সর্বোচ্চ ২ মেগাবাইট হতে পারবে।');
            }
            if ($postType === 'custom' && trim($customCode) === '') {
                throw new RuntimeException('আপনার সম্পূর্ণ HTML, CSS ও JavaScript কোড যোগ করুন।');
            }
            if ($postType === 'text' && trim($richHtml) === '') {
                throw new RuntimeException('পোস্টের লেখা লিখুন অথবা একটি ছবি যোগ করুন।');
            }

            $description = $postType === 'custom'
                ? ($customExcerpt !== '' ? mb_substr($customExcerpt, 0, 500) : ($title !== '' ? $title : 'Custom HTML, CSS and JavaScript community post'))
                : rich_html_plain_text($richHtml, 500);
            if ($description === '') $description = $title !== '' ? $title : 'Community post';

            $existing = null;
            if ($action === 'update') {
                $st = $pdo->prepare('SELECT * FROM public.posts WHERE id = :id LIMIT 1');
                $st->execute([':id' => $postId]);
                $existing = $st->fetch();
                if (!$existing) throw new RuntimeException('পোস্টটি পাওয়া যায়নি।');
            }

            $mediaBucket = $existing['media_bucket'] ?? null;
            $mediaPath = $existing['media_path'] ?? null;
            $mediaUrl = $existing['media_url'] ?? null;
            $mediaMime = $existing['media_mime'] ?? null;
            $oldBucketToমুছুন = null;
            $oldPathToমুছুন = null;
            $newlyUploaded = null;

            if (in_array($postType, ['text', 'custom'], true)) {
                if ($existing && !empty($existing['media_bucket']) && !empty($existing['media_path'])) {
                    $oldBucketToমুছুন = (string) $existing['media_bucket'];
                    $oldPathToমুছুন = (string) $existing['media_path'];
                }
                $mediaBucket = $mediaPath = $mediaUrl = $mediaMime = null;
            } elseif (!empty($_FILES['media_file']['name'])) {
                $allowed = $postType === 'video' ? ALLOWED_VIDEO_MIMES : ALLOWED_IMAGE_MIMES;
                $maxSize = $postType === 'video' ? MAX_VIDEO_UPLOAD_BYTES : MAX_IMAGE_UPLOAD_BYTES;
                $newlyUploaded = $storage->upload($_FILES['media_file'], BUCKET_POST_MEDIA, $allowed, $maxSize, 'posts');
                if ($existing && !empty($existing['media_bucket']) && !empty($existing['media_path'])) {
                    $oldBucketToমুছুন = (string) $existing['media_bucket'];
                    $oldPathToমুছুন = (string) $existing['media_path'];
                }
                $mediaBucket = $newlyUploaded['bucket'];
                $mediaPath = $newlyUploaded['path'];
                $mediaUrl = $newlyUploaded['url'];
                $mediaMime = $newlyUploaded['mime'];
            } elseif ($action === 'create' || !$existing || ($existing['post_type'] ?? '') !== $postType || empty($existing['media_path'])) {
                throw new RuntimeException('এই ধরনের পোস্টের জন্য একটি ছবি অথবা ভিডিও আপলোড করুন।');
            }

            try {
                if ($action === 'create') {
                    $slugBase = slugify($title !== '' ? $title : $description);
                    $slug = mb_substr($slugBase, 0, 220) . '-' . bin2hex(random_bytes(3));
                    $ins = $pdo->prepare(
                        'INSERT INTO public.posts
                         (category_id, admin_id, post_type, title, slug, description, content_html, code_document,
                          media_bucket, media_path, media_url, media_mime, html_content, css_content, js_content, status, published_at)
                         VALUES (:cat, :aid, :type, :title, :slug, :description, :content_html, :code_document,
                                 :bucket, :path, :url, :mime, NULL, NULL, NULL, :status,
                                 CASE WHEN :status_check = \'published\' THEN NOW() ELSE NULL END)
                         RETURNING id'
                    );
                    $ins->execute([
                        ':cat' => $categoryId, ':aid' => (int) $currentAdmin['id'], ':type' => $postType,
                        ':title' => $title !== '' ? $title : null, ':slug' => $slug, ':description' => $description,
                        ':content_html' => $postType === 'custom' ? null : $richHtml,
                        ':code_document' => $postType === 'custom' ? $customCode : null,
                        ':bucket' => $mediaBucket, ':path' => $mediaPath, ':url' => $mediaUrl, ':mime' => $mediaMime,
                        ':status' => $status, ':status_check' => $status,
                    ]);
                    $newId = (int) $ins->fetchColumn();
                    log_admin_activity((int) $currentAdmin['id'], 'post_created', 'post', $newId);
                    set_flash('success', 'পোস্ট তৈরি হয়েছে।');
                } else {
                    $upd = $pdo->prepare(
                        'UPDATE public.posts
                         SET category_id = :cat, post_type = :type, title = :title, description = :description,
                             content_html = :content_html, code_document = :code_document,
                             media_bucket = :bucket, media_path = :path, media_url = :url, media_mime = :mime,
                             html_content = NULL, css_content = NULL, js_content = NULL,
                             status = :status,
                             published_at = CASE WHEN :status_check = \'published\' THEN COALESCE(published_at, NOW()) ELSE NULL END,
                             updated_at = NOW()
                         WHERE id = :id'
                    );
                    $upd->execute([
                        ':cat' => $categoryId, ':type' => $postType, ':title' => $title !== '' ? $title : null,
                        ':description' => $description, ':content_html' => $postType === 'custom' ? null : $richHtml,
                        ':code_document' => $postType === 'custom' ? $customCode : null,
                        ':bucket' => $mediaBucket, ':path' => $mediaPath, ':url' => $mediaUrl, ':mime' => $mediaMime,
                        ':status' => $status, ':status_check' => $status, ':id' => $postId,
                    ]);
                    log_admin_activity((int) $currentAdmin['id'], 'post_updated', 'post', $postId);
                    set_flash('success', 'পোস্ট হালনাগাদ হয়েছে।');
                }
            } catch (Throwable $dbErr) {
                if ($newlyUploaded !== null) $storage->delete($newlyUploaded['bucket'], $newlyUploaded['path']);
                throw $dbErr;
            }

            if ($oldBucketToমুছুন !== null && $oldPathToমুছুন !== null) $storage->delete($oldBucketToমুছুন, $oldPathToমুছুন);
            redirect_to(admin_url('posts.php'));
        } elseif ($action === 'toggle_status') {
            $postId = (int) ($_POST['post_id'] ?? 0);
            $newStatus = ($_POST['new_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            $upd = $pdo->prepare('UPDATE public.posts SET status = :status, published_at = CASE WHEN :status_check = \'published\' THEN COALESCE(published_at, NOW()) ELSE NULL END, updated_at = NOW() WHERE id = :id');
            $upd->execute([':status' => $newStatus, ':status_check' => $newStatus, ':id' => $postId]);
            log_admin_activity((int) $currentAdmin['id'], 'post_status_' . $newStatus, 'post', $postId);
            set_flash('success', 'পোস্টের দৃশ্যমানতা হালনাগাদ হয়েছে।');
            redirect_to(admin_url('posts.php'));
        } elseif ($action === 'delete') {
            $postId = (int) ($_POST['post_id'] ?? 0);
            $st = $pdo->prepare('SELECT media_bucket, media_path FROM public.posts WHERE id = :id LIMIT 1');
            $st->execute([':id' => $postId]);
            $row = $st->fetch();
            if ($row) {
                $del = $pdo->prepare('DELETE FROM public.posts WHERE id = :id');
                $del->execute([':id' => $postId]);
                if (!empty($row['media_bucket']) && !empty($row['media_path'])) $storage->delete((string) $row['media_bucket'], (string) $row['media_path']);
                log_admin_activity((int) $currentAdmin['id'], 'post_deleted', 'post', $postId);
            }
            set_flash('success', 'পোস্ট মুছে ফেলা হয়েছে।');
            redirect_to(admin_url('posts.php'));
        }
    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            error_log('Community post operation failed: ' . $e->getMessage());
            set_flash('error', 'ডেটাবেসের সমস্যার কারণে পোস্ট সংরক্ষণ করা যায়নি। সার্ভার লগ পরীক্ষা করুন।');
        } else {
            set_flash('error', $e->getMessage());
        }
        $returnUrl = admin_url('posts.php');
        if ($action === 'create') $returnUrl .= '?add=1';
        if ($action === 'update') $returnUrl .= '?edit=' . max(0, (int) ($_POST['post_id'] ?? 0));
        redirect_to($returnUrl);
    }
}

$categories = $pdo->query('SELECT * FROM public.post_categories ORDER BY sort_order ASC, id ASC')->fetchAll();
$posts = $pdo->query(
    'SELECT p.*, pc.name AS category_name
     FROM public.posts p
     LEFT JOIN public.post_categories pc ON pc.id = p.category_id
     ORDER BY p.created_at DESC'
)->fetchAll();

$editPost = null;
if (!empty($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM public.posts WHERE id = :id LIMIT 1');
    $st->execute([':id' => (int) $_GET['edit']]);
    $editPost = $st->fetch() ?: null;
}
$showPostForm = isset($_GET['add']) || $editPost !== null;
$legacyCodeDocument = '';
$initialRichHtml = (string) ($editPost['content_html'] ?? '');
if ($editPost && ($editPost['post_type'] ?? '') !== 'custom' && trim($initialRichHtml) === '') {
    // Preserve legacy plain-text post bodies when opening them in the new editor.
    $initialRichHtml = '<p>' . nl2br(e((string) ($editPost['description'] ?? ''))) . '</p>';
}
if ($editPost && ($editPost['post_type'] ?? '') === 'custom' && empty($editPost['code_document'])) {
    $legacyHtml = (string) ($editPost['html_content'] ?? '');
    $legacyCss = (string) ($editPost['css_content'] ?? '');
    $legacyJs = (string) ($editPost['js_content'] ?? '');
    $legacyJs = preg_replace('~</script~i', '<\\/script', $legacyJs) ?? $legacyJs;
    $legacyCodeDocument = "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<style>\n" . $legacyCss . "\n</style>\n</head>\n<body>\n" . $legacyHtml . "\n<script>\n" . $legacyJs . "\n</script>\n</body>\n</html>";
}
?>

<section class="adm-panel">
  <div class="adm-section-heading">
    <div>
      <h2 class="adm-panel-title" style="margin-bottom:5px;">গ্রামের খবর ও পোস্ট</h2>
      <p class="form-hint" style="margin:0;">Manage all existing community posts, or create a new post.</p>
    </div>
    <a class="btn btn-green" href="<?= e(admin_url('posts.php')) ?>?add=1">＋ পোস্ট যোগ করুন</a>
  </div>
</section>

<?php if ($showPostForm): ?>
<section class="adm-panel">
  <div class="adm-section-heading">
    <h2 class="adm-panel-title" style="margin-bottom:0;"><?= $editPost ? 'Edit Community Post #' . (int) $editPost['id'] : 'Add Community Post' ?></h2>
    <a href="<?= e(admin_url('posts.php')) ?>" class="btn btn-outline">← সব পোস্টে ফিরে যান</a>
  </div>
  <form id="community-post-form" method="post" action="<?= e(admin_url('posts.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editPost ? 'update' : 'create' ?>">
    <?php if ($editPost): ?><input type="hidden" name="post_id" value="<?= (int) $editPost['id'] ?>"><?php endif; ?>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="post_type_select">পোস্টের ধরন</label>
        <select class="form-control" name="post_type" id="post_type_select">
          <?php $curType = $editPost['post_type'] ?? 'text'; ?>
          <option value="text" <?= $curType === 'text' ? 'selected' : '' ?>>Rich Text Post (WordPress-style)</option>
          <option value="image" <?= $curType === 'image' ? 'selected' : '' ?>>ছবিসহ লেখা পোস্ট</option>
          <option value="video" <?= $curType === 'video' ? 'selected' : '' ?>>ভিডিওসহ লেখা পোস্ট</option>
          <option value="custom" <?= $curType === 'custom' ? 'selected' : '' ?>>সম্পূর্ণ HTML + CSS + JavaScript ডকুমেন্ট</option>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label" for="category_id">Category</label>
        <select class="form-control" name="category_id" id="category_id">
          <option value="">বিভাগবিহীন</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int) $cat['id'] ?>" <?= ((int) ($editPost['category_id'] ?? 0) === (int) $cat['id']) ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="title">পোস্টের শিরোনাম</label>
        <input class="form-control" type="text" id="title" name="title" maxlength="220" value="<?= e($editPost['title'] ?? '') ?>" placeholder="শিরোনাম লিখুন">
      </div>
      <div class="form-row">
        <label class="form-label" for="status">প্রকাশের অবস্থা</label>
        <select class="form-control" name="status" id="status">
          <option value="published" <?= (($editPost['status'] ?? 'published') === 'published') ? 'selected' : '' ?>>প্রকাশিত</option>
          <option value="draft" <?= (($editPost['status'] ?? '') === 'draft') ? 'selected' : '' ?>>খসড়া</option>
        </select>
      </div>
    </div>

    <div class="form-row" id="post_media_group">
      <label class="form-label" for="media_file">Featured Image or Video (Required for Image/Video format)</label>
      <input class="form-control" type="file" id="media_file" name="media_file" data-preview-target="post_media_preview" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm">
      <span class="form-hint">Image up to 10 MB; video up to 50 MB. The rich editor below can also contain multiple inline images.</span>
      <div class="adm-media-preview" id="post_media_preview">
        <?php if ($editPost && !empty($editPost['media_url'])): ?>
          <?php if ($editPost['post_type'] === 'video'): ?><video src="<?= e(safe_url($editPost['media_url'])) ?>" controls preload="metadata" class="adm-preview-media"></video>
          <?php else: ?><img src="<?= e(safe_url($editPost['media_url'])) ?>" alt="Current post media" class="adm-preview-media"><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div id="post_rich_editor_group" class="form-row">
      <label class="form-label">পোস্টের লেখা — ভিজ্যুয়াল এডিটর</label>
      <div class="rich-editor" data-rich-editor>
        <div class="rich-toolbar" role="toolbar" aria-label="লেখা সাজানোর টুল">
          <button type="button" class="rich-tool" data-editor-command="bold"><strong>B</strong></button>
          <button type="button" class="rich-tool" data-editor-command="italic"><em>I</em></button>
          <button type="button" class="rich-tool" data-editor-command="underline"><u>U</u></button>
          <button type="button" class="rich-tool" data-editor-command="strikeThrough"><s>S</s></button>
          <select class="rich-tool-select" data-editor-block aria-label="লেখার ধরন"><option value="P">অনুচ্ছেদ</option><option value="H2">শিরোনাম ২</option><option value="H3">শিরোনাম ৩</option><option value="H4">শিরোনাম ৪</option><option value="BLOCKQUOTE">উদ্ধৃতি</option></select>
          <select class="rich-tool-select" data-editor-size aria-label="লেখার আকার"><option value="14">14 px</option><option value="16" selected>16 px</option><option value="18">18 px</option><option value="22">22 px</option><option value="28">28 px</option><option value="36">36 px</option></select>
          <label class="rich-color-tool" title="লেখার রং">A <input type="color" data-editor-color value="#202820" aria-label="লেখার রং"></label>
          <button type="button" class="rich-tool" data-editor-command="justifyLeft" title="বামে সাজান">⇤</button>
          <button type="button" class="rich-tool" data-editor-command="justifyCenter" title="মাঝখানে সাজান">↔</button>
          <button type="button" class="rich-tool" data-editor-command="justifyRight" title="ডানে সাজান">⇥</button>
          <button type="button" class="rich-tool" data-editor-command="insertUnorderedList" title="বুলেট তালিকা">• তালিকা</button>
          <button type="button" class="rich-tool" data-editor-command="insertOrderedList" title="ক্রমিক তালিকা">১. তালিকা</button>
          <button type="button" class="rich-tool" data-editor-link>🔗 লিংক</button>
          <button type="button" class="rich-tool" data-editor-image>▧ Image</button>
          <button type="button" class="rich-tool" data-editor-command="removeFormat" title="লেখার সাজসজ্জা মুছুন">মুছে ফেলুন</button>
          <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-editor-image-file hidden>
        </div>
        <div id="post_rich_editor" class="rich-editor-surface" contenteditable="true" role="textbox" aria-multiline="true" aria-label="পোস্টের লেখা" dir="auto"><?= sanitize_rich_html($initialRichHtml) ?></div>
      </div>
      <textarea id="post_content_html" name="content_html" hidden></textarea>
      <span class="form-hint">বাংলা ও ইংরেজি—দুই ভাষাতেই লিখতে পারবেন। লেখা নির্বাচন করে টুলবার থেকে সাজসজ্জা, লিংক বা ছবি যোগ করুন।</span>
    </div>

    <div class="form-row" id="post_custom_code_group" style="display:none;">
      <label class="form-label" for="post_code_document">সম্পূর্ণ HTML ডকুমেন্ট (HTML + CSS + JavaScript একসঙ্গে)</label>
      <textarea class="form-control code-document-editor" id="post_code_document" name="code_document" rows="24" spellcheck="false"><?= e($editPost['code_document'] ?? $legacyCodeDocument) ?></textarea>
      <span class="form-hint">একটি সম্পূর্ণ HTML ডকুমেন্ট পেস্ট করুন। CSS-এর জন্য &lt;style&gt; এবং JavaScript-এর জন্য &lt;script&gt; ব্যবহার করুন। এটি আলাদা সীমাবদ্ধ iframe-এ চলবে; fetch/XHR/WebSocket, ফর্ম, পপআপ ও মূল পেজে প্রবেশ বন্ধ থাকবে; HTTPS ছবি লোড হতে পারে।</span>
      <div class="form-row" style="margin-top:12px;">
        <label class="form-label" for="custom_excerpt">Short Excerpt (Optional)</label>
        <input class="form-control" id="custom_excerpt" name="custom_excerpt" maxlength="500" value="<?= e((($editPost['post_type'] ?? '') === 'custom') ? ($editPost['description'] ?? '') : '') ?>" placeholder="পোস্টের তালিকায় দেখানো সংক্ষিপ্ত বিবরণ">
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button type="submit" class="btn btn-green"><?= $editPost ? 'Save Changes' : 'Publish Post' ?></button>
      <a href="<?= e(admin_url('posts.php')) ?>" class="btn btn-outline">Cancel</a>
    </div>
  </form>
</section>

<?php else: ?>
<section class="adm-panel">
  <h2 class="adm-panel-title">সব পোস্ট</h2>
  <?php if (empty($posts)): ?><p style="color:var(--adm-muted);margin:0;">এখনো কোনো পোস্ট তৈরি করা হয়নি। প্রথম পোস্ট প্রকাশ করতে <strong>পোস্ট যোগ করুন</strong> বাটনে ক্লিক করুন।</p>
  <?php else: ?>
    <div class="table-wrap"><table class="adm-table">
      <thead><tr><th>পূর্বরূপ</th><th>শিরোনাম / সংক্ষিপ্ত বিবরণ</th><th>ধরন</th><th>বিভাগ</th><th>অবস্থা</th><th>তারিখ</th><th>করণীয়</th></tr></thead>
      <tbody>
      <?php foreach ($posts as $p): ?>
        <tr>
          <td><?php if ($p['post_type'] === 'image' && !empty($p['media_url'])): ?><img src="<?= e(safe_url($p['media_url'])) ?>" alt="" class="adm-thumb">
              <?php elseif ($p['post_type'] === 'video'): ?><span>ভিডিও</span><?php elseif ($p['post_type'] === 'custom'): ?><span>কাস্টম কোড</span><?php else: ?><span>লেখা</span><?php endif; ?></td>
          <td><strong><?= e($p['title'] ?: '(শিরোনামহীন)') ?></strong><div class="post-admin-excerpt"><?= e($p['description']) ?></div></td>
          <td><?= e($p['post_type'] === 'video' ? 'ভিডিও' : ($p['post_type'] === 'custom' ? 'কাস্টম কোড' : ($p['post_type'] === 'image' ? 'ছবি' : 'লেখা'))) ?></td><td><?= e($p['category_name'] ?? '—') ?></td><td><?= e($p['status'] === 'published' ? 'প্রকাশিত' : 'খসড়া') ?></td>
          <td class="num-tab"><?= e(format_date($p['published_at'] ?? $p['created_at'])) ?></td>
          <td><div style="display:flex;gap:6px;flex-wrap:wrap;">
            <a href="<?= e(admin_url('posts.php')) ?>?edit=<?= (int) $p['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
            <form method="post" action="<?= e(admin_url('posts.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="post_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="new_status" value="<?= $p['status'] === 'published' ? 'draft' : 'published' ?>"><button type="submit" class="btn btn-outline btn-sm"><?= $p['status'] === 'published' ? 'Unpublish' : 'Publish' ?></button></form>
            <form method="post" action="<?= e(admin_url('posts.php')) ?>" data-confirm="মুছুন this post permanently? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="post_id" value="<?= (int) $p['id'] ?>"><button type="submit" class="btn btn-danger btn-sm">মুছুন</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
