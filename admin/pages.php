<?php
declare(strict_types=1);

$activePage = 'pages';
$adminPageTitle = 'Landing Pages & About Us';
$requiredPermission = 'manage_pages';
require_once dirname(__DIR__) . '/includes/admin-layout.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save') {
            $pageId = (int) ($_POST['page_id'] ?? 0);
            $pageType = ($_POST['page_type'] ?? 'landing') === 'about' ? 'about' : 'landing';
            $title = trim((string) ($_POST['title'] ?? ''));
            $slug = validate_content_slug((string) ($_POST['slug'] ?? ''));
            $editorMode = ($_POST['editor_mode'] ?? 'separate') === 'single' ? 'single' : 'separate';
            $html = (string) ($_POST['html_content'] ?? '');
            $css = (string) ($_POST['css_content'] ?? '');
            $js = (string) ($_POST['js_content'] ?? '');
            $codeDocument = (string) ($_POST['code_document'] ?? '');
            $meta = trim((string) ($_POST['meta_description'] ?? ''));
            $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

            if ($title === '' || mb_strlen($title) > 220) {
                throw new RuntimeException('Page title is required and must be 220 characters or fewer.');
            }
            if ($editorMode === 'single') {
                if (trim($codeDocument) === '') throw new RuntimeException('Complete HTML document code is required.');
                if (strlen($codeDocument) > 2097152) throw new RuntimeException('Complete HTML document must be 2 MB or smaller.');
                $html = $css = $js = '';
            } else {
                if (strlen($html) > 1048576 || strlen($css) > 524288 || strlen($js) > 524288) {
                    throw new RuntimeException('Code size limit exceeded. HTML max 1 MB; CSS and JavaScript max 512 KB each.');
                }
                if (trim($html) === '') throw new RuntimeException('HTML content is required.');
                $codeDocument = '';
            }
            if (mb_strlen($meta) > 300) {
                throw new RuntimeException('SEO description must be 300 characters or fewer.');
            }

            if ($pageId > 0) {
                $exists = $pdo->prepare('SELECT id, page_type FROM public.content_pages WHERE id = :id LIMIT 1');
                $exists->execute([':id' => $pageId]);
                $existing = $exists->fetch();
                if (!$existing) {
                    throw new RuntimeException('Page not found.');
                }
                if ($existing['page_type'] === 'about') {
                    $pageType = 'about';
                }

                $stmt = $pdo->prepare(
                    "UPDATE public.content_pages
                     SET page_type = :type, title = :title, slug = :slug,
                         html_content = :html, css_content = :css, js_content = :js, code_document = :code_document,
                         meta_description = :meta, status = :status,
                         published_at = CASE WHEN :status_check = 'published' THEN COALESCE(published_at, NOW()) ELSE NULL END,
                         updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':type' => $pageType, ':title' => $title, ':slug' => $slug,
                    ':html' => $html, ':css' => $css, ':js' => $js, ':code_document' => $codeDocument !== '' ? $codeDocument : null,
                    ':meta' => $meta !== '' ? $meta : null, ':status' => $status,
                    ':status_check' => $status, ':id' => $pageId,
                ]);
                log_admin_activity((int) $currentAdmin['id'], 'content_page_updated', 'content_page', $pageId, ['slug' => $slug, 'status' => $status]);
                set_flash('success', 'Page updated successfully.');
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO public.content_pages
                     (page_type, title, slug, html_content, css_content, js_content, code_document, meta_description, status, admin_id, published_at)
                     VALUES (:type, :title, :slug, :html, :css, :js, :code_document, :meta, :status, :admin_id,
                             CASE WHEN :status_check = 'published' THEN NOW() ELSE NULL END)
                     RETURNING id"
                );
                $stmt->execute([
                    ':type' => $pageType, ':title' => $title, ':slug' => $slug,
                    ':html' => $html, ':css' => $css, ':js' => $js, ':code_document' => $codeDocument !== '' ? $codeDocument : null,
                    ':meta' => $meta !== '' ? $meta : null, ':status' => $status,
                    ':admin_id' => (int) $currentAdmin['id'], ':status_check' => $status,
                ]);
                $newId = (int) $stmt->fetchColumn();
                log_admin_activity((int) $currentAdmin['id'], 'content_page_created', 'content_page', $newId, ['slug' => $slug, 'type' => $pageType]);
                set_flash('success', 'Page created successfully.');
            }
            redirect_to(admin_url('pages.php'));
        } elseif ($action === 'delete') {
            $pageId = (int) ($_POST['page_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM public.content_pages WHERE id = :id RETURNING id, slug');
            $stmt->execute([':id' => $pageId]);
            $deleted = $stmt->fetch();
            if ($deleted) {
                log_admin_activity((int) $currentAdmin['id'], 'content_page_deleted', 'content_page', (int) $deleted['id'], ['slug' => $deleted['slug']]);
            }
            set_flash('success', 'Page deleted.');
            redirect_to(admin_url('pages.php'));
        }
    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            $message = str_contains(strtolower($e->getMessage()), 'unique')
                ? 'That URL Slug is already in use, or an About Us page already exists.'
                : 'The page could not be saved because of a database error. Check the server log for details.';
            error_log('Content page save failed: ' . $e->getMessage());
        } else {
            $message = $e->getMessage();
        }
        set_flash('error', $message);
        redirect_to(admin_url('pages.php'));
    }
}

$pages = $pdo->query(
    "SELECT id, page_type, title, slug, status, updated_at
     FROM public.content_pages
     ORDER BY CASE WHEN page_type = 'about' THEN 0 ELSE 1 END, updated_at DESC"
)->fetchAll();

$editPage = null;
if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM public.content_pages WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $editPage = $stmt->fetch() ?: null;
}
$hasAbout = false;
foreach ($pages as $pageRow) {
    if ($pageRow['page_type'] === 'about') {
        $hasAbout = true;
        break;
    }
}
?>
<section class="adm-panel">
  <h2 class="adm-panel-title"><?= $editPage ? 'Edit Page' : 'Create a Page' ?></h2>
  <p style="color:var(--adm-muted);margin-top:0;">Create a landing page or manage the About Us page using your own HTML, CSS and JavaScript. Custom code runs inside a restricted sandbox and cannot access the Admin Panel session.</p>
  <form method="post" action="<?= e(admin_url('pages.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($editPage): ?><input type="hidden" name="page_id" value="<?= (int) $editPage['id'] ?>"><?php endif; ?>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="page_type">Page Type</label>
        <select class="form-control" name="page_type" id="page_type" <?= $editPage && $editPage['page_type'] === 'about' ? 'disabled' : '' ?>>
          <option value="landing" <?= (($editPage['page_type'] ?? 'landing') === 'landing') ? 'selected' : '' ?>>Landing Page</option>
          <option value="about" <?= (($editPage['page_type'] ?? '') === 'about') ? 'selected' : '' ?> <?= (!$editPage && $hasAbout) ? 'disabled' : '' ?>>About Us <?= $hasAbout && (!$editPage || $editPage['page_type'] !== 'about') ? '(already exists)' : '' ?></option>
        </select>
        <?php if ($editPage && $editPage['page_type'] === 'about'): ?><input type="hidden" name="page_type" value="about"><?php endif; ?>
      </div>
      <div class="form-row">
        <label class="form-label" for="status">Status</label>
        <select class="form-control" name="status" id="status">
          <option value="draft" <?= (($editPage['status'] ?? 'draft') === 'draft') ? 'selected' : '' ?>>Draft</option>
          <option value="published" <?= (($editPage['status'] ?? '') === 'published') ? 'selected' : '' ?>>Published</option>
        </select>
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="title">Page Title</label>
        <input class="form-control" type="text" id="title" name="title" maxlength="220" required value="<?= e($editPage['title'] ?? '') ?>" placeholder="Example: Summer Festival">
      </div>
      <div class="form-row">
        <label class="form-label" for="slug">Public URL Slug</label>
        <input class="form-control" type="text" id="slug" name="slug" maxlength="120" required pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= e($editPage['slug'] ?? '') ?>" placeholder="summer-festival">
        <span class="form-hint">Public URL: <?= e(APP_URL) ?>/your-slug — lowercase letters, numbers and hyphens only.</span>
      </div>
    </div>

    <div class="form-row">
      <label class="form-label" for="meta_description">SEO Meta Description (Optional)</label>
      <input class="form-control" type="text" id="meta_description" name="meta_description" maxlength="300" value="<?= e($editPage['meta_description'] ?? '') ?>">
    </div>

    <div class="form-row">
      <label class="form-label" for="editor_mode">Code Editor Mode</label>
      <select class="form-control" name="editor_mode" id="page_editor_mode">
        <option value="separate" <?= empty($editPage['code_document']) ? 'selected' : '' ?>>Separate HTML, CSS and JavaScript editors</option>
        <option value="single" <?= !empty($editPage['code_document']) ? 'selected' : '' ?>>One complete HTML document (HTML + CSS + JavaScript together)</option>
      </select>
      <span class="form-hint">একটি HTML ফাইলে HTML, &lt;style&gt; এবং &lt;script&gt; একসঙ্গে লিখতে চাইলে দ্বিতীয় অপশনটি বেছে নাও।</span>
    </div>

    <div id="page_separate_code_fields" style="<?= !empty($editPage['code_document']) ? 'display:none;' : '' ?>">
    <div class="form-row">
      <label class="form-label" for="html_content">HTML Body Content (fragment, not a full document)</label>
      <textarea class="form-control" id="html_content" name="html_content" rows="14" spellcheck="false" required><?= e($editPage['html_content'] ?? '') ?></textarea>
    </div>
    <div class="form-row">
      <label class="form-label" for="css_content">CSS</label>
      <textarea class="form-control" id="css_content" name="css_content" rows="10" spellcheck="false"><?= e($editPage['css_content'] ?? '') ?></textarea>
    </div>
    <div class="form-row">
      <label class="form-label" for="js_content">JavaScript</label>
      <textarea class="form-control" id="js_content" name="js_content" rows="10" spellcheck="false"><?= e($editPage['js_content'] ?? '') ?></textarea>
      <span class="form-hint">Write JavaScript code only (without surrounding script tags). Fetch/XHR/WebSocket, forms, popups and parent-page access are blocked; HTTPS images may load.</span>
    </div>
    </div>
    <div class="form-row" id="page_single_code_field" style="<?= empty($editPage['code_document']) ? 'display:none;' : '' ?>">
      <label class="form-label" for="code_document">Complete HTML Document</label>
      <textarea class="form-control code-document-editor" id="code_document" name="code_document" rows="24" spellcheck="false"><?= e($editPage['code_document'] ?? '') ?></textarea>
      <span class="form-hint">একই document-এর মধ্যে &lt;style&gt; ও &lt;script&gt; লিখবে। এটি sandboxed iframe-এ চলবে; same-origin access ও fetch/XHR/WebSocket বন্ধ; HTTPS images load হতে পারে।</span>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button type="submit" class="btn btn-green"><?= $editPage ? 'Save Changes' : 'Create Page' ?></button>
      <?php if ($editPage): ?><a href="<?= e(admin_url('pages.php')) ?>" class="btn btn-outline">Cancel</a><?php endif; ?>
    </div>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">Your Pages</h2>
  <?php if (!$pages): ?>
    <p style="color:var(--adm-muted);">No pages have been created yet.</p>
  <?php else: ?>
    <div class="table-wrap"><table class="adm-table">
      <thead><tr><th>Title</th><th>Type</th><th>URL</th><th>Status</th><th>Updated</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($pages as $p): ?>
        <tr>
          <td><strong><?= e($p['title']) ?></strong></td>
          <td><?= $p['page_type'] === 'about' ? 'About Us' : 'Landing Page' ?></td>
          <td><code>/<?= e($p['slug']) ?></code></td>
          <td><?= e(ucfirst($p['status'])) ?></td>
          <td><?= e(format_date($p['updated_at'])) ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              <a class="btn btn-outline btn-sm" href="<?= e(admin_url('pages.php')) ?>?edit=<?= (int) $p['id'] ?>">Edit</a>
              <?php if ($p['status'] === 'published'): ?><a class="btn btn-outline btn-sm" href="/<?= e($p['slug']) ?>" target="_blank" rel="noopener noreferrer">View</a><?php endif; ?>
              <form method="post" action="<?= e(admin_url('pages.php')) ?>" data-confirm="Delete this page permanently?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="page_id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
