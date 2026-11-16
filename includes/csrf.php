<?php
declare(strict_types=1);

/**
 * Generates and caches a cryptographically secure per-session CSRF token.
 */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session must be started before generating CSRF token.');
    }

    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_token(?string $submittedToken): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $sessionToken = $_SESSION['_csrf_token'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '' || !is_string($submittedToken) || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function require_valid_csrf(): void
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $contentLength > 0 && empty($_POST) && empty($_FILES)) {
        $postMax = (string) ini_get('post_max_size');
        http_response_code(413);
        exit(
            'Uploaded file exceeds the server POST size limit'
            . ($postMax !== '' ? ' (post_max_size = ' . htmlspecialchars($postMax, ENT_QUOTES, 'UTF-8') . ')' : '')
            . '. Please upload a smaller file or increase upload_max_filesize and post_max_size in php.ini.'
        );
    }

    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!verify_csrf_token(is_string($token) ? $token : null)) {
        http_response_code(403);
        exit('Invalid or expired security token. Please refresh the page and try again.');
    }
}
