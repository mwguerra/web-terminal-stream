<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Livewire;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use MWGuerra\WebTerminalStream\Data\Keymap;
use MWGuerra\WebTerminalStream\Data\Layout\LayoutTree;
use MWGuerra\WebTerminalStream\Enums\SplitOrientation;

/**
 * The tmux-style terminal workspace: owns the pane roster and the
 * binary split-tree topology.
 *
 * Ownership contract with the frontend:
 * - Livewire (this class) owns which panes exist, their server-held
 *   connection configs, and the authoritative tree. Mutations happen
 *   only through splitPane()/closePane()/spawnPane()/updateRatios().
 * - Alpine owns geometry, focus, zoom, and the prefix-key state
 *   machine. Keystrokes never round-trip through Livewire.
 *
 * Security invariant: the client can only ever send a pane id, an
 * orientation string, and ratio floats. A new pane's connection config
 * is derived server-side — a clone of the split-source pane's Locked
 * roster entry, or the Locked template — never from client input.
 */
class StreamWorkspace extends Component
{
    public string $height = '600px';

    /** The authoritative split tree; null renders the empty state. */
    #[Locked]
    public ?array $tree = null;

    /** @var array<string, array<string, mixed>> paneId => StreamTerminal props */
    #[Locked]
    public array $panes = [];

    /** @var array<string, mixed> Props for the initial pane and spawn fallback. */
    #[Locked]
    public array $paneDefaults = [];

    /** @var array<string, mixed>|null Template for new panes; null = clone the split source. */
    #[Locked]
    public ?array $paneTemplate = null;

    /** @var array{prefix: string|null, prefix_timeout: int, bindings: array<string, list<string>>} */
    #[Locked]
    public array $keymap = [];

    #[Locked]
    public bool $shortcutsEnabled = true;

    #[Locked]
    public int $maxPanes = 9;

    #[Locked]
    public float $minPaneRatio = 0.1;

    #[Locked]
    public float $resizeStep = 0.03;

    #[Locked]
    public string $componentId = '';

    /**
     * @param  array<string, mixed>  $paneDefaults
     * @param  array<string, mixed>|null  $paneTemplate
     * @param  array<string, mixed>  $keymap
     */
    public function mount(
        array $paneDefaults = [],
        ?array $paneTemplate = null,
        array $keymap = [],
        bool $shortcutsEnabled = true,
        ?int $maxPanes = null,
        string $height = '600px',
    ): void {
        $this->componentId = 'wts-'.Str::random(8);
        $this->height = $height;
        $this->paneDefaults = $this->normalizePaneConfig($paneDefaults);
        $this->paneTemplate = $paneTemplate === null ? null : $this->normalizePaneConfig($paneTemplate);
        $this->keymap = $keymap !== []
            ? $keymap
            : Keymap::fromArray(config('web-terminal-stream.workspace.shortcuts', []) ?: [])->toArray();
        $this->shortcutsEnabled = $shortcutsEnabled
            && (bool) config('web-terminal-stream.workspace.shortcuts.enabled', true);
        $this->maxPanes = max(1, $maxPanes ?? (int) config('web-terminal-stream.workspace.max_panes', 9));
        $this->minPaneRatio = (float) config('web-terminal-stream.workspace.min_pane_ratio', 0.1);
        $this->resizeStep = (float) config('web-terminal-stream.workspace.resize_step', 0.03);

        $paneId = $this->generatePaneId();
        $this->panes = [$paneId => $this->paneDefaults];
        $this->tree = LayoutTree::pane($paneId);
    }

    /**
     * Split a pane. The new pane's config is a server-side clone of the
     * source pane (tmux semantics) or the workspace template if one was
     * declared via defaultPane(). `$before` inserts the new pane on the
     * leading side (left/above) instead of the trailing side (right/below).
     *
     * @return array{tree?: array, newPaneId?: string, error?: string}
     */
    public function splitPane(string $paneId, string $orientation, bool $before = false): array
    {
        if (! $this->userMayUseTerminal()) {
            return ['error' => 'Unauthorized'];
        }

        $direction = SplitOrientation::tryFrom($orientation);

        if ($direction === null) {
            return ['error' => 'Invalid orientation'];
        }

        if (! isset($this->panes[$paneId]) || $this->tree === null) {
            return ['error' => 'Unknown pane'];
        }

        if (count($this->panes) >= $this->maxPanes) {
            return ['error' => 'Pane limit reached'];
        }

        $newPaneId = $this->generatePaneId();

        $this->tree = LayoutTree::splitPane($this->tree, $paneId, $direction, $newPaneId, $before);
        $this->panes = [...$this->panes, $newPaneId => $this->paneTemplate ?? $this->panes[$paneId]];

        return ['tree' => $this->tree, 'newPaneId' => $newPaneId];
    }

    /**
     * Close a pane; its parent split collapses into the sibling. Closing
     * the last pane leaves the workspace in its empty state.
     *
     * @return array{tree?: array|null, error?: string}
     */
    public function closePane(string $paneId): array
    {
        if (! $this->userMayUseTerminal()) {
            return ['error' => 'Unauthorized'];
        }

        if (! isset($this->panes[$paneId]) || $this->tree === null) {
            return ['error' => 'Unknown pane'];
        }

        $this->tree = LayoutTree::removePane($this->tree, $paneId);

        $panes = $this->panes;
        unset($panes[$paneId]);
        $this->panes = $panes;

        return ['tree' => $this->tree];
    }

    /**
     * Open a fresh pane in an empty workspace (after the last pane was
     * closed). Splitting is the only way to add panes otherwise.
     *
     * @return array{tree?: array, newPaneId?: string, error?: string}
     */
    public function spawnPane(): array
    {
        if (! $this->userMayUseTerminal()) {
            return ['error' => 'Unauthorized'];
        }

        if ($this->tree !== null) {
            return ['error' => 'Workspace is not empty'];
        }

        $paneId = $this->generatePaneId();
        $this->panes = [$paneId => $this->paneTemplate ?? $this->paneDefaults];
        $this->tree = LayoutTree::pane($paneId);

        return ['tree' => $this->tree, 'newPaneId' => $paneId];
    }

    /**
     * Persist divider positions. Ratios are keyed by split-node id,
     * validated against the server tree, and clamped; unknown ids are
     * ignored so a topology change in flight can't corrupt anything.
     *
     * @param  array<string, float|int>  $ratios
     * @return array{tree?: array|null, error?: string}
     */
    public function updateRatios(array $ratios): array
    {
        if (! $this->userMayUseTerminal()) {
            return ['error' => 'Unauthorized'];
        }

        if ($this->tree === null) {
            return ['tree' => null];
        }

        $numeric = [];

        foreach ($ratios as $splitId => $ratio) {
            if (is_string($splitId) && (is_float($ratio) || is_int($ratio))) {
                $numeric[$splitId] = (float) $ratio;
            }
        }

        $this->tree = LayoutTree::updateRatios($this->tree, $numeric, $this->minPaneRatio);

        return ['tree' => $this->tree];
    }

    public function render()
    {
        return view('web-terminal-stream::terminal-workspace');
    }

    protected function userMayUseTerminal(): bool
    {
        return ! Gate::has('useStreamTerminal') || Gate::allows('useStreamTerminal');
    }

    protected function generatePaneId(): string
    {
        return 'p-'.Str::random(8);
    }

    /**
     * Panes fill their tree-computed rects; their own height is always
     * structural, never configurable inside a workspace.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function normalizePaneConfig(array $config): array
    {
        $config['height'] = '100%';

        return $config;
    }
}
