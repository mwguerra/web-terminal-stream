<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use MWGuerra\WebTerminalStream\Enums\ConnectionType;
use MWGuerra\WebTerminalStream\Events\TerminalConnectedEvent;
use MWGuerra\WebTerminalStream\Events\TerminalDisconnectedEvent;
use MWGuerra\WebTerminalStream\Livewire\StreamTerminal;

describe('StreamTerminal', function () {
    it('can be mounted with default parameters', function () {
        Livewire::test(StreamTerminal::class, [
            'connectionConfig' => ['type' => 'local'],
            'height' => '400px',
            'title' => 'Test Terminal',
            'streamTheme' => [],
            'showWindowControls' => true,
        ])->assertStatus(200);
    });

    it('has locked connection config', function () {
        $component = Livewire::test(StreamTerminal::class, [
            'connectionConfig' => ['type' => 'local'],
            'height' => '400px',
            'title' => 'Test Terminal',
            'streamTheme' => [],
            'showWindowControls' => true,
        ]);

        $component->assertStatus(200);
        expect($component->get('isConnected'))->toBeFalse();
    });

    it('renders the stream terminal view', function () {
        Livewire::test(StreamTerminal::class, [
            'connectionConfig' => ['type' => 'local'],
            'height' => '400px',
            'title' => 'Test Terminal',
            'streamTheme' => [],
            'showWindowControls' => true,
        ])->assertViewIs('web-terminal-stream::stream-terminal');
    });

    describe('connection lifecycle events', function () {
        it('dispatches TerminalConnectedEvent on connect', function () {
            Event::fake([TerminalConnectedEvent::class]);

            Livewire::test(StreamTerminal::class, [
                'connectionConfig' => ['type' => 'local'],
                'logIdentifier' => 'test-terminal',
            ])->call('connect');

            Event::assertDispatched(TerminalConnectedEvent::class, function (TerminalConnectedEvent $event) {
                return $event->connectionType === ConnectionType::Local
                    && $event->terminalIdentifier === 'test-terminal'
                    && $event->sessionId !== '';
            });
        });

        it('includes SSH details on the connected event', function () {
            Event::fake([TerminalConnectedEvent::class]);

            Livewire::test(StreamTerminal::class, [
                'connectionConfig' => [
                    'type' => 'ssh',
                    'host' => 'example.com',
                    'port' => 2222,
                    'username' => 'deploy',
                ],
            ])->call('connect');

            Event::assertDispatched(TerminalConnectedEvent::class, function (TerminalConnectedEvent $event) {
                return $event->connectionType === ConnectionType::SSH
                    && $event->host === 'example.com'
                    && $event->port === 2222
                    && $event->sshUsername === 'deploy';
            });
        });

        it('dispatches TerminalDisconnectedEvent on disconnect', function () {
            Event::fake([TerminalConnectedEvent::class, TerminalDisconnectedEvent::class]);

            Livewire::test(StreamTerminal::class, [
                'connectionConfig' => ['type' => 'local'],
            ])->call('connect')->call('disconnect');

            Event::assertDispatched(TerminalDisconnectedEvent::class);
        });

        it('does not dispatch a disconnected event when never connected', function () {
            Event::fake([TerminalDisconnectedEvent::class]);

            Livewire::test(StreamTerminal::class, [
                'connectionConfig' => ['type' => 'local'],
            ])->call('disconnect');

            Event::assertNotDispatched(TerminalDisconnectedEvent::class);
        });

        it('does not dispatch a second connected event when already connected', function () {
            Event::fake([TerminalConnectedEvent::class]);

            Livewire::test(StreamTerminal::class, [
                'connectionConfig' => ['type' => 'local'],
            ])->call('connect')->call('connect');

            Event::assertDispatchedTimes(TerminalConnectedEvent::class, 1);
        });
    });

    describe('scripts', function () {
        it('returns the commands for a known script key', function () {
            $component = Livewire::test(StreamTerminal::class, [
                'connectionConfig' => ['type' => 'local'],
                'scripts' => [
                    ['key' => 'greet', 'commands' => ['echo hello']],
                ],
            ]);

            expect($component->instance()->getScriptsForExecution('greet'))->toBe(['echo hello']);
        });

        it('returns an empty array for an unknown script key', function () {
            $component = Livewire::test(StreamTerminal::class, [
                'connectionConfig' => ['type' => 'local'],
            ]);

            expect($component->instance()->getScriptsForExecution('missing'))->toBe([]);
        });
    });
});
