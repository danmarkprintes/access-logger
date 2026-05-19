<?php

declare(strict_types=1);

namespace AccessLogger\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Fase 2: stub de ingestão. Persistência real na fase 3.
 */
final class AccessLogController
{
    public function logAccess(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            return $this->json($response, 400, [
                'success' => false,
                'message' => 'Invalid JSON body',
            ]);
        }

        $url = trim((string)($data['url'] ?? ''));
        if ($url === '') {
            return $this->json($response, 400, [
                'success' => false,
                'message' => 'URL is required',
            ]);
        }

        $sessionId = trim((string)($data['session_id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = $this->uuidV4();
        }

        // Stub: IDs fictícios até AccessLogService (fase 3).
        $logId = random_int(1, 999999);

        return $this->json($response, 200, [
            'success' => true,
            'log_id' => $logId,
            'session_id' => $sessionId,
            'stub' => true,
            'message' => 'Phase 2 stub — persistence in phase 3',
        ]);
    }

    public function notImplemented(Request $request, Response $response): Response
    {
        return $this->json($response, 501, [
            'success' => false,
            'message' => 'Not implemented yet (phase 3)',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(Response $response, int $status, array $data): Response
    {
        $response->getBody()->write((string)json_encode($data));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
