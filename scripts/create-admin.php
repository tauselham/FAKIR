#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Secure CLI Bootstrap Script for Creating the First Administrator.
 * Run from terminal: php scripts/create-admin.php
 * Never run or expose via a public web route.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI execution only.');
}

require_once dirname(__DIR__) . '/includes/auth.php';

echo "==========================================================\n";
echo " Purbo Nijmaona Fakir Bari — Secure Admin Account Creator\n";
echo "==========================================================\n\n";

$username = trim((string) readline('Admin Username (min 3 chars): '));
$email    = trim((string) readline('Admin Email: '));
$fullName = trim((string) readline('Full Name: '));

if (strlen($username) < 3 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
    fwrite(STDERR, "Error: Username must be at least 3 alphanumeric/underscore/dot characters.\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Error: Valid email address is required.\n");
    exit(1);
}

if ($fullName === '') {
    $fullName = $username;
}

// Read password securely without echoing if stty is available
echo 'Password (min 10 chars, upper, lower, number, special char): ';
if (function_exists('exec') && stripos(PHP_OS, 'WIN') !== 0) {
    @exec('stty -echo');
    $password = rtrim((string) fgets(STDIN), "\r\n");
    @exec('stty echo');
    echo "\n";
    echo 'Confirm Password: ';
    @exec('stty -echo');
    $confirm = rtrim((string) fgets(STDIN), "\r\n");
    @exec('stty echo');
    echo "\n";
} else {
    $password = trim((string) readline(''));
    $confirm  = trim((string) readline('Confirm Password: '));
}

if ($password !== $confirm) {
    fwrite(STDERR, "Error: Passwords do not match.\n");
    exit(1);
}

$errors = validate_password_strength($password);
if ($errors !== []) {
    foreach ($errors as $err) {
        fwrite(STDERR, "Error: {$err}\n");
    }
    exit(1);
}

$hash = hash_admin_password($password);
$permJson = json_encode(array_keys(all_admin_permissions()), JSON_THROW_ON_ERROR);

try {
    $adminId = db_transaction(function (PDO $pdo) use ($username, $email, $hash, $fullName, $permJson): int {
        $stmt = $pdo->prepare(
            "INSERT INTO public.admins (username, email, password_hash, full_name, role, permissions, must_change_password, is_active)
             VALUES (:username, :email, :hash, :full_name, 'super_admin', CAST(:permissions AS jsonb), FALSE, TRUE)
             RETURNING id"
        );
        $stmt->execute([
            ':username'    => $username,
            ':email'       => $email,
            ':hash'        => $hash,
            ':full_name'   => $fullName,
            ':permissions' => $permJson,
        ]);
        $id = (int) $stmt->fetchColumn();
        log_admin_activity($id, 'admin_created_cli', 'admin', $id, ['username' => $username]);
        return $id;
    });

    echo "\nSuccess: Super Admin '{$username}' (ID #{$adminId}) created securely.\n";
    echo 'You may now sign in at ' . admin_url() . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Database Error: " . $e->getMessage() . "\n");
    exit(1);
}
