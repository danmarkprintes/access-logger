<?php

declare(strict_types=1);

namespace AccessLogger\Repository;

use PDO;
use PDOException;
use RuntimeException;

final class PdoConnection
{
    /**
     * @param array{dsn?: string, user?: string, pass?: string} $db
     */
    public static function fromSettings(array $db): PDO
    {
        $dsn = $db['dsn'] ?? 'mysql:host=127.0.0.1;dbname=access_logger;charset=utf8mb4';
        $user = $db['user'] ?? 'root';
        $pass = $db['pass'] ?? '';

        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function ping(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }
}
