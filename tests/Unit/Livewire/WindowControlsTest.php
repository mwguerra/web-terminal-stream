<?php

declare(strict_types=1);

use Livewire\Livewire;
use MWGuerra\WebTerminalStream\Livewire\StreamDashboard;
use MWGuerra\WebTerminalStream\Livewire\StreamTerminal;
use MWGuerra\WebTerminalStream\Livewire\StreamWorkspace;
use MWGuerra\WebTerminalStream\Schemas\Components\TerminalDashboard;
use MWGuerra\WebTerminalStream\Schemas\Components\WebTerminalStream;

/*
 * The three title-bar dots.
 *
 * They were decorative <span>s until 1.1.1. Now red and yellow ask the
 * CONTAINER to close the window, and green toggles fullscreen locally. The
 * split matters: only a container knows whether closing is allowed right now,
 * while fullscreen needs no container at all and so works standalone too.
 */

describe('window controls', function () {
    it('renders real buttons, not inert decoration', function () {
        $html = Livewire::test(StreamTerminal::class, [
            'connectionConfig' => ['type' => 'local'],
            'chrome' => 'full',
        ])->html();

        // The dots must be focusable, labelled controls — a <span> is neither
        // reachable by keyboard nor announced by a screen reader. Counted on
        // the class attribute specifically: the dispatched EVENT shares the
        // name `wts-window-close`, so a bare substring count sees three.
        expect(substr_count($html, 'class="wts-window-close'))->toBe(2)
            ->and(substr_count($html, '<button'))->toBeGreaterThanOrEqual(3)
            ->and($html)->toContain('requestClose()')
            ->and($html)->toContain('toggleFullscreen()');
    });

    it('is not closable on its own — a standalone terminal has no window to close', function () {
        $component = Livewire::test(StreamTerminal::class, [
            'connectionConfig' => ['type' => 'local'],
        ]);

        expect($component->get('closable'))->toBeFalse();
    });

    it('offers fullscreen even when nothing can close it', function () {
        // Fullscreen is local, so it must not be gated on having a container.
        $html = Livewire::test(StreamTerminal::class, [
            'connectionConfig' => ['type' => 'local'],
            'chrome' => 'full',
            'closable' => false,
        ])->html();

        expect($html)->toContain('toggleFullscreen()');
    });

    it('marks every dashboard source closable', function () {
        $props = TerminalDashboard::make()
            ->sources([
                'a' => WebTerminalStream::make()->title('A')->local(),
                'b' => WebTerminalStream::make()->title('B')->local(),
            ])
            ->getComponentProperties();

        expect($props['sources']['a']['props']['closable'])->toBeTrue()
            ->and($props['sources']['b']['props']['closable'])->toBeTrue();
    });

    it('routes a dashboard close through the same toggle the bar uses', function () {
        // Reusing toggle() means a closed source stays reachable from the bar,
        // instead of vanishing with no way back.
        $props = TerminalDashboard::make()
            ->sources(['a' => WebTerminalStream::make()->title('A')->local()])
            ->getComponentProperties();

        $html = Livewire::test(StreamDashboard::class, $props)->html();

        expect($html)->toContain('wts-window-close')
            ->and($html)->toContain("toggle('a')");
    });

    it('marks workspace panes closable and guards the last one', function () {
        $html = Livewire::test(StreamWorkspace::class, [
            'paneDefaults' => ['connectionConfig' => ['type' => 'local'], 'height' => '100%'],
        ])->html();

        // The guard is in the container, because a keyed child never re-renders
        // to learn it became the last pane.
        expect($html)->toContain('paneCount > 1')
            ->and($html)->toContain('wts-pane-solo');
    });

    it('passes closable down to workspace panes', function () {
        $component = Livewire::test(StreamWorkspace::class, [
            'paneDefaults' => ['connectionConfig' => ['type' => 'local'], 'height' => '100%'],
        ]);

        $panes = $component->get('panes');
        $first = reset($panes);

        expect($first['closable'])->toBeTrue();
    });

    it('keeps the close prop off the wire as a client-writable value', function () {
        // #[Locked]: a client that could flip `closable` would not gain much,
        // but the pane's authority over its own window state should not be
        // client input on principle.
        $ref = new ReflectionProperty(StreamTerminal::class, 'closable');
        $attributes = array_map(fn ($a) => $a->getName(), $ref->getAttributes());

        expect($attributes)->toContain(\Livewire\Attributes\Locked::class);
    });

    it('has translations for every window control in both shipped locales', function () {
        foreach (['en', 'pt_BR'] as $locale) {
            $strings = require __DIR__."/../../../lang/{$locale}/terminal.php";

            expect($strings['window'] ?? null)->toBeArray()
                ->and($strings['window'])->toHaveKeys(['close', 'fullscreen', 'exit_fullscreen']);
        }
    });
});

describe('rounded corners', function () {
    it('clips the pane container so the divider cannot show as square corners', function () {
        // The container paints the divider colour behind the panes. Without a
        // matching radius that colour showed through at the four corners the
        // pane rounds away — a square edge behind every rounded one.
        $props = TerminalDashboard::make()
            ->sources(['a' => WebTerminalStream::make()->title('A')->local()])
            ->getComponentProperties();

        $html = Livewire::test(StreamDashboard::class, $props)->html();

        expect($html)->toMatch('/wts-dashboard-panes[^"]*rounded-xl|rounded-xl[^"]*wts-dashboard-panes/');
    });

    it('clips the workspace container the same way', function () {
        $html = Livewire::test(StreamWorkspace::class, [
            'paneDefaults' => ['connectionConfig' => ['type' => 'local'], 'height' => '100%'],
        ])->html();

        expect($html)->toMatch('/wts-workspace[^"]*rounded-xl|rounded-xl[^"]*wts-workspace/');
    });
});
