# Demo Copa 2026 + testes Playwright

Site estático de exemplo que exercita cada recurso do Access Logger, com suíte E2E em Playwright (API + browser).

## Pré-requisitos

1. Docker do access-logger em execução (`docker compose up -d`)
2. Node.js 18+ na máquina host

## Abrir no browser

| URL | O quê |
|-----|--------|
| http://localhost:8088/ | Página inicial com links |
| http://localhost:8088/health | Health check (JSON) |
| http://localhost:8088/demo/world-cup-2026/ | **Demo Copa 2026** |

> Use **sem barra extra** em `/health` ou deixe o redirect automático (`/health/` → `/health`).
> `GET /api/access-log` no browser retorna **405** (só aceita POST) — isso é esperado.

| Página | Feature exercitada |
|--------|-------------------|
| `index.html` | POST `/api/access-log` (pageview, fingerprint, UTM) |
| `jornada-grupo.html` … `jornada-final.html` | Jornada (`navigation_order`, mesmo `session_id`) |
| `engajamento.html` | POST `/api/access-log/update` |
| `eventos.html` | POST `/events` (lote) e POST `/event` (único) |
| `painel-api.html` | GET `stats`, `journey`, `fingerprint` |

O script `web/access-logger.js` é servido em `/web/access-logger.js`.

## Rodar Playwright

```bash
cd access-logger
npm install
npx playwright install chromium

# API (todos os 7 endpoints + validações)
npm run test:e2e:api

# Demo no browser (navegação Copa 2026)
npm run test:e2e:demo

# Tudo
npm run test:e2e
```

Variável opcional: `PLAYWRIGHT_BASE_URL=http://localhost:8088`

Relatório HTML: `npx playwright show-report` (pasta `playwright-report/`, ignorada pelo Git).

## Estrutura

```
demo/world-cup-2026/     # site estático
tests/playwright/        # specs E2E
playwright.config.ts
package.json
```
