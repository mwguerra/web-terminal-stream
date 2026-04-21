<?php

declare(strict_types=1);

use MWGuerra\WebTerminal\Enums\ConnectionBehavior;
use MWGuerra\WebTerminal\Enums\TerminalChrome;
use MWGuerra\WebTerminal\Enums\TerminalMode;
use MWGuerra\WebTerminal\Enums\TerminalPermission;
use MWGuerra\WebTerminal\Livewire\TerminalBuilder;

/*
 * Coverage for the Stage 5 API consolidation — `mode()`, `dual()`,
 * `chrome()`, `frameless()`, `connectionBehavior()`, and `deny()`.
 *
 * These run against `TerminalBuilder` because it uses the exact same
 * Concerns/Configures* traits as Schemas\Components\WebTerminal and
 * doesn't need Filament loaded in the test container.
 */

describe('mode()', function () {
    it('stream sets stream on and classic off', function () {
        $b = (new TerminalBuilder)->mode(TerminalMode::Stream);

        expect($b->getStreamEnabled())->toBeTrue();
        expect($b->getClassicEnabled())->toBeFalse();
        expect($b->getDefaultMode())->toBe(TerminalMode::Stream);
    });

    it('classic sets classic on and stream off', function () {
        $b = (new TerminalBuilder)
            ->mode(TerminalMode::Stream)   // flip first
            ->mode(TerminalMode::Classic); // then back

        expect($b->getStreamEnabled())->toBeFalse();
        expect($b->getClassicEnabled())->toBeTrue();
        expect($b->getDefaultMode())->toBe(TerminalMode::Classic);
    });
});

describe('dual()', function () {
    it('enables both modes with Classic default by default', function () {
        $b = (new TerminalBuilder)->dual();

        expect($b->getStreamEnabled())->toBeTrue();
        expect($b->getClassicEnabled())->toBeTrue();
        expect($b->getDefaultMode())->toBe(TerminalMode::Classic);
    });

    it('honors an explicit default mode argument', function () {
        $b = (new TerminalBuilder)->dual(TerminalMode::Stream);

        expect($b->getDefaultMode())->toBe(TerminalMode::Stream);
    });
});

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

describe('deny()', function () {
    it('removes permissions granted by a preceding allow()', function () {
        $b = (new TerminalBuilder)
            ->allow([TerminalPermission::ShellOperators])
            ->deny([TerminalPermission::Expansion]);

        expect($b->getAllowPipes())->toBeTrue();
        expect($b->getAllowRedirection())->toBeTrue();
        expect($b->getAllowChaining())->toBeTrue();
        expect($b->getAllowExpansion())->toBeFalse();
    });

    it('revokes all four individual flags when denying the aggregate', function () {
        $b = (new TerminalBuilder)
            ->allow([TerminalPermission::ShellOperators])
            ->deny([TerminalPermission::ShellOperators]);

        expect($b->getAllowPipes())->toBeFalse();
        expect($b->getAllowRedirection())->toBeFalse();
        expect($b->getAllowChaining())->toBeFalse();
        expect($b->getAllowExpansion())->toBeFalse();
        expect($b->getAllowAllShellOperators())->toBeFalse();
    });
});
