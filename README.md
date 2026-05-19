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
| [docs/API.md](docs/API.md) | Contrato REST (7 endpoints) |
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
| [docs/ROADMAP.md](docs/ROADMAP.md) | Fases de implementação |

---

## Stack

- **PHP 8.2+** com [Slim 4](https://www.slimframework.com/)
- **MySQL 8.0** + PDO (SQL explícito, sem ORM)
- **Produção:** PHP + MySQL + **LiteSpeed Web Server** (sem Docker) — ver [docs/DEPLOYMENT-PRODUCTION.md](docs/DEPLOYMENT-PRODUCTION.md)
- **Desenvolvimento:** Docker Compose (opcional) — [docs/DOCKER.md](docs/DOCKER.md)
- Cliente: **`web/access-logger.js`** (fase 3)

---

## Quick start

```bash
cd access-logger
docker compose build
docker compose up -d
curl http://localhost:8088/health
```

Guia completo: **[docs/DOCKER.md](docs/DOCKER.md)**

Teste do stub de ingestão:

```bash
curl -s -X POST http://localhost:8088/api/access-log \
  -H "Content-Type: application/json" \
  -d "{\"url\":\"https://example.com/\",\"fingerprint\":{\"user_agent\":\"Mozilla/5.0\",\"language\":\"pt-BR\",\"timezone\":\"America/Sao_Paulo\"}}"
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
