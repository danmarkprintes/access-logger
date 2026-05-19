<?php

declare(strict_types=1);

namespace AccessLogger;

use AccessLogger\Controller\AccessLogController;
use AccessLogger\Controller\HealthController;
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
        $accessLog = new AccessLogController();

        $app->get('/health', [$health, 'check']);

        $app->group('/api/access-log', function (RouteCollectorProxy $group) use ($accessLog): void {
            $group->post('', [$accessLog, 'logAccess']);
            $group->post('/update', [$accessLog, 'notImplemented']);
            $group->put('/update', [$accessLog, 'notImplemented']);
            $group->post('/events', [$accessLog, 'notImplemented']);
            $group->post('/event', [$accessLog, 'notImplemented']);
            $group->get('/stats', [$accessLog, 'notImplemented']);
            $group->get('/journey', [$accessLog, 'notImplemented']);
            $group->get('/fingerprint', [$accessLog, 'notImplemented']);
        });
    }
}
