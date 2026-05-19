<?php

declare(strict_types=1);

namespace AccessLogger\Repository;

use PDO;

final class AccessLogRepository
{
    private ?bool $hasUserIdColumn = null;

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function hasUserIdColumn(): bool
    {
        if ($this->hasUserIdColumn !== null) {
            return $this->hasUserIdColumn;
        }

        $stmt = $this->pdo->query(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'access_logs'
               AND COLUMN_NAME = 'user_id'"
        );
        $row = $stmt->fetch();
        $this->hasUserIdColumn = isset($row['c']) && (int)$row['c'] > 0;

        return $this->hasUserIdColumn;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM access_logs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return (bool)$stmt->fetch();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM access_logs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getMaxNavigationOrder(string $sessionId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT navigation_order FROM access_logs
             WHERE session_id = :sid ORDER BY navigation_order DESC LIMIT 1'
        );
        $stmt->execute(['sid' => $sessionId]);
        $row = $stmt->fetch();

        return $row ? (int)$row['navigation_order'] : 0;
    }

    public function getLatestIdBySession(string $sessionId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM access_logs WHERE session_id = :sid ORDER BY created DESC LIMIT 1'
        );
        $stmt->execute(['sid' => $sessionId]);
        $row = $stmt->fetch();

        return $row ? (int)$row['id'] : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $columns = [
            'user_fingerprint_id', 'session_id', 'url', 'referer', 'is_authenticated',
            'page_load_time', 'scroll_depth', 'time_on_page',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'navigation_order', 'previous_access_log_id', 'exit_type',
            'viewport_width', 'viewport_height',
        ];
        $params = [
            'user_fingerprint_id' => $data['user_fingerprint_id'],
            'session_id' => $data['session_id'] ?? null,
            'url' => $data['url'],
            'referer' => $data['referer'] ?? null,
            'is_authenticated' => !empty($data['is_authenticated']) ? 1 : 0,
            'page_load_time' => $data['page_load_time'] ?? null,
            'scroll_depth' => $data['scroll_depth'] ?? 0,
            'time_on_page' => $data['time_on_page'] ?? null,
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'utm_term' => $data['utm_term'] ?? null,
            'utm_content' => $data['utm_content'] ?? null,
            'navigation_order' => $data['navigation_order'] ?? 1,
            'previous_access_log_id' => $data['previous_access_log_id'] ?? null,
            'exit_type' => $data['exit_type'] ?? null,
            'viewport_width' => $data['viewport_width'] ?? null,
            'viewport_height' => $data['viewport_height'] ?? null,
        ];

        if ($this->hasUserIdColumn() && array_key_exists('user_id', $data)) {
            $columns[] = 'user_id';
            $params['user_id'] = $data['user_id'];
        }

        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);
        $sql = 'INSERT INTO access_logs (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ')';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, array $fields): bool
    {
        if ($fields === []) {
            return true;
        }

        $sets = [];
        $params = ['id' => $id];
        foreach ($fields as $key => $value) {
            $sets[] = $key . ' = :' . $key;
            $params[$key] = $value;
        }

        $sql = 'UPDATE access_logs SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($params);
    }

    public function linkToUserByFingerprint(int $fingerprintId, int $userId): int
    {
        if (!$this->hasUserIdColumn()) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE access_logs SET user_id = :uid, is_authenticated = 1
             WHERE user_fingerprint_id = :fid AND user_id IS NULL'
        );
        $stmt->execute(['uid' => $userId, 'fid' => $fingerprintId]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{total_access: int, unique_users: int, authenticated_access: int, anonymous_access: int}
     */
    public function getStats(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'created >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'created <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        if (isset($filters['is_authenticated'])) {
            $where[] = 'is_authenticated = :auth';
            $params['auth'] = (int)$filters['is_authenticated'];
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) AS c FROM access_logs WHERE {$whereSql}");
        $stmt->execute($params);
        $totalAccess = (int)($stmt->fetch()['c'] ?? 0);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT user_fingerprint_id) AS c FROM access_logs WHERE {$whereSql}"
        );
        $stmt->execute($params);
        $uniqueFingerprints = (int)($stmt->fetch()['c'] ?? 0);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM access_logs WHERE is_authenticated = 1');
        $stmt->execute();
        $authenticatedAccess = (int)($stmt->fetch()['c'] ?? 0);

        return [
            'total_access' => $totalAccess,
            'unique_users' => $uniqueFingerprints,
            'authenticated_access' => $authenticatedAccess,
            'anonymous_access' => max(0, $totalAccess - $authenticatedAccess),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getJourneyBySession(string $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT al.*, uf.device_type, uf.browser_name, uf.language
             FROM access_logs al
             INNER JOIN user_fingerprints uf ON uf.id = al.user_fingerprint_id
             WHERE al.session_id = :sid
             ORDER BY al.navigation_order ASC'
        );
        $stmt->execute(['sid' => $sessionId]);

        return $stmt->fetchAll();
    }
}
