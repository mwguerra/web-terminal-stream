<?php

declare(strict_types=1);

use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use MWGuerra\WebTerminalStream\Enums\ConnectionBehavior;
use MWGuerra\WebTerminalStream\Enums\TerminalChrome;
use MWGuerra\WebTerminalStream\Schemas\Components\TerminalGrid;
use MWGuerra\WebTerminalStream\Schemas\Components\WebTerminalStream;
use MWGuerra\WebTerminalStream\Themes\Dracula;
use MWGuerra\WebTerminalStream\Themes\TokyoNight;

describe('make', function () {
    it('composes Filament\'s Grid with 2 columns by default', function () {
        $grid = TerminalGrid::make();

        expect($grid)->toBeInstanceOf(Grid::class);
        expect($grid->getColumns('lg'))->toBe(2);
    });

    it('accepts Filament-style responsive columns', function () {
        $grid = TerminalGrid::make()->columns(['md' => 2, 'xl' => 3]);

        expect($grid->getColumns('md'))->toBe(2);
        expect($grid->getColumns('xl'))->toBe(3);
    });
});

describe('terminals', function () {
    it('registers each terminal as a child component', function () {
        $panes = [
            WebTerminalStream::make()->local(),
            WebTerminalStream::make()->local(),
            WebTerminalStream::make()->local(),
        ];

        $grid = TerminalGrid::make()->panes($panes);

        expect($grid->getDefaultChildComponents())->toBe($panes);
    });

    it('rejects children that are not WebTerminalStream components', function () {
        TerminalGrid::make()->panes([
            WebTerminalStream::make()->local(),
            Grid::make(),
        ]);
    })->throws(InvalidArgumentException::class);

    it('auto-applies frameless and squareCorners to each pane', function () {
        $pane = WebTerminalStream::make()->local();

        TerminalGrid::make()->panes([$pane]);

        expect($pane->getChrome())->toBe(TerminalChrome::None);
        expect($pane->getSquareCorners())->toBeTrue();
    });

    it('keeps a pane\'s explicitly configured chrome and corners', function () {
        $pane = WebTerminalStream::make()
            ->local()
            ->chrome(TerminalChrome::Minimal)
            ->squareCorners(false);

        TerminalGrid::make()->panes([$pane]);

        expect($pane->getChrome())->toBe(TerminalChrome::Minimal);
        expect($pane->getSquareCorners())->toBeFalse();
    });

    it('keeps the panes\' unique auto-generated wire keys', function () {
        $panes = [
            WebTerminalStream::make()->local(),
            WebTerminalStream::make()->local(),
        ];

        TerminalGrid::make()->container(Schema::make())->panes($panes);

        $keys = array_map(
            fn (WebTerminalStream $pane) => $pane->container(Schema::make())->getKey(),
            $panes,
        );

        expect($keys[0])->not->toBe($keys[1]);
        expect($keys[0])->toStartWith('web-terminal-stream-');
    });
});

describe('gap', function () {
    it('defaults to a flush 0px gap with no divider background', function () {
        $grid = TerminalGrid::make()->panes([WebTerminalStream::make()->local()]);

        expect($grid->getPaneGap())->toBe(0);

        $style = $grid->getExtraAttributes()['style'];

        expect($style)->toContain('--wts-grid-gap: 0px')
            ->and($style)->not->toContain('--wts-grid-divider');
    });

    it('renders a positive gap with the divider background variable', function () {
        $grid = TerminalGrid::make()->paneGap(1)->panes([WebTerminalStream::make()->local()]);

        expect($grid->getPaneGap())->toBe(1);

        $style = $grid->getExtraAttributes()['style'];

        expect($style)->toContain('--wts-grid-gap: 1px')
            ->and($style)->toContain('--wts-grid-divider');
    });

    it('disables Filament\'s own schema gap so panes sit flush', function () {
        expect(TerminalGrid::make()->hasGap())->toBeFalse();
    });
});

describe('height', function () {
    it('has no grid height by default', function () {
        $grid = TerminalGrid::make();

        expect($grid->getHeight())->toBeNull();
        expect($grid->getExtraAttributes()['style'])->not->toContain('--wts-grid-height');
    });

    it('sets the grid height variable', function () {
        $grid = TerminalGrid::make()->height('600px');

        expect($grid->getHeight())->toBe('600px');
        expect($grid->getExtraAttributes()['style'])->toContain('--wts-grid-height: 600px');
    });

    it('stretches panes without an explicit height to fill their row', function () {
        $pane = WebTerminalStream::make()->local();

        TerminalGrid::make()->height('600px')->panes([$pane]);

        expect($pane->getHeight())->toBe('100%');
    });

    it('applies regardless of call order relative to terminals()', function () {
        $pane = WebTerminalStream::make()->local();

        TerminalGrid::make()->panes([$pane])->height('600px');

        expect($pane->getHeight())->toBe('100%');
    });

    it('keeps a pane\'s explicitly configured height', function () {
        $pane = WebTerminalStream::make()->local()->height('300px');

        TerminalGrid::make()->height('600px')->panes([$pane]);

        expect($pane->getHeight())->toBe('300px');
    });
});

describe('connectionBehavior forwarding', function () {
    it('forwards the grid behavior to panes without their own', function () {
        $pane = WebTerminalStream::make()->local();

        TerminalGrid::make()
            ->connectionBehavior(ConnectionBehavior::Manual)
            ->panes([$pane]);

        expect($pane->getConnectionBehavior())->toBe(ConnectionBehavior::Manual);
    });

    it('forwards regardless of call order relative to terminals()', function () {
        $pane = WebTerminalStream::make()->local();

        TerminalGrid::make()
            ->panes([$pane])
            ->connectionBehavior(ConnectionBehavior::Manual);

        expect($pane->getConnectionBehavior())->toBe(ConnectionBehavior::Manual);
    });

    it('keeps a pane\'s explicitly configured behavior', function () {
        $pane = WebTerminalStream::make()
            ->local()
            ->connectionBehavior(ConnectionBehavior::Auto);

        TerminalGrid::make()
            ->connectionBehavior(ConnectionBehavior::Manual)
            ->panes([$pane]);

        expect($pane->getConnectionBehavior())->toBe(ConnectionBehavior::Auto);
    });

    it('leaves panes on the default behavior when the grid sets none', function () {
        $pane = WebTerminalStream::make()->local();

        TerminalGrid::make()->panes([$pane]);

        expect($pane->getConnectionBehavior())->toBe(ConnectionBehavior::Always);
    });
});

describe('container attributes', function () {
    it('tags the container with the wts-terminal-grid class', function () {
        $grid = TerminalGrid::make();

        expect($grid->getExtraAttributes()['class'])->toContain('wts-terminal-grid');
    });

    it('emits the theme divider CSS variables into the container style', function () {
        $grid = TerminalGrid::make()
            ->panes([WebTerminalStream::make()->local()])
            ->theme(Dracula::make()->dividerWidth(4));

        $style = $grid->getExtraAttributes()['style'];

        expect($style)->toContain('--wts-divider-width: 4px;')
            ->and($style)->toContain('--wts-divider-color:');
    });
});

describe('theme forwarding', function () {
    it('forwards the grid theme to panes that have none', function () {
        $pane = WebTerminalStream::make()->local();

        TerminalGrid::make()->panes([$pane])->theme(TokyoNight::make());

        expect($pane->getTheme()['background'])->toBe('#1a1b26')
            ->and($pane->getFontFamily())->toContain('monospace');
    });

    it('leaves a pane that set its own theme alone', function () {
        $pane = WebTerminalStream::make()->local()->theme(['background' => '#abcdef']);

        TerminalGrid::make()->panes([$pane])->theme(TokyoNight::make());

        expect($pane->getTheme())->toBe(['background' => '#abcdef']);
    });
});
