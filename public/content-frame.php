<?php
declare(strict_types=1);

// This endpoint is embedded only in an iframe with sandbox="allow-scripts".
// It deliberately does not share the parent document's restrictive CSP.
define('SANDBOXED_CONTENT_FRAME', true);
require_once dirname(__DIR__) . '/includes/functions.php';

header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob: https:; media-src data: blob: https:; font-src data:; connect-src 'none'; form-action 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'self'; worker-src 'none'; manifest-src 'none'");
header('Cache-Control: no-store, max-age=0');
header('Content-Type: text/html; charset=UTF-8');
header('Referrer-Policy: no-referrer');

$type = (string) ($_GET['type'] ?? '');
$id = max(0, (int) ($_GET['id'] ?? 0));
if ($id < 1 || !in_array($type, ['page', 'post'], true)) {
    http_response_code(404);
    exit('Content not found.');
}

if ($type === 'page') {
    $stmt = db()->prepare("SELECT title, html_content, css_content, js_content, code_document FROM public.content_pages WHERE id = :id AND status = 'published' LIMIT 1");
} else {
    $stmt = db()->prepare("SELECT title, html_content, css_content, js_content, code_document FROM public.posts WHERE id = :id AND post_type = 'custom' AND status = 'published' LIMIT 1");
}
$stmt->execute([':id' => $id]);
$content = $stmt->fetch();
if (!$content) {
    http_response_code(404);
    exit('Content not found.');
}

$codeDocument = (string) ($content['code_document'] ?? '');
if ($codeDocument !== '') {
    // Full HTML documents are admin-authored and rendered only inside an iframe
    // whose sandbox omits same-origin, forms, popups and top-navigation.
    echo $codeDocument;
    exit;
}

$title = e((string) ($content['title'] ?? 'Custom Content'));
$css = (string) ($content['css_content'] ?? '');
$html = (string) ($content['html_content'] ?? '');
$js = (string) ($content['js_content'] ?? '');
$js = preg_replace('~</script~i', '<\\/script', $js) ?? $js;
?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title><?= $title ?></title>
<style><?= $css ?></style>
</head>
<body>
<?= $html ?>
<?php if ($js !== ''): ?><script><?= $js ?></script><?php endif; ?>
</body>
</html>
