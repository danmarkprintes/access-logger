import { test, expect } from '@playwright/test';
import { API, assertApiUp, humanFingerprint } from './helpers';

test.describe.configure({ mode: 'serial' });

test.describe('Access Logger API — 7 endpoints', () => {
  let logId: number;
  let sessionId: string;
  let fingerprintId: number;

  test.beforeAll(async ({ request }) => {
    await assertApiUp(request);
  });

  test('POST /api/access-log — pageview + fingerprint', async ({ request }) => {
    sessionId = `pw-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const res = await request.post(API, {
      data: {
        url: 'https://demo.copa2026.test/?utm_source=playwright&utm_medium=e2e&utm_campaign=copa',
        session_id: sessionId,
        referer: 'https://google.com/',
        page_load_time: 512,
        viewport_width: 1280,
        viewport_height: 720,
        fingerprint: humanFingerprint(),
      },
    });

    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.success).toBe(true);
    expect(body.skipped).toBeFalsy();
    expect(body.log_id).toBeGreaterThan(0);
    logId = body.log_id;
  });

  test('POST /api/access-log — bot é ignorado (skipped)', async ({ request }) => {
    const res = await request.post(API, {
      data: {
        url: 'https://demo.copa2026.test/bot',
        session_id: 'pw-bot-session',
        fingerprint: humanFingerprint({
          user_agent: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        }),
      },
    });

    const body = await res.json();
    expect(res.status()).toBe(200);
    expect(body.success).toBe(true);
    expect(body.skipped).toBe(true);
    expect(body.log_id).toBeNull();
  });

  test('POST /api/access-log/update — scroll e tempo', async ({ request }) => {
    const res = await request.post(`${API}/update`, {
      data: {
        log_id: logId,
        scroll_depth: 640,
        time_on_page: 28,
        exit_type: 'navigation',
      },
    });

    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.success).toBe(true);
  });

  test('PUT /api/access-log/update — mesmo contrato', async ({ request }) => {
    const res = await request.put(`${API}/update`, {
      data: {
        log_id: logId,
        scroll_depth: 800,
        time_on_page: 35,
      },
    });

    expect(res.status()).toBe(200);
    expect((await res.json()).success).toBe(true);
  });

  test('POST /api/access-log/events — lote', async ({ request }) => {
    const res = await request.post(`${API}/events`, {
      data: {
        access_log_id: logId,
        events: [
          {
            event_name: 'button_click',
            element_type: 'button',
            element_label: 'favoritar_brasil',
            time_offset_ms: 1200,
          },
          {
            event_name: 'scroll',
            scroll_percent: 45,
            time_offset_ms: 2400,
          },
        ],
      },
    });

    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.success).toBe(true);
    expect(body.inserted).toBe(2);
  });

  test('POST /api/access-log/event — único', async ({ request }) => {
    const res = await request.post(`${API}/event`, {
      data: {
        access_log_id: logId,
        event: {
          event_name: 'goal_live',
          element_type: 'widget',
          element_label: 'placar_ao_vivo',
          numeric_value: 1,
        },
      },
    });

    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.success).toBe(true);
    expect(body.access_log_event_id).toBeGreaterThan(0);
  });

  test('GET /api/access-log/stats', async ({ request }) => {
    const res = await request.get(`${API}/stats`);
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.success).toBe(true);
    expect(body.data.total_access).toBeGreaterThan(0);
    expect(body.data.unique_users).toBeGreaterThan(0);
  });

  test('GET /api/access-log/journey', async ({ request }) => {
    const res = await request.get(`${API}/journey`, {
      params: { session_id: sessionId },
    });

    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.success).toBe(true);
    expect(body.data.length).toBeGreaterThanOrEqual(1);
    expect(body.data[0].session_id).toBe(sessionId);
    fingerprintId = body.data[0].user_fingerprint_id;
    expect(fingerprintId).toBeGreaterThan(0);
  });

  test('GET /api/access-log/fingerprint', async ({ request }) => {
    const res = await request.get(`${API}/fingerprint`, {
      params: { fingerprint_id: fingerprintId },
    });

    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.success).toBe(true);
    expect(body.data.id).toBe(fingerprintId);
    expect(body.data.fingerprint_hash).toBeTruthy();
  });

  test('GET /api/access-log/fingerprint — 404 inexistente', async ({ request }) => {
    const res = await request.get(`${API}/fingerprint`, {
      params: { fingerprint_id: 999999999 },
    });
    expect(res.status()).toBe(404);
    expect((await res.json()).success).toBe(false);
  });

  test('POST /api/access-log — validação url obrigatória', async ({ request }) => {
    const res = await request.post(API, {
      data: { fingerprint: humanFingerprint() },
    });
    expect(res.status()).toBe(400);
  });
});
