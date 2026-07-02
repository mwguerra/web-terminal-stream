<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Enums\ConnectionBehavior;
use MWGuerra\WebTerminalStream\Enums\TerminalChrome;
use MWGuerra\WebTerminalStream\Livewire\TerminalBuilder;

/*
 * Coverage for the appearance and lifecycle fluent APIs — `chrome()`,
 * `frameless()`, `theme()`, and `connectionBehavior()`.
 *
 * These run against `TerminalBuilder` because it uses the exact same
 * Concerns/Configures* traits as Schemas\Components\WebTerminalStream and
 * doesn't need Filament loaded in the test container.
 */

describe('chrome()', function () {
    it('defaults to Full', function () {
        expect((new TerminalBuilder)->getChrome())->toBe(TerminalChrome::Full);
    });

    it('is settable to Minimal and None', function () {
        expect((new TerminalBuilder)->chrome(TerminalChrome::Minimal)->getChrome())
            ->toBe(TerminalChrome::Minimal);

        expect((new TerminalBuilder)->chrome(TerminalChrome::None)->getChrome())
            ->toBe(TerminalChrome::None);
    });

    it('frameless() is sugar for chrome(None)', function () {
        expect((new TerminalBuilder)->frameless()->getChrome())->toBe(TerminalChrome::None);
    });

    it('is forwarded as the chrome Livewire parameter', function () {
        expect((new TerminalBuilder)->local()->frameless()->getParameters()['chrome'])
            ->toBe('none');
    });
});

describe('theme()', function () {
    it('defaults to an empty array', function () {
        expect((new TerminalBuilder)->getTheme())->toBe([]);
    });

    it('accepts an array and a Closure', function () {
        expect((new TerminalBuilder)->theme(['background' => '#000'])->getTheme())
            ->toBe(['background' => '#000']);

        expect((new TerminalBuilder)->theme(fn (): array => ['fontSize' => 14])->getTheme())
            ->toBe(['fontSize' => 14]);
    });

    it('is forwarded as the theme Livewire parameter', function () {
        expect((new TerminalBuilder)->local()->theme(['background' => '#000'])->getParameters()['theme'])
            ->toBe(['background' => '#000']);
    });
});

describe('connectionBehavior()', function () {
    it('defaults to Always when nothing was configured', function () {
        expect((new TerminalBuilder)->getConnectionBehavior())
            ->toBe(ConnectionBehavior::Always);
    });

    it('returns the explicitly declared behavior', function () {
        expect((new TerminalBuilder)->connectionBehavior(ConnectionBehavior::Manual)->getConnectionBehavior())
            ->toBe(ConnectionBehavior::Manual);

        expect((new TerminalBuilder)->connectionBehavior(ConnectionBehavior::Auto)->getConnectionBehavior())
            ->toBe(ConnectionBehavior::Auto);
    });

    it('accepts a Closure resolved at read time', function () {
        expect((new TerminalBuilder)->connectionBehavior(fn (): ConnectionBehavior => ConnectionBehavior::Manual)->getConnectionBehavior())
            ->toBe(ConnectionBehavior::Manual);
    });

    it('reports whether a behavior was explicitly chosen', function () {
        expect((new TerminalBuilder)->hasExplicitConnectionBehavior())->toBeFalse();

        expect((new TerminalBuilder)->connectionBehavior(ConnectionBehavior::Manual)->hasExplicitConnectionBehavior())->toBeTrue();
    });

    it('is forwarded as the connectionBehavior Livewire parameter', function () {
        expect((new TerminalBuilder)->local()->getParameters()['connectionBehavior'])
            ->toBe('always');

        expect((new TerminalBuilder)->local()->connectionBehavior(ConnectionBehavior::Manual)->getParameters()['connectionBehavior'])
            ->toBe('manual');

        expect((new TerminalBuilder)->local()->connectionBehavior(ConnectionBehavior::Auto)->getParameters()['connectionBehavior'])
            ->toBe('auto');
    });
});

describe('removed legacy API', function () {
    it('no longer exposes the deprecated flag setters', function () {
        expect(method_exists(TerminalBuilder::class, 'windowControls'))->toBeFalse()
            ->and(method_exists(TerminalBuilder::class, 'startConnected'))->toBeFalse()
            ->and(method_exists(TerminalBuilder::class, 'autoConnect'))->toBeFalse()
            ->and(method_exists(TerminalBuilder::class, 'streamTheme'))->toBeFalse();
    });

    it('no longer emits the legacy Livewire parameters', function () {
        $params = (new TerminalBuilder)->local()->getParameters();

        expect($params)->not->toHaveKey('showWindowControls')
            ->and($params)->not->toHaveKey('autoConnect')
            ->and($params)->not->toHaveKey('streamTheme');
    });
});
