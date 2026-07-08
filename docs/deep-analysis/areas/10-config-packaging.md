# 10 — Config, provider, packaging, deps

**Area key:** `config-packaging`

## Summary

Packaging and config are mostly coherent and the side-by-side isolation story is largely intact (namespaced config file, view/lang namespace, Livewire aliases, route name, cache prefix, storage dir, table, env prefix). All three Livewire components are registered, publishing/commands/assets are wired, and the plugin's components() whitelist + without*() subtraction logic is correct. However there is one real packaging hazard: the server hard-depends on ext-posix (posix_kill), ext-pcntl (SIGWINCH constant) and Unix proc_open PTY, none of which are declared in composer.json require nor guarded by the serve preflight — so the package installs cleanly on platforms that lack them and then fatals at runtime (notably on terminal resize). Secondary issues: the declared PHP floor (^8.2) disagrees with the documented floor (8.3+) and neither 8.2 nor 8.5 (the dev machine's version, and allowed by the constraint) is covered by CI; and a cluster of logging config keys/env vars carried over from the removed Classic mode are dead in Stream mode yet still shipped and (for one) documented in the README.

## What exists and works

- Config namespacing / side-by-side isolation: config file web-terminal-stream.php, view+lang namespace web-terminal-stream, Livewire aliases web-terminal-stream/-workspace/-dashboard, route name web-terminal-stream.ws-token, cache prefix terminal-stream-pty:, storage dir web-terminal-stream, table terminal_stream_logs, WEB_TERMINAL_STREAM_* env, artisan terminal-stream:* — all distinct from parent package (WebTerminalStreamServiceProvider.php:38-49)
- All three Livewire components registered (ServiceProvider.php:42-44)
- Commands, config/views/lang publishing gated behind runningInConsole() (ServiceProvider.php:51-70)
- FilamentAsset registration guarded by class_exists(FilamentAsset::class) and file_exists() for the JS bundle (ServiceProvider.php:74-86)
- Plugin components() whitelist + withoutTerminalPage()/withoutTerminalLogs() subtraction logic is correct and does not silently re-enable subtracted components (WebTerminalStreamPlugin.php:92-125)
- Every stream.* config key (ratchet_host/port, websocket_url, ssl_cert/key, shell, working_directory, max_session_lifetime, signed_url_ttl, allowed_origins) has a live consumer
- workspace.* keys (shortcuts.enabled/prefix/prefix_timeout/bindings, max_panes, min_pane_ratio, resize_step) are all consumed by StreamWorkspace/TerminalWorkspace/Keymap
- logging.enabled/log_connections/log_disconnections/terminals/tenant_column/tenant_resolver/user_table/user_foreign_key/retention_days are all consumed

## Gaps (missing functionality)

| Gap | Severity | Detail |
|---|:--:|---|
| ext-posix / ext-pcntl / Unix proc_open PTY are hard runtime deps but undeclared in composer.json and unchecked by the serve preflight | high | The WebSocket server and PTY bridge call posix_kill() (ReactPhpProvider.php:32-33,78-79; TerminalPtyBridge.php:220), use the SIGWINCH constant which is only defined by ext-pcntl (TerminalPtyBridge.php:220), and open a PTY via proc_open() with ['pty'] descriptors which is Unix-only (TerminalPtyBridge.php:60-82). composer.json require (lines 22-32) declares no ext-posix, ext-pcntl, or platform note, and TerminalServeCommand::handle() only checks class_exists(SocketServer::class) (TerminalServeCommand.php:30) before starting. Consequence: on a host where ext-pcntl is not compiled (common on stock/shared PHP) or ext-posix is disabled, composer install succeeds, the serve command prints 'Starting...', and then the process fatals — SIGWINCH is an undefined-constant fatal Error the first time any browser resizes a terminal (a routine action), and posix_kill is a call-to-undefined-function fatal during stale-session cleanup. On Windows the proc_open PTY path is entirely unsupported. CI masks this by installing pcntl+posix extensions explicitly (.github/workflows/tests.yml) while composer never requires them. Fix: add "ext-posix" and "ext-pcntl" to require (or at minimum suggest + a preflight guard in TerminalServeCommand for extension_loaded('posix')/('pcntl') and function_exists('proc_open')). |
| PHP version constraint, documented floor, and CI matrix disagree; neither the ^8.2 floor nor PHP 8.5 is tested | medium | composer.json requires "php": "^8.2" (line 23), but README.md:11 and CLAUDE.md:14 both state PHP 8.3+, and the CI matrix (.github/workflows/tests.yml) only runs 8.3 and 8.4. So the advertised 8.2 floor is never exercised, and PHP 8.5 — the version on the dev machine (8.5.7) and permitted by ^8.2 — has zero CI coverage despite being a very new release with new deprecations. Either raise the constraint to ^8.3 to match the docs, or add 8.2 (if genuinely supported) and 8.5 to the test matrix so the declared support surface is actually validated. |
| laravel/prompts and filament/livewire pinned to bleeding-edge majors with no lower-bound cushion | low | require pins livewire/livewire ^4.0 and (dev) filament/filament ^5.0, both brand-new majors, and laravel/prompts ^0.3.0 (a 0.x, so ^0.3.0 only allows 0.3.x and will not float to 0.4). This is a deliberate pre-1.0 choice (breaking changes allowed), but the tight 0.3.x prompts pin can cause resolver conflicts in host apps that pull a newer prompts via laravel/framework. Flagging as intentional-but-brittle rather than a defect. |

## Flaws (implemented but wrong/risky)

Each critical/high flaw was handed to an independent skeptic instructed to refute it.

### Dead logging config keys + a README-documented env var that does nothing in Stream mode
- **Severity:** medium — **Verdict:** ⚪ unverified
- **Files:** config/web-terminal-stream.php, src/Services/TerminalLogger.php, README.md
- **Detail:** logging.log_errors, logging.max_output_length, and logging.truncate_output (config/web-terminal-stream.php:23,26,27) plus the README-documented env var WEB_TERMINAL_STREAM_MAX_OUTPUT_LOG (README.md:208) are dead in this package. They are only read by TerminalLogger::logError(), logCommand() and logOutput() (TerminalLogger.php:180-235), and shouldLog('commands')/shouldLog('output') resolve config keys log_commands/log_output that do not even exist in the shipped config. A repo-wide grep shows logError(), logCommand(), and logOutput() have ZERO callers anywhere in src/ — they are Classic-mode leftovers, and CLAUDE.md explicitly states 'command-level logging does not exist here' / 'Connection lifecycle only.' Net effect: a host operator who sets WEB_TERMINAL_STREAM_MAX_OUTPUT_LOG or toggles log_errors/truncate_output gets no behavior change, and the README advertises a knob that is inert. Remove the dead keys+methods or wire them; at minimum drop them from config and README.
- **Evidence:** TerminalLogger.php:57-71 shouldLog() reads log_{$type} where $type is 'commands'/'output' (keys absent from config); logOutput()/logCommand() (lines 180-235) are the only readers of max_output_length/truncate_output and are never called; grep 'logError|logCommand|logOutput' across src/ returns no call sites outside TerminalLogger.php; README.md:208 documents WEB_TERMINAL_STREAM_MAX_OUTPUT_LOG.

### JS Filament asset registered under un-namespaced id 'stream-terminal' — collision risk with parent package, violates the repo's own isolation rule
- **Severity:** medium — **Verdict:** ⚪ unverified
- **Files:** src/WebTerminalStreamServiceProvider.php
- **Detail:** registerAssets() registers the CSS with the properly namespaced id 'web-terminal-stream' but registers the JS bundle with the bare id 'stream-terminal' (ServiceProvider.php:78,82). FilamentAsset stores registered scripts in a single global registry keyed by asset id regardless of the package group argument, so if the parent mwguerra/web-terminal (from which this was extracted, sharing the same resources/js/stream-terminal.js filename and StreamWeb global) also registers a JS asset id 'stream-terminal', the two collide when both packages are installed side-by-side — the stated design goal. Whichever provider boots last wins, silently overriding the other package's bundle. This directly violates CLAUDE.md's rule 'Never introduce a host-visible identifier that collides with mwguerra/web-terminal.' The CSS id shows the correct pattern; the JS id should be 'web-terminal-stream' (or similarly prefixed) too. Severity contingent on the parent package's asset id, which I could not inspect here.
- **Evidence:** WebTerminalStreamServiceProvider.php:78 Css::make('web-terminal-stream', ...) vs line 82 Js::make('stream-terminal', ...); registration group is 'mwguerra/web-terminal-stream' but per-asset ids are global in Filament's AssetManager.

### Per-terminal ->log() cannot independently toggle disconnection logging — both map to the single logConnections flag
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** src/Livewire/StreamTerminal.php
- **Detail:** StreamTerminal builds its logger overrides mapping BOTH 'connections' and 'disconnections' to $this->logConnections (StreamTerminal.php:215-216), so a per-terminal override can never disable disconnection logging while keeping connection logging (or vice versa), even though the global config exposes independent logging.log_connections and logging.log_disconnections keys. This makes the per-terminal API strictly less expressive than the config it overrides. Likely an intentional simplification (one ->log() boolean) but the asymmetry with the config surface is a latent DX inconsistency.
- **Evidence:** src/Livewire/StreamTerminal.php:215-216 both keys read $this->logConnections; config exposes separate log_connections/log_disconnections (config/web-terminal-stream.php:21-22).

