# 09 — Artisan commands, installer, stubs

**Area key:** `commands-install`

## Summary

The four artisan commands (terminal-stream:install, :make-page, :logs:cleanup, :serve) and the four stubs are largely correct and current: all are registered in WebTerminalStreamServiceProvider (lines 51-58); the stubs use only the live fluent API (->key/->local/->workingDirectory/->height/->title/->log — verified against src/Concerns/*) with NO removed methods (streamTheme/only/terminals/windowControls/startConnected/autoConnect are absent); the stub Filament-5 namespaces are correct (Filament\Schemas\Components\Section, Filament\Schemas\Schema, Filament\Actions\Action, Filament\Resources\Pages\ListRecords/ViewRecord); the redeclared static property types in terminal-page.php.stub exactly match Filament 5.6.7's Page base (string|BackedEnum|null, string|UnitEnum|null, ?string, ?int); every translation key referenced in the stubs exists in lang/en/terminal.php; and the plugin methods cited in the installer's next-steps guidance (withoutTerminalPage/withoutTerminalLogs/components/components([])) all exist with the described behavior. The defects are secondary: install --force duplicates rather than overwrites the timestamped migration (an idempotency footgun that breaks migrate on re-run), make-page never StudlyCase-normalizes or validates the class name (reasonable non-studly input yields an uncompilable class or path traversal), logs:cleanup silently discards --days=0 due to PHP "0" string falsiness, serve treats empty --host=/--port= strings as valid, and there is zero automated test coverage for any command, the 640-LOC installer, or stub generation (no tests/Unit/Console directory).

## What exists and works

- All four commands (TerminalInstallCommand, TerminalLogsCleanupCommand, TerminalMakePageCommand, TerminalServeCommand) registered under runningInConsole() in WebTerminalStreamServiceProvider.php:51-58
- Stubs use only the current fluent API verified live: ->key(), ->local() (ConfiguresConnection.php:40), ->workingDirectory() (ConfiguresConnection.php:110), ->height()/->title() (ConfiguresAppearance.php:47,65), ->log(enabled,connections,identifier) (ConfiguresLogging.php:39) — signature matches named args in the stub
- No removed/deprecated API referenced anywhere in stubs/ or src/Console/ (grep for streamTheme, ->only(, ->terminals(, windowControls, startConnected, autoConnect all empty)
- terminal-page.php.stub redeclared static property types (string|BackedEnum|null $navigationIcon, string|UnitEnum|null $navigationGroup, ?string $navigationLabel, ?int $navigationSort, ?string $slug) exactly match Filament\Pages\Page in installed Filament v5.6.7 (Page.php:55,61,65,67) — no invariance fatal
- All stub Filament-5 namespaces correct: Filament\Schemas\Components\Section, Filament\Schemas\Schema, Filament\Actions\Action, Filament\Resources\Pages\ListRecords/ViewRecord
- Every translation key used in stubs exists in lang/en/terminal.php (navigation.terminal_logs/tools, pages.terminal.local_terminal[_description], resource.back) — no raw-key leakage
- Installer next-steps guidance references only real plugin methods: withoutTerminalPage() (Plugin:217), withoutTerminalLogs() (Plugin:306), components() (Plugin:193); components([]) correctly registers neither page nor resource (Plugin:register 92-96 foreach over empty array) so the 'keep services without pages' note is accurate
- serve/cleanup config keys resolve correctly: web-terminal-stream.stream.ratchet_host/ratchet_port (config:60-61) and logging.retention_days (config:34) exist; TerminalLog::olderThan scope (Model:158) and TerminalLogger::cleanup (Service:365) back the cleanup command
- Migration publishing uses distinct with-tenant/standard stubs (both present in database/migrations/) selected by --with-tenant/--no-tenant with mutual-exclusion validation (Install:76-80)
- TerminalServeCommand guards missing ReactPHP (class_exists(SocketServer)) and instantiates the real MWGuerra\WebTerminalStream\WebSocket\ReactPhpProvider whose start(string,int) signature matches (ReactPhpProvider.php:21)

## Gaps (missing functionality)

| Gap | Severity | Detail |
|---|:--:|---|
| Zero automated test coverage for all artisan commands, the installer, and stub generation | medium | There is no tests/Unit/Console directory and no test anywhere references terminal-stream:install, TerminalInstallCommand, TerminalServeCommand, TerminalMakePageCommand, logs:cleanup, or make-page (grep across tests/ excluding e2e-app returns nothing). The 640-LOC installer with its panel-selection, tenant branching, idempotency guards, non-interaction matrix, and stub rendering is entirely untested, as are the cleanup retention logic and stub-to-fluent-API compatibility. This directly conflicts with the repo's testing mandate ('no test in the suite skips'; features require tests) and means a stub referencing a renamed fluent method or a broken next-steps snippet would ship undetected. A single artisan()->assertExitCode + generated-file-content test per command would catch the flaws below. |

## Flaws (implemented but wrong/risky)

Each critical/high flaw was handed to an independent skeptic instructed to refute it.

### install --force creates a duplicate timestamped migration instead of overwriting, breaking re-runs
- **Severity:** medium — **Verdict:** ⚪ unverified
- **Files:** src/Console/Commands/TerminalInstallCommand.php
- **Detail:** publishMigration() gates the existing-migration guard on `! $this->option('force')` (line 393), so with --force the guard is skipped and line 407 copies to a fresh `{$timestamp}_create_terminal_stream_logs_table.php`. Because the destination is timestamp-prefixed, --force never overwrites the prior file — it ADDS a second migration that also runs Schema::create('terminal_stream_logs'). Re-provisioning/CI that runs `terminal-stream:install --force` twice, then `php artisan migrate`, fails with 'table terminal_stream_logs already exists'. This contradicts the --force signature help ('Overwrite existing files', line 32). The fix is to glob-and-delete/replace the existing migration under --force, or refuse to duplicate.
- **Evidence:** src/Console/Commands/TerminalInstallCommand.php:392-407 — glob check `if (! empty($existingMigrations) && ! $this->option('force'))` then unconditional `$this->files->copy($source, $destination)` with a new date('Y_m_d_His') prefix.

### make-page does not StudlyCase-normalize or validate the class name, producing uncompilable files or path traversal
- **Severity:** medium — **Verdict:** ⚪ unverified
- **Files:** src/Console/Commands/TerminalMakePageCommand.php
- **Detail:** getPageName() returns the raw argument/prompt input unmodified (lines 148-167); generatePage() writes `$directory.'/'.$name.'.php'` and passes `class_name => $name` verbatim into the stub (lines 236, 257-263), applying str()->headline()/kebab() only to the navigation label and slug. Input like `server terminal` or `server-console` yields `class server terminal` / `class server-console` — a PHP parse error in the generated file. Input containing slashes (e.g. `../Foo` or `Admin/Terminal`) writes outside the intended directory. Laravel's own make: commands studly the class name; this one should too, and should reject non-identifier names.
- **Evidence:** src/Console/Commands/TerminalMakePageCommand.php:150 `return $name;` (raw), :236 `$path = $directory.'/'.$name.'.php';`, :257-263 `'class_name' => $name` — no Str::studly / identifier validation anywhere.

### logs:cleanup silently ignores --days=0 (falls back to 90) due to PHP "0"-string falsiness
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** src/Console/Commands/TerminalLogsCleanupCommand.php
- **Detail:** The retention is resolved with `$this->option('days') ? (int) $this->option('days') : config(...)`. When invoked as `--days=0`, the option value is the string "0", which is falsy in PHP ((bool)"0" === false), so the ternary takes the config branch (default 90) instead of 0. olderThan(0) is a legitimate 'purge everything older than now' request, but it is unreachable — the operator gets a 90-day cleanup with no warning. Use `$this->option('days') !== null` (or is_numeric) instead of a truthiness test.
- **Evidence:** src/Console/Commands/TerminalLogsCleanupCommand.php:30-32 — `$days = $this->option('days') ? (int) $this->option('days') : (int) config('web-terminal-stream.logging.retention_days', 90);`  (verified (bool)"0" === false)

### serve accepts empty --host=/--port= strings as valid, bypassing config fallback
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** src/Console/Commands/TerminalServeCommand.php
- **Detail:** Host/port are resolved with the null-coalescing operator only: `$this->option('host') ?? config(...)`. `getOption()` returns '' (empty string, not null) when a user passes `--host=` or `--port=` with no value, so ?? does not fall back to config. The server then binds to an empty host and `(int) ''` === 0 (a random/privileged ephemeral port), instead of the intended 127.0.0.1:8090. Guard with filled()/blank() rather than ??.
- **Evidence:** src/Console/Commands/TerminalServeCommand.php:36-37 — `$host = $this->option('host') ?? config(...)` / `$port = $this->option('port') ?? config(...)` then `(int) $port`.

### install --migrate is silently ignored when 'migration' is not among the installed components
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** src/Console/Commands/TerminalInstallCommand.php
- **Detail:** handle() only invokes handleMigration() `if (in_array('migration', $toInstall))` (line 99). Running e.g. `terminal-stream:install --config --migrate --no-interaction` publishes only config (askWhatToInstall returns ['config'] because --migration wasn't passed), so --migrate does nothing and the user gets no feedback that their flag was a no-op. Either imply --migration when --migrate is present, or warn.
- **Evidence:** src/Console/Commands/TerminalInstallCommand.php:99-101 (handleMigration gated on toInstall) vs :162-176 (askWhatToInstall only adds 'migration' when --migration flag set).

