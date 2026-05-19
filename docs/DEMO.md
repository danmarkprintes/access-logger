# Demo Copa 2026 + testes Playwright

Site estático de exemplo que exercita cada recurso do Access Logger, com suíte E2E em Playwright (API + browser).

## Pré-requisitos

1. Docker do access-logger em execução (`docker compose up -d`)
2. Node.js 18+ na máquina host

Guia Docker completo: [DOCKER.md](./DOCKER.md).

---

## Abrir no browser

| URL | O quê |
|-----|--------|
| http://localhost:8088/ | Página inicial com links |
| http://localhost:8088/health | Health — página **HTML** (status + MySQL) |
| http://localhost:8088/health?format=json | Health em JSON |
| http://localhost:8088/demo/world-cup-2026/ | **Demo Copa 2026** (recomendado) |

Atalhos nginx: `/demo` e `/demo/` redirecionam para o demo. `/health/` redireciona para `/health`.

### Páginas do demo

| Página | Feature exercitada |
|--------|-------------------|
| `index.html` | POST `/api/access-log` (pageview, fingerprint, UTM) |
| `jornada-grupo.html` … `jornada-final.html` | Jornada (`navigation_order`, mesmo `session_id`) |
| `engajamento.html` | POST `/api/access-log/update` |
| `eventos.html` | POST `/events` (lote) e POST `/event` (único) |
| `painel-api.html` | GET `stats`, `journey`, `fingerprint` |

O script `web/access-logger.js` é servido em `/web/access-logger.js` (mesma origem `:8088`).

### O que não abrir direto no browser

| URL | Comportamento |
|-----|----------------|
| `GET /api/access-log` | **405** — só aceita POST |
| `POST /api/access-log` | Precisa de corpo JSON (`curl`, demo ou DevTools) |

---

## Rodar Playwright

```bash
cd access-logger
npm install
npx playwright install chromium

# API (11 casos — 7 endpoints + validações)
npm run test:e2e:api

# Demo no browser (navegação Copa 2026)
npm run test:e2e:demo

# Tudo (16 testes)
npm run test:e2e
```

Variável opcional: `PLAYWRIGHT_BASE_URL=http://localhost:8088`

Relatório HTML: `npx playwright show-report` (pasta `playwright-report/`, ignorada pelo Git).

---

## Estrutura

```
demo/world-cup-2026/       # site estático
  assets/css|js/
  index.html, jornada-*.html, engajamento.html, eventos.html, painel-api.html
public/index.html          # landing local (:8088/)
tests/playwright/          # specs E2E
playwright.config.ts
package.json
```

---

## Dica DevTools

No demo, abra **Rede (Network)** e filtre por `access-log` para ver pageview, update, events e consultas GET do painel API.
