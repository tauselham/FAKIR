<?php
declare(strict_types=1);

$activePage = 'categories';
$adminPageTitle = 'বিভাগসমূহ';
$requiredPermission = 'manage_categories';
require_once dirname(__DIR__) . '/includes/admin-layout.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $scope  = ($_POST['scope'] ?? 'gallery') === 'post' ? 'post' : 'gallery';
    $table  = $scope === 'post' ? 'post_categories' : 'gallery_categories';

    try {
        if ($action === 'create') {
            $name      = trim((string) ($_POST['name'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            if ($name === '') {
                throw new RuntimeException('বিভাগের নাম লিখতে হবে।');
            }
            $slug = slugify($name);
            $stmt = $pdo->prepare("INSERT INTO public.{$table} (name, slug, sort_order, is_active) VALUES (:name, :slug, :ord, TRUE) RETURNING id");
            $stmt->execute([':name' => $name, ':slug' => $slug, ':ord' => $sortOrder]);
            log_admin_activity((int) $currentAdmin['id'], $scope . '_category_created', $table, (int) $stmt->fetchColumn());
            set_flash('success', 'বিভাগ যোগ করা হয়েছে।');
        } elseif ($action === 'update') {
            $id        = (int) ($_POST['id'] ?? 0);
            $name      = trim((string) ($_POST['name'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isচালু  = !empty($_POST['is_active']);
            if ($name === '') {
                throw new RuntimeException('বিভাগের নাম ফাঁকা রাখা যাবে না।');
            }
            $stmt = $pdo->prepare("UPDATE public.{$table} SET name = :name, slug = :slug, sort_order = :ord, is_active = :act WHERE id = :id");
            $stmt->execute([
                ':name' => $name,
                ':slug' => slugify($name),
                ':ord'  => $sortOrder,
                ':act'  => $isচালু ? 'true' : 'false',
                ':id'   => $id,
            ]);
            log_admin_activity((int) $currentAdmin['id'], $scope . '_category_updated', $table, $id);
            set_flash('success', 'বিভাগ হালনাগাদ হয়েছে।');
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM public.{$table} WHERE id = :id")->execute([':id' => $id]);
            log_admin_activity((int) $currentAdmin['id'], $scope . '_category_deleted', $table, $id);
            set_flash('success', 'বিভাগ মুছে ফেলা হয়েছে।');
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    redirect_to(admin_url('categories.php'));
}

$galleryCats = $pdo->query('SELECT * FROM public.gallery_categories ORDER BY sort_order ASC, id ASC')->fetchAll();
$postCats    = $pdo->query('SELECT * FROM public.post_categories ORDER BY sort_order ASC, id ASC')->fetchAll();
?>

<div class="adm-grid-2">
  <!-- গ্যালারির বিভাগসমূহ -->
  <section class="adm-panel">
    <h2 class="adm-panel-title">গ্যালারির বিভাগসমূহ</h2>
    <form method="post" action="<?= e(admin_url('categories.php')) ?>" style="display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="scope" value="gallery">
      <input class="form-control" type="text" name="name" placeholder="নতুন গ্যালারি বিভাগের নাম" required style="flex: 1; min-width: 160px;">
      <input class="form-control" type="number" name="sort_order" value="0" style="width: 84px;" title="Sort order">
      <button type="submit" class="btn btn-green">Add</button>
    </form>

    <div class="table-wrap">
      <table class="adm-table">
        <thead>
          <tr><th>নাম ও ক্রম</th><th>করণীয়</th></tr>
        </thead>
        <tbody>
          <?php foreach ($galleryCats as $c): ?>
            <tr>
              <td>
                <form method="post" action="<?= e(admin_url('categories.php')) ?>" style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="scope" value="gallery">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <input class="form-control" type="text" name="name" value="<?= e($c['name']) ?>" style="max-width: 170px;">
                  <input class="form-control" type="number" name="sort_order" value="<?= (int) $c['sort_order'] ?>" style="width: 70px;">
                  <label class="form-check" style="font-size: 0.8rem;">
                    <input type="checkbox" name="is_active" value="1" <?= !empty($c['is_active']) ? 'checked' : '' ?>>
                    <span>চালু</span>
                  </label>
                  <button type="submit" class="btn btn-outline btn-sm">Save</button>
                </form>
              </td>
              <td>
                <form method="post" action="<?= e(admin_url('categories.php')) ?>" data-confirm="Delete this gallery category?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="scope" value="gallery">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- পোস্টের বিভাগসমূহ -->
  <section class="adm-panel">
    <h2 class="adm-panel-title">পোস্টের বিভাগসমূহ</h2>
    <form method="post" action="<?= e(admin_url('categories.php')) ?>" style="display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="scope" value="post">
      <input class="form-control" type="text" name="name" placeholder="নতুন পোস্ট বিভাগের নাম" required style="flex: 1; min-width: 160px;">
      <input class="form-control" type="number" name="sort_order" value="0" style="width: 84px;" title="Sort order">
      <button type="submit" class="btn btn-green">Add</button>
    </form>

    <div class="table-wrap">
      <table class="adm-table">
        <thead>
          <tr><th>নাম ও ক্রম</th><th>করণীয়</th></tr>
        </thead>
        <tbody>
          <?php foreach ($postCats as $c): ?>
            <tr>
              <td>
                <form method="post" action="<?= e(admin_url('categories.php')) ?>" style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="scope" value="post">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <input class="form-control" type="text" name="name" value="<?= e($c['name']) ?>" style="max-width: 170px;">
                  <input class="form-control" type="number" name="sort_order" value="<?= (int) $c['sort_order'] ?>" style="width: 70px;">
                  <label class="form-check" style="font-size: 0.8rem;">
                    <input type="checkbox" name="is_active" value="1" <?= !empty($c['is_active']) ? 'checked' : '' ?>>
                    <span>চালু</span>
                  </label>
                  <button type="submit" class="btn btn-outline btn-sm">Save</button>
                </form>
              </td>
              <td>
                <form method="post" action="<?= e(admin_url('categories.php')) ?>" data-confirm="Delete this post category?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="scope" value="post">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php require_once dirname(__DIR__) . '/includes/admin-footer.php'; ?>
