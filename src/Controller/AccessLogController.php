<?php

declare(strict_types=1);

namespace AccessLogger\Controller;

use AccessLogger\Service\AccessLogService;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AccessLogController
{
    public function __construct(
        private readonly AccessLogService $accessLogService
    ) {
    }

    public function logAccess(Request $request, Response $response): Response
    {
        try {
            $data = $this->body($request);
            if ($data === null) {
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

            if (empty($data['session_id'])) {
                $data['session_id'] = $this->uuidV4();
            }

            if (!isset($data['is_authenticated'])) {
                $data['is_authenticated'] = !empty($data['user_id']);
            }

            $data['ip_address'] = $this->clientIp($request);

            if (!isset($data['referer']) || $data['referer'] === '' || $data['referer'] === null) {
                $data['referer'] = $request->getHeaderLine('Referer') ?: 'direct';
            }

            $result = $this->accessLogService->logAccess($data);

            if (!($result['success'] ?? false)) {
                return $this->json($response, 500, [
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to log access',
                ]);
            }

            if (!empty($result['skipped'])) {
                return $this->json($response, 200, [
                    'success' => true,
                    'skipped' => true,
                    'reason' => $result['reason'] ?? 'skipped',
                    'log_id' => null,
                    'session_id' => $data['session_id'],
                ]);
            }

            return $this->json($response, 200, [
                'success' => true,
                'log_id' => $result['access_log_id'],
                'session_id' => $data['session_id'],
            ]);
        } catch (Exception) {
            return $this->json($response, 500, [
                'success' => false,
                'message' => 'Internal server error',
            ]);
        }
    }

    public function update(Request $request, Response $response): Response
    {
        try {
            $data = $this->body($request);
            if ($data === null) {
                return $this->json($response, 400, [
                    'success' => false,
                    'message' => 'Invalid JSON body',
                ]);
            }

            $logId = (int)($data['log_id'] ?? 0);
            if ($logId <= 0) {
                return $this->json($response, 400, [
                    'success' => false,
                    'message' => 'Log ID is required',
                ]);
            }

            $result = $this->accessLogService->updateAccessLog($logId, $data);

            if ($result['success'] ?? false) {
                return $this->json($response, 200, [
                    'success' => true,
                    'message' => $result['message'] ?? 'Access log updated successfully',
                ]);
            }

            $message = (string)($result['message'] ?? 'Access log not found');
            $status = str_contains(strtolower($message), 'not found') ? 404 : 500;

            return $this->json($response, $status, [
                'success' => false,
                'message' => $message,
            ]);
        } catch (Exception) {
            return $this->json($response, 500, [
                'success' => false,
                'message' => 'Internal server error',
            ]);
        }
    }

    public function events(Request $request, Response $response): Response
    {
        try {
            $data = $this->body($request);
            if ($data === null) {
                return $this->json($response, 400, [
                    'success' => false,
                    'message' => 'Invalid JSON body',
                ]);
            }

            $accessLogId = (int)($data['access_log_id'] ?? 0);
            $events = $data['events'] ?? [];

            if ($accessLogId <= 0) {
                return $this->json($response, 400, [
                    'success' => false,
                    'message' => 'access_log_id is required',
                ]);
            }

            if (!is_array($events) || $events === []) {
                return $this->json($response, 400, [
                    'success' => false,
                    'message' => 'events must be a non-empty array',
                ]);
            }

            if (count($events) > 100) {
                $events = array_slice($events, 0, 100);
            }

            $normalized = [];
            $startTs = microtime(true);
            foreach ($events as $ev) {
                $event = $this->accessLogService->normalizeEventPayload($ev, $startTs);
                if ($event !== null) {
                    $normalized[] = $event;
                }
            }

            $result = $this->accessLogService->recordEvents($accessLogId, $normalized);
            $status = ($result['success'] ?? false) ? 200 : 500;

            return $this->json($response, $status, $result);
        } catch (Exception) {
            return $this->json($response, 500, [
                'success' => false,
                'message' => 'Internal server error',
            ]);
        }
    }

    public function recordEvent(Request $request, Response $response): Response
    {
        try {
            $data = $this->body($request);
            if ($data === null) {
                return $this->json($response, 400, [
                    'success' => false,
                    'message' => 'Invalid JSON body',
                ]);
            }

            $accessLogId = (int)($data['access_log_id'] ?? 0);
            if ($accessLogId <= 0) {
                return $this->json($response, 400, [
                    'success' => false,
                    'message' => 'access_log_id is required',
                ]);
            }

            $payload = $data['event'] ?? $data;
            if (!is_array($payload)) {
                return $this->json($response, 400, [
                    'success' => false,
                    'message' => 'event payload must be an object',
                ]);
            }

            unset($payload['access_log_id'], $payload['event']);
            $normalized = $this->accessLogService->normalizeEventPayload($payload, microtime(true));
            if ($normalized === null) {
                return $this->json($response, 400, [
                    'success' => false,
                    'message' => 'event_name is required',
                ]);
            }

            $result = $this->accessLogService->recordSingleAccessLogEvent($accessLogId, $normalized);

            return $this->responseFromServiceResult($response, $result);
        } catch (Exception) {
            return $this->json($response, 500, [
                'success' => false,
                'message' => 'Internal server error',
            ]);
        }
    }

    public function stats(Request $request, Response $response): Response
    {
        try {
            $filters = $request->getQueryParams();
            $stats = $this->accessLogService->getAccessStats($filters);

            return $this->json($response, 200, [
                'success' => true,
                'data' => $stats,
            ]);
        } catch (Exception) {
            return $this->json($response, 500, [
                'success' => false,
                'message' => 'Internal server error',
            ]);
        }
    }

    public function journey(Request $request, Response $response): Response
    {
        try {
            $sessionId = trim((string)($request->getQueryParams()['session_id'] ?? ''));
            if ($sessionId === '') {
                return $this->json($response, 400, [
                    'success' => false,
                    'message' => 'Session ID is required',
                ]);
            }

            $journey = $this->accessLogService->getJourney($sessionId);

            return $this->json($response, 200, [
                'success' => true,
                'data' => $journey,
            ]);
        } catch (Exception) {
            return $this->json($response, 500, [
                'success' => false,
                'message' => 'Internal server error',
            ]);
        }
    }

    public function fingerprint(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $fingerprintId = isset($params['fingerprint_id']) ? (int)$params['fingerprint_id'] : 0;
            $fingerprintHash = trim((string)($params['fingerprint_hash'] ?? ''));

            if ($fingerprintId <= 0 && $fingerprintHash === '') {
                return $this->json($response, 400, [
                    'success' => false,
                    'message' => 'Fingerprint ID or hash is required',
                ]);
            }

            $fingerprint = $fingerprintId > 0
                ? $this->accessLogService->getFingerprintById($fingerprintId)
                : $this->accessLogService->getFingerprintByHash($fingerprintHash);

            if ($fingerprint === null) {
                return $this->json($response, 404, [
                    'success' => false,
                    'message' => 'Fingerprint not found',
                ]);
            }

            return $this->json($response, 200, [
                'success' => true,
                'data' => $fingerprint,
            ]);
        } catch (Exception) {
            return $this->json($response, 500, [
                'success' => false,
                'message' => 'Internal server error',
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function body(Request $request): ?array
    {
        $data = $request->getParsedBody();
        if (is_array($data)) {
            return $data;
        }

        $raw = (string)$request->getBody();
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function clientIp(Request $request): string
    {
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            $parts = array_map('trim', explode(',', $xff));

            return $parts[0];
        }

        $serverParams = $request->getServerParams();

        return (string)($serverParams['REMOTE_ADDR'] ?? '');
    }

    /**
     * @param array<string, mixed> $result
     */
    private function responseFromServiceResult(Response $response, array $result): Response
    {
        if (!($result['success'] ?? false)) {
            $message = (string)($result['message'] ?? 'Failed');
            $status = str_contains(strtolower($message), 'not found') ? 404 : 400;

            return $this->json($response, $status, $result);
        }

        return $this->json($response, 200, $result);
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
