# Docker — ambiente local (desenvolvimento)

> **Produção** usa **LiteSpeed + PHP + MySQL** sem Docker. Ver [DEPLOYMENT-PRODUCTION.md](./DEPLOYMENT-PRODUCTION.md).

O **access-logger** corre num stack **próprio**, separado do `meelion-docker`. Não depende do container `meelion-apache` nem da base `meelion`.

---

## Pré-requisitos

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows/macOS) ou Docker Engine + Compose (Linux)
- Portas livres no host:
  - **8088** — HTTP (API, demo, estáticos)
  - **3307** — MySQL (opcional; cliente no host)

O Meelion pode continuar nas portas **80** e **3306** em paralelo.

---

## Subir o ambiente

```bash
cd C:\meelion-docker\www\access-logger

docker compose build
docker compose up -d
```

Primeira execução: o entrypoint PHP corre `composer install` automaticamente.

---

## URLs no browser (porta 8088)

| URL | O quê |
|-----|--------|
| http://localhost:8088/ | Página inicial (`public/index.html`) com links |
| http://localhost:8088/health | Health check — **HTML** no browser |
| http://localhost:8088/health?format=json | Health em JSON (monitoramento) |
| http://localhost:8088/demo/world-cup-2026/ | **Demo interativo** Copa 2026 |
| http://localhost:8088/web/access-logger.js | Cliente JavaScript |
| http://localhost:8088/api/access-log/stats | Estatísticas (JSON; abrir no browser OK) |

Redirects automáticos (nginx / Slim):

- `/demo` e `/demo/` → `/demo/world-cup-2026/`
- `/health/` → `/health`

> `GET /api/access-log` no browser retorna **405** — essa rota só aceita **POST**. Use o demo ou `curl`/`Invoke-RestMethod`.

Guia do demo e Playwright: [DEMO.md](./DEMO.md).

---

## Verificar (terminal)

No PowerShell, `curl` pode ser alias de `Invoke-WebRequest`. Prefira **`curl.exe`**:

```powershell
# Health JSON
curl.exe http://localhost:8088/health?format=json

# Pageview
$body = '{"url":"https://example.com/","session_id":"teste-1","fingerprint":{"user_agent":"Mozilla/5.0 Chrome/120","language":"pt-BR","timezone":"America/Sao_Paulo","screen_resolution":"1920x1080"}}'
Invoke-RestMethod -Uri http://localhost:8088/api/access-log -Method POST -ContentType "application/json" -Body $body
```

Resposta esperada do health (JSON):

```json
{
  "ok": true,
  "service": "access-logger",
  "phase": 3,
  "database": "connected",
  "timestamp": "2026-05-19T20:19:25+00:00"
}
```

---

## Serviços

| Container | Função | Porta host |
|-----------|--------|------------|
| `access-logger-nginx` | HTTP — API Slim, demo, `/web/` | 8088 |
| `access-logger-php` | PHP 8.2 FPM + Slim | (interno 9000) |
| `access-logger-mysql` | MySQL 8.0 + schema inicial | 3307 |

---

## Base de dados

- **Database:** `access_logger`
- **User / pass:** `root` / `root`
- **Schema:** aplicado automaticamente via `docs/sql/schema_core.sql` no primeiro `up` (volume vazio).

### Consulta rápida

```bash
docker exec -it access-logger-mysql mysql -u root -proot access_logger -e "SELECT id, session_id, url FROM access_logs ORDER BY id DESC LIMIT 5;"
```

### Import manual (se o volume já existir sem tabelas)

```bash
docker compose exec -T mysql mysql -u root -proot access_logger < docs/sql/schema_core.sql
```

### Cliente MySQL no host

```bash
mysql -h 127.0.0.1 -P 3307 -u root -proot access_logger
```

---

## Variáveis de ambiente

Definidas em `docker-compose.yml`:

| Variável | Default (Docker) | Descrição |
|----------|------------------|-----------|
| `DB_DSN` | `mysql:host=mysql;dbname=access_logger;...` | PDO DSN |
| `DB_USER` / `DB_PASS` | `root` / `root` | MySQL |
| `CORS_ORIGINS` | `*` | Origens CORS (CSV) |
| `APP_DEBUG` | `0` | Detalhes de erro JSON (`1` para debug) |
| `GEO_BR_ONLY` | `0` | Filtro geo Brasil (`1` ativa) |
| `RATE_LIMIT_IP` | `500` | Requisições/min por IP em `/api/access-log*` |
| `RATE_LIMIT_UA` | `500` | Requisições/min por User-Agent |

---

## Testes

### PHPUnit (no container)

```bash
docker compose exec php sh -lc "cd /var/www/html && vendor/bin/phpunit"
```

### Playwright (no host; Docker em :8088)

```bash
npm install
npx playwright install chromium
npm run test:e2e
```

---

## Comandos úteis

```bash
# Logs
docker compose logs -f nginx php

# Recarregar nginx após mudar docker/nginx/default.conf
docker compose restart nginx

# Parar
docker compose down

# Parar e apagar dados MySQL
docker compose down -v

# Composer dentro do PHP
docker compose exec php composer install

# Shell PHP
docker compose exec php sh
```

---

## Desenvolvimento sem Docker (opcional)

Requer PHP 8.2+, extensões `pdo_mysql`, Composer, MySQL local.

```bash
composer install
cp config/settings.local.php.example config/settings.local.php
# Ajustar DB_DSN em settings.local.php
php -S localhost:8088 -t public
```

---

## Troubleshooting

### `/health` em branco no browser

Use http://localhost:8088/health (HTML). Para JSON: `?format=json`. Reinicie o PHP após `git pull`: `docker compose restart php`.

### Porta 8088 em uso

Altere em `docker-compose.yml`:

```yaml
ports:
  - "8090:80"
```

### `database: unavailable` no health

- Aguarde o MySQL ficar healthy: `docker compose ps`
- Logs: `docker compose logs mysql`
- Credenciais em `config/settings.php` / env do Compose

### Erro 500 em `/` ou rotas com barra final

Corrigido na fase 3+: raiz serve `public/index.html`; trailing slash redireciona; 404/405 em vez de 500 genérico. Atualize o código e `docker compose restart nginx php`.

### `vendor/autoload.php` missing

```bash
docker compose exec php composer install
```

### Rate limit 429 nos testes E2E

No Docker dev os limites já são altos (`RATE_LIMIT_*=500`). Se alterou, reinicie: `docker compose up -d php`.

### Conflito com Meelion

| Recurso | Meelion | access-logger |
|---------|---------|---------------|
| HTTP | 80 | **8088** |
| MySQL | 3306 | **3307** |

Stacks independentes — podem correr ao mesmo tempo.

---

## Documentação relacionada

- [DEMO.md](./DEMO.md) — site Copa 2026 + Playwright  
- [API.md](./API.md) — contrato REST  
- [ROADMAP.md](./ROADMAP.md) — fases do produto  
