import type { APIRequestContext } from '@playwright/test';

export const API = '/api/access-log';

export function humanFingerprint(overrides: Record<string, unknown> = {}) {
  return {
    user_agent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 PlaywrightTest Chrome/120.0.0.0',
    screen_resolution: '1280x720',
    language: 'pt-BR',
    timezone: 'America/Sao_Paulo',
    device_type: 'desktop',
    browser_name: 'Chrome',
    browser_version: '120',
    operating_system: 'Windows',
    color_depth: 24,
    pixel_ratio: 1,
    touch_support: false,
    ...overrides,
  };
}

export async function assertApiUp(request: APIRequestContext): Promise<void> {
  const health = await request.get('/health');
  if (!health.ok()) {
    throw new Error(
      'Access Logger não está em http://localhost:8088 — rode: docker compose up -d (na pasta access-logger)'
    );
  }
}
