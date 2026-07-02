<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Schemas\Components;

use Closure;
use Filament\Schemas\Components\Livewire;
use Illuminate\Support\Str;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresAppearance;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresConnection;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresConnectionLifecycle;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresLogging;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresScripts;
use MWGuerra\WebTerminalStream\Concerns\ResolvesTerminalProperties;
use MWGuerra\WebTerminalStream\Data\Keymap;
use MWGuerra\WebTerminalStream\Enums\TerminalChrome;
use MWGuerra\WebTerminalStream\Livewire\StreamWorkspace;
use MWGuerra\WebTerminalStream\Livewire\TerminalBuilder;

/**
 * A tmux-style terminal workspace for Filament schemas: one terminal
 * that splits into arbitrarily nested panes at runtime, driven by
 * configurable keyboard shortcuts.
 *
 * The trait-provided fluent methods (connection, theme, scripts,
 * logging, connectionBehavior) describe the FIRST pane and the clone
 * base for every pane split from it. Use defaultPane() to give newly
 * split panes a different template instead.
 *
 * @example
 * TerminalWorkspace::make()
 *     ->ssh(host: 'staging', username: 'deploy', privateKey: $key)
 *     ->height('70vh')
 *     ->maxPanes(6)
 *     ->keymap(Keymap::tmux()->prefix('ctrl+a'))
 */
class TerminalWorkspace extends Livewire
{
    use ConfiguresAppearance;
    use ConfiguresConnection;
    use ConfiguresConnectionLifecycle;
    use ConfiguresLogging;
    use ConfiguresScripts;
    use ResolvesTerminalProperties;

    protected Keymap|array|Closure|null $keymap = null;

    protected bool|Closure $shortcuts = true;

    protected int|Closure|null $maxPanes = null;

    protected ?Closure $defaultPane = null;

    public static function make(Closure|string|null $component = null, Closure|array $data = []): static
    {
        $static = app(static::class, [
            'component' => $component ?? StreamWorkspace::class,
            'data' => $data,
        ]);
        $static->configure();

        // Unique default wire:key per instance — same isolation mechanism
        // as WebTerminalStream::make().
        $static->key('terminal-workspace-'.Str::random(8));

        return $static;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->height('600px');
    }

    public function getComponent(): string
    {
        return StreamWorkspace::class;
    }

    /**
     * The keyboard shortcut map: a Keymap, a config-shaped array, or a
     * Closure returning either. Fluent wins over the config file, which
     * wins over the tmux preset.
     */
    public function keymap(Keymap|array|Closure $keymap): static
    {
        $this->keymap = $keymap;

        return $this;
    }

    /**
     * Kill-switch for all workspace shortcuts without losing the map.
     */
    public function shortcuts(bool|Closure $enabled = true): static
    {
        $this->shortcuts = $enabled;

        return $this;
    }

    /**
     * Hard ceiling on panes, enforced server-side on every split.
     */
    public function maxPanes(int|Closure $max): static
    {
        $this->maxPanes = $max;

        return $this;
    }

    /**
     * Template for newly split panes. The Closure receives a fresh
     * TerminalBuilder to configure and is evaluated once at schema
     * build time; without it, a new pane clones the pane it was split
     * from (tmux semantics).
     *
     * @example ->defaultPane(fn (TerminalBuilder $pane) => $pane->local()->title('Scratch'))
     */
    public function defaultPane(Closure $configure): static
    {
        $this->defaultPane = $configure;

        return $this;
    }

    public function getKeymap(): Keymap
    {
        $keymap = $this->evaluate($this->keymap);

        return match (true) {
            $keymap instanceof Keymap => $keymap,
            is_array($keymap) => Keymap::fromArray($keymap),
            default => Keymap::fromArray(config('web-terminal-stream.workspace.shortcuts', []) ?: []),
        };
    }

    public function getShortcutsEnabled(): bool
    {
        return (bool) $this->evaluate($this->shortcuts)
            && (bool) config('web-terminal-stream.workspace.shortcuts.enabled', true);
    }

    public function getMaxPanes(): int
    {
        return max(1, (int) ($this->evaluate($this->maxPanes)
            ?? config('web-terminal-stream.workspace.max_panes', 9)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPaneTemplate(): ?array
    {
        if ($this->defaultPane === null) {
            return null;
        }

        $builder = new TerminalBuilder;
        $builder->frameless()->squareCorners();

        $this->evaluate($this->defaultPane, ['pane' => $builder]);

        return $builder->getParameters();
    }

    public function getComponentProperties(): array
    {
        return [
            'paneDefaults' => $this->resolvePaneDefaults(),
            'paneTemplate' => $this->getPaneTemplate(),
            'keymap' => $this->getKeymap()->toArray(),
            'shortcutsEnabled' => $this->getShortcutsEnabled(),
            'maxPanes' => $this->getMaxPanes(),
            'height' => $this->getHeight(),
        ];
    }

    /**
     * The workspace's own trait config, translated into the first pane.
     * Panes default to the tmux look (frameless, square corners) unless
     * chrome/corners were configured explicitly.
     *
     * @return array<string, mixed>
     */
    protected function resolvePaneDefaults(): array
    {
        if (! $this->hasExplicitChrome()) {
            $this->chrome(TerminalChrome::None);
        }

        if (! $this->hasExplicitSquareCorners()) {
            $this->squareCorners();
        }

        return $this->resolveTerminalProperties();
    }
}
