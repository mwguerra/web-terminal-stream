# 06 — Fluent component API + Livewire props

**Area key:** `components-api`

## Summary

The fluent-config layer is well-factored: seven shared Concerns traits (ConfiguresConnection/Appearance/ConnectionLifecycle/Logging/Scripts, ResolvesTerminalProperties, EvaluatesOptions) are composed identically by WebTerminalStream, TerminalBuilder, and TerminalWorkspace, and the prop contract is exact — ResolvesTerminalProperties::resolveTerminalProperties() emits precisely the 14 keys StreamTerminal::mount() accepts, with no drift, no extras, no silent drops (chrome/connectionBehavior correctly serialized to enum ->value). Per-instance wire:key isolation is real and regression-tested (WebTerminalStream::make() stamps a unique random key post-configure(); explicit ->key() overrides it). TerminalGrid's pane forwarding (frameless/squareCorners/height/connectionBehavior/theme) is order-independent and guarded by hasExplicit* markers plus spl_object_id bookkeeping. The Themes system is clean: extendable TerminalTheme with fluent partial overrides, TokyoNight/Dracula subclasses, toColors()/toCssVariables() with font wiring. The maturity gaps are in the container layer's consistency: the three container components (Grid, Workspace, Dashboard) diverge in how much of a pane's look they normalize, TerminalWorkspace's defaultPane template silently drops the workspace theme/font/connection, TerminalDashboard silently hard-caps maxOpen at 4, and the EvaluatesOptions "parity with Filament evaluate" claim is false for parameterized closures (it fatals).

## What exists and works

- Prop contract is exact: ResolvesTerminalProperties returns the same 14 keys StreamTerminal::mount accepts (connectionConfig, height, title, theme, fontFamily, fontSize, chrome, squareCorners, connectionBehavior, scripts, loggingEnabled, logConnections, logIdentifier, logMetadata) — no drift, no dropped props; enums serialized via ->value (ResolvesTerminalProperties.php:20-36, StreamTerminal.php:65-96)
- Per-instance wire:key uniqueness for multi-terminal isolation: WebTerminalStream/TerminalWorkspace/TerminalDashboard::make() each stamp a unique 'web-terminal-stream-/terminal-workspace-/terminal-dashboard-'.Str::random(8) key after configure(); explicit ->key() overrides; regression-tested (WebTerminalStream.php:53, WebTerminalStreamKeyIsolationTest.php)
- Trait composition is consistent across WebTerminalStream, TerminalBuilder, and TerminalWorkspace (all six Configures* + ResolvesTerminalProperties; TerminalBuilder adds EvaluatesOptions since it lacks Filament's evaluate())
- TerminalGrid pane forwarding is order-independent: panes()/connectionBehavior()/theme()/height() each re-loop and the hasExplicit* guards leave user-configured children alone; gridManagedBehaviorPanes reset on panes() re-set to avoid spl_object_id reuse clobbering (TerminalGrid.php:80-235)
- StreamWorkspace/StreamDashboard mutation surface is Locked-down: client can only send pane/source ids, orientation strings, and ratio floats; connection configs derived server-side from #[Locked] rosters; useStreamTerminal gate re-checked on every split/close/spawn/toggle (StreamWorkspace.php, StreamDashboard.php)
- Themes system: TerminalTheme is an extendable-defaults base with fluent partial overrides; TokyoNight/Dracula override only needed colors; toColors() feeds ghostty theme, toCssVariables()/toCssVariableString() feed container dividers, font wired through getFontFamily/getFontSize (Themes/*)

## Gaps (missing functionality)

| Gap | Severity | Detail |
|---|:--:|---|
| No dashboard config section mirroring workspace defaults | low | config/web-terminal-stream.php has a full 'workspace' block (shortcuts, max_panes, min_pane_ratio, resize_step) but no 'dashboard' block. Dashboard maxOpen (hard 4), default arrangement, and default height are only settable per-instance and the 4-cap can't be raised at all. If dashboards are a first-class container alongside workspaces, a parallel config section would match the established pattern. |
| CLAUDE.md / docblocks reference removed traits and deprecated aliases | low | CLAUDE.md's Concerns section names ConfiguresTerminalAppearance, ConfiguresStreamMode, EmitsDeprecationNotices and deprecated aliases windowControls()/startConnected()/autoConnect()/streamTheme() as part of the fluent API. None exist in src/Concerns (the real traits are ConfiguresAppearance/ConfiguresConnection/ConfiguresConnectionLifecycle/ConfiguresLogging/ConfiguresScripts/ResolvesTerminalProperties/EvaluatesOptions) and NewFluentApisTest.php explicitly asserts the legacy setters are gone. Contributor docs describe a superseded API surface. |

## Flaws (implemented but wrong/risky)

Each critical/high flaw was handed to an independent skeptic instructed to refute it.

### EvaluatesOptions falsely claims parity with Filament evaluate(); parameterized closures fatal in TerminalBuilder
- **Severity:** medium — **Verdict:** ⚪ unverified
- **Files:** src/Concerns/EvaluatesOptions.php
- **Detail:** EvaluatesOptions::evaluate() is `$value instanceof Closure ? $value() : $value` — it invokes closures with zero arguments. Its docblock (and CLAUDE.md) assert it 'satisfies the contract' matching Filament's evaluate(), but Filament's evaluate() does named-parameter dependency injection. Any shared-trait closure that declares a parameter — e.g. ->height(fn ($record) => ...), ->ssh(fn ($livewire) => ...), ->connectionBehavior(fn ($state) => ...) — works in the Filament WebTerminalStream/Workspace components but throws ArgumentCountError in TerminalBuilder (Blade usage). Since the fluent traits are shared verbatim, config copy-pasted from a Filament page to a Blade TerminalBuilder silently fatals.
- **Evidence:** Confirmed at runtime: `(new TerminalBuilder)->height(fn ($record) => '500px')->getHeight()` throws `ArgumentCountError: Too few arguments ... 0 passed in .../EvaluatesOptions.php on line 24 and exactly 1 expected`. Parity claim: EvaluatesOptions.php:16-19.

### TerminalWorkspace defaultPane template drops the workspace theme, font, and connection; split panes render unthemed/local
- **Severity:** medium — **Verdict:** ⚪ unverified
- **Files:** src/Schemas/Components/TerminalWorkspace.php
- **Detail:** getPaneTemplate() builds a fresh `new TerminalBuilder` seeded only with frameless()+squareCorners() plus the user's closure, then returns getParameters(). It inherits nothing from the workspace. So a workspace configured `->ssh(host:'staging',...)->theme(TokyoNight::make())->defaultPane(fn($p)=>$p->title('Scratch'))` produces: the FIRST pane (paneDefaults via resolvePaneDefaults→resolveTerminalProperties) is themed TokyoNight over SSH, but every SPLIT pane (paneTemplate) is unthemed (theme=[], fontFamily=null) and connects to LOCAL. The container divider CSS (themeCss) IS still emitted, so themed dividers frame unthemed panes — a visible mismatch. The connection divergence (local instead of ssh) is the sharpest: a template that forgets to restate the connection silently downgrades split panes to a local shell.
- **Evidence:** Confirmed at runtime: template getParameters() → theme=[], fontFamily=NULL, connectionConfig.type='local'. getPaneTemplate builds fresh builder at TerminalWorkspace.php:161-166; first-pane path resolvePaneDefaults→resolveTerminalProperties at :189-200; themeCss forwarded at :178.

### TerminalDashboard does not auto-apply frameless()/squareCorners() to sources, unlike Grid and Workspace
- **Severity:** medium — **Verdict:** ⚪ unverified
- **Files:** src/Schemas/Components/TerminalDashboard.php
- **Detail:** TerminalGrid.applyPaneDefaults() and TerminalWorkspace.resolvePaneDefaults() both force child panes to the tmux look (frameless + square corners) unless explicitly overridden. TerminalDashboard.getComponentProperties() forwards each source's props verbatim (only overriding height='100%' and optionally theme) and never touches chrome/squareCorners. Because a WebTerminalStream defaults to TerminalChrome::Full + rounded corners, dashboard source panes render window-control dots, a header bar, and rounded corners inside the absolutely-positioned tiled/columns auto-layout — visually inconsistent with the other two containers and with the flush-tile intent. The user must remember to add ->frameless()->squareCorners() to every source by hand.
- **Evidence:** TerminalDashboard.php:140-160 forwards props with no chrome handling; compare TerminalGrid.php:184-197 and TerminalWorkspace.php:189-200 which apply frameless/squareCorners. Dashboard view renders panes absolutely-positioned in a tree layout (terminal-dashboard.blade.php:38-47).

### TerminalDashboard::maxOpen silently hard-caps at 4, undocumented and not configurable
- **Severity:** medium — **Verdict:** ⚪ unverified
- **Files:** src/Schemas/Components/TerminalDashboard.php, src/Livewire/StreamDashboard.php, config/web-terminal-stream.php
- **Detail:** maxOpen() does `max(1, min($max, 4))` and StreamDashboard.mount() repeats `min($maxOpen ?? 4, 4)`. A caller asking for ->maxOpen(9) silently gets 4 (the test even asserts this). The ceiling of 4 is not stated in the method docblock, has no config key (unlike workspace.max_panes which is configurable and defaults to 9), and is artificial: LayoutTree::arrange() handles arbitrary pane counts (evenChain/tiled recurse over N). This is a silent-clamp DX trap plus an inconsistency with the workspace's configurable ceiling.
- **Evidence:** TerminalDashboard.php:119-124 (`max(1, min($max, 4))`); StreamDashboard.php:82; test asserts maxOpen(9)→4 (TerminalDashboardTest.php:38-47); no 'dashboard' key in config/web-terminal-stream.php (only workspace.max_panes=9); LayoutTree::arrange handles any count (LayoutTree.php arrange/evenChain/tiled).

### TerminalGrid mutates caller's pane objects and pollutes their explicit-set markers
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** src/Schemas/Components/TerminalGrid.php, src/Concerns/ConfiguresAppearance.php
- **Detail:** applyPaneDefaults()/applyPaneHeight() call $terminal->frameless(), ->squareCorners(), ->height('100%') on the user's WebTerminalStream instances. Each of those setters flips heightExplicitlySet/chromeExplicitlySet/squareCornersExplicitlySet to true. After grid processing a pane reports hasExplicitHeight()/hasExplicitChrome()===true even though the user never set them, so any later container or introspection sees grid-injected values as user-explicit. Because panes are objects passed by reference, a WebTerminalStream reused across two grids (or read after) carries the first grid's mutations.
- **Evidence:** height()/chrome()/squareCorners() set the *ExplicitlySet flags (ConfiguresAppearance.php:47-53,138-144,171-177); grid invokes them in applyPaneDefaults/applyPaneHeight (TerminalGrid.php:184-197,225-235).

### ConfiguresLogging::log() defaults enabled=true, so setting only identifier/metadata force-enables logging
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** src/Concerns/ConfiguresLogging.php
- **Detail:** log(bool $enabled = true, ...) always assigns $this->loggingEnabled = $enabled. Calling ->log(identifier: 'admin') or ->log(metadata: [...]) to tweak one field implicitly forces loggingEnabled=true, overriding a config-level default of disabled. There is no way to set identifier/metadata/connections while leaving the enabled flag at its config default via this method — a surprising side effect for a partial-config call.
- **Evidence:** ConfiguresLogging.php:39-58: `$this->loggingEnabled = $enabled;` runs unconditionally with default true; identifier/metadata only assigned when non-null.

