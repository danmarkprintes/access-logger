# Deploy em produção — PHP + MySQL + LiteSpeed (LSWS)

**Critério de sucesso:** o access-logger deve funcionar em produção **sem Docker**, apenas com:

- **LiteSpeed Web Server** (OpenLiteSpeed ou LiteSpeed Enterprise)
- **PHP 8.2+** (handler `lsphp` / LSAPI)
- **MySQL 8.0+** (ou MariaDB compatível)

O Docker deste repositório é **só para desenvolvimento local**. A stack de produção é a mesma família do Meelion (PHP + MySQL + LSWS).

---

## Compatibilidade

| Componente | Suportado | Notas |
|------------|-----------|--------|
| LiteSpeed Web Server | Sim | Rewrite para `public/index.php` via `.htaccess` |
| PHP 8.2+ | Sim | Extensões: `pdo_mysql`, `json`, `mbstring` |
| MySQL 8 | Sim | Schema em `docs/sql/schema_core.sql` |
| APCu (rate limit) | Opcional | Recomendado; senão usa ficheiros em `/tmp` |
| Docker | Não obrigatório | Apenas dev |

**Slim 4** é PHP puro (Composer + `vendor/`). Não exige nginx, Apache nem Node em produção.

---

## Requisitos PHP (LSWS)

No painel LiteSpeed ou `php.ini` do vhost, confirmar:

```ini
extension=pdo_mysql
; Opcional — rate limit mais eficiente:
extension=apcu
apc.enabled=1
```

Versão mínima: **PHP 8.2**.

```bash
php -v
php -m | grep -E 'pdo_mysql|apcu'
```

---

## Passo a passo — deploy

### 1. Publicar ficheiros no servidor

Exemplo de destino:

```text
/home/user/access-logger/
├── config/
├── public/          ← document root do vhost
├── src/
├── vendor/          ← após composer install
├── web/             ← access-logger.js (fase 3)
└── docs/
```

No servidor (SSH):

```bash
cd /caminho/access-logger
composer install --no-dev --optimize-autoloader
```

Não é necessário `node`, `npm` nem container.

### 2. Base de dados

```bash
mysql -u root -p -e "CREATE DATABASE access_logger CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p access_logger < docs/sql/schema_core.sql
```

Criar utilizador dedicado com privilégios só em `access_logger.*`.

### 3. Configuração (`settings.local.php`)

```bash
cp config/settings.local.php.example config/settings.local.php
# Editar credenciais MySQL e CORS
```

Este ficheiro **não vai para o Git** (ver `.gitignore`).

Alternativa: variáveis no ambiente do LSWS (`DB_DSN`, `DB_USER`, `DB_PASS`, `CORS_ORIGINS`) — `config/settings.php` lê `getenv()`.

### 4. Virtual host LiteSpeed — ponto crítico

**Document Root** = pasta `public/` do projeto (não a raiz do repo).

| Campo | Valor exemplo |
|-------|----------------|
| Document Root | `/home/user/access-logger/public` |
| Index files | `index.php` |
| PHP Handler | `lsphp82` (ou versão instalada) |

#### Rewrite (escolha uma opção)

**Opção A — `.htaccess` (recomendado)**  
Já incluído em `public/.htaccess`. No vhost, ativar:

- **Enable Rewrite**: Yes  
- **Auto Load from .htaccess**: Yes (ou equivalente no painel)

**Opção B — Rewrite no painel LSWS**  
Se não usar `.htaccess`, nas Rewrite Rules do virtual host:

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

#### Servir o JavaScript (`/web/`)

Quando existir `web/access-logger.js` (fase 3):

**Opção 1 — Alias no vhost** (docroot continua `public/`):

```text
Context /web/ → /home/user/access-logger/web/
```

**Opção 2 — Cópia/symlink** para `public/web/access-logger.js` e URL `https://logger.seudominio.com/web/access-logger.js`.

### 5. Subpath (opcional)

Se a API não estiver na raiz do domínio (ex. `https://seudominio.com/access-logger/api/access-log`):

1. Descomente `RewriteBase /access-logger` em `public/.htaccess`
2. Configure base path no Slim (fase 3 — ver nota técnica abaixo)

Para subdomínio dedicado (`https://logger.seudominio.com/`), **não** precisa de `RewriteBase`.

### 6. HTTPS e CORS

- Force HTTPS no LSWS (SSL vhost).
- Em `settings.local.php`, liste origens reais em `cors.allowed_origins` (sites que embutem o script).
- Não use `*` em produção se enviar cookies (não é o caso do logger atual).

### 7. Cache (importante no LSWS)

**Não cachear** respostas da API de ingestão:

No painel, para o contexto da API ou paths `/api/`:

- LiteSpeed Cache: **Off** para `POST`/`PUT` em `/api/access-log*`
- Ou regra de exclusão no cache do domínio

Pageviews enviados via POST devem chegar sempre ao PHP.

### 8. Validação pós-deploy

```bash
# JSON (monitoramento)
curl -s "https://logger.seudominio.com/health?format=json"
# {"ok":true,"database":"connected","phase":3,...}

# Browser: https://logger.seudominio.com/health → página HTML com status

curl -s -X POST https://logger.seudominio.com/api/access-log \
  -H "Content-Type: application/json" \
  -d '{"url":"https://www.meelion.com/","fingerprint":{"user_agent":"Mozilla/5.0","language":"pt-BR","timezone":"America/Sao_Paulo"}}'
```

No browser (site Meelion ou outro), apontar o JS para `https://logger.seudominio.com/api/access-log`.

---

## Diferenças Docker (dev) vs LSWS (prod)

| Item | Docker local | Produção LSWS |
|------|----------------|---------------|
| Servidor HTTP | nginx | LiteSpeed |
| PHP | php-fpm container | lsphp nativo |
| MySQL host | `mysql` (rede Docker) | `127.0.0.1` ou socket |
| Config | `docker-compose.yml` env | `settings.local.php` |
| Porta | 8088 | 443 (HTTPS) |
| Composer | no container ou host | no servidor via SSH |

---

## Segurança em produção

- `display_error_details` = `false` em `settings.local.php`
- Pasta `vendor/`, `config/settings.local.php`, `src/` **fora** do docroot (`public/` apenas exposto)
- Permissões: utilizador LSWS com leitura no projeto; escrita só em `rate_limit.storage_path` se usar fallback ficheiro
- Rate limit ativo (APCu ou `/tmp`)
- MySQL: utilizador com mínimo privilégio

---

## Troubleshooting LSWS

### 404 em todas as rotas exceto `/`

- Document root não é `public/`, ou rewrite desativado.
- Testar: `https://dominio/health` — deve bater no Slim.

### 500 / página em branco

- Ver `stderr.log` do vhost LSWS e log PHP.
- `vendor/` instalado? `composer install` no servidor.
- Extensão `pdo_mysql` ativa?

### `/health` em branco no browser

- A partir da fase 3+, `/health` devolve **HTML** quando o `Accept` pede `text/html`.
- Para JSON: `?format=json` ou `curl` sem Accept HTML.
- Confirme que a rota não está a ser servida por ficheiro estático antigo em `public/health`.

### `database: unavailable` no `/health`

- Credenciais em `settings.local.php`
- MySQL acessível do host PHP (`127.0.0.1` vs socket: `mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname=...`)

### CORS no browser

- Incluir origem exata (`https://www.meelion.com`) em `allowed_origins`
- Pedido `OPTIONS` deve responder 204 (middleware CORS)

### Rate limit 429 em tráfego legítimo

- Aumentar `RATE_LIMIT_IP` / `RATE_LIMIT_UA` no ambiente
- Ativar APCu para contadores consistentes

---

## Integração com o Meelion (mesmo LSWS ou outro host)

O Meelion pode continuar no vhost principal (`www.meelion.com`). O logger num **subdomínio** ou path separado:

```javascript
new AccessLogger({
  endpoint: 'https://logger.meelion.com/api/access-log',
  updateEndpoint: 'https://logger.meelion.com/api/access-log/update',
  eventsEndpoint: 'https://logger.meelion.com/api/access-log/events'
});
```

Ver [EXTRACTION-MEELION-INTEGRATION.md](./EXTRACTION-MEELION-INTEGRATION.md).

---

## Checklist de critério de sucesso (produção)

- [ ] PHP 8.2+ com `pdo_mysql`
- [ ] MySQL com `access_logger` e schema aplicado
- [ ] `composer install --no-dev` no servidor
- [ ] Virtual host LSWS com **Document Root = `public/`**
- [ ] Rewrite ativo (`.htaccess` ou regras no painel)
- [ ] `config/settings.local.php` com DB e CORS
- [ ] `GET /health` → 200 + `database: connected` (JSON com `?format=json`)
- [ ] `POST /api/access-log` → `{ "success": true, "log_id": ... }` (persistência real)
- [ ] Cache LSWS não intercepta POST da API
- [ ] HTTPS válido no domínio do logger
