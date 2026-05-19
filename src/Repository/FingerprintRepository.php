<?php

declare(strict_types=1);

namespace AccessLogger\Repository;

use PDO;

final class FingerprintRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function findIdByHash(string $hash): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM user_fingerprints WHERE fingerprint_hash = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();

        return $row ? (int)$row['id'] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_fingerprints WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_fingerprints WHERE fingerprint_hash = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $sql = 'INSERT INTO user_fingerprints (
            fingerprint_hash, screen_resolution, user_agent, ip_address,
            operating_system, browser_name, browser_version, device_type,
            language, timezone, color_depth, pixel_ratio, touch_support,
            webgl_vendor, webgl_renderer, canvas_fingerprint, audio_fingerprint,
            plugins_list, fonts_list
        ) VALUES (
            :fingerprint_hash, :screen_resolution, :user_agent, :ip_address,
            :operating_system, :browser_name, :browser_version, :device_type,
            :language, :timezone, :color_depth, :pixel_ratio, :touch_support,
            :webgl_vendor, :webgl_renderer, :canvas_fingerprint, :audio_fingerprint,
            :plugins_list, :fonts_list
        )';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'fingerprint_hash' => $data['fingerprint_hash'],
            'screen_resolution' => $data['screen_resolution'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'operating_system' => $data['operating_system'] ?? null,
            'browser_name' => $data['browser_name'] ?? null,
            'browser_version' => $data['browser_version'] ?? null,
            'device_type' => $data['device_type'] ?? 'unknown',
            'language' => $data['language'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'color_depth' => $data['color_depth'] ?? null,
            'pixel_ratio' => $data['pixel_ratio'] ?? null,
            'touch_support' => !empty($data['touch_support']) ? 1 : 0,
            'webgl_vendor' => $data['webgl_vendor'] ?? null,
            'webgl_renderer' => $data['webgl_renderer'] ?? null,
            'canvas_fingerprint' => $data['canvas_fingerprint'] ?? null,
            'audio_fingerprint' => $data['audio_fingerprint'] ?? null,
            'plugins_list' => $data['plugins_list'] ?? null,
            'fonts_list' => $data['fonts_list'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }
}
