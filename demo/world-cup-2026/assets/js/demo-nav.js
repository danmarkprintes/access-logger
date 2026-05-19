(function initDemoNav() {
  const path = window.location.pathname;
  const links = [
    { href: '/demo/world-cup-2026/index.html', label: 'Início (pageview)' },
    { href: '/demo/world-cup-2026/jornada-grupo.html', label: 'Jornada 1' },
    { href: '/demo/world-cup-2026/jornada-mata.html', label: 'Jornada 2' },
    { href: '/demo/world-cup-2026/jornada-final.html', label: 'Jornada 3' },
    { href: '/demo/world-cup-2026/engajamento.html', label: 'Update' },
    { href: '/demo/world-cup-2026/eventos.html', label: 'Eventos' },
    { href: '/demo/world-cup-2026/painel-api.html', label: 'API (stats/journey/fingerprint)' },
  ];

  const nav = document.getElementById('demo-nav');
  if (!nav) return;

  links.forEach((item) => {
    const a = document.createElement('a');
    a.href = item.href;
    a.textContent = item.label;
    if (path.endsWith(item.href.replace('/demo/world-cup-2026/', ''))) {
      a.setAttribute('aria-current', 'page');
    }
    nav.appendChild(a);
  });
})();
