<?php

declare(strict_types=1);

namespace AccessLogger;

use AccessLogger\Controller\AccessLogController;
use AccessLogger\Controller\HealthController;
use AccessLogger\Repository\AccessLogEventRepository;
use AccessLogger\Repository\AccessLogRepository;
use AccessLogger\Repository\FingerprintRepository;
use AccessLogger\Service\AccessLogService;
use PDO;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

final class Routes
{
    /**
     * @param array<string, mixed> $settings
     */
    public static function register(App $app, array $settings, PDO $pdo): void
    {
        $health = new HealthController($pdo);

        $fingerprints = new FingerprintRepository($pdo);
        $accessLogs = new AccessLogRepository($pdo);
        $events = new AccessLogEventRepository($pdo);
        $accessLogService = new AccessLogService(
            $fingerprints,
            $accessLogs,
            $events,
            $settings
        );
        $accessLog = new AccessLogController($accessLogService);

        $app->get('/health', [$health, 'check']);

        $app->group('/api/access-log', function (RouteCollectorProxy $group) use ($accessLog): void {
            $group->post('', [$accessLog, 'logAccess']);
            $group->post('/update', [$accessLog, 'update']);
            $group->put('/update', [$accessLog, 'update']);
            $group->post('/events', [$accessLog, 'events']);
            $group->post('/event', [$accessLog, 'recordEvent']);
            $group->get('/stats', [$accessLog, 'stats']);
            $group->get('/journey', [$accessLog, 'journey']);
            $group->get('/fingerprint', [$accessLog, 'fingerprint']);
        });
    }
}
