# Stack técnica — Slim 4 + PDO

Decisão: **microserviço PHP standalone**, sem CakePHP, o mais leve possível com manutenção razoável.

---

## Por que Slim 4 (e não Cake / Laravel / Flight puro)

| Critério | Slim 4 | Flight 3 | FastRoute + PDO | CakePHP 5 |
|----------|--------|----------|---------------|-----------|
| Tamanho vendor | ~2 MB | ~1 MB | ~0,5 MB | ~30+ MB |
| Middleware PSR-15 | Nativo | Manual | Manual | Sim |
| Routing | Sim | Sim | Sim | Sim |
| Curva para API JSON | Baixa | Baixa | Média | Alta |
| Testes PHPUnit | Fácil | Fácil | Fácil | Fácil |
| Relatórios futuros | Rotas + Twig/SPA | Idem | Mais código | Overkill |

**Recomendação:** Slim 4 — equilíbrio entre “pequeno” e não reinventar CORS, rate limit e error handler.

Flight 3 é alternativa válida se quiser reduzir ainda mais dependências (ver [ROADMAP.md](./ROADMAP.md) alternativas).

---

## Dependências Composer

```json
{
  "require": {
    "php": ">=8.2",
    "slim/slim": "^4.14",
    "slim/psr7": "^1.7"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0"
  },
  "autoload": {
    "psr-4": {
      "AccessLogger\\": "src/"
    }
  }
}
```

Opcionais:

| Pacote | Uso |
|--------|-----|
| `php-di/php-di` | Container simples (ou factory manual em `public/index.php`) |
| `monolog/monolog` | Logs estruturados |
| `vlucas/phpdotenv` | **Somente no access-logger** — Docker env; Meelion não usa `.env` |

**Não usar:** Doctrine ORM, Laravel, Cake, API Platform.

---

## Estrutura de pastas (fase 2)

```
access-logger/
├── public/
│   └── index.php           # Front controller
├── src/
│   ├── App.php             # Bootstrap Slim
│   ├── Routes.php
│   ├── Middleware/
│   │   ├── CorsMiddleware.php
│   │   └── RateLimitMiddleware.php
│   ├── Controller/
│   │   └── AccessLogController.php
│   ├── Service/
│   │   └── AccessLogService.php
│   └── Repository/
│       ├── PdoConnection.php
│       ├── FingerprintRepository.php
│       ├── AccessLogRepository.php
│       └── AccessLogEventRepository.php
├── config/
│   └── settings.php        # DB, CORS origins, filtros
├── web/
│   └── access-logger.js
├── docs/
├── tests/
├── docker-compose.yml
├── Dockerfile
└── composer.json
```

---

## Bootstrap mínimo (`public/index.php`)

```php
<?php
declare(strict_types=1);

use AccessLogger\App;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';
$app = AppFactory::create();
App::register($app, $settings);
$app->run();
```

`App::register` adiciona middleware, error handler JSON, rotas.

---

## Configuração (`config/settings.php`)

```php
<?php
return [
    'db' => [
        'dsn' => getenv('DB_DSN') ?: 'mysql:host=mysql;dbname=access_logger;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: 'root',
    ],
    'cors' => [
        'allowed_origins' => explode(',', getenv('CORS_ORIGINS') ?: '*'),
    ],
    'rate_limit' => [
        'ip_per_minute' => 20,
        'ua_per_minute' => 40,
    ],
    'filters' => [
        'geo_brazil_only' => filter_var(getenv('GEO_BR_ONLY') ?: '0', FILTER_VALIDATE_BOOL),
        'filtered_hosts' => ['deve.meelion.com'],
    ],
];
```

---

## PDO — padrão repository

```php
// Exemplo: inserir access_log
$stmt = $pdo->prepare(
    'INSERT INTO access_logs (user_fingerprint_id, session_id, url, ...)
     VALUES (:fid, :sid, :url, ...)'
);
$stmt->execute([...]);
return (int) $pdo->lastInsertId();
```

Sem ORM — alinhado à regra Meelion de SQL explícito para queries de carga.

---

## Rate limit middleware

Portar lógica de `meelion/webroot/rate_limit_access_log.php`:

- Chaves APCu: `accesslogger:rl:ip:{hash}:{bucket}`
- Fallback: arquivo em `/tmp/access-logger-rl/` se APCu indisponível
- Aplicar a **todas** as rotas `/api/access-log*`
- Resposta 429 JSON no OSS (melhor que text/plain)

---

## Docker Compose (esboço fase 2)

```yaml
services:
  nginx:
    image: nginx:alpine
    ports: ["8088:80"]
    volumes:
      - ./public:/var/www/html/public
  php:
    build: .
    volumes: ["./:/var/www/html"]
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: access_logger
      MYSQL_ROOT_PASSWORD: root
```

Healthcheck: `GET /health` → `{ "ok": true }`.

---

## Servir o JavaScript

Nginx:

```nginx
location /web/ {
    alias /var/www/html/web/;
    add_header Access-Control-Allow-Origin *;
}
```

Ou publicar `access-logger.js` em CDN/npm na fase 3.

---

## Integração Meelion

Ver [EXTRACTION-MEELION-INTEGRATION.md](./EXTRACTION-MEELION-INTEGRATION.md).

Host Meelion apenas muda URLs no construtor `AccessLogger` — sem depender do stack Cake do logger.
