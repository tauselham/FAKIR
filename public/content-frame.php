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
$reporter = "<script>\n(() => {\n  const report = () => {\n    const body = document.body;\n    const root = document.documentElement;\n    const height = Math.max(\n      body ? body.scrollHeight : 0,\n      body ? body.offsetHeight : 0,\n      root ? root.scrollHeight : 0,\n      root ? root.offsetHeight : 0\n    );\n    parent.postMessage({ type: 'fakir:content-height', height }, '*');\n  };\n  const start = () => {\n    report();\n    if ('ResizeObserver' in window) {\n      const observer = new ResizeObserver(report);\n      if (document.documentElement) observer.observe(document.documentElement);\n      if (document.body) observer.observe(document.body);\n    }\n    if ('MutationObserver' in window && document.documentElement) {\n      const observer = new MutationObserver(report);\n      observer.observe(document.documentElement, { childList: true, subtree: true, attributes: true, characterData: true });\n    }\n    window.addEventListener('load', report);\n    window.addEventListener('resize', report);\n    document.addEventListener('DOMContentLoaded', report);\n    if (document.fonts && document.fonts.ready) document.fonts.ready.then(report).catch(() => {});\n    document.querySelectorAll('img').forEach((img) => {\n      if (!img.complete) {\n        img.addEventListener('load', report, { once: true });\n        img.addEventListener('error', report, { once: true });\n      }\n    });\n  };\n  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });\n  else start();\n})();\n</script>";
if ($codeDocument !== '') {
    // Full HTML documents are admin-authored and rendered only inside an iframe
    // whose sandbox omits same-origin, forms, popups and top-navigation.
    // Inject automatic height reporting so the public wrapper can remove
    // excess blank space without granting the iframe same-origin access.
    if (stripos($codeDocument, '</body>') !== false) {
        $codeDocument = preg_replace('~</body>~i', $reporter . '</body>', $codeDocument, 1) ?? $codeDocument;
    } elseif (stripos($codeDocument, '</html>') !== false) {
        $codeDocument = preg_replace('~</html>~i', $reporter . '</html>', $codeDocument, 1) ?? $codeDocument;
    } else {
        $codeDocument .= $reporter;
    }
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
<?= $reporter ?>
</body>
</html>
