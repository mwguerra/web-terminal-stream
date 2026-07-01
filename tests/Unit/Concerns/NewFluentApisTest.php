<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Enums\ConnectionBehavior;
use MWGuerra\WebTerminalStream\Enums\TerminalChrome;
use MWGuerra\WebTerminalStream\Livewire\TerminalBuilder;

/*
 * Coverage for the appearance-related fluent APIs — `chrome()`,
 * `frameless()`, and `connectionBehavior()`.
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

    it('drives the boolean getShowWindowControls accessor', function () {
        expect((new TerminalBuilder)->chrome(TerminalChrome::Full)->getShowWindowControls())->toBeTrue();
        expect((new TerminalBuilder)->chrome(TerminalChrome::Minimal)->getShowWindowControls())->toBeFalse();
        expect((new TerminalBuilder)->chrome(TerminalChrome::None)->getShowWindowControls())->toBeFalse();
    });
});

describe('getEffectiveConnectionBehavior()', function () {
    it('defaults to AutoHidden when nothing was configured (extraction-era behavior)', function () {
        expect((new TerminalBuilder)->getEffectiveConnectionBehavior())
            ->toBe(ConnectionBehavior::AutoHidden);
    });

    it('returns the explicitly declared behavior', function () {
        expect((new TerminalBuilder)->connectionBehavior(ConnectionBehavior::Manual)->getEffectiveConnectionBehavior())
            ->toBe(ConnectionBehavior::Manual);

        expect((new TerminalBuilder)->connectionBehavior(ConnectionBehavior::AutoWithButton)->getEffectiveConnectionBehavior())
            ->toBe(ConnectionBehavior::AutoWithButton);
    });

    it('maps the deprecated flags when they were explicitly set', function () {
        expect((new TerminalBuilder)->autoConnect(true)->getEffectiveConnectionBehavior())
            ->toBe(ConnectionBehavior::AutoHidden);

        expect((new TerminalBuilder)->startConnected(true)->getEffectiveConnectionBehavior())
            ->toBe(ConnectionBehavior::AutoWithButton);

        expect((new TerminalBuilder)->autoConnect(false)->getEffectiveConnectionBehavior())
            ->toBe(ConnectionBehavior::Manual);
    });

    it('reports whether a behavior was explicitly chosen', function () {
        expect((new TerminalBuilder)->hasExplicitConnectionBehavior())->toBeFalse();

        expect((new TerminalBuilder)->connectionBehavior(ConnectionBehavior::Manual)->hasExplicitConnectionBehavior())->toBeTrue();
        expect((new TerminalBuilder)->autoConnect(false)->hasExplicitConnectionBehavior())->toBeTrue();
    });

    it('is forwarded as the connectionBehavior Livewire parameter', function () {
        expect((new TerminalBuilder)->local()->getParameters()['connectionBehavior'])
            ->toBe('auto_hidden');

        expect((new TerminalBuilder)->local()->connectionBehavior(ConnectionBehavior::Manual)->getParameters()['connectionBehavior'])
            ->toBe('manual');

        expect((new TerminalBuilder)->local()->connectionBehavior(ConnectionBehavior::AutoWithButton)->getParameters()['connectionBehavior'])
            ->toBe('auto_with_button');
    });
});

describe('connectionBehavior()', function () {
    it('Manual clears both start and auto-connect flags', function () {
        $b = (new TerminalBuilder)->connectionBehavior(ConnectionBehavior::Manual);

        expect($b->getStartConnected())->toBeFalse();
        expect($b->getAutoConnect())->toBeFalse();
    });

    it('AutoWithButton sets startConnected but not autoConnect', function () {
        $b = (new TerminalBuilder)->connectionBehavior(ConnectionBehavior::AutoWithButton);

        expect($b->getStartConnected())->toBeTrue();
        expect($b->getAutoConnect())->toBeFalse();
    });

    it('AutoHidden sets both so the button is hidden but the connection is live', function () {
        $b = (new TerminalBuilder)->connectionBehavior(ConnectionBehavior::AutoHidden);

        expect($b->getStartConnected())->toBeTrue();
        expect($b->getAutoConnect())->toBeTrue();
    });
});
