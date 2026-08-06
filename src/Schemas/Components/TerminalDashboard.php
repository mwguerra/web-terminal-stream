<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Schemas\Components;

use Closure;
use Filament\Schemas\Components\Livewire;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MWGuerra\WebTerminalStream\Livewire\StreamDashboard;
use MWGuerra\WebTerminalStream\Themes\TerminalTheme;

/**
 * A toggle-driven terminal dashboard: a roster of named terminal sources,
 * each opened/closed by a button, with the open panes auto-arranged by a
 * count → layout-preset map.
 *
 * @example
 * TerminalDashboard::make()
 *     ->sources([
 *         'web' => WebTerminalStream::make()->title('Web')->ssh(...),
 *         'db'  => WebTerminalStream::make()->title('Database')->ssh(...),
 *     ])
 *     ->maxOpen(4)
 *     ->arrangement([2 => 'columns', 3 => 'main-left'], default: 'tiled')
 */
class TerminalDashboard extends Livewire
{
    /** @var array<string, WebTerminalStream> */
    protected array $sources = [];

    /** @var list<string> */
    protected array $defaultOpen = [];

    /** @var array<int, string> */
    protected array $arrangement = [];

    protected string $defaultPreset = 'tiled';

    protected int $maxOpen = 4;

    protected string|Closure $height = '600px';

    protected ?TerminalTheme $theme = null;

    public static function make(Closure|string|null $component = null, Closure|array $data = []): static
    {
        $static = app(static::class, [
            'component' => $component ?? StreamDashboard::class,
            'data' => $data,
        ]);
        $static->configure();
        $static->key('terminal-dashboard-'.Str::random(8));

        return $static;
    }

    public function getComponent(): string
    {
        return StreamDashboard::class;
    }

    /**
     * The terminal sources, keyed by a stable source id. Each value is a
     * WebTerminalStream carrying that source's connection, title (used as
     * the button label), and theme.
     *
     * @param  array<string, WebTerminalStream>  $sources
     */
    public function sources(array $sources): static
    {
        foreach ($sources as $id => $source) {
            if (! is_string($id) || $id === '') {
                throw new InvalidArgumentException('TerminalDashboard source keys must be non-empty strings.');
            }

            if (! $source instanceof WebTerminalStream) {
                throw new InvalidArgumentException(sprintf(
                    'TerminalDashboard::sources() values must be %s instances, %s given.',
                    WebTerminalStream::class,
                    get_debug_type($source),
                ));
            }
        }

        $this->sources = $sources;

        return $this;
    }

    /**
     * Which sources start open (defaults to the first). Capped at maxOpen.
     *
     * @param  list<string>  $ids
     */
    public function defaultOpen(array $ids): static
    {
        $this->defaultOpen = array_values($ids);

        return $this;
    }

    /**
     * Layout preset per open-pane count, plus the fallback preset.
     *
     * Presets: tiled, columns, rows, main-left, main-top.
     *
     * @param  array<int, string>  $map
     */
    public function arrangement(array $map, string $default = 'tiled'): static
    {
        $this->arrangement = $map;
        $this->defaultPreset = $default;

        return $this;
    }

    public function maxOpen(int $max): static
    {
        $this->maxOpen = max(1, min($max, 4));

        return $this;
    }

    public function height(string|Closure $height): static
    {
        $this->height = $height;

        return $this;
    }

    public function theme(TerminalTheme $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    public function getComponentProperties(): array
    {
        $sources = [];

        foreach ($this->sources as $id => $source) {
            // A dashboard pane fills its arranged rect; height is structural.
            $props = $source->getComponentProperties();
            $props['height'] = '100%';

            // Fall back to the dashboard theme for panes that set none.
            if ($this->theme !== null && $source->getThemeObject() === null && $source->getTheme() === []) {
                $props['theme'] = $this->theme->toColors();
                $props['fontFamily'] = $this->theme->getFontFamily();
                $props['fontSize'] = $this->theme->getFontSize();
            }

            $sources[$id] = [
                'label' => $source->getTitle(),
                'props' => $props,
            ];
        }

        // Default to opening the first source so the dashboard isn't blank.
        $defaultOpen = $this->defaultOpen !== []
            ? $this->defaultOpen
            : array_slice(array_keys($sources), 0, 1);

        return [
            'sources' => $sources,
            'defaultOpen' => $defaultOpen,
            'arrangement' => $this->arrangement,
            'defaultPreset' => $this->defaultPreset,
            'maxOpen' => $this->maxOpen,
            'height' => $this->evaluate($this->height),
            'themeCss' => $this->theme?->toCssVariables() ?? [],
        ];
    }
}
