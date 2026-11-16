<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Returns a singleton PDO connection to Supabase PostgreSQL.
 * Supports both Direct Connection (port 5432) and Transaction Pooler (port 6543),
 * configured via SUPABASE_DB_* variables or DATABASE_URL / SUPABASE_DB_URL.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host    = env_value('SUPABASE_DB_HOST');
    $port    = env_value('SUPABASE_DB_PORT', '5432');
    $dbName  = env_value('SUPABASE_DB_NAME', 'postgres');
    $user    = env_value('SUPABASE_DB_USER');
    $pass    = env_value('SUPABASE_DB_PASSWORD');
    $sslMode = env_value('SUPABASE_DB_SSLMODE', 'require');

    $dbUrl = env_value('SUPABASE_DB_URL') ?? env_value('DATABASE_URL');
    if ($dbUrl !== null && (!$host || !$user || !$pass)) {
        $parsed = parse_url($dbUrl);
        if (is_array($parsed)) {
            $host   = $host ?: ($parsed['host'] ?? null);
            $port   = isset($parsed['port']) ? (string) $parsed['port'] : $port;
            $dbName = !empty($parsed['path']) ? ltrim((string) $parsed['path'], '/') : $dbName;
            $user   = $user ?: (isset($parsed['user']) ? rawurldecode((string) $parsed['user']) : null);
            $pass   = $pass ?: (isset($parsed['pass']) ? rawurldecode((string) $parsed['pass']) : null);
            if (!empty($parsed['query'])) {
                parse_str((string) $parsed['query'], $qParams);
                if (!empty($qParams['sslmode']) && is_string($qParams['sslmode'])) {
                    $sslMode = $qParams['sslmode'];
                }
            }
        }
    }

    if (!$host || !$user || !$pass) {
        throw new RuntimeException('Database configuration is incomplete. Check .env credentials.');
    }

    // Supabase Transaction Pooler (Supavisor on port 6543) requires emulated prepares
    $isPooler = ((int) $port === 6543) || str_contains((string) $host, 'pooler.supabase.com');
    $emulateEnv = env_value('SUPABASE_DB_EMULATE_PREPARES');
    $emulatePrepares = $emulateEnv !== null
        ? filter_var($emulateEnv, FILTER_VALIDATE_BOOL)
        : $isPooler;

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
        $host,
        $port,
        $dbName,
        $sslMode
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => $emulatePrepares,
        PDO::ATTR_PERSISTENT         => false,
        PDO::ATTR_TIMEOUT            => 8,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        if (!$isPooler) {
            $pdo->exec("SET TIME ZONE 'UTC'");
        }
    } catch (PDOException $e) {
        error_log('PostgreSQL connection error: ' . $e->getMessage());
        throw new RuntimeException('Unable to connect to the community database.');
    }

    return $pdo;
}

/**
 * Execute a callback safely inside a PostgreSQL transaction.
 *
 * @template T
 * @param callable(PDO): T $callback
 * @return T
 */
function db_transaction(callable $callback): mixed
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $result = $callback($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
