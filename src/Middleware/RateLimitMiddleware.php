<?php

declare(strict_types=1);

namespace AccessLogger\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

final class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param array{
     *   ip_per_minute?: int,
     *   ua_per_minute?: int,
     *   storage_path?: string
     * } $config
     */
    public function __construct(
        private readonly array $config = []
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/api/access-log')) {
            return $handler->handle($request);
        }

        $method = $request->getMethod();
        if (!in_array($method, ['GET', 'POST', 'PUT'], true)) {
            return $handler->handle($request);
        }

        $ip = $this->clientIp($request);
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return $handler->handle($request);
        }

        $ipLimit = (int)($this->config['ip_per_minute'] ?? 20);
        $uaLimit = (int)($this->config['ua_per_minute'] ?? 40);
        $bucket = (int)floor(time() / 60);

        $ua = trim($request->getHeaderLine('User-Agent'));
        $uaToken = $ua === '' ? '(empty)' : substr($ua, 0, 512);

        $ipKey = 'ip:' . hash('sha256', $ip) . ':' . $bucket;
        $uaKey = 'ua:' . hash('sha256', $uaToken) . ':' . $bucket;

        $ipCount = $this->increment($ipKey);
        $uaCount = $this->increment($uaKey);

        if ($ipCount > $ipLimit || $uaCount > $uaLimit) {
            $response = new \Slim\Psr7\Response(429);
            $response->getBody()->write((string)json_encode([
                'success' => false,
                'message' => 'Too many requests',
            ]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', '60');
        }

        return $handler->handle($request);
    }

    private function increment(string $key): int
    {
        if (extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled()) {
            $success = false;
            $count = apcu_inc('accesslogger:rl:' . $key, 1, $success, 120);

            if ($success && $count !== false) {
                return (int)$count;
            }
        }

        return $this->incrementFile($key);
    }

    private function incrementFile(string $key): int
    {
        $dir = $this->config['storage_path'] ?? '/tmp/access-logger-rl';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $file = $dir . '/' . hash('sha256', $key) . '.cnt';
        $count = 0;
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $count = is_string($raw) ? (int)$raw : 0;
        }
        ++$count;
        @file_put_contents($file, (string)$count, LOCK_EX);

        return $count;
    }

    private function clientIp(Request $request): string
    {
        $params = $request->getServerParams();
        $ip = $params['REMOTE_ADDR'] ?? '0.0.0.0';

        return is_string($ip) ? $ip : '0.0.0.0';
    }
}
