# Access Logger

**Repositório:** [github.com/danmarkprintes/access-logger](https://github.com/danmarkprintes/access-logger)

Microserviço open source de **telemetria web**: fingerprint de dispositivo, pageviews, eventos de interação e API REST para analytics.

Extraído do sistema de logs do [Meelion](https://www.meelion.com), **sem** feature gates, modais Pro ou checkout attribution — apenas o núcleo de access logging.

---

## O que faz

- Identifica visitantes anônimos (`user_fingerprints`)
- Registra cada pageview com UTM, referer, scroll e tempo (`access_logs`)
- Captura cliques e micro-eventos (`access_log_events`)
- Filtra bots, crawlers e (opcionalmente) tráfego fora do público-alvo
- Expõe API JSON consumível por qualquer site via `access-logger.js`

---

## Para agentes de IA

- **[AGENTS.md](AGENTS.md)** — instruções operacionais (stack, limites, portação Meelion, testes).
- **[CLAUDE.md](CLAUDE.md)** — entrada Claude Code; importa `AGENTS.md` via `@AGENTS.md`.

## Documentação

| Documento | Conteúdo |
|-----------|----------|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Fluxos, camadas, diagramas |
| [docs/API.md](docs/API.md) | Contrato REST (7 endpoints + `/health`) |
| [docs/DATABASE.md](docs/DATABASE.md) | Modelo de dados |
| [docs/sql/schema_core.sql](docs/sql/schema_core.sql) | DDL MySQL |
| [docs/FRONTEND.md](docs/FRONTEND.md) | Cliente JavaScript |
| [docs/BOT-FILTERING.md](docs/BOT-FILTERING.md) | Filtros de qualidade |
| [docs/PRIVACY-LGPD.md](docs/PRIVACY-LGPD.md) | Privacidade e retenção |
| [docs/STACK.md](docs/STACK.md) | Slim 4 + PDO + Docker |
| [docs/EXTRACTION-INVENTORY.md](docs/EXTRACTION-INVENTORY.md) | Mapeamento Meelion → OSS |
| [docs/EXTRACTION-MEELION-INTEGRATION.md](docs/EXTRACTION-MEELION-INTEGRATION.md) | Integração futura |
| [docs/DEPLOYMENT-PRODUCTION.md](docs/DEPLOYMENT-PRODUCTION.md) | **Produção: LiteSpeed + PHP + MySQL** |
| [docs/DOCKER.md](docs/DOCKER.md) | Desenvolvimento local com Docker |
| [docs/DEMO.md](docs/DEMO.md) | **Demo Copa 2026 + Playwright E2E** |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Fases de implementação |

---

## Stack

- **PHP 8.2+** com [Slim 4](https://www.slimframework.com/)
- **MySQL 8.0** + PDO (SQL explícito, sem ORM)
- **Produção:** PHP + MySQL + **LiteSpeed Web Server** (sem Docker) — ver [docs/DEPLOYMENT-PRODUCTION.md](docs/DEPLOYMENT-PRODUCTION.md)
- **Desenvolvimento:** Docker Compose (opcional) — [docs/DOCKER.md](docs/DOCKER.md)
- Cliente: **`web/access-logger.js`**

---

## Testar localmente (Docker, porta 8088)

```bash
cd access-logger
docker compose up -d
```

| URL | Descrição |
|-----|-----------|
| http://localhost:8088/ | Página inicial com links |
| http://localhost:8088/health | Health check (HTML no browser) |
| http://localhost:8088/health?format=json | Health em JSON |
| http://localhost:8088/demo/world-cup-2026/ | Demo interativo Copa 2026 |

**Testes automatizados:**

```bash
npm install && npx playwright install chromium
npm run test:e2e
docker compose exec php sh -lc "vendor/bin/phpunit"
```

Detalhes: [docs/DEMO.md](docs/DEMO.md) · [docs/DOCKER.md](docs/DOCKER.md)

---

## Quick start

```bash
cd access-logger
docker compose build
docker compose up -d
curl.exe http://localhost:8088/health?format=json
```

Exemplo de pageview (PowerShell):

```powershell
$body = '{"url":"https://example.com/","fingerprint":{"user_agent":"Mozilla/5.0","language":"pt-BR","timezone":"America/Sao_Paulo"}}'
Invoke-RestMethod -Uri http://localhost:8088/api/access-log -Method POST -ContentType "application/json" -Body $body
```

Integração no site:

```html
<script src="https://logger.example.com/web/access-logger.js" defer></script>
<script>
  window.accessLogger = new AccessLogger({
    endpoint: 'https://logger.example.com/api/access-log',
    updateEndpoint: 'https://logger.example.com/api/access-log/update',
    eventsEndpoint: 'https://logger.example.com/api/access-log/events'
  });
</script>
```

---

## Fora do escopo

- `auth-gate.js` e `access_log_feature_events` (Meelion)
- Pixel de campanhas (`user_activities`)
- Feature flags / `FeatureGateService`

---

## Licença

MIT — ver [LICENSE](LICENSE).

---

## Origem

Lógica de referência no monólito Meelion:

- `src/Service/AccessLogService.php`
- `src/Controller/AccessLogController.php`
- `webroot/js/access-logger.js`

O repositório Meelion **não é modificado** durante a extração documentada.
