<?php

declare(strict_types=1);

namespace AccessLogger\Repository;

use PDO;

final class AccessLogEventRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function belongsToAccessLog(int $eventId, int $accessLogId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM access_log_events WHERE id = :eid AND access_log_id = :aid LIMIT 1'
        );
        $stmt->execute(['eid' => $eventId, 'aid' => $accessLogId]);

        return (bool)$stmt->fetch();
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    public function insertMany(int $accessLogId, array $events): int
    {
        if ($events === []) {
            return 0;
        }

        $sql = 'INSERT INTO access_log_events (
            access_log_id, event_name, element_type, element_label, target_href,
            numeric_value, scroll_percent, time_offset_ms, created
        ) VALUES (
            :access_log_id, :event_name, :element_type, :element_label, :target_href,
            :numeric_value, :scroll_percent, :time_offset_ms, :created
        )';

        $stmt = $this->pdo->prepare($sql);
        $count = 0;

        foreach ($events as $ev) {
            $stmt->execute([
                'access_log_id' => $accessLogId,
                'event_name' => $ev['event_name'],
                'element_type' => $ev['element_type'] ?? null,
                'element_label' => $ev['element_label'] ?? null,
                'target_href' => $ev['target_href'] ?? null,
                'numeric_value' => $ev['numeric_value'] ?? null,
                'scroll_percent' => $ev['scroll_percent'] ?? null,
                'time_offset_ms' => $ev['time_offset_ms'] ?? null,
                'created' => $ev['created'] ?? date('Y-m-d H:i:s'),
            ]);
            ++$count;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $event
     */
    public function insertOne(int $accessLogId, array $event): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO access_log_events (
                access_log_id, event_name, element_type, element_label, target_href,
                numeric_value, scroll_percent, time_offset_ms, created
            ) VALUES (
                :access_log_id, :event_name, :element_type, :element_label, :target_href,
                :numeric_value, :scroll_percent, :time_offset_ms, :created
            )'
        );
        $stmt->execute([
            'access_log_id' => $accessLogId,
            'event_name' => $event['event_name'],
            'element_type' => $event['element_type'] ?? null,
            'element_label' => $event['element_label'] ?? null,
            'target_href' => $event['target_href'] ?? null,
            'numeric_value' => $event['numeric_value'] ?? null,
            'scroll_percent' => $event['scroll_percent'] ?? null,
            'time_offset_ms' => $event['time_offset_ms'] ?? null,
            'created' => $event['created'] ?? date('Y-m-d H:i:s'),
        ]);

        return (int)$this->pdo->lastInsertId();
    }
}
