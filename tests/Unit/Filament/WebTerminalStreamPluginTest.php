<?php

declare(strict_types=1);

use Filament\Contracts\Plugin;
use Filament\Panel;
use MWGuerra\WebTerminalStream\Filament\Pages\Terminal;
use MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource;
use MWGuerra\WebTerminalStream\WebTerminalStreamPlugin;

describe('WebTerminalStreamPlugin', function () {
    it('can be instantiated using make()', function () {
        $plugin = WebTerminalStreamPlugin::make();

        expect($plugin)->toBeInstanceOf(WebTerminalStreamPlugin::class)
            ->and($plugin)->toBeInstanceOf(Plugin::class);
    });

    it('has correct plugin id', function () {
        $plugin = WebTerminalStreamPlugin::make();

        expect($plugin->getId())->toBe('web-terminal-stream');
    });

    describe('register', function () {
        it('registers the Terminal page and TerminalLogResource by default', function () {
            $panel = Panel::make()->id('test');

            WebTerminalStreamPlugin::make()->register($panel);

            expect($panel->getPages())->toContain(Terminal::class);
            expect($panel->getResources())->toContain(TerminalLogResource::class);
        });

        it('stores the registered instance for current()', function () {
            $plugin = WebTerminalStreamPlugin::make();
            $plugin->register(Panel::make()->id('test'));

            expect(WebTerminalStreamPlugin::current())->toBe($plugin);
        });

        it('stores the booted instance for current()', function () {
            $plugin = WebTerminalStreamPlugin::make();
            $plugin->boot(Panel::make()->id('test'));

            expect(WebTerminalStreamPlugin::current())->toBe($plugin);
        });
    });

    describe('terminal page', function () {
        it('is enabled by default', function () {
            expect(WebTerminalStreamPlugin::make()->isTerminalPageEnabled())->toBeTrue();
        });

        it('can be disabled with withoutTerminalPage()', function () {
            $plugin = WebTerminalStreamPlugin::make()->withoutTerminalPage();

            expect($plugin->isTerminalPageEnabled())->toBeFalse();

            $panel = Panel::make()->id('test');
            $plugin->register($panel);

            expect($panel->getPages())->not->toContain(Terminal::class);
            expect($panel->getResources())->toContain(TerminalLogResource::class);
        });

        it('can be toggled with terminalPage()', function () {
            expect(WebTerminalStreamPlugin::make()->terminalPage(false)->isTerminalPageEnabled())->toBeFalse();
            expect(WebTerminalStreamPlugin::make()->terminalPage(false)->terminalPage()->isTerminalPageEnabled())->toBeTrue();
        });
    });

    describe('terminal logs resource', function () {
        it('is enabled by default', function () {
            expect(WebTerminalStreamPlugin::make()->isTerminalLogsEnabled())->toBeTrue();
        });

        it('can be disabled with withoutTerminalLogs()', function () {
            $plugin = WebTerminalStreamPlugin::make()->withoutTerminalLogs();

            expect($plugin->isTerminalLogsEnabled())->toBeFalse();

            $panel = Panel::make()->id('test');
            $plugin->register($panel);

            expect($panel->getPages())->toContain(Terminal::class);
            expect($panel->getResources())->not->toContain(TerminalLogResource::class);
        });

        it('can be toggled with terminalLogs()', function () {
            expect(WebTerminalStreamPlugin::make()->terminalLogs(false)->isTerminalLogsEnabled())->toBeFalse();
            expect(WebTerminalStreamPlugin::make()->terminalLogs(false)->terminalLogs()->isTerminalLogsEnabled())->toBeTrue();
        });
    });

    describe('component selection', function () {
        it('components() restricts registration to the given components', function () {
            $panel = Panel::make()->id('test');

            WebTerminalStreamPlugin::make()
                ->components([Terminal::class])
                ->register($panel);

            expect($panel->getPages())->toContain(Terminal::class);
            expect($panel->getResources())->not->toContain(TerminalLogResource::class);
        });

        it('make() accepts the component list directly', function () {
            $panel = Panel::make()->id('test');

            WebTerminalStreamPlugin::make([Terminal::class])->register($panel);

            expect($panel->getPages())->toContain(Terminal::class);
            expect($panel->getResources())->not->toContain(TerminalLogResource::class);
        });

        it('ignores unknown classes in the component list', function () {
            $panel = Panel::make()->id('test');

            WebTerminalStreamPlugin::make()
                ->components([stdClass::class])
                ->register($panel);

            expect($panel->getPages())->not->toContain(Terminal::class);
            expect($panel->getResources())->not->toContain(TerminalLogResource::class);
        });

        it('withoutTerminalPage() subtracts from the components() whitelist', function () {
            $panel = Panel::make()->id('test');

            WebTerminalStreamPlugin::make()
                ->components([Terminal::class, TerminalLogResource::class])
                ->withoutTerminalPage()
                ->register($panel);

            expect($panel->getPages())->not->toContain(Terminal::class);
            expect($panel->getResources())->toContain(TerminalLogResource::class);
        });

        it('withoutTerminalLogs() subtracts from the components() whitelist', function () {
            $panel = Panel::make()->id('test');

            WebTerminalStreamPlugin::make()
                ->components([Terminal::class, TerminalLogResource::class])
                ->withoutTerminalLogs()
                ->register($panel);

            expect($panel->getPages())->toContain(Terminal::class);
            expect($panel->getResources())->not->toContain(TerminalLogResource::class);
        });
    });

    describe('terminal navigation', function () {
        it('has sensible defaults', function () {
            $plugin = WebTerminalStreamPlugin::make();

            expect($plugin->getTerminalNavigationIcon())->toBe('heroicon-o-command-line')
                ->and($plugin->getTerminalNavigationLabel())->toBe('Terminal')
                ->and($plugin->getTerminalNavigationSort())->toBe(100)
                ->and($plugin->getTerminalNavigationGroup())->toBe('Tools');
        });

        it('is configurable via terminalNavigation()', function () {
            $plugin = WebTerminalStreamPlugin::make()->terminalNavigation(
                icon: 'heroicon-o-cpu-chip',
                label: 'Console',
                sort: 10,
                group: 'Ops',
            );

            expect($plugin->getTerminalNavigationIcon())->toBe('heroicon-o-cpu-chip')
                ->and($plugin->getTerminalNavigationLabel())->toBe('Console')
                ->and($plugin->getTerminalNavigationSort())->toBe(10)
                ->and($plugin->getTerminalNavigationGroup())->toBe('Ops');
        });

        it('keeps defaults for omitted terminalNavigation() arguments', function () {
            $plugin = WebTerminalStreamPlugin::make()->terminalNavigation(label: 'Console');

            expect($plugin->getTerminalNavigationLabel())->toBe('Console')
                ->and($plugin->getTerminalNavigationIcon())->toBe('heroicon-o-command-line')
                ->and($plugin->getTerminalNavigationSort())->toBe(100)
                ->and($plugin->getTerminalNavigationGroup())->toBe('Tools');
        });
    });

    describe('terminal logs navigation', function () {
        it('has sensible defaults', function () {
            $plugin = WebTerminalStreamPlugin::make();

            expect($plugin->getTerminalLogsNavigationIcon())->toBe('heroicon-o-clipboard-document-list')
                ->and($plugin->getTerminalLogsNavigationLabel())->toBe('Terminal Logs')
                ->and($plugin->getTerminalLogsNavigationSort())->toBe(101)
                ->and($plugin->getTerminalLogsNavigationGroup())->toBe('Tools');
        });

        it('is configurable via terminalLogsNavigation()', function () {
            $plugin = WebTerminalStreamPlugin::make()->terminalLogsNavigation(
                icon: 'heroicon-o-document-text',
                label: 'Console Logs',
                sort: 11,
                group: 'Ops',
            );

            expect($plugin->getTerminalLogsNavigationIcon())->toBe('heroicon-o-document-text')
                ->and($plugin->getTerminalLogsNavigationLabel())->toBe('Console Logs')
                ->and($plugin->getTerminalLogsNavigationSort())->toBe(11)
                ->and($plugin->getTerminalLogsNavigationGroup())->toBe('Ops');
        });
    });
});
