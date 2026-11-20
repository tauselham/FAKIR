<?php
declare(strict_types=1);

// This endpoint is embedded only in an iframe with sandbox="allow-scripts".
// It deliberately does not share the parent document's restrictive CSP.
define('SANDBOXED_CONTENT_FRAME', true);
require_once dirname(__DIR__) . '/includes/functions.php';

header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: blob: http: https:; media-src 'self' data: blob: http: https:; font-src 'self' data: https://fonts.gstatic.com; connect-src 'none'; form-action 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'self'; worker-src 'none'; manifest-src 'none'");
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
  overflow-x: hidden !important;
  overflow-y: visible !important;
  margin: 0 !important;
}
body {
  padding-bottom: 1px !important;
}
#__fakir_height_end__ {
  display: block !important;
  clear: both !important;
  height: 1px !important;
  min-height: 0 !important;
  margin: 0 !important;
  padding: 0 !important;
  border: 0 !important;
  visibility: hidden !important;
  pointer-events: none !important;
}
</style>';
$reporter = <<<'HTML'
<div id="__fakir_height_end__" aria-hidden="true"></div>
<script>
(() => {
  let lastHeight = 0;
  let prevInnerHeight = window.innerHeight || 0;
  let lastSentTime = 0;
  let mutationSeq = 0;
  let lastMutationSeq = 0;
  let vhLoopCount = 0;
  let scheduled = false;
  let resizeObserver = null;
  const measure = (force) => {
    const root = document.documentElement;
    const body = document.body;
    if (!root || !body) return;
    let contentBottom = 0;
    const endNode = document.getElementById('__fakir_height_end__');
    if (endNode) {
      const endRect = endNode.getBoundingClientRect();
      contentBottom = Math.max(contentBottom, endRect.bottom + window.scrollY);
    }
    for (const element of Array.from(body.children)) {
      if (element.id === '__fakir_height_end__' || element.tagName === 'SCRIPT' || element.tagName === 'STYLE') continue;
      const cs = window.getComputedStyle(element);
      if (cs.display === 'none' || cs.position === 'fixed') continue;
      const rect = element.getBoundingClientRect();
      const mb = parseFloat(cs.marginBottom) || 0;
      contentBottom = Math.max(contentBottom, rect.bottom + window.scrollY + Math.max(0, mb));
    }
    const bodyStyle = window.getComputedStyle(body);
    const bodyPb = parseFloat(bodyStyle.paddingBottom) || 0;
    const height = Math.ceil(Math.max(240, contentBottom + bodyPb));
    const curInnerHeight = window.innerHeight || 0;
    if (
      !force &&
      lastHeight > 0 &&
      mutationSeq === lastMutationSeq &&
      Date.now() - lastSentTime < 500 &&
      curInnerHeight > prevInnerHeight + 15 &&
      height > lastHeight + 15 &&
      Math.abs((height - lastHeight) - (curInnerHeight - prevInnerHeight)) < 40
    ) {
      vhLoopCount += 1;
      prevInnerHeight = curInnerHeight;
      if (vhLoopCount >= 2) return;
    } else if (mutationSeq !== lastMutationSeq || Math.abs(curInnerHeight - prevInnerHeight) <= 15) {
      vhLoopCount = 0;
    }
    prevInnerHeight = curInnerHeight;
    lastMutationSeq = mutationSeq;
    if (height > 0 && (force || Math.abs(height - lastHeight) > 1)) {
      lastHeight = height;
      lastSentTime = Date.now();
      parent.postMessage({ type: 'fakir:content-height', height }, '*');
    }
  };
  const report = (force) => {
    if (scheduled && !force) return;
    scheduled = true;
    requestAnimationFrame(() => { scheduled = false; measure(Boolean(force)); });
  };
  const attachMediaListeners = (scope) => {
    (scope || document).querySelectorAll('img, video, iframe').forEach((element) => {
      if (element.dataset.fakirBound) return;
      element.dataset.fakirBound = '1';
      ['load', 'loadedmetadata', 'error'].forEach((eventName) => element.addEventListener(eventName, () => { mutationSeq += 1; report(false); }));
      if (element.tagName === 'IMG' && element.complete) report(false);
    });
  };
  const observeChildren = () => {
    if (!resizeObserver || !document.body) return;
    document.querySelectorAll('body > *').forEach((element) => {
      if (element.id !== '__fakir_height_end__') {
        try { resizeObserver.observe(element); } catch (_) {}
      }
    });
  };
  const start = () => {
    report(true);
    if ('ResizeObserver' in window) {
      resizeObserver = new ResizeObserver(() => report(false));
      if (document.body) resizeObserver.observe(document.body);
      observeChildren();
    }
    if ('MutationObserver' in window) {
      const observer = new MutationObserver(() => {
        mutationSeq += 1;
        attachMediaListeners(document);
        observeChildren();
        report(false);
      });
      observer.observe(document.documentElement, { childList: true, subtree: true, attributes: true, characterData: true });
    }
    attachMediaListeners(document);
    window.addEventListener('load', () => { mutationSeq += 1; report(true); });
    window.addEventListener('resize', () => report(false));
    window.addEventListener('message', (event) => {
      if (event.data && event.data.type === 'fakir:request-height') {
        report(true);
      }
    });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { mutationSeq += 1; report(false); }).catch(() => {});
    [0, 60, 180, 500, 1200, 2500].forEach((delay) => window.setTimeout(() => report(false), delay));
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
</script>
HTML;
if ($codeDocument !== '') {
    // Keep admin-authored content sandboxed, but prevent a second scroll area.
    // The outer page owns scrolling; this frame expands or shrinks to its real content height.
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
