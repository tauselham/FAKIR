<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Escapes text safely for HTML output to prevent XSS.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Validates URLs to allow only safe relative paths, anchors, or http/https URLs.
 * Never returns a bare '#' fragment as a fallback URL.
 */
function safe_url(?string $url, string $fallback = '/'): string
{
    $trimmed = trim((string) $url);
    if ($trimmed === '' || $trimmed === '#' || preg_match('/[\x00-\x1F\x7F]/', $trimmed)) {
        return $fallback;
    }

    if ((str_starts_with($trimmed, '/') && !str_starts_with($trimmed, '//')) || str_starts_with($trimmed, '#')) {
        return $trimmed;
    }

    $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));
    if (in_array($scheme, ['http', 'https'], true)) {
        return $trimmed;
    }

    return $fallback;
}

/**
 * Normalizes internal navigation URLs so homepage links always use '/'.
 */
function clean_nav_url(?string $url, string $fallback = '/'): string
{
    $safe = safe_url($url, $fallback);
    if ($safe === '/index.php') {
        return '/';
    }
    if (str_starts_with($safe, '/index.php?')) {
        return '/' . substr($safe, strlen('/index.php'));
    }
    return $safe;
}

function sanitize_hex_color(?string $color, string $fallback = '#14532d'): string
{
    $trimmed = trim((string) $color);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $trimmed)) {
        return strtolower($trimmed);
    }
    return $fallback;
}

function slugify(string $text): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'item-' . bin2hex(random_bytes(4));
}


/**
 * Validates a single-segment public content slug and blocks reserved routes.
 */
function validate_content_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 120) {
        throw new RuntimeException('URL Slug must contain only lowercase English letters, numbers, and single hyphens.');
    }

    $reserved = [
        '448662', 'admin', 'api', 'assets', 'index', 'index-php',
        'posts', 'post', 'gallery', 'login', 'logout', 'forgot-password',
        'content-frame', 'page', 'router', 'sitemap', 'robots', 'favicon', 'uploads',
    ];
    if (in_array($slug, $reserved, true)) {
        throw new RuntimeException('This URL Slug is reserved by the website. Please choose another one.');
    }
    return $slug;
}

function format_date(?string $timestamp, string $format = 'M j, Y'): string
{
    if (!$timestamp) {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($timestamp);
        return $dt->format($format);
    } catch (Throwable) {
        return '';
    }
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function redirect_to(string $path): never
{
    $cleanPath = preg_replace('/[\r\n\x00]/', '', $path) ?? '/';
    header('Location: ' . $cleanPath);
    exit;
}

/**
 * Builds canonical public URLs under the configured Admin Panel entry route (/448662).
 */
function admin_url(string $subpath = ''): string
{
    $base = defined('ADMIN_BASE_PATH') ? rtrim((string) ADMIN_BASE_PATH, '/') : '/448662';
    $clean = ltrim($subpath, '/');
    return $clean === '' ? $base : $base . '/' . $clean;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

/**
 * @return array{type: string, message: string}|null
 */
function get_flash(): ?array
{
    if (empty($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        return null;
    }
    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $flash;
}

function log_admin_activity(?int $adminId, string $action, ?string $entityType = null, ?int $entityId = null, array $details = []): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO public.activity_logs (admin_id, action, entity_type, entity_id, details, ip_address)
             VALUES (:admin_id, :action, :entity_type, :entity_id, :details, :ip)'
        );
        $stmt->execute([
            ':admin_id'    => $adminId,
            ':action'      => $action,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':details'     => $details !== [] ? json_encode($details, JSON_THROW_ON_ERROR) : null,
            ':ip'          => client_ip(),
        ]);
    } catch (Throwable $e) {
        error_log('Activity log error: ' . $e->getMessage());
    }
}

function get_website_settings(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $defaults = [
        'site_name'         => 'Purbo Nijmaona Fakir Bari',
        'site_description'  => 'Local village community stories, announcements, events, and photo memories.',
        'logo_url'          => null,
        'favicon_url'       => null,
        'header_bg_color'   => '#14532d',
        'header_text_color' => '#ffffff',
        'footer_bg_color'   => '#14532d',
        'footer_text_color' => '#ecfdf5',
        'accent_color'      => '#15803d',
        'hero_interval_ms'  => 5500,
        'seo_title'         => 'Purbo Nijmaona Fakir Bari — Local Community & Photo Archive',
        'seo_description'   => 'Local community updates, announcements, events, and photography from Purbo Nijmaona Fakir Bari.',
    ];

    try {
        $row = db()->query('SELECT * FROM public.website_settings WHERE id = 1 LIMIT 1')->fetch();
        $cached = $row ? array_merge($defaults, $row) : $defaults;
    } catch (Throwable) {
        $cached = $defaults;
    }

    return $cached;
}

function get_footer_settings(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $defaults = [
        'logo_url'          => null,
        'site_name'         => 'Purbo Nijmaona Fakir Bari',
        'description'       => 'Sharing village life, local gatherings, and community memories from Purbo Nijmaona Fakir Bari.',
        'contact_address'   => 'Purbo Nijmaona Fakir Bari',
        'contact_phone'     => '',
        'contact_email'     => '',
        'facebook_url'      => '',
        'instagram_url'     => '',
        'youtube_url'       => '',
        'x_url'             => '',
        'copyright_text'    => '© ' . gmdate('Y') . ' Purbo Nijmaona Fakir Bari Community.',
        'show_social_links' => true,
        'show_contact_info' => true,
        'is_visible'        => true,
    ];

    try {
        $row = db()->query('SELECT * FROM public.footer_settings WHERE id = 1 LIMIT 1')->fetch();
        $cached = $row ? array_merge($defaults, $row) : $defaults;
    } catch (Throwable) {
        $cached = $defaults;
    }

    return $cached;
}

/**
 * Fetches visible navigation items for a location ('header' or 'footer') as a parent-child tree.
 */
function get_navigation_tree(string $location, bool $includeHidden = false): array
{
    $sql = 'SELECT ni.*
            FROM public.navigation_items ni
            INNER JOIN public.navigation_menus nm ON nm.id = ni.menu_id
            WHERE nm.location = :location';

    if (!$includeHidden) {
        $sql .= ' AND ni.is_visible = TRUE';
    }

    $sql .= ' ORDER BY ni.sort_order ASC, ni.id ASC';

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute([':location' => $location]);
        $rows = $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }

    $byId = [];
    foreach ($rows as $row) {
        $row['children'] = [];
        $byId[(int) $row['id']] = $row;
    }

    $tree = [];
    foreach ($byId as $id => &$node) {
        $parentId = $node['parent_id'] !== null ? (int) $node['parent_id'] : null;
        if ($parentId !== null && isset($byId[$parentId])) {
            $byId[$parentId]['children'][] = &$node;
        } else {
            $tree[] = &$node;
        }
    }

    return $tree;
}

function rate_limiter(): RateLimiter
{
    static $instance = null;
    if ($instance === null) {
        require_once __DIR__ . '/rate_limiter.php';
        $instance = new RateLimiter(db());
    }
    return $instance;
}

/**
 * Enforces a server-side rate limit and sends HTTP 429 + Retry-After when exceeded.
 *
 * @param array{ip?: string|null, account?: string|null} $targets
 */
function enforce_rate_limit(string $scope, array $targets, bool $asJson = false): array
{
    $res = rate_limiter()->check($scope, $targets, true);
    if (!$res['allowed']) {
        RateLimiter::sendTooManyRequestsResponse($res, $asJson);
    }
    return $res;
}

