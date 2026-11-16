<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Outputs a single active popup payload. Content is rendered through DOM text
 * nodes by the frontend script; no administrator-supplied HTML is executed.
 */
function render_site_popup(): void
{
    try {
        $stmt = db()->query(
            "SELECT id, title, popup_type, body_text, image_url, image_link_url,
                    image_link_target, button_text, button_url, button_target,
                    delay_seconds, frequency, display_scope, device_target
             FROM public.site_popups
             WHERE is_active = TRUE
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at > NOW())
             ORDER BY priority DESC, created_at DESC
             LIMIT 20"
        );
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Public popup lookup failed: ' . $e->getMessage());
        return;
    }

    if (!$rows) return;
    $isHome = (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/') === '/';
    $eligible = [];
    foreach ($rows as $row) {
        if ($row['display_scope'] === 'home' && !$isHome) continue;
        if ($row['popup_type'] === 'image' && empty($row['image_url'])) continue;
        if ($row['popup_type'] === 'text' && trim((string) $row['body_text']) === '') continue;
        $eligible[] = $row;
    }
    if (!$eligible) return;

    $json = json_encode($eligible, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return;
    echo '<script type="application/json" id="site-popup-data">' . $json . '</script>';
}
