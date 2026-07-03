<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Livewire\StreamDashboard;
use MWGuerra\WebTerminalStream\Schemas\Components\TerminalDashboard;
use MWGuerra\WebTerminalStream\Schemas\Components\WebTerminalStream;
use MWGuerra\WebTerminalStream\Themes\TokyoNight;

function dashboardComponent(): TerminalDashboard
{
    return TerminalDashboard::make()->sources([
        'web' => WebTerminalStream::make()->title('Web')->local(),
        'db' => WebTerminalStream::make()->title('Database')->ssh(host: 'db', username: 'u', password: 'p'),
    ]);
}

describe('TerminalDashboard', function () {
    it('mounts the StreamDashboard Livewire component', function () {
        expect(TerminalDashboard::make()->getComponent())->toBe(StreamDashboard::class);
    });

    it('maps each source to a label + resolved terminal props', function () {
        $props = dashboardComponent()->getComponentProperties();

        expect($props['sources'])->toHaveKeys(['web', 'db'])
            ->and($props['sources']['web']['label'])->toBe('Web')
            ->and($props['sources']['db']['label'])->toBe('Database')
            ->and($props['sources']['db']['props']['connectionConfig']['host'])->toBe('db')
            // Dashboard panes fill their arranged rect.
            ->and($props['sources']['web']['props']['height'])->toBe('100%');
    });

    it('rejects non-WebTerminalStream sources', function () {
        TerminalDashboard::make()->sources(['bad' => 'not-a-component']);
    })->throws(InvalidArgumentException::class);

    it('carries the arrangement map, default preset, and capped maxOpen', function () {
        $props = dashboardComponent()
            ->arrangement([2 => 'columns', 3 => 'main-left'], default: 'rows')
            ->maxOpen(9)
            ->getComponentProperties();

        expect($props['arrangement'])->toBe([2 => 'columns', 3 => 'main-left'])
            ->and($props['defaultPreset'])->toBe('rows')
            ->and($props['maxOpen'])->toBe(4); // capped
    });

    it('forwards a dashboard theme to sources that have none', function () {
        $props = dashboardComponent()
            ->theme(TokyoNight::make()->fontSize(15))
            ->getComponentProperties();

        expect($props['sources']['web']['props']['theme']['background'])->toBe('#1a1b26')
            ->and($props['sources']['web']['props']['fontSize'])->toBe(15)
            ->and($props['themeCss']['--wts-divider-color'])->not->toBeEmpty();
    });

    it('emits an initial defaultOpen list', function () {
        $props = dashboardComponent()->defaultOpen(['db'])->getComponentProperties();

        expect($props['defaultOpen'])->toBe(['db']);
    });

    it('defaults to opening the first source when none is specified', function () {
        $props = dashboardComponent()->getComponentProperties();

        expect($props['defaultOpen'])->toBe(['web']);
    });
});
