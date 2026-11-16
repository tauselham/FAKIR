#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Emergency CLI Utility to Generate a Single-Use Password Recovery Link.
 * Useful in environments before SMTP/mail() transport is configured.
 * Usage:
 *   php scripts/create-password-reset.php admin@example.com
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI execution only.');
}

require_once dirname(__DIR__) . '/includes/auth.php';

$email = trim((string) ($argv[1] ?? ''));
if ($email === '') {
    $email = trim((string) readline('Administrator Email: '));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Error: Valid email address is required.\n");
    exit(1);
}

$token = create_password_reset_token($email);
if ($token === null) {
    fwrite(STDERR, "No active administrator account found for that email.\n");
    exit(1);
}

$url = APP_URL . admin_url('forgot-password.php') . '?token=' . urlencode($token);
echo "Single-use password reset link (expires in 30 minutes):\n{$url}\n";
