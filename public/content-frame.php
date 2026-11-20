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
$layoutFix = '<style id="fakir-frame-layout-fix">
html, body {
  width: 100% !important;
  height: auto !important;
  min-height: 0 !important;
  max-height: none !important;
  overflow: visible !important;
  overflow-y: visible !important;
}
html { margin: 0 !important; }
body { margin: 0 !important; }
body > * {
  max-height: none !important;
  overflow: visible !important;
  overflow-y: visible !important;
}
</style>';
$reporter = <<<'HTML'
<script>
(() => {
  let lastHeight = 0;
  let scheduled = false;
  const measure = () => {
    const root = document.documentElement;
    const body = document.body;
    if (!root || !body) return;
    const height = Math.ceil(Math.max(
      root.scrollHeight, root.offsetHeight, root.clientHeight,
      body.scrollHeight, body.offsetHeight, body.clientHeight,
      body.getBoundingClientRect().height
    ));
    if (height > 0 && height !== lastHeight) {
      lastHeight = height;
      parent.postMessage({ type: 'fakir:content-height', height }, '*');
    }
  };
  const report = () => {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => {
      scheduled = false;
      measure();
    });
  };
  const start = () => {
    report();
    if ('ResizeObserver' in window) {
      const ro = new ResizeObserver(report);
      ro.observe(document.documentElement);
      if (document.body) ro.observe(document.body);
    }
    if ('MutationObserver' in window) {
      const mo = new MutationObserver(report);
      mo.observe(document.documentElement, { childList: true, subtree: true, attributes: true, characterData: true });
    }
    window.addEventListener('load', report);
    window.addEventListener('resize', report);
    document.querySelectorAll('img, video, iframe').forEach((el) => {
      el.addEventListener('load', report);
      el.addEventListener('loadedmetadata', report);
      el.addEventListener('error', report);
    });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(report).catch(() => {});
    [0, 100, 300, 700, 1500, 3000].forEach(ms => setTimeout(report, ms));
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
</script>
HTML;
if ($codeDocument !== '') {
    // Keep admin-authored content sandboxed, but prevent a second scroll area.
    // The outer page owns scrolling; this frame expands to its real content height.
    if (stripos($codeDocument, '</head>') !== false) {
        $codeDocument = preg_replace('~</head>~i', $layoutFix . '</head>', $codeDocument, 1) ?? $codeDocument;
    } else {
        $codeDocument = $layoutFix . $codeDocument;
    }
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
<?= $layoutFix ?>
</head>
<body>
<?= $html ?>
<?php if ($js !== ''): ?><script><?= $js ?></script><?php endif; ?>
<?= $reporter ?>
</body>
</html>
