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
            'phase' => 3,
            'database' => $dbOk ? 'connected' : 'unavailable',
            'timestamp' => gmdate('c'),
        ];

        if ($this->wantsHtml($request)) {
            return $this->htmlResponse($response, $payload, $dbOk);
        }

        $response->getBody()->write((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function htmlResponse(Response $response, array $payload, bool $dbOk): Response
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $dbClass = $dbOk ? 'ok' : 'fail';
        $dbLabel = $dbOk ? 'Conectado' : 'Indisponível';
        $service = htmlspecialchars((string)$payload['service'], ENT_QUOTES, 'UTF-8');
        $phase = (int)$payload['phase'];
        $timestamp = htmlspecialchars((string)$payload['timestamp'], ENT_QUOTES, 'UTF-8');
        $jsonSafe = htmlspecialchars((string)$json, ENT_NOQUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Health — Access Logger</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 36rem; margin: 2rem auto; padding: 0 1rem; color: #1a1a1a; line-height: 1.5; }
    h1 { font-size: 1.35rem; margin-bottom: 0.25rem; }
    .status { display: inline-block; padding: 0.35rem 0.75rem; border-radius: 6px; font-weight: 600; margin: 0.5rem 0 1rem; }
    .status.ok { background: #d4edda; color: #155724; }
    .status.fail { background: #f8d7da; color: #721c24; }
    dl { display: grid; grid-template-columns: 8rem 1fr; gap: 0.35rem 0.75rem; }
    dt { font-weight: 600; color: #555; }
    pre { background: #f4f4f4; padding: 0.75rem; border-radius: 8px; overflow: auto; font-size: 0.85rem; }
    a { color: #0066cc; }
  </style>
</head>
<body>
  <h1>Access Logger — Health</h1>
  <p class="status ok">Serviço no ar</p>
  <dl>
    <dt>Serviço</dt><dd>{$service}</dd>
    <dt>Fase</dt><dd>{$phase}</dd>
    <dt>MySQL</dt><dd><span class="status {$dbClass}">{$dbLabel}</span></dd>
    <dt>UTC</dt><dd>{$timestamp}</dd>
  </dl>
  <p><a href="/">← Início</a> · <a href="/demo/world-cup-2026/">Demo Copa 2026</a> · <a href="/health?format=json">JSON</a></p>
  <h2>Resposta JSON</h2>
  <pre>{$jsonSafe}</pre>
</body>
</html>
HTML;

        $response->getBody()->write($html);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function wantsHtml(Request $request): bool
    {
        $format = $request->getQueryParams()['format'] ?? '';
        if ($format === 'json') {
            return false;
        }
        if ($format === 'html') {
            return true;
        }

        $accept = strtolower($request->getHeaderLine('Accept'));
        if ($accept === '' || $accept === '*/*') {
            return false;
        }

        return str_contains($accept, 'text/html');
    }
}
