# Filtragem de bots e qualidade de tráfego

Portado do comportamento de `AccessLogService::logAccess()` no Meelion. Objetivo: **não persistir** pageviews de bots, crawlers e tráfego irrelevante, retornando sucesso ao cliente para não gerar erros no JavaScript.

---

## Onde aplica

Filtros rodam no **servidor**, no início de `logAccess()`, **antes** de criar fingerprint ou `access_logs`.

Ordem:

1. URL blocklist (staging)
2. User-Agent bot
3. Timezone suspeito
4. Geo Brasil (opcional, configurável)

---

## 1. URL blocklist

`shouldSkipLogging(url)` — hosts que não devem gerar telemetria.

**Default Meelion:** `deve.meelion.com`

**Config OSS** (`config/settings.php`):

```php
'filtered_hosts' => ['staging.example.com', 'localhost'],
```

Resposta:

```json
{
  "success": true,
  "skipped": true,
  "reason": "URL filtered - development/staging environment"
}
```

---

## 2. User-Agent (`isBotUserAgent`)

Análise case-insensitive do `fingerprint.user_agent`.

### Categorias detectadas

| Categoria | Exemplos |
|-----------|----------|
| Busca | googlebot, adsbot-google, bingbot, slurp, duckduckbot, baiduspider, yandexbot |
| Social | facebookexternalhit, twitterbot, linkedinbot, whatsapp, telegrambot |
| Genérico | crawler, spider, bot, scraper |
| CLI/HTTP libs | curl, wget, python-requests, java/, go-http-client, axios, node-fetch |
| Monitoramento | pingdom, uptimerobot, newrelic, datadog |
| SEO | ahrefsbot, semrushbot, mj12bot |
| Automação | headlesschrome, phantomjs, selenium, webdriver, chrome-lighthouse |
| Produção Meelion | `(compatible; googleother)`, `android 10; k)`, `genspark_flutter` |

### Heurísticas extras

- Chrome com versão major **&lt; 60** → bot/spoofer
- iPhone OS **26+** (inexistente em 2025/2026) → spoofed

### Resposta

```json
{
  "success": true,
  "skipped": true,
  "reason": "Bot detected - access not logged"
}
```

---

## 3. Timezone (`isBotTimezone`)

Lista de fusos associados a datacenters / bot farms (configurável):

- `Asia/Shanghai`, `Asia/Calcutta`, `Asia/Singapore`, `Asia/Hong_Kong`
- `Etc/Unknown`, `UTC`, `Etc/GMT+3`, `Europe/Moscow`

> Se o produto tiver audiência legítima nesses fusos, remova da lista em config.

Combinado com UA: `isBotUserAgent() || isBotTimezone()`.

---

## 4. Filtro geo Brasil (`shouldExcludeNonBrazilTargetFingerprint`)

**Opcional** — no Meelion está ativo. No OSS, flag:

```php
'geo_filter_brazil_only' => false, // true para comportamento Meelion
```

Lógica (resumo):

- Fusos IANA oficiais do Brasil → **aceita**
- `language` começando com `pt` → **aceita**
- `language` `zh*` → **rejeita**
- `en-us` com timezone fora do Brasil e fora de `Europe/*` → **rejeita**

Fingerprint **novo** fora do perfil: não insere linha (`getOrCreateFingerprint` retorna 0).

---

## Comportamento no cliente

| Situação | `currentLogId` | Eventos |
|----------|----------------|---------|
| Gravado | definido | normais |
| Skipped | `null` | descartados silenciosamente |

---

## Rate limiting (complementar)

Não detecta bots sofisticados, mas limita abuso:

- 20 req/min/IP
- 40 req/min/User-Agent

Ver middleware em [STACK.md](./STACK.md).

---

## Manutenção

### Adicionar padrão de bot

Editar array em `AccessLogService` (portado) ou arquivo `config/bot_patterns.php` carregado no bootstrap.

### Monitorar falsos positivos/negativos

- Amostrar `skipped` reasons em log estruturado (sem PII)
- Comparar `total_access` antes/depois de mudanças na lista
- User-Agents únicos em query ad-hoc:

```sql
SELECT user_agent, COUNT(*) c
FROM user_fingerprints
GROUP BY user_agent
ORDER BY c DESC
LIMIT 50;
```

### Falsos negativos

Bots com UA de browser real exigem camadas adicionais (fase futura):

- Honeypot fields
- Verificação de execução JS
- Análise de padrão de eventos (scroll zero, tempo zero, etc.)

---

## Referência Meelion

Documento original: `meelion/docs/bot-filtering-implementation.md`  
Implementação: `meelion/src/Service/AccessLogService.php` — métodos `isBotUserAgent`, `isBotTimezone`, `shouldExcludeNonBrazilTargetFingerprint`, `shouldSkipLogging`.
