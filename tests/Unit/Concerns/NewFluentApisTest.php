<?php

declare(strict_types=1);

use MWGuerra\WebTerminal\Enums\ConnectionBehavior;
use MWGuerra\WebTerminal\Enums\TerminalChrome;
use MWGuerra\WebTerminal\Livewire\TerminalBuilder;

/*
 * Coverage for the appearance-related fluent APIs — `chrome()`,
 * `frameless()`, and `connectionBehavior()`.
 *
 * These run against `TerminalBuilder` because it uses the exact same
 * Concerns/Configures* traits as Schemas\Components\WebTerminal and
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
