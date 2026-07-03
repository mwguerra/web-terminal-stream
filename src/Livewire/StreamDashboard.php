<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Livewire;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use MWGuerra\WebTerminalStream\Data\Layout\LayoutTree;

/**
 * A toggle-driven terminal dashboard: a fixed roster of terminal "sources"
 * (each a distinct connection), each opened or closed by a button. Open
 * panes are auto-arranged by a count → layout-preset map.
 *
 * Same isolation model as StreamWorkspace: each open source renders one
 * keyed StreamTerminal child, so toggling one never re-renders the others;
 * closing a source removes its pane, which tears down its WebSocket + PTY.
 *
 * Security: the client only ever sends a source id. Each source's
 * connection config is derived server-side from the #[Locked] roster —
 * never from client input — and opening re-checks the useStreamTerminal
 * gate. The paneId of an open source is the source id itself.
 */
class StreamDashboard extends Component
{
    public string $height = '600px';

    /** @var array<string, array{label: string, props: array<string, mixed>}> */
    #[Locked]
    public array $sources = [];

    /** @var list<string> Open source ids, in the order they define the layout. */
    #[Locked]
    public array $openOrder = [];

    /** @var array<string, array<string, mixed>> paneId(=sourceId) => StreamTerminal props */
    #[Locked]
    public array $panes = [];

    #[Locked]
    public ?array $tree = null;

    /** @var array<int, string> pane count => layout preset name */
    #[Locked]
    public array $arrangement = [];

    #[Locked]
    public string $defaultPreset = 'tiled';

    #[Locked]
    public int $maxOpen = 4;

    /** @var array<string, string> */
    #[Locked]
    public array $themeCss = [];

    #[Locked]
    public string $componentId = '';

    /**
     * @param  array<string, array{label: string, props: array<string, mixed>}>  $sources
     * @param  list<string>  $defaultOpen
     * @param  array<int|string, string>  $arrangement
     * @param  array<string, string>  $themeCss
     */
    public function mount(
        array $sources = [],
        array $defaultOpen = [],
        array $arrangement = [],
        string $defaultPreset = 'tiled',
        ?int $maxOpen = null,
        string $height = '600px',
        array $themeCss = [],
    ): void {
        $this->componentId = 'wtsd-'.Str::random(8);
        $this->sources = $sources;
        $this->arrangement = self::normalizeArrangement($arrangement);
        $this->defaultPreset = $defaultPreset;
        $this->maxOpen = max(1, min($maxOpen ?? 4, 4));
        $this->height = $height;
        $this->themeCss = $themeCss;

        // Open exactly the requested sources, up to the cap. (The "default to
        // the first source" convenience lives in TerminalDashboard so that an
        // explicit empty list here genuinely starts with nothing open.)
        foreach ($defaultOpen as $sourceId) {
            if (isset($this->sources[$sourceId]) && count($this->openOrder) < $this->maxOpen) {
                $this->openOrder[] = $sourceId;
            }
        }

        $this->rebuild();
    }

    /**
     * Toggle a source open or closed.
     *
     * @return array{tree?: array|null, open?: list<string>, error?: string}
     */
    public function toggle(string $sourceId): array
    {
        if (! isset($this->sources[$sourceId])) {
            return ['error' => 'Unknown source'];
        }

        $index = array_search($sourceId, $this->openOrder, true);

        if ($index !== false) {
            // Close: destroys the pane (and its WebSocket + PTY on the client).
            array_splice($this->openOrder, $index, 1);
            $this->rebuild();

            return ['tree' => $this->tree, 'open' => $this->openOrder];
        }

        if (! $this->userMayUseTerminal()) {
            return ['error' => 'Unauthorized'];
        }

        if (count($this->openOrder) >= $this->maxOpen) {
            return ['error' => 'Open limit reached'];
        }

        $this->openOrder[] = $sourceId;
        $this->rebuild();

        return ['tree' => $this->tree, 'open' => $this->openOrder];
    }

    public function render()
    {
        return view('web-terminal-stream::terminal-dashboard');
    }

    /**
     * Re-derive the open panes and the arranged tree from openOrder.
     */
    protected function rebuild(): void
    {
        $panes = [];

        foreach ($this->openOrder as $sourceId) {
            $panes[$sourceId] = $this->sources[$sourceId]['props'];
        }

        $this->panes = $panes;
        $this->tree = LayoutTree::arrange($this->openOrder, $this->presetFor(count($this->openOrder)));
    }

    protected function presetFor(int $count): string
    {
        return $this->arrangement[$count] ?? $this->defaultPreset;
    }

    protected function userMayUseTerminal(): bool
    {
        return ! Gate::has('useStreamTerminal') || Gate::allows('useStreamTerminal');
    }

    /**
     * @param  array<int|string, string>  $arrangement
     * @return array<int, string>
     */
    protected static function normalizeArrangement(array $arrangement): array
    {
        $normalized = [];

        foreach ($arrangement as $count => $preset) {
            $normalized[(int) $count] = $preset;
        }

        return $normalized;
    }
}
