<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Schemas\Components;

use Closure;
use Filament\Schemas\Components\Livewire;
use Illuminate\Support\Str;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresConnection;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresLogging;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresScripts;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresStreamMode;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresTerminalAppearance;
use MWGuerra\WebTerminalStream\Livewire\StreamTerminal as StreamTerminalComponent;

/**
 * Web Terminal component for use in Filament schemas/forms.
 *
 * This component embeds the Stream terminal (full interactive PTY over
 * WebSocket) into any Filament form or page using a fluent API. Extends
 * Filament's built-in Livewire component for proper component isolation.
 *
 * @example
 * WebTerminalStream::make()
 *     ->local()
 *     ->height('400px')
 *     ->title('Server Console')
 */
class WebTerminalStream extends Livewire
{
    use ConfiguresConnection;
    use ConfiguresLogging;
    use ConfiguresScripts;
    use ConfiguresStreamMode;
    use ConfiguresTerminalAppearance;

    public static function make(Closure|string|null $component = null, Closure|array $data = []): static
    {
        $static = app(static::class, [
            'component' => $component ?? StreamTerminalComponent::class,
            'data' => $data,
        ]);
        $static->configure();

        // Unique default key per instance so two WebTerminalStream::make() calls on the
        // same Filament page don't collide on Livewire's wire:key. Users who call
        // ->key('custom-id') later override this default. The random segment is
        // stable for the lifetime of a page render (make() only runs at schema
        // build time, not on every re-render).
        $static->key('web-terminal-stream-'.Str::random(8));

        return $static;
    }

    /**
     * Get the Livewire component class to use.
     */
    public function getComponent(): string
    {
        return StreamTerminalComponent::class;
    }

    /**
     * Get the properties to pass to the Livewire component.
     *
     * @return array<string, mixed>
     */
    public function getComponentProperties(): array
    {
        return [
            'connectionConfig' => $this->getConnectionConfig(),
            'height' => $this->getHeight(),
            'title' => $this->getTitle(),
            'streamTheme' => $this->getStreamTheme(),
            'showWindowControls' => $this->getShowWindowControls(),
            'chrome' => $this->getChrome()->value,
            'squareCorners' => $this->getSquareCorners(),
            'scripts' => $this->getScripts(),
            'autoConnect' => $this->getAutoConnect(),
            'connectionBehavior' => $this->getEffectiveConnectionBehavior()->value,
            'loggingEnabled' => $this->getLoggingEnabled(),
            'logConnections' => $this->getLogConnections(),
            'logIdentifier' => $this->getLogIdentifier(),
            'logMetadata' => $this->getLogMetadata(),
        ];
    }
}
