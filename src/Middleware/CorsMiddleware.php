<?php

declare(strict_types=1);

namespace AccessLogger\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

final class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param array{allowed_origins?: list<string>} $config
     */
    public function __construct(
        private readonly array $config = []
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $origin = $request->getHeaderLine('Origin');
        $allowed = $this->resolveAllowedOrigin($origin);

        if ($request->getMethod() === 'OPTIONS') {
            $response = new \Slim\Psr7\Response(204);
        } else {
            $response = $handler->handle($request);
        }

        if ($allowed !== null) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $allowed)
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
                ->withHeader('Access-Control-Max-Age', '86400');
        }

        return $response;
    }

    private function resolveAllowedOrigin(string $origin): ?string
    {
        $allowedOrigins = $this->config['allowed_origins'] ?? ['*'];

        if (in_array('*', $allowedOrigins, true)) {
            return $origin !== '' ? $origin : '*';
        }

        if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            return $origin;
        }

        return $allowedOrigins[0] ?? null;
    }
}
