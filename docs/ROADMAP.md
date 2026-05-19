# Roadmap

Fases do projeto **access-logger** (open source). O Meelion **não é alterado** até a fase 5, e só se explicitamente solicitado.

---

## Fase 1 — Documentação

**Entrega:** `docs/`, `docs/sql/schema_core.sql`, README, LICENSE, AGENTS.md.

**Status:** concluída.

---

## Fase 2 — Scaffold Slim + Docker

**Entrega:**

- `composer.json` + Slim 4
- `public/index.php`, `src/App.php`, `src/Routes.php`
- `GET /health`
- `POST /api/access-log` stub (valida JSON, retorna mock)
- Middleware CORS + RateLimit
- `docker-compose.yml` + Dockerfile PHP 8.2
- [docs/DOCKER.md](./DOCKER.md)

**Status:** concluída.

**Critério de pronto:** `curl http://localhost:8088/health` → 200.

**Produção:** compatível com LiteSpeed + PHP + MySQL — [DEPLOYMENT-PRODUCTION.md](./DEPLOYMENT-PRODUCTION.md) (`public/.htaccess`, `settings.local.php`).

---

## Fase 3 — Portar lógica core

**Entrega:**

- `AccessLogService` completo (sem gates/pixel)
- Repositories PDO (3 tabelas)
- 7 endpoints conforme [API.md](./API.md)
- `web/access-logger.js` copiado do Meelion
- PHPUnit: `logAccess`, bot skip, `recordEvents`, update
- `.github/workflows/phpunit.yml` (opcional)

**Critério de pronto:** Playwright ou curl E2E grava pageview + evento no MySQL Docker.

**Fora do escopo:**

- `gate-feature-*`
- `pixel` / `user_activities`
- `ExternalLinkClicksBackupService` (adapter opcional documentado)

---

## Fase 4 — Relatórios

**Entrega:**

- Dashboard simples (Twig ou HTML estático + fetch)
- Páginas: overview stats, jornada por `session_id`, top URLs
- Proteção: API key header `X-Access-Logger-Key`
- Queries SQL agregadas (inspirado em `AnalyticsService` Meelion, **reescritas**)
- Job CLI `bin/purge-old-logs.php` (retenção LGPD)
- Export CSV por `user_id` / `fingerprint_hash`

**Inspiração Meelion (não copiar):**

- `src/Service/AnalyticsService.php`
- `docs/analytics-dashboard.md`
- `src/Controller/System/AnalyticsController.php`

---

## Fase 5 — Integração Meelion (opcional)

**Entrega no Meelion** (só com aprovação):

- Layouts apontam endpoints para microserviço
- `LoginController` chama link-user
- Remover ou proxy código duplicado Cake

Ver [EXTRACTION-MEELION-INTEGRATION.md](./EXTRACTION-MEELION-INTEGRATION.md).

---

## Backlog / ideias

| Item | Prioridade |
|------|------------|
| Pacote npm `@access-logger/client` | Média |
| Flight 3 como variante "ultra-minimal" | Baixa |
| Redis rate limit | Média em produção |
| Detecção comportamental de bots | Baixa |
| Particionamento MySQL por mês | Baixa até volume |
| OpenAPI 3 spec gerada | Média |

---

## Explicitamente fora do roadmap OSS

- Feature gates (`auth-gate.js`, `access_log_feature_events`)
- Checkout / subscription attribution
- Catálogo `features` / FeatureGateService
- Pixel Meelion (`user_activities`)

Esses permanecem no repositório Meelion.
