# Upgrading

This file tracks the upgrade path from one major version to the next. Each
section documents changes users need to adopt, grouped by version.

---

## v2.x → v3.0 (in preparation)

Target: within ~12 months. No confirmed date yet — this document lists
deprecations as they land so you can migrate incrementally.

### See what's deprecated in your code

Every deprecated method is tagged with a `@deprecated` PHPDoc annotation,
so your IDE (PhpStorm, VS Code + Intelephense) will surface them with a
strikethrough. No runtime notices are emitted by default.

For an audit-style sweep — e.g., before upgrading a large project — flip
the opt-in notices on in your `.env`:

```dotenv
WEB_TERMINAL_DEPRECATIONS_EMIT_NOTICES=true
```

The package then calls `trigger_error(..., E_USER_DEPRECATED)` every time a
deprecated fluent-API method is invoked. Each notice starts with
`web-terminal:` so grepping staging logs is easy:

```bash
tail -f storage/logs/laravel.log | grep 'web-terminal:'
```

Turn it back off before deploying to production.

### Deprecated today (2.x maintenance — removed in 3.0)

Each entry lists the old call and its direct replacement. Old calls keep
working through every 2.x release; the removal only happens in 3.0.

#### Individual shell-operator permissions → `allow([...])`

The individual `allowPipes`, `allowRedirection`, `allowChaining`, and
`allowExpansion` methods are deprecated in favor of the enum-based
`allow()` dispatcher that already exists in 2.x.

```php
// Before
WebTerminal::make()
    ->allowPipes()
    ->allowRedirection()
    ->allowChaining()
    ->allowExpansion();

// After
use MWGuerra\WebTerminal\Enums\TerminalPermission;

WebTerminal::make()
    ->allow([
        TerminalPermission::Pipes,
        TerminalPermission::Redirection,
        TerminalPermission::Chaining,
        TerminalPermission::Expansion,
    ]);

// Or, if you want all four at once:
WebTerminal::make()->allow([TerminalPermission::ShellOperators]);
```

`allowAllCommands()`, `allowAllShellOperators()`, and
`allowInteractiveMode()` remain fully supported — they have no shorter
equivalent and users rely on them.

#### `TerminalBuilder::toHtml()` / `__toString()` → `render()`

`TerminalBuilder` has two output paths: `render()` (canonical — uses
`Livewire::mount`) and `toHtml()` (emits a raw
`<livewire:web-terminal …/>` string). `toHtml()` silently supports only a
subset of options — Stream-mode props, logging config, scripts, session
management are not serialized into the HTML string.

```php
// Before
{!! (string) (new TerminalBuilder)->local()->allowedCommands(['ls']) !!}

// After
{{ (new TerminalBuilder)->local()->allowedCommands(['ls'])->render() }}
```

#### `MWGuerra\WebTerminal\Schemas\Components\WebTerminalEmbed` alias

Use the canonical class directly:

```php
// Before
use MWGuerra\WebTerminal\Schemas\Components\WebTerminalEmbed;

// After
use MWGuerra\WebTerminal\Schemas\Components\WebTerminal;
```

The old alias continues to resolve through every 2.x release.

### Planned (not yet deprecated in the code)

These replacements are tracked for 2.x as new APIs land. Until the
replacement exists, you do not need to do anything:

- `startConnected()` + `autoConnect()` → a unified `connectionBehavior()`
  with explicit modes (manual / auto-with-button / auto-hidden).
- `streamTerminal()` + `classicTerminal()` → `mode(TerminalMode::Classic|Stream|Dual)`.
- `windowControls(bool)` → `chrome(TerminalChrome::Full|Minimal|None)`.

When those replacement methods land in a 2.x release, this file will be
updated with their before/after examples.

### Breaking changes in 3.0 (inventory-only, not yet implemented)

- All methods marked `@deprecated` in 2.x are removed.
- The `WebTerminalEmbed` class_alias is removed.
- The `toHtml()` / `__toString()` path on `TerminalBuilder` is removed.

Nothing else is planned for 3.0 at this time. If something else ends up
on the breaking list, it lands in this document first as a deprecation
with at least one 2.x release of runway before removal.

---

## v1.x → v2.0 (shipped)

- Requires Laravel 12.x or 13.x, Filament 5.x, Livewire 4.x (v1 was on
  Laravel 11 / Filament 4 / Livewire 3).
- If you published Blade views: update any `@entangle('prop')` to
  `$wire.entangle('prop')` in your custom views.
- Composer: `composer require mwguerra/web-terminal:"^2.0"`.

See the CHANGELOG for the full list of changes.
