# Contrato da API REST

Base URL configurável (ex.: `https://logger.example.com`). Endpoints de telemetria usam prefixo `/api/access-log`.

**Content-Type (telemetria):** `application/json`  
**Charset:** UTF-8

Endpoints de **ingestão** são públicos (sem API key). Respostas de erro seguem `{ "success": false, "message": "..." }`.

Códigos HTTP comuns fora da ingestão: **404** (rota inexistente), **405** (método não permitido), **500** (erro interno).

---

## GET `/health`

Verificação de vida do serviço e ligação ao MySQL. **Não** está sob `/api/access-log`.

### Negociação de conteúdo

| Cliente | Resposta |
|---------|----------|
| Browser (`Accept: text/html`) | Página HTML legível (status, MySQL, links) |
| `curl`, monitoramento, Playwright (`Accept: */*` ou vazio) | JSON |
| Qualquer cliente com `?format=json` | JSON |
| Qualquer cliente com `?format=html` | HTML |

### Exemplo JSON

```json
{
  "ok": true,
  "service": "access-logger",
  "phase": 3,
  "database": "connected",
  "timestamp": "2026-05-19T20:19:25+00:00"
}
```

### URLs locais (Docker)

- HTML: http://localhost:8088/health  
- JSON: http://localhost:8088/health?format=json  

`/health/` (com barra final) redireciona para `/health` (301).

---

## POST `/api/access-log`

Registra o pageview inicial e cria/atualiza o fingerprint.

### Request body

| Campo | Tipo | Obrigatório | Descrição |
|-------|------|-------------|-----------|
| `url` | string | **sim** | URL completa da página |
| `session_id` | string | não | UUID; gerado no servidor se ausente |
| `referer` | string | não | Preenchido com header `Referer` se ausente |
| `page_load_time` | int | não | Tempo de carregamento (ms) |
| `navigation_order` | int | não | Enviado pelo cliente; servidor recalcula por sessão |
| `previous_log_id` | int | não | ID do pageview anterior (cliente); servidor recalcula |
| `viewport_width` | int | não | |
| `viewport_height` | int | não | |
| `fingerprint` | object | **sim** | Ver [FRONTEND.md](./FRONTEND.md) |
| `utm_*` | string | não | Também extraídos da `url` no servidor |

O servidor adiciona: `ip_address` (do request), `is_authenticated`, `user_id` (se sessão autenticada no host).

### Exemplo

```json
{
  "url": "https://www.example.com/produtos?utm_source=google",
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "referer": "https://www.google.com/",
  "page_load_time": 842,
  "fingerprint": {
    "screen_resolution": "1920x1080",
    "user_agent": "Mozilla/5.0 ...",
    "language": "pt-BR",
    "timezone": "America/Sao_Paulo",
    "device_type": "desktop",
    "canvas_fingerprint": "a1b2c3..."
  }
}
```

### Respostas

**200 — gravado**

```json
{
  "success": true,
  "log_id": 12345,
  "session_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**200 — filtrado (bot, geo, URL staging)**

```json
{
  "success": true,
  "skipped": true,
  "reason": "Bot detected - access not logged",
  "log_id": null,
  "session_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**400** — `url` ausente  
**500** — erro interno

---

## POST / PUT `/api/access-log/update`

Atualiza métricas de engajamento de um pageview existente.

### Request body

| Campo | Tipo | Obrigatório | Descrição |
|-------|------|-------------|-----------|
| `log_id` | int | **sim** | ID retornado no POST inicial |
| `scroll_depth` | int | não | Profundidade máxima de scroll (px) |
| `time_on_page` | int | não | Segundos na página |
| `exit_type` | enum | não | `navigation`, `close`, `refresh`, `back`, `forward` |

### Resposta

**200**

```json
{
  "success": true,
  "message": "Access log updated successfully"
}
```

**404** — `log_id` inexistente

---

## POST `/api/access-log/events`

Persiste um lote de eventos para um pageview.

### Request body

| Campo | Tipo | Obrigatório | Descrição |
|-------|------|-------------|-----------|
| `access_log_id` | int | **sim** | Mesmo que `log_id` do pageview |
| `events` | array | **sim** | 1–100 objetos de evento |

Cada evento:

| Campo | Tipo | Obrigatório | Limite |
|-------|------|-------------|--------|
| `event_name` | string | **sim** | 96 chars |
| `element_type` | string | não | 32 |
| `element_label` | string | não | 128 |
| `target_href` | string | não | 255 |
| `numeric_value` | number | não | |
| `scroll_percent` | int | não | 0–100 |
| `time_offset_ms` | int | não | calculado no servidor se ausente |

### Exemplo

```json
{
  "access_log_id": 12345,
  "events": [
    {
      "event_name": "button_click",
      "element_type": "button",
      "element_label": "cta_comprar",
      "target_href": "/checkout",
      "numeric_value": 1,
      "time_offset_ms": 4200
    }
  ]
}
```

### Resposta

**200**

```json
{
  "success": true,
  "inserted": 1
}
```

**400** — `access_log_id` ou `events` inválidos  
**404/500** — pageview não encontrado ou falha ao salvar

---

## POST `/api/access-log/event`

Registra **um** evento (legado / integrações síncronas). Corpo: `access_log_id` + objeto `event` (ou campos do evento na raiz).

### Resposta

**200**

```json
{
  "success": true,
  "access_log_event_id": 98765
}
```

---

## GET `/api/access-log/stats`

Agregados simples para dashboards.

### Query params

| Param | Tipo | Descrição |
|-------|------|-----------|
| `date_from` | datetime | Filtro `created >=` |
| `date_to` | datetime | Filtro `created <=` |
| `is_authenticated` | 0\|1 | Filtrar autenticados |

### Resposta

```json
{
  "success": true,
  "data": {
    "total_access": 15000,
    "unique_users": 3200,
    "authenticated_access": 800,
    "anonymous_access": 14200
  }
}
```

> Na fase 4, proteger com API key (ver [ROADMAP.md](./ROADMAP.md)).

---

## GET `/api/access-log/journey`

Lista pageviews de uma sessão, ordenados por `navigation_order`.

### Query params

| Param | Obrigatório |
|-------|-------------|
| `session_id` | **sim** |

### Resposta

```json
{
  "success": true,
  "data": [
    {
      "id": 100,
      "url": "https://example.com/",
      "navigation_order": 1,
      "created": "2026-05-19 10:00:00",
      "user_fingerprint": { "id": 5, "device_type": "desktop" }
    }
  ]
}
```

---

## GET `/api/access-log/fingerprint`

Detalhe de um fingerprint.

### Query params

Informe **um** de:

- `fingerprint_id` (int)
- `fingerprint_hash` (string)

### Resposta

**200** — objeto fingerprint  
**404** — não encontrado

---

## Rate limiting

Quando excedido:

- **HTTP 429**
- Header `Retry-After: 60`
- Corpo texto: `429 Too Many Requests`

Limites padrão (configuráveis):

- 20 requisições/minuto por IP
- 40 requisições/minuto por User-Agent

Aplica-se a rotas sob `/api/access-log` (no OSS, todas; no Meelion legado, só o path exato `/api/access-log` no script APCu).

---

## CORS

O microserviço deve enviar headers para origens permitidas configuradas em `config/settings.php`:

```
Access-Control-Allow-Origin: https://www.example.com
Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS
Access-Control-Allow-Headers: Content-Type
```

`OPTIONS` pré-flight deve retornar 204.

---

## Endpoints Meelion-only (não implementar no OSS)

| Endpoint | Motivo |
|----------|--------|
| `POST /api/access-log/gate-feature-open` | Gates |
| `POST /api/access-log/gate-feature-pass` | Gates |
| `POST /api/access-log/pixel` | Tabela `user_activities` |

---

## Exemplos curl

```bash
# Pageview
curl -s -X POST https://logger.local/api/access-log \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com/","fingerprint":{"user_agent":"Mozilla/5.0","timezone":"America/Sao_Paulo","language":"pt-BR"}}'

# Update
curl -s -X POST https://logger.local/api/access-log/update \
  -H 'Content-Type: application/json' \
  -d '{"log_id":1,"scroll_depth":400,"time_on_page":30}'

# Eventos
curl -s -X POST https://logger.local/api/access-log/events \
  -H 'Content-Type: application/json' \
  -d '{"access_log_id":1,"events":[{"event_name":"button_click","element_label":"hero_cta"}]}'
```
