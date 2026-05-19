<?php

declare(strict_types=1);

namespace AccessLogger\Repository;

use PDO;
use PDOException;

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
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            // Health endpoint reports DB status; ingestão fase 2 ainda é stub.
            $pdo = new PDO('sqlite::memory:');
        }

        return $pdo;
    }

    public static function isMysql(PDO $pdo): bool
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    public static function ping(PDO $pdo): bool
    {
        if (!self::isMysql($pdo)) {
            return false;
        }

        try {
            $pdo->query('SELECT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }
}
