<?php

declare(strict_types=1);

namespace AccessLogger;

use AccessLogger\Middleware\CorsMiddleware;
use AccessLogger\Middleware\RateLimitMiddleware;
use AccessLogger\Middleware\TrailingSlashMiddleware;
use AccessLogger\Repository\PdoConnection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App as SlimApp;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
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

        $app->add(new TrailingSlashMiddleware());
        $app->add(new CorsMiddleware($settings['cors'] ?? []));
        $app->add(new RateLimitMiddleware($settings['rate_limit'] ?? []));

        $displayDetails = (bool)($settings['display_error_details'] ?? false);
        $errorMiddleware = $app->addErrorMiddleware($displayDetails, true, true);

        $jsonError = static function (
            SlimApp $app,
            int $status,
            string $message,
            ?Throwable $exception = null,
            bool $displayErrorDetails = false
        ): Response {
            $response = $app->getResponseFactory()->createResponse($status);
            $payload = ['success' => false, 'message' => $message];
            if ($displayErrorDetails && $exception !== null) {
                $payload['error'] = $exception->getMessage();
            }
            $response->getBody()->write((string)json_encode($payload));

            return $response->withHeader('Content-Type', 'application/json');
        };

        $errorMiddleware->setErrorHandler(
            HttpNotFoundException::class,
            static function (
                Request $request,
                Throwable $exception,
                bool $displayErrorDetails,
                bool $logErrors,
                bool $logErrorDetails
            ) use ($app, $jsonError, $displayDetails): Response {
                return $jsonError($app, 404, 'Not found', $exception, $displayDetails);
            }
        );

        $errorMiddleware->setErrorHandler(
            HttpMethodNotAllowedException::class,
            static function (
                Request $request,
                Throwable $exception,
                bool $displayErrorDetails,
                bool $logErrors,
                bool $logErrorDetails
            ) use ($app, $jsonError, $displayDetails): Response {
                return $jsonError($app, 405, 'Method not allowed', $exception, $displayDetails);
            }
        );

        $errorMiddleware->setDefaultErrorHandler(
            static function (
                Request $request,
                Throwable $exception,
                bool $displayErrorDetails
            ) use ($app, $jsonError): Response {
                return $jsonError($app, 500, 'Internal server error', $exception, $displayErrorDetails);
            }
        );

        $pdo = PdoConnection::fromSettings($settings['db'] ?? []);

        Routes::register($app, $settings, $pdo);
    }
}
