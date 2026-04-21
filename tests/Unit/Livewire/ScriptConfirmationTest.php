<?php

declare(strict_types=1);

use Livewire\Livewire;
use MWGuerra\WebTerminal\Data\Script;
use MWGuerra\WebTerminal\Livewire\WebTerminal;

/*
 * Scripts declared with ->confirmBeforeRun() must arm a pending-confirmation
 * state on the first runScript() call and only actually run on the second
 * call (via confirmPendingScript).
 *
 * Until this was wired up, Script::confirmBeforeRun() set a flag that no
 * code read — the "confirmation" never happened. Users who added it to
 * guard destructive commands (sudo reboot, drop-database, etc.) had no
 * guard at all.
 */

function mountTerminalWithConfirmableScript(): \Livewire\Features\SupportTesting\Testable
{
    // Elevated so the script bypasses the allowedCommands check — this suite
    // tests the confirm/cancel state machine, not authorization. Confirmable
    // scripts are almost always elevated in practice (sudo reboot, etc.).
    $script = Script::make('reboot')
        ->label('Reboot Server')
        ->commands(['echo rebooting'])
        ->elevated()
        ->confirmBeforeRun();

    // Force isConnected so runScript() reaches the confirmation gate — the
    // actual connection lifecycle is covered elsewhere.
    return Livewire::test(WebTerminal::class, [
        'allowedCommands' => ['echo *'],
        'scripts' => [$script->toArray()],
    ])->set('isConnected', true);
}

it('arms pendingScriptKey instead of running on the first click for a confirmable script', function () {
    $component = mountTerminalWithConfirmableScript();

    $component->call('runScript', 'reboot');

    expect($component->get('pendingScriptKey'))->toBe('reboot');
    expect($component->get('scriptExecution'))->toBe([]);
    expect($component->get('showScriptPanel'))->toBeFalse();
});

it('runs the script when confirmPendingScript() is called after arming', function () {
    $component = mountTerminalWithConfirmableScript();

    $component->call('runScript', 'reboot');
    expect($component->get('pendingScriptKey'))->toBe('reboot');

    $component->call('confirmPendingScript');

    expect($component->get('pendingScriptKey'))->toBe('');
    expect($component->get('scriptExecution'))->not->toBe([]);
    expect($component->get('showScriptPanel'))->toBeTrue();
});

it('clears pendingScriptKey without running when cancelPendingScript() is called', function () {
    $component = mountTerminalWithConfirmableScript();

    $component->call('runScript', 'reboot');
    expect($component->get('pendingScriptKey'))->toBe('reboot');

    $component->call('cancelPendingScript');

    expect($component->get('pendingScriptKey'))->toBe('');
    expect($component->get('scriptExecution'))->toBe([]);
    expect($component->get('showScriptPanel'))->toBeFalse();
});

it('does nothing when confirmPendingScript() is called with no pending script', function () {
    $component = mountTerminalWithConfirmableScript();

    $component->call('confirmPendingScript');

    expect($component->get('pendingScriptKey'))->toBe('');
    expect($component->get('scriptExecution'))->toBe([]);
});

it('runs a non-confirmable script directly without arming pendingScriptKey', function () {
    $plain = Script::make('ping')
        ->label('Ping')
        ->commands(['echo pong'])
        ->elevated();

    $component = Livewire::test(WebTerminal::class, [
        'allowedCommands' => ['echo *'],
        'scripts' => [$plain->toArray()],
    ])->set('isConnected', true);

    $component->call('runScript', 'ping');

    expect($component->get('pendingScriptKey'))->toBe('');
    expect($component->get('scriptExecution'))->not->toBe([]);
    expect($component->get('showScriptPanel'))->toBeTrue();
});
