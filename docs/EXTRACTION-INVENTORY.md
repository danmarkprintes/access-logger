# Inventário de extração — Meelion → access-logger

Mapeamento ficheiro a ficheiro. **Não alterar** o repositório Meelion na fase 1–4; apenas copiar/portar para `access-logger`.

Legenda: **Copiar** | **Portar lógica** | **Adapter** | **Ignorar**

---

## Backend PHP

| Ação | Origem (Meelion) | Destino (access-logger) | Notas |
|------|------------------|-------------------------|-------|
| Portar | `src/Service/AccessLogService.php` | `src/Service/AccessLogService.php` | Remover gates, `Features`, `user_activities`, métodos pixel; trocar ORM por PDO |
| Portar | `src/Controller/AccessLogController.php` | `src/Controller/AccessLogController.php` | Remover `gateFeatureOpen`, `gateFeaturePass`, `pixel`; Slim PSR-7 |
| Portar | `src/Model/Table/AccessLogsTable.php` | `src/Repository/AccessLogRepository.php` | SQL explícito |
| Portar | `src/Model/Table/AccessLogEventsTable.php` | `src/Repository/AccessLogEventRepository.php` | |
| Portar | `src/Model/Table/UserFingerprintsTable.php` | `src/Repository/FingerprintRepository.php` | |
| Ignorar | `src/Model/Table/AccessLogFeatureEventsTable.php` | — | Gates |
| Ignorar | `src/Model/Entity/AccessLogFeatureEvent.php` | — | Gates |
| Copiar refs | `src/Model/Entity/AccessLog.php` etc. | DTOs opcionais | Arrays associativos bastam |
| Adapter | `src/Service/ExternalLinkClicksBackupService.php` | `src/Adapter/ExternalLinkClicksBackup.php` | Opcional; SQLite + join users |
| Ignorar | `src/Service/GateAnalyticsService.php` | — | Meelion admin |
| Ignorar | `src/Service/ConversionFunnelAnalyticsService.php` | — | Usa gate events |
| Ignorar | `src/Service/AnalyticsService.php` | — | Reimplementar fase 4 |
| Ignorar | `src/Controller/LoginController.php` (trecho link) | — | Documentar hook em INTEGRATION |
| Ignorar | `src/Controller/UsersController.php` (export LGPD) | — | CLI export fase 4 |

---

## Rotas e infra

| Ação | Origem | Destino | Notas |
|------|--------|---------|-------|
| Portar | `config/routes.php` L230–241 | `src/Routes.php` | Sem gate/pixel |
| Portar | `webroot/rate_limit_access_log.php` | `src/Middleware/RateLimitMiddleware.php` | APCu ou fallback arquivo |
| Ignorar | `webroot/index.php` (require rate limit) | `public/index.php` | Bootstrap Slim único |

---

## Frontend

| Ação | Origem | Destino | Notas |
|------|--------|---------|-------|
| Copiar | `webroot/js/access-logger.js` | `web/access-logger.js` | Sem mudança funcional na fase 3 |
| Ignorar | `webroot/js/access-logger-backup.js` | — | Legado |
| Ignorar | `webroot/js/auth-gate.js` | — | Gates Meelion |
| Ignorar | `webroot/css/auth-gate.css` | — | |
| Ignorar | `templates/element/Auth/auth_gate_modal.php` | — | |

---

## SQL e schema

| Ação | Origem | Destino | Notas |
|------|--------|---------|-------|
| Consolidar | `config/schema/meelion (18).sql` (tabelas core) | `docs/sql/schema_core.sql` | **Feito** fase 1 |
| Consolidar | `config/Migrations/20250815094500_CreateAccessLogEvents.php` | `schema_core.sql` | |
| Ignorar | `docs/sql/gate_audit_schema_manual.sql` | — | Gates |
| Ignorar | `docs/sql/recreate_gate_tables_clean.sql` | — | |
| Adapter doc | `docs/sql/purge_non_br_fingerprints_access_logs.sql` | `docs/PRIVACY-LGPD.md` | Job opcional |
| Ignorar | `config/Migrations/20250613023730_CreateAccessLogTables.php` | — | Stub vazio |

---

## Testes

| Ação | Origem | Destino | Notas |
|------|--------|---------|-------|
| Portar | `tests/TestCase/Service/AccessLogServiceTest.php` | `tests/AccessLogServiceTest.php` | Remover casos gate |
| Portar | `tests/TestCase/Model/Table/AccessLogsTableTest.php` | `tests/AccessLogRepositoryTest.php` | PDO + fixtures |
| Portar | `tests/TestCase/Model/Table/AccessLogEventsTableTest.php` | `tests/AccessLogEventRepositoryTest.php` | |
| Portar | `tests/Fixture/*.php` | `tests/fixtures/sql/` | SQL seed |
| Ignorar | `tests/TestCase/Service/SubscriptionServiceTest.php` (ALFE) | — | Gates/checkout |
| Adaptar | `tests/indicadores-financeiros-accesslogger.spec.ts` | `tests/e2e/access-logger.spec.ts` | URL microserviço |

---

## Documentação Meelion (referência)

| Ficheiro Meelion | Uso no OSS |
|------------------|------------|
| `docs/bot-filtering-implementation.md` | → `docs/BOT-FILTERING.md` |
| `docs/analytics-dashboard.md` | Inspiração fase 4 ROADMAP |
| `docs/gate-audit-playbook.md` | **Não portar** — link como Meelion-only |
| `docs/feature-gate-service.md` | **Não portar** |

---

## Métodos AccessLogService — checklist de portação

| Método | Portar? |
|--------|---------|
| `logAccess` | Sim |
| `updateAccessLog` | Sim |
| `recordEvents` | Sim (sem backup corretora ou como adapter) |
| `recordSingleAccessLogEvent` | Sim |
| `normalizeEventPayload` | Sim |
| `getOrCreateFingerprintId` / `getOrCreateFingerprint` | Sim |
| `linkAccessLogsToUserByFingerprint` | Sim (se coluna `user_id`) |
| `hasAccessLogsUserIdColumn` | Sim (feature detect ou config) |
| `getAccessStats` | Sim |
| `recordGateFeatureOpen` | **Não** |
| `recordGateFeaturePass` | **Não** |
| `recordPixelActivity` | **Não** (adapter Meelion) |
| `isBotUserAgent`, `isBotTimezone`, geo filter | Sim (configurável) |

---

## Namespace e autoload

| Meelion | OSS |
|---------|-----|
| `App\Service\AccessLogService` | `AccessLogger\Service\AccessLogService` |
| Composer PSR-4 `App\` | `AccessLogger\` → `src/` |
