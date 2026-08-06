<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MWGuerra\WebTerminalStream\Livewire\StreamDashboard;

function callDashboard(Testable $component, string $method, mixed ...$params): mixed
{
    $captured = null;

    $component->call($method, ...$params)->assertReturned(function ($value) use (&$captured): bool {
        $captured = $value;

        return true;
    });

    return $captured;
}

function dashboardSources(): array
{
    $mk = fn (string $label): array => [
        'label' => $label,
        'props' => ['connectionConfig' => ['type' => 'local'], 'height' => '100%'],
    ];

    return ['a' => $mk('Alpha'), 'b' => $mk('Bravo'), 'c' => $mk('Charlie'), 'd' => $mk('Delta')];
}

function dashboardArgs(array $overrides = []): array
{
    return [
        'sources' => dashboardSources(),
        'defaultOpen' => ['a'], // StreamDashboard opens exactly what it's given
        'arrangement' => [3 => 'main-left'],
        'defaultPreset' => 'tiled',
        'maxOpen' => 4,
        ...$overrides,
    ];
}

describe('StreamDashboard', function () {
    describe('mount', function () {
        it('opens exactly the given sources and arranges a single pane', function () {
            $component = Livewire::test(StreamDashboard::class, dashboardArgs());

            expect($component->get('openOrder'))->toBe(['a'])
                ->and($component->get('panes'))->toHaveKey('a')
                ->and($component->get('tree'))->toBe(['type' => 'pane', 'paneId' => 'a']);
        });

        it('starts with nothing open for an explicit empty list', function () {
            $component = Livewire::test(StreamDashboard::class, dashboardArgs(['defaultOpen' => []]));

            expect($component->get('openOrder'))->toBe([])
                ->and($component->get('tree'))->toBeNull();
        });

        it('honors an explicit defaultOpen list', function () {
            $component = Livewire::test(StreamDashboard::class, dashboardArgs(['defaultOpen' => ['b', 'c']]));

            expect($component->get('openOrder'))->toBe(['b', 'c'])
                ->and($component->get('tree')['type'])->toBe('split');
        });

        it('caps maxOpen at 4', function () {
            $component = Livewire::test(StreamDashboard::class, dashboardArgs(['maxOpen' => 9]));

            expect($component->get('maxOpen'))->toBe(4);
        });

        it('renders a toggle button per source', function () {
            $component = Livewire::test(StreamDashboard::class, dashboardArgs());

            $component
                ->assertSeeHtml('data-wts-source="a"')
                ->assertSeeHtml('data-wts-source="d"')
                ->assertSee('Alpha');
        });
    });

    describe('toggle', function () {
        it('opens a closed source and re-arranges', function () {
            $component = Livewire::test(StreamDashboard::class, dashboardArgs(['defaultOpen' => ['a']]));

            $result = callDashboard($component, 'toggle', 'b');

            expect($result['open'])->toBe(['a', 'b'])
                ->and($component->get('panes'))->toHaveKeys(['a', 'b'])
                ->and($result['tree']['type'])->toBe('split');
        });

        it('closes an open source (destroying its pane)', function () {
            $component = Livewire::test(StreamDashboard::class, dashboardArgs(['defaultOpen' => ['a', 'b']]));

            $result = callDashboard($component, 'toggle', 'a');

            expect($result['open'])->toBe(['b'])
                ->and($component->get('panes'))->not->toHaveKey('a')
                ->and($component->get('tree'))->toBe(['type' => 'pane', 'paneId' => 'b']);
        });

        it('uses the arrangement map for the resulting count', function () {
            // 3 open => 'main-left' per dashboardArgs.
            $component = Livewire::test(StreamDashboard::class, dashboardArgs(['defaultOpen' => ['a', 'b']]));

            $result = callDashboard($component, 'toggle', 'c');

            // main-left: big first pane on the left, rest stacked on the right.
            expect($result['tree']['orientation'])->toBe('horizontal')
                ->and($result['tree']['first']['paneId'])->toBe('a')
                ->and($result['tree']['second']['orientation'])->toBe('vertical');
        });

        it('refuses to open past maxOpen', function () {
            $component = Livewire::test(StreamDashboard::class, dashboardArgs([
                'defaultOpen' => ['a', 'b', 'c', 'd'],
            ]));

            // A 5th would exceed the cap — but there are only 4 sources; close
            // one, then confirm the cap is enforced by lowering it.
            $limited = Livewire::test(StreamDashboard::class, dashboardArgs([
                'defaultOpen' => ['a', 'b'],
                'maxOpen' => 2,
            ]));

            expect(callDashboard($limited, 'toggle', 'c'))->toBe(['error' => 'Open limit reached'])
                ->and($limited->get('openOrder'))->toBe(['a', 'b']);

            expect($component->get('openOrder'))->toHaveCount(4);
        });

        it('rejects unknown sources', function () {
            $component = Livewire::test(StreamDashboard::class, dashboardArgs());

            expect(callDashboard($component, 'toggle', 'nope'))->toBe(['error' => 'Unknown source']);
        });

        it('denies opening when the useStreamTerminal gate forbids', function () {
            Gate::define('useStreamTerminal', fn ($user = null) => false);

            $component = Livewire::test(StreamDashboard::class, dashboardArgs(['defaultOpen' => []]));

            expect(callDashboard($component, 'toggle', 'a'))->toBe(['error' => 'Unauthorized'])
                ->and($component->get('openOrder'))->toBe([]);
        });

        it('allows closing even without the gate (destructive is always fine)', function () {
            $component = Livewire::test(StreamDashboard::class, dashboardArgs(['defaultOpen' => ['a']]));
            Gate::define('useStreamTerminal', fn ($user = null) => false);

            $result = callDashboard($component, 'toggle', 'a');

            expect($result['open'])->toBe([]);
        });
    });

    describe('locked state', function () {
        it('locks the source roster and tree against client tampering', function () {
            $component = Livewire::test(StreamDashboard::class, dashboardArgs());

            expect(fn () => $component->set('sources', ['evil' => ['label' => 'x', 'props' => []]]))
                ->toThrow(Exception::class, 'Cannot update locked property: [sources]');
        });
    });
});
