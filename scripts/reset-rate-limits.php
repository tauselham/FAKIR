#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Emergency CLI Utility to Reset Rate Limit Buckets.
 * Usage:
 *   php scripts/reset-rate-limits.php              # Resets all scopes
 *   php scripts/reset-rate-limits.php admin_login  # Resets a specific scope
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI execution only.');
}

require_once dirname(__DIR__) . '/includes/rate_limiter.php';

$scope = $argv[1] ?? null;
$limiter = new RateLimiter();

if ($scope !== null) {
    $deleted = $limiter->resetScope($scope);
    echo "Cleared {$deleted} rate-limit bucket(s) for scope '{$scope}'.\n";
    exit(0);
}

$total = 0;
foreach (RateLimiter::SCOPES as $s) {
    $total += $limiter->resetScope($s);
}
echo "Cleared {$total} rate-limit bucket(s) across all scopes.\n";
