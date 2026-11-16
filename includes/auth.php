<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/rate_limiter.php';

/**
 * Starts a hardened PHP session for admin authentication & CSRF.
 */
function send_admin_nocache_headers(): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
    }
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    $secureCookie = filter_var(env_value('SESSION_SECURE_COOKIE', $isHttps ? 'true' : 'false'), FILTER_VALIDATE_BOOL) && $isHttps;

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function current_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }

    $now = time();
    $loginAt = (int) ($_SESSION['admin_login_at'] ?? 0);
    $lastActive = (int) ($_SESSION['admin_last_active'] ?? 0);

    if (($now - $loginAt) > SESSION_ABSOLUTE_TIMEOUT || ($now - $lastActive) > SESSION_IDLE_TIMEOUT) {
        logout_admin(false);
        return null;
    }

    $_SESSION['admin_last_active'] = $now;

    $stmt = db()->prepare(
        'SELECT id, username, email, full_name, role, permissions, must_change_password, is_active, last_login_at
         FROM public.admins
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => (int) $_SESSION['admin_id']]);
    $admin = $stmt->fetch();

    if (!$admin || empty($admin['is_active'])) {
        logout_admin(false);
        return null;
    }

    $admin['permissions'] = decode_admin_permissions($admin['permissions'] ?? '[]');
    $admin['must_change_password'] = !empty($admin['must_change_password']);

    return $admin;
}

/**
 * Granular Admin Panel permission definitions assignable by a Super Admin.
 *
 * @return array<string, array{label: string, description: string}>
 */
function all_admin_permissions(): array
{
    return [
        'manage_posts' => [
            'label'       => 'Community Posts',
            'description' => 'Create, edit, publish/unpublish, and delete Text, Image, Video, and Custom Code posts.',
        ],
        'manage_pages' => [
            'label'       => 'Landing Pages & About Us',
            'description' => 'Create and publish custom HTML/CSS/JavaScript pages and manage the About Us URL.',
        ],
        'manage_gallery' => [
            'label'       => 'Photo Gallery',
            'description' => 'Upload, edit captions, reorder, feature, and delete gallery photos.',
        ],
        'manage_banners' => [
            'label'       => 'Hero Banners',
            'description' => 'Upload, reorder, and configure homepage hero banner slides and timing.',
        ],
        'manage_categories' => [
            'label'       => 'Categories',
            'description' => 'Create, edit, reorder, and delete Post and Gallery categories.',
        ],
        'manage_navigation' => [
            'label'       => 'Header & Footer',
            'description' => 'Customize header logo/colors/menus and footer content, links, and contacts.',
        ],
        'manage_settings' => [
            'label'       => 'Website Settings',
            'description' => 'Edit website title, favicon, SEO metadata, accent colors, and social links.',
        ],
    ];
}

/**
 * @return array<int, string>
 */
function decode_admin_permissions(mixed $raw): array
{
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
    } elseif (is_array($raw)) {
        $decoded = $raw;
    } else {
        $decoded = [];
    }

    if (!is_array($decoded)) {
        return [];
    }

    $validKeys = array_keys(all_admin_permissions());
    $clean = [];
    foreach ($decoded as $perm) {
        if (is_string($perm) && in_array($perm, $validKeys, true) && !in_array($perm, $clean, true)) {
            $clean[] = $perm;
        }
    }
    return $clean;
}

function is_super_admin(?array $admin = null): bool
{
    return (($admin['role'] ?? '') === 'super_admin');
}

function admin_has_permission(string $permission, ?array $admin = null): bool
{
    if ($admin === null) {
        return false;
    }
    if (is_super_admin($admin)) {
        return true;
    }
    $perms = is_array($admin['permissions'] ?? null)
        ? $admin['permissions']
        : decode_admin_permissions($admin['permissions'] ?? '[]');
    return in_array($permission, $perms, true);
}

function require_admin(): array
{
    start_secure_session();
    $admin = current_admin();
    if (!$admin) {
        set_flash('error', 'Please sign in to access the Admin Panel.');
        redirect_to(admin_url('login.php'));
    }
    return $admin;
}

function is_login_rate_limited(string $identifier): array
{
    return rate_limiter()->check('admin_login', [
        'ip'      => client_ip(),
        'account' => $identifier,
    ], false);
}

function record_login_attempt(string $identifier, bool $success): void
{
    $stmt = db()->prepare(
        'INSERT INTO public.login_attempts (ip_address, identifier, success) VALUES (:ip, :identifier, :success)'
    );
    $stmt->execute([
        ':ip'         => client_ip(),
        ':identifier' => substr($identifier, 0, 180),
        ':success'    => $success ? 'true' : 'false',
    ]);
}

function hash_admin_password(string $password): string
{
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    return password_hash($password, $algo);
}

/**
 * Validates password strength (min 10 chars, upper, lower, digit, symbol).
 */
function validate_password_strength(string $password): array
{
    $errors = [];
    if (strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character.';
    }
    return $errors;
}

/**
 * Attempts login with username or email + password.
 *
 * @return array{ok: bool, message: string, rate_limited?: bool, retry_after?: int, limit_result?: array}
 */
function attempt_admin_login(string $identifier, string $password): array
{
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') {
        return ['ok' => false, 'message' => 'Please enter both your username/email and password.'];
    }

    $targets = [
        'ip'      => client_ip(),
        'account' => strtolower($identifier),
    ];

    $limitCheck = rate_limiter()->check('admin_login', $targets, false);
    if (!$limitCheck['allowed']) {
        return [
            'ok'           => false,
            'rate_limited' => true,
            'retry_after'  => $limitCheck['retry_after'],
            'limit_result' => $limitCheck,
            'message'      => $limitCheck['retry_message'],
        ];
    }

    $stmt = db()->prepare(
        'SELECT id, username, email, password_hash, full_name, role, is_active
         FROM public.admins
         WHERE LOWER(username) = LOWER(:ident_u) OR LOWER(email) = LOWER(:ident_e)
         LIMIT 1'
    );
    $stmt->execute([
        ':ident_u' => $identifier,
        ':ident_e' => $identifier,
    ]);
    $admin = $stmt->fetch();

    if (!$admin || empty($admin['is_active']) || !password_verify($password, (string) $admin['password_hash'])) {
        record_login_attempt($identifier, false);
        $afterHit = rate_limiter()->hit('admin_login', $targets);
        if (!$afterHit['allowed']) {
            return [
                'ok'           => false,
                'rate_limited' => true,
                'retry_after'  => $afterHit['retry_after'],
                'limit_result' => $afterHit,
                'message'      => $afterHit['retry_message'],
            ];
        }
        return ['ok' => false, 'message' => 'Invalid credentials.'];
    }

    // Rehash automatically if algorithm parameters improve
    $targetAlgo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    if (password_needs_rehash((string) $admin['password_hash'], $targetAlgo)) {
        $newHash = hash_admin_password($password);
        $upd = db()->prepare('UPDATE public.admins SET password_hash = :hash WHERE id = :id');
        $upd->execute([':hash' => $newHash, ':id' => (int) $admin['id']]);
    }

    record_login_attempt($identifier, true);
    // Reset failed login buckets so legitimate admins are never locked out
    rate_limiter()->reset('admin_login', $targets);

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_login_at'] = time();
    $_SESSION['admin_last_active'] = time();
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

    $updateLogin = db()->prepare('UPDATE public.admins SET last_login_at = NOW() WHERE id = :id');
    $updateLogin->execute([':id' => (int) $admin['id']]);

    log_admin_activity((int) $admin['id'], 'admin_login', 'admin', (int) $admin['id']);

    return ['ok' => true, 'message' => 'Signed in successfully.'];
}

function logout_admin(bool $logActivity = true): void
{
    if ($logActivity && !empty($_SESSION['admin_id'])) {
        log_admin_activity((int) $_SESSION['admin_id'], 'admin_logout', 'admin', (int) $_SESSION['admin_id']);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Changes the authenticated admin's password after verifying the current password.
 *
 * @return array{ok: bool, errors: array<string>}
 */
function change_admin_password(int $adminId, string $currentPassword, string $newPassword, string $confirmPassword): array
{
    if ($newPassword !== $confirmPassword) {
        return ['ok' => false, 'errors' => ['New password and confirmation do not match.']];
    }

    $strengthErrors = validate_password_strength($newPassword);
    if ($strengthErrors !== []) {
        return ['ok' => false, 'errors' => $strengthErrors];
    }

    $stmt = db()->prepare('SELECT password_hash FROM public.admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $adminId]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($currentPassword, (string) $row['password_hash'])) {
        return ['ok' => false, 'errors' => ['Current password is incorrect.']];
    }

    if (password_verify($newPassword, (string) $row['password_hash'])) {
        return ['ok' => false, 'errors' => ['New password must differ from your current password.']];
    }

    $newHash = hash_admin_password($newPassword);

    db_transaction(function (PDO $pdo) use ($adminId, $newHash): void {
        $upd = $pdo->prepare('UPDATE public.admins SET password_hash = :hash, must_change_password = FALSE WHERE id = :id');
        $upd->execute([':hash' => $newHash, ':id' => $adminId]);
        log_admin_activity($adminId, 'password_changed', 'admin', $adminId);
    });

    return ['ok' => true, 'errors' => []];
}

/**
 * Creates a single-use cryptographic password recovery token (valid for 30 minutes).
 */
function create_password_reset_token(string $email): ?string
{
    $stmt = db()->prepare('SELECT id, email FROM public.admins WHERE LOWER(email) = LOWER(:email) AND is_active = TRUE LIMIT 1');
    $stmt->execute([':email' => trim($email)]);
    $admin = $stmt->fetch();

    if (!$admin) {
        return null;
    }

    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    db_transaction(function (PDO $pdo) use ($admin, $tokenHash): void {
        // Invalidate previous unused tokens for this admin
        $inv = $pdo->prepare('UPDATE public.password_resets SET used_at = NOW() WHERE admin_id = :aid AND used_at IS NULL');
        $inv->execute([':aid' => (int) $admin['id']]);

        $ins = $pdo->prepare(
            "INSERT INTO public.password_resets (admin_id, token_hash, expires_at)
             VALUES (:aid, :hash, NOW() + INTERVAL '30 minutes')"
        );
        $ins->execute([
            ':aid'  => (int) $admin['id'],
            ':hash' => $tokenHash,
        ]);

        log_admin_activity((int) $admin['id'], 'password_reset_requested', 'admin', (int) $admin['id']);
    });

    return $rawToken;
}

/**
 * Completes password reset using a valid cryptographic token.
 *
 * @return array{ok: bool, errors: array<string>}
 */
function reset_password_with_token(string $rawToken, string $newPassword, string $confirmPassword): array
{
    if ($newPassword !== $confirmPassword) {
        return ['ok' => false, 'errors' => ['Passwords do not match.']];
    }

    $strengthErrors = validate_password_strength($newPassword);
    if ($strengthErrors !== []) {
        return ['ok' => false, 'errors' => $strengthErrors];
    }

    $tokenHash = hash('sha256', trim($rawToken));

    $stmt = db()->prepare(
        'SELECT id, admin_id FROM public.password_resets
         WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([':hash' => $tokenHash]);
    $reset = $stmt->fetch();

    if (!$reset) {
        return ['ok' => false, 'errors' => ['Reset token is invalid or has expired.']];
    }

    $newHash = hash_admin_password($newPassword);

    db_transaction(function (PDO $pdo) use ($reset, $newHash): void {
        $upd = $pdo->prepare('UPDATE public.admins SET password_hash = :hash, must_change_password = FALSE WHERE id = :aid');
        $upd->execute([':hash' => $newHash, ':aid' => (int) $reset['admin_id']]);

        $mark = $pdo->prepare('UPDATE public.password_resets SET used_at = NOW() WHERE id = :id');
        $mark->execute([':id' => (int) $reset['id']]);

        log_admin_activity((int) $reset['admin_id'], 'password_reset_completed', 'admin', (int) $reset['admin_id']);
    });

    return ['ok' => true, 'errors' => []];
}

/**
 * Dispatches a password recovery link via PHP mail() when MAIL_ENABLED=true in .env.
 * Returns true if handed off to the server mail transport, false if mail is disabled or failed.
 */
function send_password_reset_email(string $recipientEmail, string $rawToken): bool
{
    $enabled = filter_var(env_value('MAIL_ENABLED', 'false'), FILTER_VALIDATE_BOOL);
    if (!$enabled || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $fromAddress = (string) env_value('MAIL_FROM_ADDRESS', 'no-reply@localhost');
    if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        $fromAddress = 'no-reply@localhost';
    }
    $fromName = preg_replace('/[\r\n]/', '', (string) env_value('MAIL_FROM_NAME', 'Purbo Nijmaona Fakir Bari')) ?: 'Admin Portal';

    $resetUrl = APP_URL . admin_url('forgot-password.php') . '?token=' . urlencode($rawToken);
    $subject  = 'Administrator Password Recovery — Purbo Nijmaona Fakir Bari';
    $body     = "A password recovery request was received for your administrator account.\n\n"
        . "Use the single-use link below within 30 minutes to set a new password:\n"
        . $resetUrl . "\n\n"
        . "If you did not request this change, you can safely ignore this message.\n";

    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromAddress . '>',
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);

    $sent = @mail($recipientEmail, $subject, $body, $headers);
    if (!$sent) {
        error_log('Password recovery mail() delivery failed for recipient.');
    }
    return $sent;
}
