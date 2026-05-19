/**
 * Configuração compartilhada do demo Copa 2026.
 * API na mesma origem quando servido via nginx (:8088).
 */
window.DEMO_CONFIG = {
  apiBase: '',
  scriptPath: '/web/access-logger.js',
  loggerOptions: {
    endpoint: '/api/access-log',
    updateEndpoint: '/api/access-log/update',
    eventsEndpoint: '/api/access-log/events',
    debug: true,
    autoStart: true,
    trackScroll: true,
    trackTime: true,
    timeUpdateInterval: 5000,
    eventsFlushInterval: 2000,
  },
};

window.demoLog = function demoLog(targetId, label, data) {
  const el = document.getElementById(targetId);
  if (!el) return;
  const line = `[${new Date().toISOString()}] ${label}\n${JSON.stringify(data, null, 2)}\n\n`;
  el.textContent = line + el.textContent;
};

window.demoFetchJson = async function demoFetchJson(path, options = {}) {
  const url = (window.DEMO_CONFIG.apiBase || '') + path;
  const res = await fetch(url, {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    ...options,
  });
  const text = await res.text();
  let body;
  try {
    body = JSON.parse(text);
  } catch {
    body = { raw: text };
  }
  return { status: res.status, ok: res.ok, body };
};
