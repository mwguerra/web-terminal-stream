# 08 — Logging, events, Filament Logs, tenancy

**Area key:** `logging-filament`

## Summary

The logging subsystem is a near-verbatim copy of the parent mwguerra/web-terminal package's audit surface, but this Stream-only extraction records ONLY connection-lifecycle events (connected/disconnected). logCommand/logOutput/logError/logBlockedCommand exist in TerminalLogger but are called from nowhere in src/, so EVENT_COMMAND/OUTPUT/ERROR/BLOCKED rows are never written. As a result roughly half the Filament Logs surface is dead: the StatsOverview "Commands" and "Errors" tiles are permanently 0, the command/exit_code/execution_time table columns and the entire command/output/SSH-command Infolist sections are always empty, and several filters ("Failed commands only", event_type=command/output/error) never match anything. Connection logging itself works via direct calls in StreamTerminal::connect()/disconnect(). Multi-tenancy is the weakest area: the with-tenant migration hardcodes a NOT NULL tenant_id FK while config defaults tenant_column=null/tenant_resolver=null, so a --with-tenant install with default config makes every insert throw and get silently swallowed (zero logs, no error). The Filament TerminalLogResource has no tenant scoping whatsoever, so in a multi-tenant panel it either 500s or exposes cross-tenant audit rows. The tenant column name is also hardcoded to tenant_id/tenants-table in the migration despite config advertising an arbitrary tenant_column. Retention/cleanup logic is correct but there is no created_at index for it to use, and no scheduler registration (manual-only, documented). The opt-in TerminalLogListener always double-logs and ignores per-terminal overrides.

## What exists and works

- Direct connection/disconnection logging from StreamTerminal::connect()/disconnect() into terminal_stream_logs via TerminalLogger (src/Livewire/StreamTerminal.php:160,188)
- Per-terminal ->log() overrides mapped to TerminalLogger::withOverrides (enabled/connections/identifier/metadata) with clone-based immutability (TerminalLogger.php:34-40, StreamTerminal.php:211-222)
- TerminalConnectedEvent/TerminalDisconnectedEvent dispatched alongside direct logging, non-broadcast (src/Events/*)
- Retention cleanup command terminal-stream:logs:cleanup with --days/--dry-run, defaulting to config retention_days (TerminalLogsCleanupCommand.php)
- All-swallowing try/catch in createLog/getSessionLogs/cleanup so a missing table never breaks a terminal connection (TerminalLogger.php:302,317,371)
- Filament TerminalLogResource read-only (canCreate/canEdit=false), List + View pages, Infolist, Table with filters, StatsOverview widget, correct Filament 5 namespaces (Filament\Schemas, Filament\Forms\Components\DatePicker, Filament\Actions\*)
- Output XSS-escaped via e() before rendering as HTML in the Infolist output section (TerminalLogInfolist.php:165)
- Migration composite indexes on [terminal_session_id, event_type] and [user_id, created_at]; namespaced table terminal_stream_logs for side-by-side isolation

## Gaps (missing functionality)

| Gap | Severity | Detail |
|---|:--:|---|
| Filament TerminalLogResource has zero tenant scoping — cross-tenant audit-log exposure or a broken page in multi-tenant panels | high | The package advertises multi-tenant support (config tenant_column/tenant_resolver, --with-tenant migration, TerminalLog::scopeForTenant) and writes a tenant column on each row, but TerminalLogResource never scopes reads by tenant: there is no getEloquentQuery() override, no tenant ownership relationship, and no isScopedToTenant handling. Registered in a Filament panel with ->tenant(...), the resource's List/View/StatsOverview run unscoped queries (TerminalLog::count(), whereDate(...), commands()->count(), the terminal_identifier distinct filter). Depending on Filament 5's default tenant-scoping path this either throws (no ownership relationship on the model) and 500s the Logs page, or leaks every tenant's connection history (host, ssh_username, ip_address, user_agent) to any tenant admin. The model's scopeForTenant exists but is never called by the resource. |
| No index on created_at — retention cleanup and the default Logs sort do full-table scans | medium | cleanup()/olderThan() filter purely on created_at (TerminalLog.php:158-161) and the table defaultSort is created_at desc (TerminalLogsTable.php:23), but the migration has no standalone created_at index — only the composite [user_id, created_at], whose leading user_id column makes it unusable for a created_at-only range scan or sort. On a large logs table both the periodic cleanup DELETE and every Logs-page load become full scans. terminal_stream_logs is the one table in this package expected to grow unbounded (one row per connect + one per disconnect per pane), so this is exactly where the missing index bites. |
| Retention is manual-only with no scheduler registration | low | terminal-stream:logs:cleanup must be invoked by the host app; the package registers no schedule and the config comment says 'cleanup via manual command'. This is documented/intentional, but combined with unbounded growth it means a default install never prunes and the retention_days config silently does nothing until the operator wires a scheduler. Worth a README callout or an opt-in ->schedule hook. |
| No PII controls on captured connection metadata | low | createLog always persists ip_address (request()->ip()) and user_agent for every connection including local shells, plus host/ssh_username for SSH — with no redaction/opt-out knob (the ->log() overrides only toggle enabled/connections/identifier/metadata). For a security-audit table this is defensible, but there is no way to suppress IP/user-agent capture for privacy-sensitive deployments. |

## Flaws (implemented but wrong/risky)

Each critical/high flaw was handed to an independent skeptic instructed to refute it.

### Half the Filament Logs UI is permanently dead — command/output/error events are never recorded in this package
- **Severity:** high — **Verdict:** ✅ confirmed
- **Files:** src/Services/TerminalLogger.php, src/Filament/Resources/TerminalLogResource/Widgets/TerminalLogsStatsOverview.php, src/Filament/Resources/TerminalLogResource/Tables/TerminalLogsTable.php, src/Filament/Resources/TerminalLogResource/Schemas/TerminalLogInfolist.php, config/web-terminal-stream.php
- **Detail:** logCommand(), logOutput(), logError(), and logBlockedCommand() exist in TerminalLogger but are called from nowhere in src/ (grep confirms only logConnection/logDisconnection are invoked). This Stream extraction only ever writes event_type 'connected'/'disconnected'. Yet the copied-from-parent Filament surface still exposes command-mode UI that can never populate: StatsOverview 'Commands' (TerminalLog::commands()->count()) and 'Errors' (errors()->count()) tiles are permanently 0; the table 'command', 'exit_code', 'execution_time' columns are always '—'; the SelectFilter event_type offers command/output/error options that match nothing and the 'Failed commands only' Filter never returns rows; the Infolist SSH-command, command, output, and exit_code sections never render. Operators see a monitoring dashboard advertising command/error auditing this package structurally cannot provide. CLAUDE.md says connection-only is intentional, but the UI and config (log_errors, max_output_length, truncate_output) were never trimmed to match.
- **Evidence:** grep for logCommand/logOutput/logError/logBlockedCommand and EVENT_COMMAND/OUTPUT/ERROR/BLOCKED across src/ returns only their definitions in TerminalLogger.php + TerminalLog.php and dead references in the Filament table/infolist; StreamTerminal.php only calls logConnection (line 160) and logDisconnection (line 188). Widget: TerminalLogsStatsOverview.php:26-34. Dead filters: TerminalLogsTable.php:
- **Verifier:** Verified: grep across src/ confirms logCommand/logOutput/logError/logBlockedCommand (TerminalLogger.php:180-259) have no callers; only logConnection (StreamTerminal.php:160) and logDisconnection (188) are ever invoked, so only 'connected'/'disconnected' rows are ever written. The copied-from-parent

### --with-tenant migration + default config = every log insert silently fails (all logging broken, no error surfaced)
- **Severity:** high — **Verdict:** ✅ confirmed
- **Files:** database/migrations/create_terminal_stream_logs_table_with_tenant.php.stub, src/Services/TerminalLogger.php, config/web-terminal-stream.php
- **Detail:** The with-tenant migration declares tenant_id as a NOT NULL foreign key: $table->foreignId('tenant_id')->constrained()->cascadeOnDelete() (no ->nullable()). But createLog only sets the tenant column when BOTH tenant_column is configured AND getTenantId() returns non-null: 'if ($tenantColumn && $tenantId !== null)'. Config ships with tenant_column=null and tenant_resolver=null. So after `terminal-stream:install --with-tenant` with default config, TerminalLog::create() never includes tenant_id, the NOT NULL/no-default column rejects the INSERT, and the catch(\Throwable) in createLog swallows the QueryException and returns null. Result: zero rows are ever written and no error is logged or shown — the operator believes auditing is on. The same silent drop happens whenever tenant_resolver returns null mid-request (e.g. no current tenant resolved).
- **Evidence:** create_terminal_stream_logs_table_with_tenant.php.stub:16 (non-nullable tenant_id FK) vs TerminalLogger.php:297-299 (tenant column only added when $tenantId !== null) and TerminalLogger.php:302-305 (catch(\Throwable){ return null; }); config defaults tenant_column=null / tenant_resolver=null at config/web-terminal-stream.php:41,46.
- **Verifier:** Claim verified across the full call chain. Migration stub line 16 declares tenant_id as NOT NULL with no default and no ->nullable(). Config defaults tenant_column=null and tenant_resolver=null (config lines 41,46). In createLog (TerminalLogger.php:268-269,297-299), tenant_id is added to the insert

### with-tenant migration hardcodes tenant_id / 'tenants' table despite config advertising an arbitrary tenant_column
- **Severity:** medium — **Verdict:** ⚪ unverified
- **Files:** database/migrations/create_terminal_stream_logs_table_with_tenant.php.stub, src/Services/TerminalLogger.php, src/Models/TerminalLog.php, config/web-terminal-stream.php
- **Detail:** config documents tenant_column as "'tenant_id' or your tenant column name" and both TerminalLogger::createLog (writes $logData[$tenantColumn]) and TerminalLog::scopeForTenant read that arbitrary column name. But the with-tenant stub hardcodes column 'tenant_id' and ->constrained(), which infers a 'tenants' table. An app whose tenant is Team/Organization (teams/organizations table, team_id column) that sets tenant_column='team_id' gets a schema with tenant_id, so createLog writes to a non-existent team_id column → QueryException → silently swallowed → no logs. The FK also fails to migrate at all if no 'tenants' table exists. The dynamic-column write path and the fixed-column schema are contradictory.
- **Evidence:** create_terminal_stream_logs_table_with_tenant.php.stub:16 hardcodes tenant_id + ->constrained() (table 'tenants'); TerminalLogger.php:268,298 write $logData[$tenantColumn] using config tenant_column; TerminalLog.php:114-120 scopeForTenant uses config tenant_column.

### logDisconnection ignores the per-terminal 'terminals' allow-list that logConnection enforces — orphan disconnect rows
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** src/Services/TerminalLogger.php
- **Detail:** logConnection() gates on shouldLogTerminal($this->getTerminalIdentifier()) (TerminalLogger.php:153) so terminals excluded via config 'terminals' produce no connect row. logDisconnection() has no matching shouldLogTerminal() check (TerminalLogger.php:163-172), so an excluded terminal still writes a 'disconnected' row with no corresponding 'connected' row. getSessionSummary then reports started_at=null but ended_at set. Asymmetric filtering.
- **Evidence:** TerminalLogger.php:153 (logConnection calls shouldLogTerminal) vs TerminalLogger.php:163-172 (logDisconnection omits it).

### Opt-in TerminalLogListener unconditionally double-logs and drops per-terminal overrides
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** src/Listeners/TerminalLogListener.php, src/Livewire/StreamTerminal.php
- **Detail:** StreamTerminal::connect()/disconnect() always dispatch the event AND directly call the logger (there is no flag to disable direct logging). So registering TerminalLogListener guarantees duplicate connected/disconnected rows — the documented caveat is accurate, but it makes the listener effectively unusable rather than an 'alternative approach'. Additionally the listener builds the base app(TerminalLogger) without withOverrides, so it ignores the per-terminal ->log() enabled/identifier/metadata settings that the direct path honors, and handleConnected never passes user_id (relying on auth()->id() at handle time, which is null in a queued context).
- **Evidence:** StreamTerminal.php:148-166 dispatches event then logs directly (unconditional); TerminalLogListener.php:32-58 uses injected base logger with no overrides and omits user_id.

### TerminalLog::user() reads user_table config then never uses it
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** src/Models/TerminalLog.php
- **Detail:** The relationship fetches $userTable = config('...user_table','users') on line 77 but the returned belongsTo only uses the model and foreign key — $userTable is dead. Harmless today (belongsTo derives the table from the related model), but the config key user_table is advertised as configurable and silently has no effect on the relationship.
- **Evidence:** TerminalLog.php:77-82 assigns $userTable then ignores it in the belongsTo().

