<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MWGuerra\WebTerminalStream\Data\Layout\LayoutTree;
use MWGuerra\WebTerminalStream\Livewire\StreamWorkspace;

/**
 * Call a Livewire method and capture its return value (Livewire's
 * Testable only exposes returns through assertReturned).
 */
function callWorkspace(Testable $component, string $method, mixed ...$params): mixed
{
    $captured = null;

    $component->call($method, ...$params)->assertReturned(function ($value) use (&$captured): bool {
        $captured = $value;

        return true;
    });

    return $captured;
}

function workspaceDefaults(): array
{
    return [
        'paneDefaults' => [
            'connectionConfig' => ['type' => 'local'],
            'height' => '400px',
            'title' => 'Terminal',
            'theme' => [],
            'chrome' => 'none',
            'squareCorners' => true,
            'scripts' => [],
            'connectionBehavior' => 'always',
            'loggingEnabled' => null,
            'logConnections' => null,
            'logIdentifier' => null,
            'logMetadata' => [],
        ],
    ];
}

describe('StreamWorkspace', function () {
    describe('mount', function () {
        it('starts with a single pane built from the defaults', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());

            $tree = $component->get('tree');
            $panes = $component->get('panes');

            expect($tree['type'])->toBe('pane')
                ->and($panes)->toHaveCount(1)
                ->and(array_key_first($panes))->toBe($tree['paneId'])
                ->and($panes[$tree['paneId']]['connectionConfig'])->toBe(['type' => 'local']);
        });

        it('forces pane height to 100% — panes fill their rects', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());

            $paneId = $component->get('tree')['paneId'];

            expect($component->get('panes')[$paneId]['height'])->toBe('100%');
        });

        it('falls back to the config keymap when none is passed', function () {
            config()->set('web-terminal-stream.workspace.shortcuts', ['prefix' => 'ctrl+a']);

            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());

            expect($component->get('keymap')['prefix'])->toBe('ctrl+a');
        });

        it('reads pane limits from config', function () {
            config()->set('web-terminal-stream.workspace.max_panes', 4);

            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());

            expect($component->get('maxPanes'))->toBe(4);
        });

        it('renders the workspace container with each pane keyed', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());

            $paneId = $component->get('tree')['paneId'];

            $component->assertSeeHtml('data-wts-workspace')
                ->assertSeeHtml('data-wts-pane="'.$paneId.'"');
        });
    });

    describe('splitPane', function () {
        it('splits a pane and clones the source config server-side', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());
            $paneId = $component->get('tree')['paneId'];

            $result = callWorkspace($component, 'splitPane', $paneId, 'horizontal');

            expect($result)->toHaveKeys(['tree', 'newPaneId'])
                ->and($result['tree']['type'])->toBe('split')
                ->and($result['tree']['orientation'])->toBe('horizontal');

            $panes = $component->get('panes');

            expect($panes)->toHaveCount(2)
                ->and($panes[$result['newPaneId']])->toBe($panes[$paneId]);
        });

        it('uses the pane template for new panes when one is set', function () {
            $template = workspaceDefaults()['paneDefaults'];
            $template['title'] = 'Template Pane';

            $component = Livewire::test(StreamWorkspace::class, [
                ...workspaceDefaults(),
                'paneTemplate' => $template,
            ]);
            $paneId = $component->get('tree')['paneId'];

            $result = callWorkspace($component, 'splitPane', $paneId, 'vertical');

            expect($component->get('panes')[$result['newPaneId']]['title'])->toBe('Template Pane');
        });

        it('inserts the new pane before the source when $before is true', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());
            $paneId = $component->get('tree')['paneId'];

            $result = callWorkspace($component, 'splitPane', $paneId, 'horizontal', true);

            // New pane leads (left); the original pane follows (right).
            expect($result['tree']['first']['paneId'])->toBe($result['newPaneId'])
                ->and($result['tree']['second']['paneId'])->toBe($paneId);
        });

        it('rejects unknown panes', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());

            $result = callWorkspace($component, 'splitPane', 'p-nope', 'horizontal');

            expect($result)->toBe(['error' => 'Unknown pane'])
                ->and($component->get('panes'))->toHaveCount(1);
        });

        it('rejects invalid orientations', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());
            $paneId = $component->get('tree')['paneId'];

            expect(callWorkspace($component, 'splitPane', $paneId, 'diagonal'))
                ->toBe(['error' => 'Invalid orientation']);
        });

        it('enforces the pane ceiling', function () {
            $component = Livewire::test(StreamWorkspace::class, [
                ...workspaceDefaults(),
                'maxPanes' => 2,
            ]);
            $paneId = $component->get('tree')['paneId'];

            $component->call('splitPane', $paneId, 'horizontal');

            expect(callWorkspace($component, 'splitPane', $paneId, 'horizontal'))
                ->toBe(['error' => 'Pane limit reached'])
                ->and($component->get('panes'))->toHaveCount(2);
        });

        it('denies splits when the useStreamTerminal gate forbids', function () {
            Gate::define('useStreamTerminal', fn ($user = null) => false);

            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());
            $paneId = $component->get('tree')['paneId'];

            expect(callWorkspace($component, 'splitPane', $paneId, 'horizontal'))
                ->toBe(['error' => 'Unauthorized'])
                ->and($component->get('panes'))->toHaveCount(1);
        });
    });

    describe('closePane', function () {
        it('closes a pane and collapses the tree', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());
            $paneId = $component->get('tree')['paneId'];
            $split = callWorkspace($component, 'splitPane', $paneId, 'horizontal');

            $result = callWorkspace($component, 'closePane', $split['newPaneId']);

            expect($result['tree'])->toBe(['type' => 'pane', 'paneId' => $paneId])
                ->and($component->get('panes'))->toHaveCount(1);
        });

        it('closing the last pane empties the workspace', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());
            $paneId = $component->get('tree')['paneId'];

            $result = callWorkspace($component, 'closePane', $paneId);

            expect($result['tree'])->toBeNull()
                ->and($component->get('panes'))->toBe([])
                ->and($component->get('tree'))->toBeNull();
        });

        it('rejects unknown panes', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());

            expect(callWorkspace($component, 'closePane', 'p-nope'))
                ->toBe(['error' => 'Unknown pane']);
        });
    });

    describe('spawnPane', function () {
        it('reopens an empty workspace from the defaults', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());
            $component->call('closePane', $component->get('tree')['paneId']);

            $result = callWorkspace($component, 'spawnPane');

            expect($result)->toHaveKeys(['tree', 'newPaneId'])
                ->and($component->get('panes'))->toHaveCount(1);
        });

        it('refuses to spawn while panes exist', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());

            expect(callWorkspace($component, 'spawnPane'))
                ->toBe(['error' => 'Workspace is not empty']);
        });
    });

    describe('updateRatios', function () {
        it('applies clamped ratios to the server tree', function () {
            config()->set('web-terminal-stream.workspace.min_pane_ratio', 0.2);

            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());
            $paneId = $component->get('tree')['paneId'];
            $split = callWorkspace($component, 'splitPane', $paneId, 'horizontal');
            $splitId = $split['tree']['id'];

            $component->call('updateRatios', [$splitId => 0.05]);

            expect($component->get('tree')['ratio'])->toBe(0.2);
        });

        it('ignores unknown split ids and junk values', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());
            $paneId = $component->get('tree')['paneId'];
            $component->call('splitPane', $paneId, 'horizontal');
            $before = $component->get('tree');

            $component->call('updateRatios', ['s-unknown' => 0.9, 'junk' => 'not-a-number']);

            expect($component->get('tree'))->toBe($before);
        });
    });

    describe('locked state', function () {
        it('locks the tree and pane roster against client tampering', function () {
            $component = Livewire::test(StreamWorkspace::class, workspaceDefaults());

            expect(fn () => $component->set('tree', LayoutTree::pane('p-evil')))
                ->toThrow(Exception::class, 'Cannot update locked property: [tree]');

            expect(fn () => $component->set('panes', ['p-evil' => ['connectionConfig' => ['type' => 'local']]]))
                ->toThrow(Exception::class, 'Cannot update locked property: [panes]');
        });
    });
});
