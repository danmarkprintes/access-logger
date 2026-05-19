# Privacidade e LGPD

Orientações para operar o Access Logger em conformidade com a LGPD (Lei 13.709/2018). **Não substitui assessoria jurídica.**

---

## Dados tratados

| Dado | Tabela | Pessoal? | Finalidade |
|------|--------|----------|------------|
| IP | `user_fingerprints` | Sim | Segurança, geo aproximado |
| User-Agent, resolução, timezone | `user_fingerprints` | Possível | Identificação de dispositivo |
| Canvas/WebGL fingerprint | `user_fingerprints` | Possível | Pseudonimização de visitante |
| URL visitada, referer | `access_logs` | Possível | Analytics de navegação |
| UTM | `access_logs` | Geralmente não | Marketing |
| Cliques / labels | `access_log_events` | Possível | Comportamento na página |
| `user_id` (opcional) | `access_logs` | Sim | Vínculo após login no host |

**Base legal típica:** legítimo interesse (art. 7º, IX) ou consentimento para cookies não essenciais — alinhar com política do site host.

---

## Princípios operacionais

1. **Minimização** — não coletar campos além do schema core; desativar geo filter se não necessário.
2. **Transparência** — política de privacidade do site deve mencionar analytics/fingerprint.
3. **Pseudonimização** — `fingerprint_hash` em vez de dados brutos agregados em relatórios públicos.
4. **Retenção limitada** — definir TTL e job de purge (abaixo).
5. **Direitos do titular** — exportação e exclusão sob demanda.

---

## Retenção sugerida

| Tabela | Retenção default | Notas |
|--------|------------------|-------|
| `access_log_events` | 6–12 meses | Maior volume |
| `access_logs` | 12–24 meses | |
| `user_fingerprints` | Enquanto houver logs ativos | Purge em cascata |

Implementar cron (fase 4):

```sql
-- Exemplo: eventos > 12 meses
DELETE FROM access_log_events
WHERE created < DATE_SUB(NOW(), INTERVAL 12 MONTH)
LIMIT 10000;
```

Executar em lotes para não travar o InnoDB.

---

## Purge geo (referência Meelion)

Script destrutivo no monólito: `meelion/docs/sql/purge_non_br_fingerprints_access_logs.sql`

Remove fingerprints fora do perfil Brasil (regras `language` + `timezone`) e dados em cascata.

**No OSS:** adaptar como job opcional `bin/purge-non-target-geo.php` — **sempre** com flag dry-run e backup.

---

## Direitos do titular

### Exportação (portabilidade)

Query de exemplo por `user_id` (quando vinculado):

```sql
SELECT al.*, ale.*
FROM access_logs al
LEFT JOIN access_log_events ale ON ale.access_log_id = al.id
WHERE al.user_id = :user_id
ORDER BY al.created, ale.created;
```

Incluir fingerprint associado. Entregar JSON/CSV ao titular.

### Exclusão

Ordem segura (FK):

1. `access_log_events` (via `access_log_id`)
2. Zerar `previous_access_log_id` se necessário
3. `access_logs`
4. `user_fingerprints` (se não compartilhado com outros titulares — avaliar hash único)

Por fingerprint:

```sql
DELETE FROM user_fingerprints WHERE id = :id;
-- CASCADE remove access_logs e events
```

### Anonimização alternativa

Em vez de delete, anonimizar IP e hashes:

```sql
UPDATE user_fingerprints
SET ip_address = NULL,
    fingerprint_hash = CONCAT('anon-', id),
    user_agent = 'redacted'
WHERE id = :id;
```

---

## Cookies e consentimento

O script `access-logger.js` usa **`sessionStorage`** (não cookie HTTP por padrão). Ainda assim:

- CMP (banner de cookies) pode ser exigido conforme orientação do DPO.
- Modo **opt-in:** só instanciar `AccessLogger` após consentimento analytics.

```javascript
if (window.__analyticsConsent === true) {
  window.accessLogger = new AccessLogger({ ... });
}
```

---

## Transferência internacional

Se o servidor do logger estiver fora do Brasil, documentar na política de privacidade e avaliar cláusulas contratuais (art. 33).

---

## Segurança

| Medida | Status |
|--------|--------|
| HTTPS obrigatório | Produção |
| Rate limit | Sim |
| API key em endpoints admin | Fase 4 |
| Logs de aplicação sem IP em texto claro | Recomendado |
| Backup criptografado | Operação |

---

## Documentos relacionados no Meelion

- `meelion/docs/lgpd_memorial_juridico_2026-03-23.md`
- `meelion/docs/lgpd_relatorio_executivo_2026-03-23.md`
- Export CSV em `UsersController` (permanece no monólito)

O OSS deve oferecer endpoints CLI ou admin **genéricos** de export/delete, sem lógica de negócio Meelion.
