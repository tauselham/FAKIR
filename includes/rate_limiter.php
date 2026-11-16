<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Server-side configurable Rate Limiter backed by PostgreSQL.
 * Keyed by IP address and account identifier (never PHP session ID).
 */
final class RateLimiter
{
    public const SCOPES = [
        'admin_login',
        'password_recovery',
        'public_api',
        'gallery_api',
        'post_submission',
        'admin_api',
    ];

    public const VALID_UNITS = ['seconds', 'minutes', 'hours', 'days'];

    public const DEFAULT_RULES = [
        'admin_login' => [
            'scope'        => 'admin_login',
            'label'        => 'অ্যাডমিন লগইন',
            'description'  => 'আইপি ঠিকানা ও অ্যাকাউন্ট অনুযায়ী অতিরিক্ত লগইন চেষ্টার বিরুদ্ধে সুরক্ষা দেয়।',
            'max_requests' => 5,
            'window_value' => 15,
            'window_unit'  => 'minutes',
            'is_enabled'   => true,
        ],
        'password_recovery' => [
            'scope'        => 'password_recovery',
            'label'        => 'পাসওয়ার্ড পুনরুদ্ধার',
            'description'  => 'আইপি ঠিকানা ও ইমেইল অনুযায়ী পাসওয়ার্ড রিসেটের অনুরোধ এবং টোকেন জমা দেওয়ার সংখ্যা সীমিত রাখে।',
            'max_requests' => 3,
            'window_value' => 1,
            'window_unit'  => 'hours',
            'is_enabled'   => true,
        ],
        'public_api' => [
            'scope'        => 'public_api',
            'label'        => 'পাবলিক এপিআই',
            'description'  => 'অতিরিক্ত অনুরোধ ও তথ্য সংগ্রহের অপব্যবহার থেকে পাবলিক পোস্ট এবং সার্চ সুবিধা সুরক্ষিত রাখে।',
            'max_requests' => 60,
            'window_value' => 1,
            'window_unit'  => 'minutes',
            'is_enabled'   => true,
        ],
        'gallery_api' => [
            'scope'        => 'gallery_api',
            'label'        => 'গ্যালারি এপিআই',
            'description'  => 'প্রতি ব্যবহারকারীর আইপি অনুযায়ী গ্যালারির পৃষ্ঠা ও বিভাগ ফিল্টারের অনুরোধ সীমিত রাখে।',
            'max_requests' => 45,
            'window_value' => 1,
            'window_unit'  => 'minutes',
            'is_enabled'   => true,
        ],
        'post_submission' => [
            'scope'        => 'post_submission',
            'label'        => 'পোস্ট জমা',
            'description'  => 'আইপি ঠিকানা ও লগইন করা অ্যাডমিন অনুযায়ী পোস্ট তৈরি এবং হালনাগাদের অনুরোধ সীমিত রাখে।',
            'max_requests' => 20,
            'window_value' => 10,
            'window_unit'  => 'minutes',
            'is_enabled'   => true,
        ],
        'admin_api' => [
            'scope'        => 'admin_api',
            'label'        => 'অ্যাডমিন এপিআই',
            'description'  => 'লগইন করা অ্যাডমিনের কার্যক্রম এবং ড্যাশবোর্ডের এপিআই অনুরোধ সুরক্ষিত রাখে।',
            'max_requests' => 120,
            'window_value' => 1,
            'window_unit'  => 'minutes',
            'is_enabled'   => true,
        ],
    ];

    private PDO $pdo;

    /** @var callable(): int */
    private $clock;

    /**
     * @param callable(): int|null $clock Optional Unix timestamp provider for deterministic tests.
     */
    public function __construct(?PDO $pdo = null, ?callable $clock = null)
    {
        $this->pdo   = $pdo ?? db();
        $this->clock = $clock ?? static fn (): int => time();
    }

    public static function windowSeconds(int $value, string $unit): int
    {
        $safeValue = max(1, $value);
        return match ($unit) {
            'seconds' => $safeValue,
            'minutes' => $safeValue * 60,
            'hours'   => $safeValue * 3600,
            'days'    => $safeValue * 86400,
            default   => throw new InvalidArgumentException('Unsupported rate-limit time unit: ' . $unit),
        };
    }

    public static function hashKey(string $rawKey): string
    {
        return hash('sha256', strtolower(trim($rawKey)));
    }

    public static function formatRetryAfter(int $seconds): string
    {
        $seconds = max(1, $seconds);
        if ($seconds < 60) {
            return $seconds === 1 ? '১ সেকেন্ড' : "{$seconds} সেকেন্ড";
        }
        if ($seconds < 3600) {
            $mins = (int) ceil($seconds / 60);
            return $mins === 1 ? '১ মিনিট' : "{$mins} মিনিট";
        }
        if ($seconds < 86400) {
            $hours = (int) ceil($seconds / 3600);
            return $hours === 1 ? '১ ঘণ্টা' : "{$hours} ঘণ্টা";
        }
        $days = (int) ceil($seconds / 86400);
        return $days === 1 ? '১ দিন' : "{$days} দিন";
    }

    public static function parseTimestamp(string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($trimmed))->getTimestamp();
        } catch (Throwable) {
            $ts = strtotime($trimmed);
            return $ts === false ? null : $ts;
        }
    }

    public function getRule(string $scope): array
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Invalid rate-limit scope: ' . $scope);
        }

        try {
            $rulesTable = $this->tableName('rate_limit_rules');
            $stmt = $this->pdo->prepare("SELECT * FROM {$rulesTable} WHERE scope = :scope LIMIT 1");
            $stmt->execute([':scope' => $scope]);
            $row = $stmt->fetch();
            if ($row) {
                $row['max_requests'] = (int) $row['max_requests'];
                $row['window_value'] = (int) $row['window_value'];
                $row['is_enabled']   = (bool) $row['is_enabled'];
                return $row;
            }
        } catch (Throwable $e) {
            error_log('RateLimiter getRule fallback: ' . $e->getMessage());
        }

        return self::DEFAULT_RULES[$scope];
    }

    /**
     * Evaluates and optionally consumes 1 unit across all provided target keys
     * (e.g. ['ip' => '1.2.3.4', 'account' => 'admin@example.com']).
     *
     * @param array{ip?: string|null, account?: string|null} $targets
     * @return array{
     *   allowed: bool,
     *   limit: int,
     *   remaining: int,
     *   retry_after: int,
     *   retry_message: string,
     *   reset_at: int,
     *   rule: array
     * }
     */
    public function check(string $scope, array $targets, bool $consume = true): array
    {
        $rule = $this->getRule($scope);
        $now = ($this->clock)();
        $maxRequests = max(1, (int) $rule['max_requests']);
        $windowSec = self::windowSeconds((int) $rule['window_value'], (string) $rule['window_unit']);

        if (empty($rule['is_enabled'])) {
            return [
                'allowed'       => true,
                'limit'         => $maxRequests,
                'remaining'     => $maxRequests,
                'retry_after'   => 0,
                'retry_message' => '',
                'reset_at'      => $now + $windowSec,
                'rule'          => $rule,
            ];
        }

        $normalizedTargets = $this->normalizeTargets($targets);
        if ($normalizedTargets === []) {
            return [
                'allowed'       => true,
                'limit'         => $maxRequests,
                'remaining'     => $maxRequests,
                'retry_after'   => 0,
                'retry_message' => '',
                'reset_at'      => $now + $windowSec,
                'rule'          => $rule,
            ];
        }

        $nowIso     = gmdate('Y-m-d H:i:sP', $now);
        $expiresIso = gmdate('Y-m-d H:i:sP', $now + $windowSec);

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ownsTx = !$this->pdo->inTransaction();
        if ($ownsTx) {
            $this->pdo->beginTransaction();
        }

        try {
            $buckets = [];
            $blockedRetryAfter = 0;
            $highestCount = 0;
            $soonestReset = $now + $windowSec;

            foreach ($normalizedTargets as $type => $hashedKey) {
                $this->ensureBucketRow($scope, $type, $hashedKey, $nowIso, $expiresIso);

                $lockSql = $driver === 'pgsql'
                    ? 'SELECT id, request_count, window_expires_at
                       FROM public.rate_limit_buckets
                       WHERE scope = :scope AND bucket_type = :type AND bucket_key = :key
                       FOR UPDATE'
                    : 'SELECT id, request_count, window_expires_at
                       FROM rate_limit_buckets
                       WHERE scope = :scope AND bucket_type = :type AND bucket_key = :key';

                $sel = $this->pdo->prepare($lockSql);
                $sel->execute([
                    ':scope' => $scope,
                    ':type'  => $type,
                    ':key'   => $hashedKey,
                ]);
                $row = $sel->fetch();

                $expiresTs = self::parseTimestamp((string) $row['window_expires_at']);
                $count = (int) $row['request_count'];

                // Automatically reset expired limits
                if ($expiresTs === null || $expiresTs <= $now) {
                    $count = 0;
                    $expiresTs = $now + $windowSec;
                    $resetStmt = $this->pdo->prepare(
                        $this->tableSql('UPDATE %s
                         SET request_count = 0, window_started_at = :now_ts, window_expires_at = :exp_ts, last_request_at = :last_ts
                         WHERE id = :id')
                    );
                    $resetStmt->execute([
                        ':now_ts'  => $nowIso,
                        ':exp_ts'  => $expiresIso,
                        ':last_ts' => $nowIso,
                        ':id'      => (int) $row['id'],
                    ]);
                }

                if ($count >= $maxRequests) {
                    $retryAfter = max(1, $expiresTs - $now);
                    if ($retryAfter > $blockedRetryAfter) {
                        $blockedRetryAfter = $retryAfter;
                    }
                }

                if ($count > $highestCount) {
                    $highestCount = $count;
                }
                if ($expiresTs < $soonestReset) {
                    $soonestReset = $expiresTs;
                }

                $buckets[] = [
                    'id'         => (int) $row['id'],
                    'count'      => $count,
                    'expires_ts' => $expiresTs,
                ];
            }

            if ($blockedRetryAfter > 0) {
                if ($ownsTx) {
                    $this->pdo->commit();
                }
                $human = self::formatRetryAfter($blockedRetryAfter);
                return [
                    'allowed'       => false,
                    'limit'         => $maxRequests,
                    'remaining'     => 0,
                    'retry_after'   => $blockedRetryAfter,
                    'retry_message' => "{$rule['label']} সীমা অতিক্রম হয়েছে। অনুগ্রহ করে {$human} পরে আবার চেষ্টা করুন।",
                    'reset_at'      => $now + $blockedRetryAfter,
                    'rule'          => $rule,
                ];
            }

            if ($consume) {
                foreach ($buckets as $b) {
                    $upd = $this->pdo->prepare(
                        $this->tableSql('UPDATE %s SET request_count = request_count + 1, last_request_at = :last_ts WHERE id = :id')
                    );
                    $upd->execute([
                        ':last_ts' => $nowIso,
                        ':id'      => $b['id'],
                    ]);
                }
                $highestCount++;
            }

            // Periodic opportunistic cleanup of stale expired rows (approx 2% of requests)
            if (random_int(1, 50) === 1) {
                $this->purgeExpired($now - 3600);
            }

            if ($ownsTx) {
                $this->pdo->commit();
            }

            return [
                'allowed'       => true,
                'limit'         => $maxRequests,
                'remaining'     => max(0, $maxRequests - $highestCount),
                'retry_after'   => 0,
                'retry_message' => '',
                'reset_at'      => $soonestReset,
                'rule'          => $rule,
            ];
        } catch (Throwable $e) {
            if ($ownsTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Convenience wrapper to consume 1 request unit (e.g. on failed login).
     *
     * @param array{ip?: string|null, account?: string|null} $targets
     */
    public function hit(string $scope, array $targets): array
    {
        return $this->check($scope, $targets, true);
    }

    /**
     * Clears rate-limit buckets for specific targets (e.g. after successful login).
     *
     * @param array{ip?: string|null, account?: string|null} $targets
     */
    public function reset(string $scope, array $targets): void
    {
        $normalized = $this->normalizeTargets($targets);
        foreach ($normalized as $type => $hashedKey) {
            $stmt = $this->pdo->prepare(
                $this->tableSql('DELETE FROM %s WHERE scope = :scope AND bucket_type = :type AND bucket_key = :key')
            );
            $stmt->execute([
                ':scope' => $scope,
                ':type'  => $type,
                ':key'   => $hashedKey,
            ]);
        }
    }

    /**
     * Resets all active buckets for a given scope (Super Admin / CLI recovery tool).
     */
    public function resetScope(string $scope): int
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Invalid rate-limit scope: ' . $scope);
        }
        $stmt = $this->pdo->prepare($this->tableSql('DELETE FROM %s WHERE scope = :scope'));
        $stmt->execute([':scope' => $scope]);
        return $stmt->rowCount();
    }

    public function purgeExpired(?int $olderThanTimestamp = null): int
    {
        $cutoff = $olderThanTimestamp ?? ($this->clock)();
        $cutoffIso = gmdate('Y-m-d H:i:sP', $cutoff);
        $stmt = $this->pdo->prepare($this->tableSql('DELETE FROM %s WHERE window_expires_at <= :cutoff'));
        $stmt->execute([':cutoff' => $cutoffIso]);
        return $stmt->rowCount();
    }

    public function getAllRulesWithStats(): array
    {
        $nowIso = gmdate('Y-m-d H:i:sP', ($this->clock)());
        $rulesTable   = $this->tableName('rate_limit_rules');
        $bucketsTable = $this->tableName('rate_limit_buckets');
        $stmt = $this->pdo->prepare(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM {$bucketsTable} b
                     WHERE b.scope = r.scope AND b.window_expires_at > :now_ts AND b.request_count > 0) AS active_buckets,
                    (SELECT COUNT(*) FROM {$bucketsTable} b
                     WHERE b.scope = r.scope AND b.window_expires_at > :now_ts2 AND b.request_count >= r.max_requests) AS blocked_buckets
             FROM {$rulesTable} r
             ORDER BY r.id ASC"
        );
        $stmt->execute([':now_ts' => $nowIso, ':now_ts2' => $nowIso]);
        return $stmt->fetchAll();
    }

    public function updateRule(
        string $scope,
        int $maxRequests,
        int $windowValue,
        string $windowUnit,
        bool $isEnabled,
        ?int $adminId = null
    ): void {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Invalid rate-limit scope.');
        }
        if ($maxRequests < 1 || $maxRequests > 100000) {
            throw new InvalidArgumentException('Maximum request count must be between 1 and 100,000.');
        }
        if ($windowValue < 1 || $windowValue > 100000) {
            throw new InvalidArgumentException('Time window value must be between 1 and 100,000.');
        }
        if (!in_array($windowUnit, self::VALID_UNITS, true)) {
            throw new InvalidArgumentException('Time unit must be seconds, minutes, hours, or days.');
        }

        $rulesTable = $this->tableName('rate_limit_rules');
        $stmt = $this->pdo->prepare(
            "UPDATE {$rulesTable}
             SET max_requests = :max_req, window_value = :win_val, window_unit = :win_unit,
                 is_enabled = :enabled, updated_by = :uid
             WHERE scope = :scope"
        );
        $stmt->bindValue(':max_req', $maxRequests, PDO::PARAM_INT);
        $stmt->bindValue(':win_val', $windowValue, PDO::PARAM_INT);
        $stmt->bindValue(':win_unit', $windowUnit, PDO::PARAM_STR);
        $stmt->bindValue(':enabled', $isEnabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':uid', $adminId, $adminId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':scope', $scope, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Emits standard HTTP 429 headers and exits cleanly for API/JSON or HTML callers.
     */
    public static function sendTooManyRequestsResponse(array $result, bool $asJson = false): never
    {
        $retryAfter = max(1, (int) ($result['retry_after'] ?? 60));
        $limit      = (int) ($result['limit'] ?? 0);
        $resetAt    = (int) ($result['reset_at'] ?? (time() + $retryAfter));
        $message    = (string) ($result['retry_message'] ?? ('অতিরিক্ত অনুরোধ করা হয়েছে। অনুগ্রহ করে ' . self::formatRetryAfter($retryAfter) . ' পরে আবার চেষ্টা করুন।'));

        if (!headers_sent()) {
            http_response_code(429);
            header('Retry-After: ' . $retryAfter);
            header('X-RateLimit-Limit: ' . $limit);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . $resetAt);
            if ($asJson) {
                header('Content-Type: application/json; charset=UTF-8');
            }
        }

        if ($asJson) {
            echo json_encode([
                'error'       => $message,
                'retry_after' => $retryAfter,
                'reset_at'    => $resetAt,
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        $safeMsg = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1.0\"><title>অতিরিক্ত অনুরোধ (HTTP 429)</title><style>body{font-family:system-ui,sans-serif;background:#f4f7f4;color:#141e16;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px}.box{max-width:480px;background:#fff;border:1px solid #dce4dc;border-radius:10px;padding:28px}h1{margin:0 0 10px;font-size:1.35rem;color:#991b1b}p{margin:0 0 16px;line-height:1.6}a{color:#15803d;font-weight:600;text-decoration:none}</style></head><body><div class=\"box\"><h1>অনেক বেশি অনুরোধ করা হয়েছে</h1><p>{$safeMsg}</p><a href=\"/\">← মূল পাতায় ফিরে যান</a></div></body></html>";
        exit;
    }

    public static function applyTooManyRequestsHeaders(array $result): void
    {
        $retryAfter = max(1, (int) ($result['retry_after'] ?? 60));
        if (!headers_sent()) {
            http_response_code(429);
            header('Retry-After: ' . $retryAfter);
            header('X-RateLimit-Limit: ' . (int) ($result['limit'] ?? 0));
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . (int) ($result['reset_at'] ?? (time() + $retryAfter)));
        }
    }

    /**
     * @param array{ip?: string|null, account?: string|null} $targets
     * @return array<string, string>
     */
    private function normalizeTargets(array $targets): array
    {
        $out = [];
        if (!empty($targets['ip']) && is_string($targets['ip'])) {
            $out['ip'] = self::hashKey('ip:' . trim($targets['ip']));
        }
        if (!empty($targets['account']) && is_string($targets['account'])) {
            $out['account'] = self::hashKey('account:' . trim($targets['account']));
        }
        return $out;
    }

    private function ensureBucketRow(string $scope, string $type, string $hashedKey, string $nowIso, string $expiresIso): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $sql = 'INSERT INTO public.rate_limit_buckets
                    (scope, bucket_type, bucket_key, request_count, window_started_at, window_expires_at, last_request_at)
                    VALUES (:scope, :type, :key, 0, :now_ts, :exp_ts, :last_ts)
                    ON CONFLICT (scope, bucket_type, bucket_key) DO NOTHING';
        } else {
            $sql = 'INSERT OR IGNORE INTO rate_limit_buckets
                    (scope, bucket_type, bucket_key, request_count, window_started_at, window_expires_at, last_request_at)
                    VALUES (:scope, :type, :key, 0, :now_ts, :exp_ts, :last_ts)';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':scope'   => $scope,
            ':type'    => $type,
            ':key'     => $hashedKey,
            ':now_ts'  => $nowIso,
            ':exp_ts'  => $expiresIso,
            ':last_ts' => $nowIso,
        ]);
    }

    private function tableName(string $name): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return $driver === 'pgsql' ? 'public.' . $name : $name;
    }

    private function tableSql(string $template): string
    {
        return sprintf($template, $this->tableName('rate_limit_buckets'));
    }
}
