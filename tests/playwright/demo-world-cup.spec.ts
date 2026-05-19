import { test, expect } from '@playwright/test';
import { assertApiUp } from './helpers';

const DEMO = '/demo/world-cup-2026';

test.describe.configure({ mode: 'serial' });

test.describe('Demo Copa 2026 + Access Logger no browser', () => {
  test.beforeAll(async ({ request }) => {
    await assertApiUp(request);
  });

  test('pageview inicial com UTM (POST /api/access-log)', async ({ page }) => {
    const logResponse = page.waitForResponse(
      (r) => r.url().includes('/api/access-log') && r.request().method() === 'POST' && !r.url().includes('/update')
    );

    await page.goto(`${DEMO}/index.html?utm_source=copa2026&utm_medium=demo&utm_campaign=playwright`);

    const res = await logResponse;
    expect(res.ok()).toBeTruthy();
    const body = await res.json();
    expect(body.success).toBe(true);
    expect(body.log_id).toBeGreaterThan(0);

    await expect(page.locator('#state')).toContainText(body.session_id ?? '');
  });

  test('jornada multi-página (navigation_order crescente)', async ({ page }) => {
    await page.goto(`${DEMO}/index.html?utm_source=copa2026&utm_medium=demo`);

    await page.getByRole('link', { name: /fase de grupos/i }).click();
    await page.waitForResponse((r) => r.url().includes('/api/access-log') && r.request().method() === 'POST');

    await page.getByRole('link', { name: /Mata-mata/i }).click();
    await page.waitForResponse((r) => r.url().includes('/api/access-log') && r.request().method() === 'POST');

    await page.getByRole('link', { name: /Grande final/i }).click();
    await page.waitForResponse((r) => r.url().includes('/api/access-log') && r.request().method() === 'POST');

    const sessionId = await page.evaluate(() => sessionStorage.getItem('access_logger_session_id'));
    expect(sessionId).toBeTruthy();

    const journeyRes = await page.request.get('/api/access-log/journey', {
      params: { session_id: sessionId! },
    });
    const journey = await journeyRes.json();
    expect(journey.success).toBe(true);
    expect(journey.data.length).toBeGreaterThanOrEqual(3);

    const orders = journey.data.map((row: { navigation_order: number }) => row.navigation_order);
    expect(orders).toEqual([...orders].sort((a, b) => a - b));
  });

  test('engajamento — update manual (POST /update)', async ({ page }) => {
    await page.goto(`${DEMO}/engajamento.html`);
    await page.waitForResponse((r) => r.url().includes('/api/access-log') && r.request().method() === 'POST');

    const updatePromise = page.waitForResponse(
      (r) => r.url().includes('/api/access-log/update') && r.request().method() === 'POST'
    );

    await page.getByTestId('force-update').click();
    const updateRes = await updatePromise;
    expect(updateRes.ok()).toBeTruthy();
    expect((await updateRes.json()).success).toBe(true);
  });

  test('eventos — lote e único', async ({ page }) => {
    await page.goto(`${DEMO}/eventos.html`);
    await page.waitForResponse((r) => r.url().includes('/api/access-log') && r.request().method() === 'POST');
    await page.waitForFunction(() => (window as unknown as { accessLogger?: { currentLogId: number } }).accessLogger?.currentLogId);

    const batchPromise = page.waitForResponse(
      (r) => r.url().includes('/api/access-log/events') && r.request().method() === 'POST',
      { timeout: 15_000 }
    );
    await page.getByTestId('evt-favorito').click();
    await page.getByTestId('evt-ingresso').click();
    const batchRes = await batchPromise;
    expect(batchRes.ok(), await batchRes.text()).toBeTruthy();
    const batch = await batchRes.json();
    expect(batch.success).toBe(true);
    expect(batch.inserted).toBeGreaterThan(0);

    const singlePromise = page.waitForResponse(
      (r) => r.url().includes('/api/access-log/event') && r.request().method() === 'POST'
    );
    await page.getByTestId('single-event').click();
    const singleRes = await singlePromise;
    expect(singleRes.ok()).toBeTruthy();
    expect((await singleRes.json()).access_log_event_id).toBeGreaterThan(0);
  });

  test('painel API — stats, journey e fingerprint', async ({ page }) => {
    await page.goto(`${DEMO}/index.html?utm_source=copa2026`);
    await page.waitForResponse((r) => r.url().includes('/api/access-log') && r.request().method() === 'POST');
    await page.goto(`${DEMO}/jornada-grupo.html`);
    await page.waitForResponse((r) => r.url().includes('/api/access-log') && r.request().method() === 'POST');

    await page.goto(`${DEMO}/painel-api.html`);

    const statsPromise = page.waitForResponse((r) => r.url().includes('/api/access-log/stats'));
    await page.getByTestId('fetch-stats').click();
    const stats = await statsPromise;
    expect(stats.ok()).toBeTruthy();
    await expect(page.locator('#out-stats')).toContainText('total_access');

    const journeyPromise = page.waitForResponse((r) => r.url().includes('/api/access-log/journey'));
    await page.getByTestId('fetch-journey').click();
    expect((await journeyPromise).ok()).toBeTruthy();
    await expect(page.locator('#out-journey')).toContainText('"success": true');

    const fpPromise = page.waitForResponse((r) => r.url().includes('/api/access-log/fingerprint'));
    await page.getByTestId('fetch-fingerprint').click();
    expect((await fpPromise).ok()).toBeTruthy();
    await expect(page.locator('#out-fingerprint')).toContainText('fingerprint_hash');
  });
});
