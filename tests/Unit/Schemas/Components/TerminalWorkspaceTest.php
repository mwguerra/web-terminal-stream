<?php

declare(strict_types=1);

use Filament\Schemas\Schema;
use MWGuerra\WebTerminalStream\Data\Keymap;
use MWGuerra\WebTerminalStream\Enums\ConnectionBehavior;
use MWGuerra\WebTerminalStream\Enums\PaneAction;
use MWGuerra\WebTerminalStream\Enums\TerminalChrome;
use MWGuerra\WebTerminalStream\Livewire\StreamWorkspace;
use MWGuerra\WebTerminalStream\Livewire\TerminalBuilder;
use MWGuerra\WebTerminalStream\Schemas\Components\TerminalWorkspace;

describe('TerminalWorkspace', function () {
    describe('make', function () {
        it('mounts the StreamWorkspace Livewire component', function () {
            expect(TerminalWorkspace::make()->getComponent())->toBe(StreamWorkspace::class);
        });

        it('assigns a unique wire key per instance', function () {
            $a = TerminalWorkspace::make()->container(Schema::make())->getKey();
            $b = TerminalWorkspace::make()->container(Schema::make())->getKey();

            expect($a)->toStartWith('terminal-workspace-')
                ->and($a)->not->toBe($b);
        });
    });

    describe('pane defaults', function () {
        it('describes the first pane through the shared traits', function () {
            $props = TerminalWorkspace::make()
                ->ssh(host: 'example.com', username: 'deploy', password: 'secret')
                ->title('Deploy')
                ->theme(['background' => '#000'])
                ->getComponentProperties();

            $defaults = $props['paneDefaults'];

            expect($defaults['connectionConfig']['host'])->toBe('example.com')
                ->and($defaults['title'])->toBe('Deploy')
                ->and($defaults['theme'])->toBe(['background' => '#000'])
                ->and($defaults['connectionBehavior'])->toBe('always');
        });

        it('defaults panes to the tmux look — frameless with square corners', function () {
            $defaults = TerminalWorkspace::make()->local()->getComponentProperties()['paneDefaults'];

            expect($defaults['chrome'])->toBe('none')
                ->and($defaults['squareCorners'])->toBeTrue();
        });

        it('respects an explicit chrome choice', function () {
            $defaults = TerminalWorkspace::make()
                ->local()
                ->chrome(TerminalChrome::Minimal)
                ->getComponentProperties()['paneDefaults'];

            expect($defaults['chrome'])->toBe('minimal');
        });

        it('forwards connection behavior to panes', function () {
            $defaults = TerminalWorkspace::make()
                ->local()
                ->connectionBehavior(ConnectionBehavior::Manual)
                ->getComponentProperties()['paneDefaults'];

            expect($defaults['connectionBehavior'])->toBe('manual');
        });
    });

    describe('keymap', function () {
        it('defaults to the tmux preset', function () {
            $keymap = TerminalWorkspace::make()->local()->getComponentProperties()['keymap'];

            expect($keymap['prefix'])->toBe('ctrl+b')
                ->and($keymap['bindings']['split_horizontal'])->toBe(['%']);
        });

        it('fluent keymap wins over config', function () {
            config()->set('web-terminal-stream.workspace.shortcuts', ['prefix' => 'ctrl+x']);

            $keymap = TerminalWorkspace::make()
                ->local()
                ->keymap(Keymap::tmux()->prefix('ctrl+a'))
                ->getComponentProperties()['keymap'];

            expect($keymap['prefix'])->toBe('ctrl+a');
        });

        it('config shortcuts apply when no fluent keymap is set', function () {
            config()->set('web-terminal-stream.workspace.shortcuts', ['prefix' => 'ctrl+x']);

            $keymap = TerminalWorkspace::make()->local()->getComponentProperties()['keymap'];

            expect($keymap['prefix'])->toBe('ctrl+x');
        });

        it('accepts a config-shaped array fluent value', function () {
            $keymap = TerminalWorkspace::make()
                ->local()
                ->keymap(['bindings' => ['zoom_pane' => ['f']]])
                ->getComponentProperties()['keymap'];

            expect($keymap['bindings']['zoom_pane'])->toBe(['f']);
        });

        it('shortcuts() kill-switch and config enabled flag both gate shortcuts', function () {
            expect(TerminalWorkspace::make()->local()->getComponentProperties()['shortcutsEnabled'])->toBeTrue();

            expect(TerminalWorkspace::make()->local()->shortcuts(false)->getComponentProperties()['shortcutsEnabled'])->toBeFalse();

            config()->set('web-terminal-stream.workspace.shortcuts.enabled', false);

            expect(TerminalWorkspace::make()->local()->getComponentProperties()['shortcutsEnabled'])->toBeFalse();
        });
    });

    describe('maxPanes', function () {
        it('fluent wins over config', function () {
            config()->set('web-terminal-stream.workspace.max_panes', 4);

            expect(TerminalWorkspace::make()->local()->getComponentProperties()['maxPanes'])->toBe(4)
                ->and(TerminalWorkspace::make()->local()->maxPanes(6)->getComponentProperties()['maxPanes'])->toBe(6);
        });
    });

    describe('defaultPane', function () {
        it('is null by default — new panes clone their split source', function () {
            expect(TerminalWorkspace::make()->local()->getComponentProperties()['paneTemplate'])->toBeNull();
        });

        it('builds a template from a TerminalBuilder closure, evaluated at build time', function () {
            $template = TerminalWorkspace::make()
                ->local()
                ->defaultPane(fn (TerminalBuilder $pane) => $pane->local()->title('Scratch'))
                ->getComponentProperties()['paneTemplate'];

            expect($template['title'])->toBe('Scratch')
                ->and($template['chrome'])->toBe('none')
                ->and($template['squareCorners'])->toBeTrue();
        });
    });

    describe('keymap enum coverage', function () {
        it('exposes a binding slot for every PaneAction in the tmux preset', function () {
            $bindings = TerminalWorkspace::make()->local()->getComponentProperties()['keymap']['bindings'];

            foreach (PaneAction::cases() as $action) {
                expect($bindings)->toHaveKey($action->value);
            }
        });
    });
});
