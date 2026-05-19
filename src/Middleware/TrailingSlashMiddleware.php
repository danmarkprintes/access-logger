<?php

declare(strict_types=1);

namespace AccessLogger\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Redireciona /path/ → /path (exceto raiz "/") para bater com rotas Slim.
 */
final class TrailingSlashMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $uri = $request->getUri();
        $path = $uri->getPath();

        if ($path !== '/' && str_ends_with($path, '/')) {
            $target = rtrim($path, '/') ?: '/';
            $location = (string)$uri->withPath($target);

            return (new SlimResponse(301))->withHeader('Location', $location);
        }

        return $handler->handle($request);
    }
}
