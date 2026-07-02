<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Data\Keymap;
use MWGuerra\WebTerminalStream\Enums\PaneAction;

describe('Keymap', function () {
    describe('presets', function () {
        it('make() starts empty and prefix-less', function () {
            $keymap = Keymap::make();

            expect($keymap->getPrefix())->toBeNull()
                ->and($keymap->toArray()['bindings'])->toBe([]);
        });

        it('tmux() carries the full tmux-flavored map', function () {
            $keymap = Keymap::tmux();

            expect($keymap->getPrefix())->toBe('ctrl+b')
                ->and($keymap->getBindings(PaneAction::SplitHorizontal))->toBe(['%'])
                ->and($keymap->getBindings(PaneAction::SplitVertical))->toBe(['"'])
                ->and($keymap->getBindings(PaneAction::ClosePane))->toBe(['x'])
                ->and($keymap->getBindings(PaneAction::ZoomPane))->toBe(['z'])
                ->and($keymap->getBindings(PaneAction::FocusLeft))->toBe(['arrowleft', 'h'])
                ->and($keymap->getBindings(PaneAction::ResizeRight))->toBe(['ctrl+arrowright']);
        });

        it('default() is the tmux preset', function () {
            expect(Keymap::default()->toArray())->toBe(Keymap::tmux()->toArray());
        });
    });

    describe('fluent customization', function () {
        it('changes the prefix', function () {
            expect(Keymap::tmux()->prefix('ctrl+a')->getPrefix())->toBe('ctrl+a');
        });

        it('allows a null prefix for direct chords', function () {
            expect(Keymap::tmux()->prefix(null)->getPrefix())->toBeNull();
        });

        it('rebinds an action, replacing previous keys', function () {
            $keymap = Keymap::tmux()->bind(PaneAction::SplitVertical, '|', 'v');

            expect($keymap->getBindings(PaneAction::SplitVertical))->toBe(['|', 'v']);
        });

        it('unbinds an action', function () {
            $keymap = Keymap::tmux()->unbind(PaneAction::ClosePane);

            expect($keymap->getBindings(PaneAction::ClosePane))->toBe([]);
        });

        it('clamps the prefix timeout to a sane floor', function () {
            expect(Keymap::tmux()->prefixTimeout(5)->getPrefixTimeout())->toBe(100);
        });
    });

    describe('validation', function () {
        it('rejects uppercase key strings', function () {
            Keymap::tmux()->prefix('Ctrl+B');
        })->throws(InvalidArgumentException::class, 'lowercase');

        it('rejects unknown modifiers', function () {
            Keymap::tmux()->bind(PaneAction::ZoomPane, 'super+z');
        })->throws(InvalidArgumentException::class, 'Invalid modifier');

        it('rejects empty key strings', function () {
            Keymap::tmux()->bind(PaneAction::ZoomPane, '');
        })->throws(InvalidArgumentException::class, 'cannot be empty');

        it('accepts special printable keys', function () {
            $keymap = Keymap::tmux()
                ->bind(PaneAction::SplitHorizontal, '%')
                ->bind(PaneAction::SplitVertical, '"')
                ->bind(PaneAction::ZoomPane, '+');

            expect($keymap->getBindings(PaneAction::ZoomPane))->toBe(['+']);
        });

        it('accepts modifier + special key combinations', function () {
            $keymap = Keymap::make()->bind(PaneAction::ZoomPane, 'ctrl++');

            expect($keymap->getBindings(PaneAction::ZoomPane))->toBe(['ctrl++']);
        });

        it('rejects bindings with a trailing separator and no key', function (string $binding) {
            expect(fn () => Keymap::make()->bind(PaneAction::ZoomPane, $binding))
                ->toThrow(InvalidArgumentException::class, 'missing its key');
        })->with(['ctrl+', 'x+', 'ctrl+shift+']);
    });

    describe('fromArray (config shape)', function () {
        it('starts from the tmux preset and overrides provided keys', function () {
            $keymap = Keymap::fromArray([
                'prefix' => 'ctrl+a',
                'bindings' => ['split_vertical' => ['|']],
            ]);

            expect($keymap->getPrefix())->toBe('ctrl+a')
                ->and($keymap->getBindings(PaneAction::SplitVertical))->toBe(['|'])
                ->and($keymap->getBindings(PaneAction::ClosePane))->toBe(['x']);
        });

        it('accepts a single string binding', function () {
            $keymap = Keymap::fromArray(['bindings' => ['zoom_pane' => 'f']]);

            expect($keymap->getBindings(PaneAction::ZoomPane))->toBe(['f']);
        });

        it('disables an action bound to an empty list', function () {
            $keymap = Keymap::fromArray(['bindings' => ['close_pane' => []]]);

            expect($keymap->getBindings(PaneAction::ClosePane))->toBe([]);
        });

        it('rejects unknown action names', function () {
            Keymap::fromArray(['bindings' => ['explode_pane' => ['x']]]);
        })->throws(InvalidArgumentException::class, 'Unknown pane action');

        it('honors an explicit null prefix', function () {
            expect(Keymap::fromArray(['prefix' => null])->getPrefix())->toBeNull();
        });
    });

    describe('toArray (wire format)', function () {
        it('emits prefix, timeout, and bindings', function () {
            $wire = Keymap::tmux()->toArray();

            expect($wire)->toHaveKeys(['prefix', 'prefix_timeout', 'bindings'])
                ->and($wire['prefix'])->toBe('ctrl+b')
                ->and($wire['prefix_timeout'])->toBe(1500)
                ->and($wire['bindings']['split_horizontal'])->toBe(['%']);
        });
    });
});
