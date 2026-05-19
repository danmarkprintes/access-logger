<?php

declare(strict_types=1);

namespace AccessLogger\Tests;

use AccessLogger\Repository\AccessLogEventRepository;
use AccessLogger\Repository\AccessLogRepository;
use AccessLogger\Repository\FingerprintRepository;
use AccessLogger\Repository\PdoConnection;
use AccessLogger\Service\AccessLogService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccessLogServiceTest extends TestCase
{
    private static ?PDO $pdo = null;

    private AccessLogService $service;

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;port=3307;dbname=access_logger;charset=utf8mb4';
        try {
            self::$pdo = PdoConnection::fromSettings([
                'dsn' => $dsn,
                'user' => getenv('DB_USER') ?: 'root',
                'pass' => getenv('DB_PASS') ?: 'root',
            ]);
            self::$pdo->exec('DELETE FROM access_log_events');
            self::$pdo->exec('DELETE FROM access_logs');
            self::$pdo->exec('DELETE FROM user_fingerprints');
        } catch (\Throwable $e) {
            self::$pdo = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestSkipped('MySQL not available (start docker compose in access-logger)');
        }

        self::$pdo->exec('DELETE FROM access_log_events');
        self::$pdo->exec('DELETE FROM access_logs');
        self::$pdo->exec('DELETE FROM user_fingerprints');

        $this->service = new AccessLogService(
            new FingerprintRepository(self::$pdo),
            new AccessLogRepository(self::$pdo),
            new AccessLogEventRepository(self::$pdo),
            ['filters' => ['geo_brazil_only' => false, 'filtered_hosts' => []]]
        );
    }

    public function testLogAccessPersistsRow(): void
    {
        $sessionId = 'test-session-' . bin2hex(random_bytes(4));
        $result = $this->service->logAccess([
            'url' => 'https://example.com/page?utm_source=test',
            'session_id' => $sessionId,
            'fingerprint' => $this->humanFingerprint(),
            'referer' => 'direct',
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('skipped', $result);
        $this->assertGreaterThan(0, $result['access_log_id']);

        $stmt = self::$pdo->prepare('SELECT COUNT(*) AS c FROM access_logs WHERE session_id = :sid');
        $stmt->execute(['sid' => $sessionId]);
        $this->assertSame(1, (int)$stmt->fetch()['c']);
    }

    public function testBotUserAgentIsSkipped(): void
    {
        $result = $this->service->logAccess([
            'url' => 'https://example.com/bot',
            'session_id' => 'bot-session',
            'fingerprint' => array_merge($this->humanFingerprint(), [
                'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            ]),
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertSame(0, (int)self::$pdo->query('SELECT COUNT(*) FROM access_logs')->fetchColumn());
    }

    public function testUpdateAccessLog(): void
    {
        $log = $this->service->logAccess([
            'url' => 'https://example.com/update',
            'session_id' => 'upd-session',
            'fingerprint' => $this->humanFingerprint(),
        ]);
        $logId = (int)$log['access_log_id'];

        $update = $this->service->updateAccessLog($logId, [
            'scroll_depth' => 80,
            'time_on_page' => 42,
            'exit_type' => 'navigation',
        ]);

        $this->assertTrue($update['success']);

        $row = self::$pdo->query("SELECT scroll_depth, time_on_page, exit_type FROM access_logs WHERE id = {$logId}")
            ->fetch();
        $this->assertSame(80, (int)$row['scroll_depth']);
        $this->assertSame(42, (int)$row['time_on_page']);
        $this->assertSame('navigation', $row['exit_type']);
    }

    public function testRecordEvents(): void
    {
        $log = $this->service->logAccess([
            'url' => 'https://example.com/events',
            'session_id' => 'ev-session',
            'fingerprint' => $this->humanFingerprint(),
        ]);
        $logId = (int)$log['access_log_id'];

        $result = $this->service->recordEvents($logId, [
            ['event_name' => 'click', 'element_type' => 'button', 'element_label' => 'CTA'],
            ['event_name' => 'scroll', 'scroll_percent' => 50],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['inserted']);

        $count = (int)self::$pdo
            ->query("SELECT COUNT(*) FROM access_log_events WHERE access_log_id = {$logId}")
            ->fetchColumn();
        $this->assertSame(2, $count);
    }

    /**
     * @return array<string, mixed>
     */
    private function humanFingerprint(): array
    {
        return [
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            'screen_resolution' => '1920x1080',
            'language' => 'pt-BR',
            'timezone' => 'America/Sao_Paulo',
            'color_depth' => 24,
            'pixel_ratio' => 1,
            'touch_support' => false,
            'device_type' => 'desktop',
            'browser_name' => 'Chrome',
            'browser_version' => '120',
            'operating_system' => 'Windows',
        ];
    }
}
