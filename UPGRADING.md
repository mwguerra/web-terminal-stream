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

#### `startConnected()` + `autoConnect()` → `connectionBehavior()`

The pair has always been confusing: `autoConnect(true)` implies
`startConnected(true)` but users setting them independently couldn't
predict which combination they were getting. Replaced with a single
enum-based method:

```php
use MWGuerra\WebTerminal\Enums\ConnectionBehavior;

// Before
WebTerminal::make()->startConnected();         // auto-connect, button visible
WebTerminal::make()->autoConnect();            // auto-connect, button hidden

// After
WebTerminal::make()->connectionBehavior(ConnectionBehavior::AutoWithButton);
WebTerminal::make()->connectionBehavior(ConnectionBehavior::AutoHidden);
WebTerminal::make()->connectionBehavior(ConnectionBehavior::Manual); // default
```

#### `streamTerminal()` + `classicTerminal()` → `mode()` / `dual()`

The old pair had a real footgun: calling `->streamTerminal()` alone
silently produced dual-mode because `classicEnabled` defaulted to
`true`. To get actual stream-only you needed both
`->streamTerminal()->classicTerminal(false)`. Fixed with an explicit
selector:

```php
use MWGuerra\WebTerminal\Enums\TerminalMode;

// Before
WebTerminal::make()->streamTerminal()->classicTerminal(false); // stream-only
WebTerminal::make()->streamTerminal();                         // dual (surprising)

// After
WebTerminal::make()->mode(TerminalMode::Stream);   // stream-only, unambiguous
WebTerminal::make()->mode(TerminalMode::Classic);  // classic-only
WebTerminal::make()->dual();                       // classic + stream with toggle
WebTerminal::make()->dual(TerminalMode::Stream);   // dual, default to stream tab
```

#### `windowControls(bool)` → `chrome(TerminalChrome)`

The boolean only toggled the three colored dots. The new enum covers
the whole spectrum of surrounding UI:

```php
use MWGuerra\WebTerminal\Enums\TerminalChrome;

// Before
WebTerminal::make()->windowControls(true);   // dots visible
WebTerminal::make()->windowControls(false);  // no dots, header otherwise full

// After
WebTerminal::make()->chrome(TerminalChrome::Full);      // dots + header + actions
WebTerminal::make()->chrome(TerminalChrome::Minimal);   // header + actions, no dots
WebTerminal::make()->chrome(TerminalChrome::None);      // no header at all (frameless)
WebTerminal::make()->frameless();                       // shorthand for chrome(None)
```

#### New: `deny()` for permission subtraction

The existing `allow()` enum-based setter is now paired with `deny()`,
so "all shell operators except expansion" patterns stop requiring
the individual methods:

```php
use MWGuerra\WebTerminal\Enums\TerminalPermission;

WebTerminal::make()
    ->allow([TerminalPermission::ShellOperators])
    ->deny([TerminalPermission::Expansion]);
```

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
