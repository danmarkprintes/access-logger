# Access Logger — contexto para agentes

Microserviço **open source** de telemetria web (fingerprint, pageviews, eventos). Extraído do Meelion **sem** feature gates, `auth-gate.js` ou pixel de campanhas.

> **Não commitar segredos** (API keys de produção, senhas reais). Use variáveis de ambiente no Docker.

---

## Estado atual do repositório

| Fase | Status | O que existe |
|------|--------|--------------|
| **1** | Concluída | `docs/`, `docs/sql/schema_core.sql`, `README.md`, `LICENSE` |
| **2** | Concluída | Slim 4, Docker (`8088`), `/health`, stub `POST /api/access-log` |
| **3** | Concluída | `AccessLogService`, repositories PDO, 7 endpoints, `web/access-logger.js`, PHPUnit, demo Copa 2026, Playwright E2E |
| **4** | Pendente | Dashboard relatórios |
| **5** | Opcional | Integração Meelion (só se o usuário pedir) |

**Produção (critério de sucesso):** PHP 8.2 + MySQL + **LiteSpeed** — sem Docker. Ver [docs/DEPLOYMENT-PRODUCTION.md](docs/DEPLOYMENT-PRODUCTION.md). Document root = `public/`, rewrite via `public/.htaccess`.

**Dev:** Docker opcional — [docs/DOCKER.md](docs/DOCKER.md). Independente do Meelion.

---

## Limites obrigatórios

1. **Não usar CakePHP** neste repo — stack: **Slim 4 + PDO + MySQL**.
2. **Não implementar gates** — sem `access_log_feature_events`, `gate-feature-open/pass`, `auth-gate.js`.
3. **Não alterar o monólito Meelion** (`../meelion/`) nas fases 1–4. Só documentar integração em [docs/EXTRACTION-MEELION-INTEGRATION.md](docs/EXTRACTION-MEELION-INTEGRATION.md).
4. **Schema:** fonte de verdade = [docs/sql/schema_core.sql](docs/sql/schema_core.sql). Não depender de migrations Phinx.
5. **SQL explícito** nos repositories — evitar ORM (Doctrine/Eloquent).
6. **KISS / DRY** — regras de negócio num único `AccessLogService`; controllers finos.

---

## O que o sistema faz

1. **`user_fingerprints`** — dispositivo anônimo (hash + UA, canvas, timezone, etc.).
2. **`access_logs`** — um registro por pageview (URL, UTM, scroll, tempo, sessão).
3. **`access_log_events`** — cliques e micro-eventos na página.

Fluxo: browser (`access-logger.js`) → API JSON → service → MySQL.

Diagramas e detalhes: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

---

## API (7 endpoints core)

| Método | Path |
|--------|------|
| POST | `/api/access-log` |
| POST/PUT | `/api/access-log/update` |
| POST | `/api/access-log/events` |
| POST | `/api/access-log/event` |
| GET | `/api/access-log/stats` |
| GET | `/api/access-log/journey` |
| GET | `/api/access-log/fingerprint` |

Contrato completo: [docs/API.md](docs/API.md).

**Não implementar aqui:** `gate-feature-open`, `gate-feature-pass`, `pixel` (Meelion-only).

---

## Estrutura do repositório

```
access-logger/
├── AGENTS.md              # Este ficheiro (instruções para agentes)
├── CLAUDE.md              # Claude Code → importa AGENTS.md
├── README.md              # Visão humana / quick start
├── LICENSE
├── docs/                  # Documentação técnica
│   ├── ARCHITECTURE.md
│   ├── API.md
│   ├── DATABASE.md
│   ├── STACK.md
│   ├── ROADMAP.md
│   ├── EXTRACTION-INVENTORY.md
│   └── sql/schema_core.sql
├── public/                # index.php (Slim), index.html (landing), .htaccess
├── demo/world-cup-2026/   # site estático de exemplo + Playwright browser
├── src/                   # Controller, Service, Repository, Middleware
├── config/                # settings.php, load-settings.php
├── web/                   # access-logger.js
├── tests/                 # PHPUnit + tests/playwright/
├── package.json           # Playwright (host)
└── docker-compose.yml
```

---

## Referência Meelion (portação)

Ao implementar fases 2–3, **copiar/portar lógica** de (não editar esses ficheiros salvo fase 5 explícita):

| Origem (Meelion) | Destino (access-logger) |
|------------------|-------------------------|
| `../meelion/src/Service/AccessLogService.php` | `src/Service/AccessLogService.php` (sem gates/pixel) |
| `../meelion/src/Controller/AccessLogController.php` | `src/Controller/AccessLogController.php` |
| `../meelion/webroot/js/access-logger.js` | `web/access-logger.js` |
| `../meelion/webroot/rate_limit_access_log.php` | `src/Middleware/RateLimitMiddleware.php` |

Inventário completo: [docs/EXTRACTION-INVENTORY.md](docs/EXTRACTION-INVENTORY.md).

---

## Ambiente de desenvolvimento

### URLs locais (Docker, porta 8088)

| URL | Uso |
|-----|-----|
| `/` | Landing `public/index.html` |
| `/health` | HTML no browser; `?format=json` para monitoramento |
| `/demo/world-cup-2026/` | Demo Copa 2026 |
| `/web/access-logger.js` | Cliente JS |
| `/api/access-log/*` | API REST (ingestão + consultas) |

Ver [docs/DOCKER.md](docs/DOCKER.md) e [docs/DEMO.md](docs/DEMO.md).

### Docker (stack próprio)

```bash
cd access-logger
docker compose build
docker compose up -d
curl.exe http://localhost:8088/health?format=json
```

- HTTP: **8088** (não usa porta 80 do Meelion)
- MySQL: **3307** no host (`access_logger` / root / root)
- Schema: auto via `docs/sql/schema_core.sql` no primeiro `up`

### Composer / PHP (dentro do container)

```bash
docker compose exec php composer install
docker compose exec php vendor/bin/phpunit
```

Preferir `docker compose exec` em vez de PHP/Composer no host Windows.

### Variáveis de ambiente (access-logger apenas)

| Variável | Uso |
|----------|-----|
| `DB_DSN` | PDO DSN MySQL |
| `DB_USER` / `DB_PASS` | Credenciais |
| `CORS_ORIGINS` | Origens permitidas (CSV) |
| `GEO_BR_ONLY` | `0`/`1` — filtro geo Brasil |
| `RATE_LIMIT_IP` / `RATE_LIMIT_UA` | Limites/min em `/api/access-log*` (Docker dev: 500) |

`.env` é aceitável **neste** repositório OSS via `docker-compose.yml`; o Meelion **não** usa `.env`.

---

## Convenções de código

### PHP

- `declare(strict_types=1);` em ficheiros novos.
- Namespace: `AccessLogger\` → PSR-4 em `src/`.
- Controllers: validar método HTTP, delegar ao service, responder JSON.
- Service: `logAccess`, `updateAccessLog`, `recordEvents`, filtros bot/geo — ver métodos em [docs/EXTRACTION-INVENTORY.md](docs/EXTRACTION-INVENTORY.md).
- Repositories: apenas SQL parametrizado (PDO).

### JavaScript (`web/access-logger.js`)

- Vanilla JS (sem framework no cliente).
- Classe `AccessLogger` com endpoints configuráveis no construtor.
- Não acoplar a modais Meelion / `auth-gate`.

### Respostas API

- Sucesso ingestão: `{ "success": true, "log_id": ... }`.
- Filtrado (bot): `{ "success": true, "skipped": true, "reason": "...", "log_id": null }`.
- Erro: HTTP 4xx/5xx + `{ "success": false, "message": "..." }`.

---

## Comportamentos críticos (não quebrar)

1. **Skipped ≠ erro** — bot/geo retorna 200 com `skipped: true` para o JS não falhar.
2. **Batch eventos** — máximo 100 por request; normalizar com `normalizeEventPayload`.
3. **Rate limit** — ~20/min IP, ~40/min UA em rotas `/api/access-log*`.
4. **CORS** — obrigatório para sites externos que embutem o script.
5. **`user_id` opcional** — coluna em `access_logs`; `linkAccessLogsToUserByFingerprint` após login (adapter).

Detalhes: [docs/BOT-FILTERING.md](docs/BOT-FILTERING.md), [docs/PRIVACY-LGPD.md](docs/PRIVACY-LGPD.md).

---

## Testes

### PHPUnit

```bash
docker compose exec php sh -lc "cd /var/www/html && vendor/bin/phpunit"
```

### Playwright (host)

```bash
npm install && npx playwright install chromium
npm run test:e2e          # API (11) + demo browser (5)
npm run test:e2e:api
npm run test:e2e:demo
```

Base URL: `http://localhost:8088` (`PLAYWRIGHT_BASE_URL` opcional). Ver [docs/DEMO.md](docs/DEMO.md).

### Health nos testes

`GET /health` com `Accept: */*` (Playwright/curl) → JSON. Browser → HTML.

---

## Tarefas comuns → onde ler

| Tarefa | Documento |
|--------|-----------|
| URLs locais / Docker / troubleshooting | [docs/DOCKER.md](docs/DOCKER.md) |
| Demo + Playwright | [docs/DEMO.md](docs/DEMO.md) |
| Novo endpoint ou alterar contrato | [docs/API.md](docs/API.md) |
| Alterar tabelas/índices | [docs/DATABASE.md](docs/DATABASE.md) + `schema_core.sql` |
| Scaffold Slim/Docker | [docs/STACK.md](docs/STACK.md) |
| Portar método do Meelion | [docs/EXTRACTION-INVENTORY.md](docs/EXTRACTION-INVENTORY.md) |
| Integrar no site Meelion | [docs/EXTRACTION-MEELION-INTEGRATION.md](docs/EXTRACTION-MEELION-INTEGRATION.md) |
| Próxima entrega de produto | [docs/ROADMAP.md](docs/ROADMAP.md) |

---

## Segurança e privacidade

- Endpoints de ingestão são públicos; proteger **stats/journey** com API key na fase 4.
- Não logar PII em ficheiros de aplicação sem necessidade.
- Purge/retention: [docs/PRIVACY-LGPD.md](docs/PRIVACY-LGPD.md).
- Scripts SQL destrutivos (`DELETE` em massa): exigir confirmação do usuário.

---

## Git e branches

| Branch | Uso |
|--------|-----|
| **`dev`** | Integração — recebe PRs de feature branches |
| **`main`** | Produção — só merge via PR aprovado a partir de `dev` |

**Proteção no GitHub (ruleset ativo):** push direto em `main` e `dev` é **bloqueado**. Só entra código via **Pull Request** com **1 aprovação**.

Fluxo recomendado:

1. `git checkout -b feature/minha-mudanca` (a partir de `dev`)
2. Commit + `git push -u origin feature/minha-mudanca`
3. Abrir PR → `dev` → você aprova e faz merge
4. Quando for release: PR `dev` → `main` → aprovar e merge

Configuração versionada: [.github/ruleset-protect-branches.json](.github/ruleset-protect-branches.json) (referência; a regra vive no GitHub em **Settings → Rules → Rulesets**).

## Git e PRs

- **Não commitar** `vendor/`, `.env` local, artefatos de teste.
- Mensagens de commit: imperativo, foco no *porquê* (ex.: `Add rate limit middleware for access-log API`).
- PR: resumo + como testar (`curl`, PHPUnit).
- **Não** fazer push ou alterar Meelion sem pedido explícito.

---

## Checklist antes de fechar uma tarefa

- [ ] Respeitou limites (sem gates, sem Cake, sem editar Meelion se não for fase 5).
- [ ] Alterações alinhadas à fase atual do [ROADMAP](docs/ROADMAP.md).
- [ ] Se mudou contrato API → atualizou [docs/API.md](docs/API.md).
- [ ] Se mudou schema → atualizou [docs/sql/schema_core.sql](docs/sql/schema_core.sql) e [docs/DATABASE.md](docs/DATABASE.md).
- [ ] Comandos de teste executados ou motivo documentado se fase ainda sem código.
