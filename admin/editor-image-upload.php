<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/storage.php';

send_admin_nocache_headers();
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$admin = require_admin();
if (!admin_has_permission('manage_posts', $admin)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to upload post images.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    require_valid_csrf();
    enforce_rate_limit('admin_api', [
        'ip' => client_ip(),
        'account' => 'admin:' . (int) $admin['id'],
    ], true);

    $storage = new SupabaseStorage();
    $uploaded = $storage->upload(
        $_FILES['image'] ?? [],
        BUCKET_POST_MEDIA,
        ALLOWED_IMAGE_MIMES,
        MAX_IMAGE_UPLOAD_BYTES,
        'editor-images'
    );

    log_admin_activity((int) $admin['id'], 'post_editor_image_uploaded', 'storage_object', null, [
        'bucket' => $uploaded['bucket'],
        'path' => $uploaded['path'],
        'mime' => $uploaded['mime'],
    ]);

    echo json_encode([
        'ok' => true,
        'url' => $uploaded['url'],
        'mime' => $uploaded['mime'],
        'name' => basename($uploaded['path']),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('Post editor image upload failed: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Image upload failed. Please check the file type, size, and Supabase Storage settings.']);
}
