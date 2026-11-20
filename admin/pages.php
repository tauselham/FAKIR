<?php
declare(strict_types=1);

$activePage = 'pages';
$adminPageTitle = 'পেজ ব্যবস্থাপনা & আমাদের পরিচিতি';
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
            $codeDocument = (string) ($_POST['code_document'] ?? '');
            $html = '';
            $css = '';
            $js = '';
            $meta = trim((string) ($_POST['meta_description'] ?? ''));
            $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

            if ($title === '' || mb_strlen($title) > 220) {
                throw new RuntimeException('পেজের শিরোনাম দিতে হবে এবং তা ২২০ অক্ষরের মধ্যে হতে হবে।');
            }
            if (trim($codeDocument) === '') throw new RuntimeException('সম্পূর্ণ HTML ডকুমেন্টের কোড দিতে হবে।');
            if (strlen($codeDocument) > 2097152) throw new RuntimeException('সম্পূর্ণ HTML ডকুমেন্ট ২ মেগাবাইটের মধ্যে হতে হবে।');
            if (mb_strlen($meta) > 300) {
                throw new RuntimeException('সার্চ বিবরণ ৩০০ অক্ষরের বেশি হতে পারবে না।');
            }

            if ($pageId > 0) {
                $exists = $pdo->prepare('SELECT id, page_type FROM public.content_pages WHERE id = :id LIMIT 1');
                $exists->execute([':id' => $pageId]);
                $existing = $exists->fetch();
                if (!$existing) {
                    throw new RuntimeException('পেজটি পাওয়া যায়নি।');
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
                set_flash('success', 'পেজ হালনাগাদ হয়েছে।');
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
                set_flash('success', 'পেজ তৈরি হয়েছে।');
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
            set_flash('success', 'পেজ মুছে ফেলা হয়েছে।');
            redirect_to(admin_url('pages.php'));
        }
    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            $message = str_contains(strtolower($e->getMessage()), 'unique')
                ? 'That লিংক Slug is already in use, or an আমাদের পরিচিতি page already exists.'
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
$editCodeDocument = '';
if ($editPage) {
    $editCodeDocument = (string) ($editPage['code_document'] ?? '');
    if ($editCodeDocument === '') {
        $legacyHtml = (string) ($editPage['html_content'] ?? '');
        $legacyCss = (string) ($editPage['css_content'] ?? '');
        $legacyJs = (string) ($editPage['js_content'] ?? '');
        if (preg_match('~<html\\b~i', $legacyHtml)) {
            $editCodeDocument = $legacyHtml;
            if ($legacyCss !== '') {
                $styleTag = "<style>\n" . $legacyCss . "\n</style>\n";
                if (preg_match('~</head>~i', $editCodeDocument)) {
                    $editCodeDocument = preg_replace('~</head>~i', $styleTag . '</head>', $editCodeDocument, 1) ?? $editCodeDocument;
                } else {
                    $editCodeDocument = preg_replace('~<body\\b~i', $styleTag . '<body', $editCodeDocument, 1) ?? $editCodeDocument;
                }
            }
            if ($legacyJs !== '') {
                $scriptTag = "\n<script>\n" . $legacyJs . "\n</script>\n";
                if (preg_match('~</body>~i', $editCodeDocument)) {
                    $editCodeDocument = preg_replace('~</body>~i', $scriptTag . '</body>', $editCodeDocument, 1) ?? $editCodeDocument;
                } else {
                    $editCodeDocument .= $scriptTag;
                }
            }
        } else {
            $editCodeDocument = "<!doctype html>\n<html lang=\\"bn\\">\n<head>\n<meta charset=\\"utf-8\\">\n<meta name=\\"viewport\\" content=\\"width=device-width, initial-scale=1\\">\n<style>\n" . $legacyCss . "\n</style>\n</head>\n<body>\n" . $legacyHtml . "\n<script>\n" . $legacyJs . "\n</script>\n</body>\n</html>";
        }
    }
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
  <h2 class="adm-panel-title"><?= $editPage ? 'পেজ সম্পাদনা' : 'নতুন পেজ তৈরি করুন' ?></h2>
  <p style="color:var(--adm-muted);margin-top:0;">নিজস্ব HTML, CSS ও JavaScript ব্যবহার করে নতুন পেজ তৈরি করুন অথবা আমাদের পরিচিতি পেজ পরিচালনা করুন। নিরাপত্তার জন্য কাস্টম কোড আলাদা সীমাবদ্ধ পরিবেশে চলে এবং অ্যাডমিন সেশনে প্রবেশ করতে পারে না।</p>
  <form method="post" action="<?= e(admin_url('pages.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($editPage): ?><input type="hidden" name="page_id" value="<?= (int) $editPage['id'] ?>"><?php endif; ?>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="page_type">পেজের ধরন</label>
        <select class="form-control" name="page_type" id="page_type" <?= $editPage && $editPage['page_type'] === 'about' ? 'disabled' : '' ?>>
          <option value="landing" <?= (($editPage['page_type'] ?? 'landing') === 'landing') ? 'selected' : '' ?>>নতুন পেজ</option>
          <option value="about" <?= (($editPage['page_type'] ?? '') === 'about') ? 'selected' : '' ?> <?= (!$editPage && $hasAbout) ? 'disabled' : '' ?>>আমাদের পরিচিতি <?= $hasAbout && (!$editPage || $editPage['page_type'] !== 'about') ? '(আগেই তৈরি আছে)' : '' ?></option>
        </select>
        <?php if ($editPage && $editPage['page_type'] === 'about'): ?><input type="hidden" name="page_type" value="about"><?php endif; ?>
      </div>
      <div class="form-row">
        <label class="form-label" for="status">অবস্থা</label>
        <select class="form-control" name="status" id="status">
          <option value="draft" <?= (($editPage['status'] ?? 'draft') === 'draft') ? 'selected' : '' ?>>খসড়া</option>
          <option value="published" <?= (($editPage['status'] ?? '') === 'published') ? 'selected' : '' ?>>প্রকাশিত</option>
        </select>
      </div>
    </div>

    <div class="adm-grid-2">
      <div class="form-row">
        <label class="form-label" for="title">পেজের শিরোনাম</label>
        <input class="form-control" type="text" id="title" name="title" maxlength="220" required value="<?= e($editPage['title'] ?? '') ?>" placeholder="যেমন: গ্রীষ্মকালীন উৎসব">
      </div>
      <div class="form-row">
        <label class="form-label" for="slug">পাবলিক লিংকের শেষ অংশ</label>
        <input class="form-control" type="text" id="slug" name="slug" maxlength="120" required pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= e($editPage['slug'] ?? '') ?>" placeholder="summer-festival">
        <span class="form-hint">পাবলিক লিংক: <?= e(APP_URL) ?>/আপনার-পেজ — ইংরেজি ছোট অক্ষর, সংখ্যা ও হাইফেন ব্যবহার করুন।</span>
      </div>
    </div>

    <div class="form-row">
      <label class="form-label" for="meta_description">সার্চ ফলাফলের বিবরণ (ঐচ্ছিক)</label>
      <input class="form-control" type="text" id="meta_description" name="meta_description" maxlength="300" value="<?= e($editPage['meta_description'] ?? '') ?>">
    </div>

    <div class="form-row">
      <label class="form-label" for="code_document">সম্পূর্ণ HTML কোড</label>
      <textarea class="form-control code-document-editor" id="code_document" name="code_document" rows="28" spellcheck="false" required><?= e($editCodeDocument) ?></textarea>
      <span class="form-hint">HTML, CSS এবং JavaScript একই কোড ঘরে লিখুন। CSS-এর জন্য &lt;style&gt; এবং JavaScript-এর জন্য &lt;script&gt; ট্যাগ ব্যবহার করুন।</span>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button type="submit" class="btn btn-green"><?= $editPage ? 'পরিবর্তন সংরক্ষণ করুন' : 'পেজ তৈরি করুন' ?></button>
      <?php if ($editPage): ?><a href="<?= e(admin_url('pages.php')) ?>" class="btn btn-outline">বাতিল</a><?php endif; ?>
    </div>
  </form>
</section>

<section class="adm-panel">
  <h2 class="adm-panel-title">আপনার তৈরি পেজ</h2>
  <?php if (!$pages): ?>
    <p style="color:var(--adm-muted);">No pages have been created yet.</p>
  <?php else: ?>
    <div class="table-wrap"><table class="adm-table">
      <thead><tr><th>শিরোনাম</th><th>ধরন</th><th>লিংক</th><th>অবস্থা</th><th>হালনাগাদ</th><th>করণীয়</th></tr></thead>
      <tbody>
      <?php foreach ($pages as $p): ?>
        <tr>
          <td><strong><?= e($p['title']) ?></strong></td>
          <td><?= $p['page_type'] === 'about' ? 'আমাদের পরিচিতি' : 'নতুন পেজ' ?></td>
          <td><code>/<?= e($p['slug']) ?></code></td>
          <td><?= e($p['status'] === 'published' ? 'প্রকাশিত' : 'খসড়া') ?></td>
          <td><?= e(format_date($p['updated_at'])) ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              <a class="btn btn-outline btn-sm" href="<?= e(admin_url('pages.php')) ?>?edit=<?= (int) $p['id'] ?>">সম্পাদনা</a>
              <?php if ($p['status'] === 'published'): ?><a class="btn btn-outline btn-sm" href="/<?= e($p['slug']) ?>" target="_blank" rel="noopener noreferrer">View</a><?php endif; ?>
              <form method="post" action="<?= e(admin_url('pages.php')) ?>" data-confirm="এই পেজটি স্থায়ীভাবে মুছে ফেলবেন?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="page_id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">মুছুন</button>
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
