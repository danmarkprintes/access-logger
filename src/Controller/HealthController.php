<?php

declare(strict_types=1);

namespace AccessLogger\Controller;

use AccessLogger\Repository\PdoConnection;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthController
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function check(Request $request, Response $response): Response
    {
        $dbOk = PdoConnection::ping($this->pdo);

        $payload = [
            'ok' => true,
            'service' => 'access-logger',
            'phase' => 2,
            'database' => $dbOk ? 'connected' : 'unavailable',
        ];

        $response->getBody()->write((string)json_encode($payload));

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json');
    }
}
