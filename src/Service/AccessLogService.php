<?php

declare(strict_types=1);

namespace AccessLogger\Service;

use AccessLogger\Repository\AccessLogEventRepository;
use AccessLogger\Repository\AccessLogRepository;
use AccessLogger\Repository\FingerprintRepository;
use Exception;
use PDOException;

class AccessLogService
{
    private const BRAZIL_IANA_TIMEZONES = [
        'America/Noronha', 'America/Belem', 'America/Fortaleza', 'America/Recife',
        'America/Araguaina', 'America/Maceio', 'America/Bahia', 'America/Sao_Paulo',
        'America/Campo_Grande', 'America/Cuiaba', 'America/Santarem', 'America/Porto_Velho',
        'America/Manaus', 'America/Eirunepe', 'America/Rio_Branco', 'America/Boa_Vista',
    ];

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private readonly FingerprintRepository $fingerprints,
        private readonly AccessLogRepository $accessLogs,
        private readonly AccessLogEventRepository $events,
        private readonly array $settings = []
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function logAccess(array $data): array
    {
        try {
            $url = $data['url'] ?? '';
            if ($this->shouldSkipLogging($url)) {
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'URL filtered - development/staging environment',
                ];
            }

            $fingerprintData = $data['fingerprint'] ?? [];
            $userAgent = (string)($fingerprintData['user_agent'] ?? '');
            $timezone = (string)($fingerprintData['timezone'] ?? '');
            if ($this->isBotUserAgent($userAgent) || $this->isBotTimezone($timezone)) {
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'Bot detected - access not logged',
                ];
            }

            $language = $fingerprintData['language'] ?? null;
            if ($this->shouldExcludeNonBrazilTargetFingerprint($language, $timezone)) {
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'Fingerprint locale outside Brazil target — not logged',
                ];
            }

            if (!empty($data['ip_address'])) {
                $fingerprintData['ip_address'] = $data['ip_address'];
            }

            $fingerprintId = $this->getOrCreateFingerprint($fingerprintData);
            if ($fingerprintId <= 0) {
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'Fingerprint not persisted (locale filter)',
                ];
            }

            $utmParams = $this->extractUtmParams($url);
            $sessionId = $data['session_id'] ?? null;
            $navigationOrder = $this->getNavigationOrder($sessionId);
            $previousLogId = $this->getPreviousLogId($sessionId);

            $logData = [
                'user_fingerprint_id' => $fingerprintId,
                'session_id' => $sessionId,
                'url' => $url,
                'referer' => $data['referer'] ?? null,
                'is_authenticated' => (bool)($data['is_authenticated'] ?? false),
                'page_load_time' => $this->normalizeOptionalInt($data['page_load_time'] ?? null),
                'scroll_depth' => $this->normalizeOptionalInt($data['scroll_depth'] ?? 0) ?? 0,
                'time_on_page' => $this->normalizeOptionalInt($data['time_on_page'] ?? null),
                'navigation_order' => $navigationOrder,
                'previous_access_log_id' => $previousLogId,
                'exit_type' => $data['exit_type'] ?? null,
                'viewport_width' => $this->normalizeOptionalInt($data['viewport_width'] ?? null),
                'viewport_height' => $this->normalizeOptionalInt($data['viewport_height'] ?? null),
            ];

            if ($this->accessLogs->hasUserIdColumn()) {
                $logData['user_id'] = isset($data['user_id']) ? (int)$data['user_id'] : null;
            }

            if (!empty($logData['user_id']) && $fingerprintId > 0) {
                $this->linkAccessLogsToUserByFingerprint($fingerprintId, (int)$logData['user_id']);
            }

            $logData = array_merge($logData, $utmParams);
            $accessLogId = $this->accessLogs->insert($logData);

            return [
                'success' => true,
                'access_log_id' => $accessLogId,
                'fingerprint_id' => $fingerprintId,
                'navigation_order' => $navigationOrder,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $fingerprintData
     */
    public function getOrCreateFingerprintId(array $fingerprintData): int
    {
        return $this->getOrCreateFingerprint($fingerprintData);
    }

    public function linkAccessLogsToUserByFingerprint(int $userFingerprintId, int $userId): int
    {
        if ($userFingerprintId <= 0 || $userId <= 0) {
            return 0;
        }

        return $this->accessLogs->linkToUserByFingerprint($userFingerprintId, $userId);
    }

    /**
     * @param array<string, mixed> $ev
     * @return array<string, mixed>|null
     */
    public function normalizeEventPayload(mixed $ev, ?float $startTs = null): ?array
    {
        if (!is_array($ev)) {
            $ev = ['event_name' => (string)$ev];
        }

        $normalized = [];
        $normalized['event_name'] = substr((string)($ev['event_name'] ?? ''), 0, 96);
        if ($normalized['event_name'] === '') {
            return null;
        }

        foreach (['element_type' => 32, 'element_label' => 128, 'target_href' => 255] as $k => $limit) {
            if (isset($ev[$k])) {
                $normalized[$k] = substr((string)$ev[$k], 0, $limit);
            }
        }

        if (isset($ev['numeric_value'])) {
            $normalized['numeric_value'] = (float)$ev['numeric_value'];
        }
        if (isset($ev['scroll_percent'])) {
            $normalized['scroll_percent'] = max(0, min(100, (int)$ev['scroll_percent']));
        }
        if (isset($ev['time_offset_ms'])) {
            $normalized['time_offset_ms'] = max(0, (int)$ev['time_offset_ms']);
        } else {
            $normalized['time_offset_ms'] = (int)round((microtime(true) - ($startTs ?? microtime(true))) * 1000);
        }

        if (isset($ev['created']) && !empty($ev['created'])) {
            $normalized['created'] = $ev['created'];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $updateData
     * @return array<string, mixed>
     */
    public function updateAccessLog(int $logId, array $updateData): array
    {
        try {
            if (!$this->accessLogs->exists($logId)) {
                return ['success' => false, 'message' => 'Access log not found'];
            }

            $fields = [];
            if (isset($updateData['scroll_depth'])) {
                $fields['scroll_depth'] = $this->normalizeOptionalInt($updateData['scroll_depth']) ?? 0;
            }
            if (isset($updateData['time_on_page'])) {
                $fields['time_on_page'] = $this->normalizeOptionalInt($updateData['time_on_page']);
            }
            if (isset($updateData['exit_type'])) {
                $fields['exit_type'] = $updateData['exit_type'];
            }

            if (!$this->accessLogs->update($logId, $fields)) {
                return ['success' => false, 'message' => 'Failed to save access log'];
            }

            return ['success' => true, 'message' => 'Access log updated successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()];
        }
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    public function recordEvents(int $accessLogId, array $events): array
    {
        try {
            if ($events === []) {
                return ['success' => true, 'inserted' => 0];
            }

            if (!$this->accessLogs->exists($accessLogId)) {
                return ['success' => false, 'message' => 'Access log not found'];
            }

            $inserted = $this->events->insertMany($accessLogId, $events);

            return ['success' => true, 'inserted' => $inserted];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function recordSingleAccessLogEvent(int $accessLogId, array $event): array
    {
        try {
            if (!$this->accessLogs->exists($accessLogId)) {
                return ['success' => false, 'message' => 'Access log not found'];
            }

            $eventId = $this->events->insertOne($accessLogId, $event);

            return ['success' => true, 'access_log_event_id' => $eventId];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getAccessStats(array $filters = []): array
    {
        try {
            return $this->accessLogs->getStats($filters);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getJourney(string $sessionId): array
    {
        return $this->accessLogs->getJourneyBySession($sessionId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFingerprintById(int $id): ?array
    {
        return $this->fingerprints->findById($id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFingerprintByHash(string $hash): ?array
    {
        return $this->fingerprints->findByHash($hash);
    }

    /**
     * @param array<string, mixed> $fingerprintData
     */
    private function getOrCreateFingerprint(array $fingerprintData): int
    {
        $hash = $this->generateFingerprintHash($fingerprintData);
        $existingId = $this->fingerprints->findIdByHash($hash);
        if ($existingId !== null) {
            return $existingId;
        }

        $language = $fingerprintData['language'] ?? null;
        $timezone = (string)($fingerprintData['timezone'] ?? '');
        if ($this->shouldExcludeNonBrazilTargetFingerprint($language, $timezone)) {
            return 0;
        }

        $plugins = $fingerprintData['plugins_list'] ?? null;
        $fonts = $fingerprintData['fonts_list'] ?? null;

        try {
            return $this->fingerprints->insert([
                'fingerprint_hash' => $hash,
                'screen_resolution' => $fingerprintData['screen_resolution'] ?? null,
                'user_agent' => $fingerprintData['user_agent'] ?? null,
                'ip_address' => $fingerprintData['ip_address'] ?? null,
                'operating_system' => $fingerprintData['operating_system'] ?? null,
                'browser_name' => $fingerprintData['browser_name'] ?? null,
                'browser_version' => $fingerprintData['browser_version'] ?? null,
                'device_type' => $fingerprintData['device_type'] ?? 'unknown',
                'language' => $fingerprintData['language'] ?? null,
                'timezone' => $fingerprintData['timezone'] ?? null,
                'color_depth' => $fingerprintData['color_depth'] ?? null,
                'pixel_ratio' => $fingerprintData['pixel_ratio'] ?? null,
                'touch_support' => $fingerprintData['touch_support'] ?? false,
                'webgl_vendor' => $fingerprintData['webgl_vendor'] ?? null,
                'webgl_renderer' => $fingerprintData['webgl_renderer'] ?? null,
                'canvas_fingerprint' => $fingerprintData['canvas_fingerprint'] ?? null,
                'audio_fingerprint' => $fingerprintData['audio_fingerprint'] ?? null,
                'plugins_list' => is_array($plugins) ? json_encode($plugins) : $plugins,
                'fonts_list' => is_array($fonts) ? json_encode($fonts) : $fonts,
            ]);
        } catch (PDOException $e) {
            $retryId = $this->fingerprints->findIdByHash($hash);
            if ($retryId !== null) {
                return $retryId;
            }
            throw $e;
        }
    }

    private function normalizeOptionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value)) {
            return (int)round($value);
        }
        if (is_string($value) && is_numeric($value)) {
            return (int)round((float)$value);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function generateFingerprintHash(array $data): string
    {
        $hashData = [
            $data['user_agent'] ?? '',
            $data['screen_resolution'] ?? '',
            $data['language'] ?? '',
            $data['timezone'] ?? '',
            $data['color_depth'] ?? '',
            $data['pixel_ratio'] ?? '',
            $data['touch_support'] ?? false,
            $data['webgl_vendor'] ?? '',
            $data['webgl_renderer'] ?? '',
            $data['canvas_fingerprint'] ?? '',
            $data['audio_fingerprint'] ?? '',
            json_encode($data['plugins_list'] ?? []),
            json_encode($data['fonts_list'] ?? []),
        ];

        return hash('sha256', implode('|', $hashData));
    }

    private function extractUtmParams(string $url): array
    {
        $utmParams = [
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'utm_term' => null,
            'utm_content' => null,
        ];

        $parsedUrl = parse_url($url);
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
            foreach ($utmParams as $key => $value) {
                if (isset($queryParams[$key])) {
                    $utmParams[$key] = $queryParams[$key];
                }
            }
        }

        return $utmParams;
    }

    private function getNavigationOrder(?string $sessionId): int
    {
        if (!$sessionId) {
            return 1;
        }

        $last = $this->accessLogs->getMaxNavigationOrder($sessionId);

        return $last > 0 ? $last + 1 : 1;
    }

    private function getPreviousLogId(?string $sessionId): ?int
    {
        if (!$sessionId) {
            return null;
        }

        return $this->accessLogs->getLatestIdBySession($sessionId);
    }

    private function shouldSkipLogging(string $url): bool
    {
        $filteredHosts = $this->settings['filters']['filtered_hosts'] ?? ['deve.meelion.com'];
        foreach ($filteredHosts as $host) {
            if ($host !== '' && str_contains($url, $host)) {
                return true;
            }
        }

        return false;
    }

    private function isBotUserAgent(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        $botPatterns = [
            'googlebot', 'adsbot-google', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot',
            'facebookexternalhit', 'twitterbot', 'linkedinbot', 'whatsapp', 'telegrambot',
            'crawler', 'spider', 'bot', 'scraper', 'curl', 'wget', 'python-requests', 'python-urllib',
            'java/', 'go-http-client', 'node-fetch', 'axios',
            'pingdom', 'uptimerobot', 'newrelic', 'datadog', 'nagios', 'zabbix',
            'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'rogerbot', 'exabot', 'ia_archiver',
            'headlesschrome', 'phantomjs', 'selenium', 'webdriver', 'chrome-lighthouse',
            '(compatible; googleother)', 'android 10; k)', 'genspark_flutter',
        ];

        $ua = strtolower($userAgent);
        foreach ($botPatterns as $pattern) {
            if (str_contains($ua, $pattern)) {
                return true;
            }
        }

        if (preg_match('/chrome\/(\d+)[.\d]*/i', $userAgent, $m) && (int)$m[1] < 60) {
            return true;
        }

        if (preg_match('/iphone\s+os\s+2[6-9]_|iphone\s+os\s+[3-9]\d_/i', $userAgent)) {
            return true;
        }

        return false;
    }

    private function isBotTimezone(string $timezone): bool
    {
        if ($timezone === '') {
            return false;
        }

        $suspicious = [
            'Asia/Shanghai', 'Asia/Calcutta', 'Asia/Singapore', 'Asia/Hong_Kong',
            'Etc/Unknown', 'UTC', 'Etc/GMT+3', 'Europe/Moscow',
        ];

        return in_array($timezone, $suspicious, true);
    }

    private function shouldExcludeNonBrazilTargetFingerprint(?string $language, ?string $timezone): bool
    {
        if (!($this->settings['filters']['geo_brazil_only'] ?? false)) {
            return false;
        }

        $langNorm = strtolower(trim(str_replace('_', '-', (string)$language)));
        if (str_starts_with($langNorm, 'zh')) {
            return true;
        }

        $isEnUs = ($langNorm === 'en-us' || str_starts_with($langNorm, 'en-us-'));
        if (!$isEnUs) {
            return false;
        }

        $tz = trim((string)$timezone);
        if ($tz === '') {
            return true;
        }
        if (in_array($tz, self::BRAZIL_IANA_TIMEZONES, true)) {
            return false;
        }
        if (str_starts_with($tz, 'Europe/')) {
            return false;
        }

        return true;
    }
}
