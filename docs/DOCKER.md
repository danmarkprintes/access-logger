# Docker — ambiente local (desenvolvimento)

> **Produção** usa **LiteSpeed + PHP + MySQL** sem Docker. Ver [DEPLOYMENT-PRODUCTION.md](./DEPLOYMENT-PRODUCTION.md).

O **access-logger** corre num stack **próprio**, separado do `meelion-docker`. Não depende do container `meelion-apache` nem da base `meelion`.

---

## Pré-requisitos

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows/macOS) ou Docker Engine + Compose (Linux)
- Portas livres no host:
  - **8088** — HTTP da API
  - **3307** — MySQL (opcional; só para debug com cliente no host)

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

## Verificar

No PowerShell, `curl` pode ser alias de `Invoke-WebRequest`. Use **`curl.exe`** ou:

```powershell
$body = '{"url":"https://example.com/","fingerprint":{"user_agent":"Mozilla/5.0","language":"pt-BR","timezone":"America/Sao_Paulo"}}'
Invoke-RestMethod -Uri http://localhost:8088/api/access-log -Method POST -ContentType "application/json" -Body $body
```

```bash
# Health
curl.exe http://localhost:8088/health

# Stub pageview (fase 2)
curl.exe -s -X POST http://localhost:8088/api/access-log \
  -H "Content-Type: application/json" \
  -d "{\"url\":\"https://example.com/\",\"fingerprint\":{\"user_agent\":\"Mozilla/5.0\",\"timezone\":\"America/Sao_Paulo\",\"language\":\"pt-BR\"}}"
```

Resposta esperada do health:

```json
{"ok":true,"service":"access-logger","phase":2,"database":"connected"}
```

---

## Serviços

| Container | Função | Porta host |
|-----------|--------|------------|
| `access-logger-nginx` | HTTP / API / estáticos `/web/` | 8088 |
| `access-logger-php` | PHP 8.2 FPM + Slim | (interno 9000) |
| `access-logger-mysql` | MySQL 8.0 + schema inicial | 3307 |

---

## Base de dados

- **Database:** `access_logger`
- **User / pass:** `root` / `root`
- **Schema:** aplicado automaticamente via `docs/sql/schema_core.sql` no primeiro `up` (volume vazio).

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

Definidas em `docker-compose.yml`. Para overrides locais, copie `.env.example` → `.env` e ajuste (Compose v2+ carrega `.env` para substituição se referenciado).

| Variável | Default | Descrição |
|----------|---------|-----------|
| `DB_DSN` | `mysql:host=mysql;...` | PDO DSN |
| `DB_USER` / `DB_PASS` | `root` / `root` | MySQL |
| `CORS_ORIGINS` | `*` | Origens CORS (CSV) |
| `APP_DEBUG` | `0` | Detalhes de erro JSON |
| `GEO_BR_ONLY` | `0` | Filtro Brasil (fase 3) |

---

## Comandos úteis

```bash
# Logs
docker compose logs -f nginx php

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

Requer PHP 8.2+, extensões `pdo_mysql`, Composer.

```bash
composer install
cp .env.example .env
# Ajustar DB_DSN para MySQL local
php -S localhost:8088 -t public
```

---

## Troubleshooting

### Porta 8088 em uso

Altere em `docker-compose.yml`:

```yaml
ports:
  - "8090:80"
```

### `database: unavailable` no health

- Aguarde o MySQL ficar healthy: `docker compose ps`
- Verifique logs: `docker compose logs mysql`
- Confirme credenciais em `config/settings.php` / env

### `vendor/autoload.php` missing

```bash
docker compose exec php composer install
```

### Permissões no Windows

Montar `./` no PHP deve permitir escrita em `vendor/`. Se falhar, corra `composer install` no host na pasta do projeto (PHP local) ou só dentro do container.

### Conflito com Meelion

| Recurso | Meelion | access-logger |
|---------|---------|---------------|
| HTTP | 80 | **8088** |
| MySQL | 3306 | **3307** |

Stacks independentes — podem correr ao mesmo tempo.

---

## Próxima fase

Fase 3: `AccessLogService` real + persistência PDO (substituir stub `POST /api/access-log`).
