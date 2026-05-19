document.addEventListener('DOMContentLoaded', function onReady() {
  const cfg = window.DEMO_CONFIG;
  if (!cfg || window.accessLogger) return;

  const script = document.createElement('script');
  script.src = cfg.scriptPath;
  script.defer = true;
  script.onload = function onScriptLoad() {
    const opts = { ...(cfg.loggerOptions || {}) };
    if (window.DEMO_LOGGER_OVERRIDES) {
      Object.assign(opts, window.DEMO_LOGGER_OVERRIDES);
    }
    window.accessLogger = new AccessLogger(opts);
    document.dispatchEvent(new CustomEvent('access-logger-ready'));
  };
  document.head.appendChild(script);
});
