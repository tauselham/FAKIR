<?php
declare(strict_types=1);

$activePage = 'posts';
$adminPageTitle = 'Community Posts';
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
                throw new RuntimeException('Post title must be 220 characters or fewer.');
            }
            if (strlen($richHtml) > 1048576 || strlen($customCode) > 2097152) {
                throw new RuntimeException('Content size limit exceeded. Rich content max 1 MB; custom HTML document max 2 MB.');
            }
            if ($postType === 'custom' && trim($customCode) === '') {
                throw new RuntimeException('Please add your complete HTML, CSS and JavaScript code.');
            }
            if ($postType === 'text' && trim($richHtml) === '') {
                throw new RuntimeException('Please write some post content or insert an image.');
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
                if (!$existing) throw new RuntimeException('Post not found.');
            }

            $mediaBucket = $existing['media_bucket'] ?? null;
            $mediaPath = $existing['media_path'] ?? null;
            $mediaUrl = $existing['media_url'] ?? null;
            $mediaMime = $existing['media_mime'] ?? null;
            $oldBucketToDelete = null;
            $oldPathToDelete = null;
            $newlyUploaded = null;

            if (in_array($postType, ['text', 'custom'], true)) {
                if ($existing && !empty($existing['media_bucket']) && !empty($existing['media_path'])) {
                    $oldBucketToDelete = (string) $existing['media_bucket'];
                    $oldPathToDelete = (string) $existing['media_path'];
                }
                $mediaBucket = $mediaPath = $mediaUrl = $mediaMime = null;
            } elseif (!empty($_FILES['media_file']['name'])) {
                $allowed = $postType === 'video' ? ALLOWED_VIDEO_MIMES : ALLOWED_IMAGE_MIMES;
                $maxSize = $postType === 'video' ? MAX_VIDEO_UPLOAD_BYTES : MAX_IMAGE_UPLOAD_BYTES;
                $newlyUploaded = $storage->upload($_FILES['media_file'], BUCKET_POST_MEDIA, $allowed, $maxSize, 'posts');
                if ($existing && !empty($existing['media_bucket']) && !empty($existing['media_path'])) {
                    $oldBucketToDelete = (string) $existing['media_bucket'];
                    $oldPathToDelete = (string) $existing['media_path'];
                }
                $mediaBucket = $newlyUploaded['bucket'];
                $mediaPath = $newlyUploaded['path'];
                $mediaUrl = $newlyUploaded['url'];
                $mediaMime = $newlyUploaded['mime'];
            } elseif ($action === 'create' || !$existing || ($existing['post_type'] ?? '') !== $postType || empty($existing['media_path'])) {
                throw new RuntimeException('Please upload an image or video file for this post type.');
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
                    set_flash('success', 'Community post created successfully.');
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
                    set_flash('success', 'Community post updated.');
                }
            } catch (Throwable $dbErr) {
                if ($newlyUploaded !== null) $storage->delete($newlyUploaded['bucket'], $newlyUploaded['path']);
                throw $dbErr;
            }

            if ($oldBucketToDelete !== null && $oldPathToDelete !== null) $storage->delete($oldBucketToDelete, $oldPathToDelete);
            redirect_to(admin_url('posts.php'));
        } elseif ($action === 'toggle_status') {
            $postId = (int) ($_POST['post_id'] ?? 0);
            $newStatus = ($_POST['new_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            $upd = $pdo->prepare('UPDATE public.posts SET status = :status, published_at = CASE WHEN :status_check = \'published\' THEN COALESCE(published_at, NOW()) ELSE NULL END, updated_at = NOW() WHERE id = :id');
            $upd->execute([':status' => $newStatus, ':status_check' => $newStatus, ':id' => $postId]);
            log_admin_activity((int) $currentAdmin['id'], 'post_status_' . $newStatus, 'post', $postId);
            set_flash('success', 'Post visibility updated.');
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
            set_flash('success', 'Post deleted.');
            redirect_to(admin_url('posts.php'));
        }
    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            error_log('Community post operation failed: ' . $e->getMessage());
            set_flash('error', 'The post could not be saved because of a database error. Check the server log.');
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
      <h2 class="adm-panel-title" style="margin-bottom:5px;">Community Posts</h2>
      <p class="form-hint" style="margin:0;">Manage all existing community posts, or create a new post.</p>
    </div>
    <a class="btn btn-green" href="<?= e(admin_url('posts.php')) ?>?add=1">＋ Add Post</a>
  </div>
</section>

<?php if ($showPostForm): ?>
<section class="adm-panel">
  <div class="adm-section-heading">
    <h2 class="adm-panel-title" style="margin-bottom:0;"><?= $editPost ? 'Edit Community Post #' . (int) $editPost['id'] : 'Add Community Post' ?></h2>
    <a href="<?= e(admin_url('posts.php')) ?>" class="btn btn-outline">← Back to Community Posts</a>
  </div>
  <form id="community-post-form" method="post" action="<?= e(admin_url('posts.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editPost ? 'update' : 'create' ?>">
    <?php if ($editPost): ?><input type="hidden" name="post_id" value="<?= (int) $editPost['id'] ?>"><?php endif; ?>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="post_type_select">Post Format</label>
        <select class="form-control" name="post_type" id="post_type_select">
          <?php $curType = $editPost['post_type'] ?? 'text'; ?>
          <option value="text" <?= $curType === 'text' ? 'selected' : '' ?>>Rich Text Post (WordPress-style)</option>
          <option value="image" <?= $curType === 'image' ? 'selected' : '' ?>>Image Post + Rich Text</option>
          <option value="video" <?= $curType === 'video' ? 'selected' : '' ?>>Video Post + Rich Text</option>
          <option value="custom" <?= $curType === 'custom' ? 'selected' : '' ?>>Full HTML + CSS + JavaScript Document</option>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label" for="category_id">Category</label>
        <select class="form-control" name="category_id" id="category_id">
          <option value="">Uncategorized</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int) $cat['id'] ?>" <?= ((int) ($editPost['category_id'] ?? 0) === (int) $cat['id']) ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="title">Post Title</label>
        <input class="form-control" type="text" id="title" name="title" maxlength="220" value="<?= e($editPost['title'] ?? '') ?>" placeholder="Write a title">
      </div>
      <div class="form-row">
        <label class="form-label" for="status">Publication Status</label>
        <select class="form-control" name="status" id="status">
          <option value="published" <?= (($editPost['status'] ?? 'published') === 'published') ? 'selected' : '' ?>>Published</option>
          <option value="draft" <?= (($editPost['status'] ?? '') === 'draft') ? 'selected' : '' ?>>Draft</option>
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
      <label class="form-label">Post Content — Visual Editor</label>
      <div class="rich-editor" data-rich-editor>
        <div class="rich-toolbar" role="toolbar" aria-label="Post formatting tools">
          <button type="button" class="rich-tool" data-editor-command="bold"><strong>B</strong></button>
          <button type="button" class="rich-tool" data-editor-command="italic"><em>I</em></button>
          <button type="button" class="rich-tool" data-editor-command="underline"><u>U</u></button>
          <button type="button" class="rich-tool" data-editor-command="strikeThrough"><s>S</s></button>
          <select class="rich-tool-select" data-editor-block aria-label="Text style"><option value="P">Paragraph</option><option value="H2">Heading 2</option><option value="H3">Heading 3</option><option value="H4">Heading 4</option><option value="BLOCKQUOTE">Quote</option></select>
          <select class="rich-tool-select" data-editor-size aria-label="Font size"><option value="14">14 px</option><option value="16" selected>16 px</option><option value="18">18 px</option><option value="22">22 px</option><option value="28">28 px</option><option value="36">36 px</option></select>
          <label class="rich-color-tool" title="Text color">A <input type="color" data-editor-color value="#202820" aria-label="Text color"></label>
          <button type="button" class="rich-tool" data-editor-command="justifyLeft" title="Align left">⇤</button>
          <button type="button" class="rich-tool" data-editor-command="justifyCenter" title="Center">↔</button>
          <button type="button" class="rich-tool" data-editor-command="justifyRight" title="Align right">⇥</button>
          <button type="button" class="rich-tool" data-editor-command="insertUnorderedList" title="Bulleted list">• List</button>
          <button type="button" class="rich-tool" data-editor-command="insertOrderedList" title="Numbered list">1. List</button>
          <button type="button" class="rich-tool" data-editor-link>🔗 Link</button>
          <button type="button" class="rich-tool" data-editor-image>▧ Image</button>
          <button type="button" class="rich-tool" data-editor-command="removeFormat" title="Clear formatting">Clear</button>
          <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-editor-image-file hidden>
        </div>
        <div id="post_rich_editor" class="rich-editor-surface" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Post content" dir="auto"><?= sanitize_rich_html($initialRichHtml) ?></div>
      </div>
      <textarea id="post_content_html" name="content_html" hidden></textarea>
      <span class="form-hint">বাংলা ও English—দুই ভাষাতেই লিখতে পারবে। লেখা সিলেক্ট করে toolbar থেকে formatting, link বা image যোগ করো।</span>
    </div>

    <div class="form-row" id="post_custom_code_group" style="display:none;">
      <label class="form-label" for="post_code_document">Complete HTML Document (HTML + CSS + JavaScript together)</label>
      <textarea class="form-control code-document-editor" id="post_code_document" name="code_document" rows="24" spellcheck="false"><?= e($editPost['code_document'] ?? $legacyCodeDocument) ?></textarea>
      <span class="form-hint">একটি সম্পূর্ণ HTML document পেস্ট করো। CSS-এর জন্য &lt;style&gt; এবং JavaScript-এর জন্য &lt;script&gt; ব্যবহার করো। এটি আলাদা sandboxed iframe-এ চলবে; fetch/XHR/WebSocket, forms, popups ও parent page access বন্ধ; HTTPS images load হতে পারে।</span>
      <div class="form-row" style="margin-top:12px;">
        <label class="form-label" for="custom_excerpt">Short Excerpt (Optional)</label>
        <input class="form-control" id="custom_excerpt" name="custom_excerpt" maxlength="500" value="<?= e((($editPost['post_type'] ?? '') === 'custom') ? ($editPost['description'] ?? '') : '') ?>" placeholder="Short description shown on the posts list">
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
  <h2 class="adm-panel-title">All Community Posts</h2>
  <?php if (empty($posts)): ?><p style="color:var(--adm-muted);margin:0;">No posts created yet. Select <strong>Add Post</strong> to publish the first update.</p>
  <?php else: ?>
    <div class="table-wrap"><table class="adm-table">
      <thead><tr><th>Preview</th><th>Title / Excerpt</th><th>Type</th><th>Category</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($posts as $p): ?>
        <tr>
          <td><?php if ($p['post_type'] === 'image' && !empty($p['media_url'])): ?><img src="<?= e(safe_url($p['media_url'])) ?>" alt="" class="adm-thumb">
              <?php elseif ($p['post_type'] === 'video'): ?><span>Video</span><?php elseif ($p['post_type'] === 'custom'): ?><span>Custom Code</span><?php else: ?><span>Text</span><?php endif; ?></td>
          <td><strong><?= e($p['title'] ?: '(Untitled)') ?></strong><div class="post-admin-excerpt"><?= e($p['description']) ?></div></td>
          <td><?= e(ucfirst($p['post_type'])) ?></td><td><?= e($p['category_name'] ?? '—') ?></td><td><?= e(ucfirst($p['status'])) ?></td>
          <td class="num-tab"><?= e(format_date($p['published_at'] ?? $p['created_at'])) ?></td>
          <td><div style="display:flex;gap:6px;flex-wrap:wrap;">
            <a href="<?= e(admin_url('posts.php')) ?>?edit=<?= (int) $p['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
            <form method="post" action="<?= e(admin_url('posts.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="post_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="new_status" value="<?= $p['status'] === 'published' ? 'draft' : 'published' ?>"><button type="submit" class="btn btn-outline btn-sm"><?= $p['status'] === 'published' ? 'Unpublish' : 'Publish' ?></button></form>
            <form method="post" action="<?= e(admin_url('posts.php')) ?>" data-confirm="Delete this post permanently? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="post_id" value="<?= (int) $p['id'] ?>"><button type="submit" class="btn btn-danger btn-sm">Delete</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
