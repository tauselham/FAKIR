#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Automated Verification Suite for Server-Side Configurable RateLimiter.
 *
 * Run locally with PHP 8.1+:
 *   php tests/rate_limiter_test.php
 */

require_once dirname(__DIR__) . '/includes/rate_limiter.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "[FAIL] {$message} — expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

// Create isolated in-memory PDO database matching the PostgreSQL rate-limit schema
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "pdo_sqlite driver is required to run isolated offline tests.\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec('
    CREATE TABLE rate_limit_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scope TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL,
        description TEXT,
        max_requests INTEGER NOT NULL,
        window_value INTEGER NOT NULL,
        window_unit TEXT NOT NULL,
        is_enabled INTEGER NOT NULL DEFAULT 1,
        updated_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE rate_limit_buckets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scope TEXT NOT NULL,
        bucket_type TEXT NOT NULL,
        bucket_key TEXT NOT NULL,
        request_count INTEGER NOT NULL DEFAULT 0,
        window_started_at TEXT NOT NULL,
        window_expires_at TEXT NOT NULL,
        last_request_at TEXT NOT NULL,
        UNIQUE (scope, bucket_type, bucket_key)
    );
');

$ins = $pdo->prepare(
    'INSERT INTO rate_limit_rules (scope, label, description, max_requests, window_value, window_unit, is_enabled)
     VALUES (:scope, :label, :desc, :max_req, :win_val, :win_unit, :enabled)'
);
foreach (RateLimiter::DEFAULT_RULES as $rule) {
    $ins->execute([
        ':scope'    => $rule['scope'],
        ':label'    => $rule['label'],
        ':desc'     => $rule['description'],
        ':max_req'  => $rule['max_requests'],
        ':win_val'  => $rule['window_value'],
        ':win_unit' => $rule['window_unit'],
        ':enabled'  => $rule['is_enabled'] ? 1 : 0,
    ]);
}

// Deterministic injectable clock
$now = 1_760_000_000;
$limiter = new RateLimiter($pdo, static function () use (&$now): int {
    return $now;
});

// 1. Time unit calculations
assert_same(15, RateLimiter::windowSeconds(15, 'seconds'), '15 seconds converts to 15s');
assert_same(900, RateLimiter::windowSeconds(15, 'minutes'), '15 minutes converts to 900s');
assert_same(7200, RateLimiter::windowSeconds(2, 'hours'), '2 hours converts to 7200s');
assert_same(172800, RateLimiter::windowSeconds(2, 'days'), '2 days converts to 172800s');

// 2. Super Admin configures a custom 3-requests-per-10-seconds rule on public_api
$limiter->updateRule('public_api', 3, 10, 'seconds', true, 1);
$r1 = $limiter->check('public_api', ['ip' => '203.0.113.10']);
$r2 = $limiter->check('public_api', ['ip' => '203.0.113.10']);
$r3 = $limiter->check('public_api', ['ip' => '203.0.113.10']);
$r4 = $limiter->check('public_api', ['ip' => '203.0.113.10']);

assert_true($r1['allowed'] && $r1['remaining'] === 2, 'Public API request 1/3 allowed (2 remaining)');
assert_true($r2['allowed'] && $r2['remaining'] === 1, 'Public API request 2/3 allowed (1 remaining)');
assert_true($r3['allowed'] && $r3['remaining'] === 0, 'Public API request 3/3 allowed (0 remaining)');
assert_true(!$r4['allowed'] && $r4['retry_after'] === 10, 'Public API request 4/3 blocked with retry_after=10s');
assert_true(str_contains($r4['retry_message'], '10 seconds'), 'Retry-after message clearly states 10 seconds');

// 3. Different IP is unaffected
$otherIp = $limiter->check('public_api', ['ip' => '203.0.113.11']);
assert_true($otherIp['allowed'], 'Different client IP has independent bucket');

// 4. Automatic reset when window expires
$now += 11; // 11 seconds later (window was 10s)
$afterExpiry = $limiter->check('public_api', ['ip' => '203.0.113.10']);
assert_true($afterExpiry['allowed'] && $afterExpiry['remaining'] === 2, 'Expired window automatically resets counter');

// 5. Dual IP + Account protection on admin_login & recovery on successful login
$limiter->updateRule('admin_login', 2, 15, 'minutes', true, 1);
$limiter->hit('admin_login', ['ip' => '198.51.100.5', 'account' => 'fakir_admin']);
$limiter->hit('admin_login', ['ip' => '198.51.100.5', 'account' => 'fakir_admin']);

// Attacker switches IP to try same account -> still blocked by account bucket!
$crossIpCheck = $limiter->check('admin_login', ['ip' => '198.51.100.99', 'account' => 'fakir_admin'], false);
assert_true(!$crossIpCheck['allowed'], 'Account-based protection blocks brute force even when IP changes');

// Attacker switches account from same IP -> still blocked by IP bucket!
$crossAcctCheck = $limiter->check('admin_login', ['ip' => '198.51.100.5', 'account' => 'other_admin'], false);
assert_true(!$crossAcctCheck['allowed'], 'IP-based protection blocks brute force across multiple accounts');

// Legitimate admin reset (simulates successful login or Super Admin reset)
$limiter->reset('admin_login', ['ip' => '198.51.100.5', 'account' => 'fakir_admin']);
$afterReset = $limiter->check('admin_login', ['ip' => '198.51.100.5', 'account' => 'fakir_admin'], false);
assert_true($afterReset['allowed'], 'Successful login / reset clears lockout so legitimate admins are not locked out permanently');

// 6. All 6 scopes exist and can be toggled/updated
foreach (RateLimiter::SCOPES as $scope) {
    $rule = $limiter->getRule($scope);
    assert_same($scope, $rule['scope'], "Scope '{$scope}' rule is configured");
}

// 7. Disabled rule permits requests
$limiter->updateRule('gallery_api', 1, 1, 'hours', false, 1);
$g1 = $limiter->check('gallery_api', ['ip' => '203.0.113.50']);
$g2 = $limiter->check('gallery_api', ['ip' => '203.0.113.50']);
assert_true($g1['allowed'] && $g2['allowed'], 'Disabled rate limit allows requests cleanly');

// 8. Purge expired buckets & resetScope
$cleared = $limiter->resetScope('public_api');
assert_true($cleared >= 1, 'resetScope clears active buckets');

echo "\nAll RateLimiter verification tests passed.\n";
