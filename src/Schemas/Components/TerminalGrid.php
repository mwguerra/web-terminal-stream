<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Schemas\Components;

use Closure;
use Filament\Schemas\Components\Grid;
use InvalidArgumentException;
use MWGuerra\WebTerminalStream\Enums\ConnectionBehavior;

/**
 * A flush CSS grid of Stream terminals — the first increment of the
 * package's tiling layer.
 *
 * Composes Filament's own Grid (responsive columns come free) and defaults
 * every pane to the tmux look: `frameless()` + `squareCorners()`, zero gap.
 * A pane that explicitly configured its own chrome, corners, height, or
 * connection behavior keeps its setting.
 *
 * The pane-facing knobs are forwarded through CSS custom properties on the
 * grid container (`--wts-grid-gap`, `--wts-grid-height`, `--wts-grid-divider`)
 * consumed by resources/css/index.css, which also draws the focused-pane
 * ring via `:focus-within`.
 *
 * @example
 * TerminalGrid::make()
 *     ->columns(2)
 *     ->height('600px')
 *     ->connectionBehavior(ConnectionBehavior::Manual)
 *     ->terminals([
 *         WebTerminalStream::make()->key('pane-1')->local(),
 *         WebTerminalStream::make()->key('pane-2')->local(),
 *     ])
 */
class TerminalGrid extends Grid
{
    /** @var array<int, WebTerminalStream> */
    protected array $terminals = [];

    protected int $paneGap = 0;

    protected string|Closure|null $gridHeight = null;

    protected ?ConnectionBehavior $paneConnectionBehavior = null;

    /**
     * Object IDs of panes whose connection behavior was set by this grid
     * (as opposed to by the user), so a later grid-level change may still
     * overwrite it without clobbering user choices.
     *
     * @var array<int, true>
     */
    protected array $gridManagedBehaviorPanes = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Filament's own schema gap (gap-6) is off — flush panes are the
        // point. Pixel gaps come back through ->gap(), rendered as dividers.
        $this->gap(false);

        $this->extraAttributes(fn (): array => [
            'class' => 'wts-terminal-grid',
            'style' => $this->getGridStyle(),
        ], merge: true);
    }

    /**
     * The panes. Only WebTerminalStream components are accepted; each pane
     * inherits the grid defaults (frameless, square corners, grid-level
     * connection behavior and height) unless it configured its own.
     *
     * @param  array<int, WebTerminalStream>  $terminals
     */
    public function terminals(array $terminals): static
    {
        foreach ($terminals as $terminal) {
            if (! $terminal instanceof WebTerminalStream) {
                throw new InvalidArgumentException(sprintf(
                    'TerminalGrid::terminals() only accepts %s instances, %s given.',
                    WebTerminalStream::class,
                    get_debug_type($terminal),
                ));
            }
        }

        $this->terminals = array_values($terminals);

        foreach ($this->terminals as $terminal) {
            $this->applyPaneDefaults($terminal);
        }

        $this->schema($this->terminals);

        return $this;
    }

    /**
     * Gap between panes in pixels. Default 0 (flush tmux look); a positive
     * gap shows as pane dividers via the grid container's background.
     *
     * Also accepts Filament's boolean gap() signature (inherited from the
     * schema layer) and passes it through untouched.
     */
    public function gap(bool|int|Closure|null $gap = 0): static
    {
        if (is_int($gap)) {
            $this->paneGap = max(0, $gap);

            return $this;
        }

        return parent::gap($gap);
    }

    public function getGap(): int
    {
        return $this->paneGap;
    }

    /**
     * Grid height (CSS value). Rows share it equally (`grid-auto-rows`),
     * and panes without an explicit height stretch to fill their row.
     */
    public function height(string|Closure $height): static
    {
        $this->gridHeight = $height;

        foreach ($this->terminals as $terminal) {
            $this->applyPaneHeight($terminal);
        }

        return $this;
    }

    public function getHeight(): ?string
    {
        return $this->evaluate($this->gridHeight);
    }

    /**
     * Default connection behavior for every pane that didn't choose its own —
     * a dashboard of Manual panes doesn't spawn N PTYs on page load.
     */
    public function connectionBehavior(ConnectionBehavior $behavior): static
    {
        $this->paneConnectionBehavior = $behavior;

        foreach ($this->terminals as $terminal) {
            $this->forwardConnectionBehavior($terminal);
        }

        return $this;
    }

    public function getConnectionBehavior(): ?ConnectionBehavior
    {
        return $this->paneConnectionBehavior;
    }

    protected function applyPaneDefaults(WebTerminalStream $terminal): void
    {
        if (! $terminal->hasExplicitChrome()) {
            $terminal->frameless();
        }

        if (! $terminal->hasExplicitSquareCorners()) {
            $terminal->squareCorners();
        }

        $this->forwardConnectionBehavior($terminal);
        $this->applyPaneHeight($terminal);
    }

    protected function forwardConnectionBehavior(WebTerminalStream $terminal): void
    {
        if ($this->paneConnectionBehavior === null) {
            return;
        }

        $id = spl_object_id($terminal);

        if ($terminal->hasExplicitConnectionBehavior() && ! isset($this->gridManagedBehaviorPanes[$id])) {
            return;
        }

        $terminal->connectionBehavior($this->paneConnectionBehavior);
        $this->gridManagedBehaviorPanes[$id] = true;
    }

    protected function applyPaneHeight(WebTerminalStream $terminal): void
    {
        if ($this->gridHeight === null) {
            return;
        }

        // When the grid owns the height, panes fill their (equal) rows.
        if (! $terminal->hasExplicitHeight()) {
            $terminal->height('100%');
        }
    }

    protected function getGridStyle(): string
    {
        $style = "--wts-grid-gap: {$this->paneGap}px;";

        if ($this->paneGap > 0) {
            $style .= ' --wts-grid-divider: rgba(148, 163, 184, 0.4);';
        }

        if (($height = $this->getHeight()) !== null) {
            $style .= " --wts-grid-height: {$height};";
        }

        return $style;
    }
}
