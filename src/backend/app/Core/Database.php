<?php

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(?array $config = null): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $config = $config ?: require dirname(__DIR__, 2) . '/config/database.php';
        $driver = $config['driver'] ?? 'mysql';
        $charset = $config['charset'] ?? 'utf8mb4';

        if ($driver !== 'mysql') {
            throw new RuntimeException('Unsupported database driver.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $charset
        );

        try {
            $connection = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            // TIMESTAMP is an instant. Make its SQL projection and Manila day
            // boundaries explicit rather than relying on the host's SYSTEM zone.
            $connection->exec("SET time_zone = '+08:00'");
            self::$connection = $connection;
        } catch (PDOException $exception) {
            error_log('Database connection failed: ' . $exception);
            throw new RuntimeException('Unable to connect to the database.', 0, $exception);
        }

        return self::$connection;
    }

    public static function resetConnection(): void
    {
        self::$connection = null;
    }

    public static function connectionWithRetry(
        ?array $config = null,
        int $maxAttempts = 5,
        int $delaySeconds = 2,
        ?callable $sleepFn = null,
        ?callable $connector = null
    ): PDO {
        $lastException = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                if ($connector !== null) {
                    $conn = $connector();
                    if ($conn instanceof PDO) {
                        self::$connection = $conn;
                        return $conn;
                    }
                }
                return self::connection($config);
            } catch (Throwable $exception) {
                $lastException = $exception;
                if ($attempt >= $maxAttempts) {
                    break;
                }
                if ($sleepFn !== null) {
                    $sleepFn($delaySeconds);
                } else {
                    sleep($delaySeconds);
                }
            }
        }

        throw new RuntimeException(
            'Unable to connect to the database after ' . $maxAttempts . ' attempts. Last error: ' . ($lastException ? $lastException->getMessage() : 'unknown'),
            0,
            $lastException
        );
    }

    public static function transaction(callable $callback)
    {
        $pdo = self::connection();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
