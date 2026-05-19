<?php

declare(strict_types=1);

namespace AccessLogger;

use AccessLogger\Middleware\CorsMiddleware;
use AccessLogger\Middleware\RateLimitMiddleware;
use AccessLogger\Repository\PdoConnection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App as SlimApp;
use Throwable;

final class App
{
    /**
     * @param array<string, mixed> $settings
     */
    public static function register(SlimApp $app, array $settings): void
    {
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        $app->add(new CorsMiddleware($settings['cors'] ?? []));
        $app->add(new RateLimitMiddleware($settings['rate_limit'] ?? []));

        $errorMiddleware = $app->addErrorMiddleware(
            (bool)($settings['display_error_details'] ?? false),
            true,
            true
        );

        $errorMiddleware->setDefaultErrorHandler(
            static function (
                Request $request,
                Throwable $exception,
                bool $displayErrorDetails
            ) use ($app): Response {
                $response = $app->getResponseFactory()->createResponse(500);
                $payload = [
                    'success' => false,
                    'message' => 'Internal server error',
                ];
                if ($displayErrorDetails) {
                    $payload['error'] = $exception->getMessage();
                }

                $response->getBody()->write((string)json_encode($payload));

                return $response->withHeader('Content-Type', 'application/json');
            }
        );

        $pdo = PdoConnection::fromSettings($settings['db'] ?? []);

        Routes::register($app, $settings, $pdo);
    }
}
