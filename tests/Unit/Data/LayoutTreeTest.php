<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Data\Layout\LayoutTree;
use MWGuerra\WebTerminalStream\Enums\SplitOrientation;

describe('LayoutTree', function () {
    describe('splitPane', function () {
        it('splits a single-pane root into a two-pane split', function () {
            $tree = LayoutTree::splitPane(LayoutTree::pane('p-a'), 'p-a', SplitOrientation::Horizontal, 'p-b');

            expect($tree)->toBe([
                'type' => 'split',
                'id' => 's-p-b',
                'orientation' => 'horizontal',
                'ratio' => 0.5,
                'first' => ['type' => 'pane', 'paneId' => 'p-a'],
                'second' => ['type' => 'pane', 'paneId' => 'p-b'],
            ]);
        });

        it('splits a nested pane, leaving the rest of the tree intact', function () {
            $tree = LayoutTree::pane('p-a');
            $tree = LayoutTree::splitPane($tree, 'p-a', SplitOrientation::Horizontal, 'p-b');
            $tree = LayoutTree::splitPane($tree, 'p-b', SplitOrientation::Vertical, 'p-c');

            expect($tree['first'])->toBe(['type' => 'pane', 'paneId' => 'p-a'])
                ->and($tree['second']['type'])->toBe('split')
                ->and($tree['second']['orientation'])->toBe('vertical')
                ->and($tree['second']['first'])->toBe(['type' => 'pane', 'paneId' => 'p-b'])
                ->and($tree['second']['second'])->toBe(['type' => 'pane', 'paneId' => 'p-c']);
        });

        it('keeps the split pane first and the new pane second (tmux order)', function () {
            $tree = LayoutTree::splitPane(LayoutTree::pane('p-a'), 'p-a', SplitOrientation::Vertical, 'p-b');

            expect(LayoutTree::paneIds($tree))->toBe(['p-a', 'p-b']);
        });

        it('inserts the new pane first when $before is true (left/up splits)', function () {
            $tree = LayoutTree::splitPane(LayoutTree::pane('p-a'), 'p-a', SplitOrientation::Horizontal, 'p-b', before: true);

            expect($tree['first'])->toBe(['type' => 'pane', 'paneId' => 'p-b'])
                ->and($tree['second'])->toBe(['type' => 'pane', 'paneId' => 'p-a'])
                ->and(LayoutTree::paneIds($tree))->toBe(['p-b', 'p-a']);
        });

        it('does not mutate the input tree', function () {
            $original = LayoutTree::splitPane(LayoutTree::pane('p-a'), 'p-a', SplitOrientation::Horizontal, 'p-b');
            $snapshot = $original;

            LayoutTree::splitPane($original, 'p-b', SplitOrientation::Vertical, 'p-c');

            expect($original)->toBe($snapshot);
        });

        it('throws for a pane that is not in the tree', function () {
            LayoutTree::splitPane(LayoutTree::pane('p-a'), 'p-missing', SplitOrientation::Horizontal, 'p-b');
        })->throws(InvalidArgumentException::class, 'not in the layout tree');
    });

    describe('removePane', function () {
        it('returns null when the last pane closes', function () {
            expect(LayoutTree::removePane(LayoutTree::pane('p-a'), 'p-a'))->toBeNull();
        });

        it('collapses a two-pane split back into the sibling pane', function () {
            $tree = LayoutTree::splitPane(LayoutTree::pane('p-a'), 'p-a', SplitOrientation::Horizontal, 'p-b');

            expect(LayoutTree::removePane($tree, 'p-b'))->toBe(['type' => 'pane', 'paneId' => 'p-a'])
                ->and(LayoutTree::removePane($tree, 'p-a'))->toBe(['type' => 'pane', 'paneId' => 'p-b']);
        });

        it('collapses a nested split into its sibling subtree', function () {
            // V(a, H(b, c)) — closing a must promote the whole H(b, c) subtree.
            $tree = LayoutTree::pane('p-a');
            $tree = LayoutTree::splitPane($tree, 'p-a', SplitOrientation::Vertical, 'p-b');
            $tree = LayoutTree::splitPane($tree, 'p-b', SplitOrientation::Horizontal, 'p-c');

            $collapsed = LayoutTree::removePane($tree, 'p-a');

            expect($collapsed['type'])->toBe('split')
                ->and($collapsed['orientation'])->toBe('horizontal')
                ->and(LayoutTree::paneIds($collapsed))->toBe(['p-b', 'p-c']);
        });

        it('removes a deep pane while preserving surrounding structure', function () {
            // H(a, V(b, H(c, d))) — closing c leaves H(a, V(b, d)).
            $tree = LayoutTree::pane('p-a');
            $tree = LayoutTree::splitPane($tree, 'p-a', SplitOrientation::Horizontal, 'p-b');
            $tree = LayoutTree::splitPane($tree, 'p-b', SplitOrientation::Vertical, 'p-c');
            $tree = LayoutTree::splitPane($tree, 'p-c', SplitOrientation::Horizontal, 'p-d');

            $result = LayoutTree::removePane($tree, 'p-c');

            expect(LayoutTree::paneIds($result))->toBe(['p-a', 'p-b', 'p-d'])
                ->and($result['second']['second'])->toBe(['type' => 'pane', 'paneId' => 'p-d']);
        });

        it('preserves the surviving split ratios through a collapse', function () {
            $tree = LayoutTree::pane('p-a');
            $tree = LayoutTree::splitPane($tree, 'p-a', SplitOrientation::Horizontal, 'p-b');
            $tree = LayoutTree::splitPane($tree, 'p-b', SplitOrientation::Vertical, 'p-c');
            $tree = LayoutTree::updateRatios($tree, ['s-p-b' => 0.7]);

            $result = LayoutTree::removePane($tree, 'p-c');

            expect($result['id'])->toBe('s-p-b')
                ->and($result['ratio'])->toBe(0.7);
        });

        it('throws for a pane that is not in the tree', function () {
            LayoutTree::removePane(LayoutTree::pane('p-a'), 'p-missing');
        })->throws(InvalidArgumentException::class, 'not in the layout tree');
    });

    describe('arrange', function () {
        it('returns null for no panes and a bare pane for one', function () {
            expect(LayoutTree::arrange([]))->toBeNull()
                ->and(LayoutTree::arrange(['a']))->toBe(['type' => 'pane', 'paneId' => 'a']);
        });

        it('preserves pane order and keeps ids unique/valid', function () {
            $tree = LayoutTree::arrange(['a', 'b', 'c', 'd'], 'tiled');

            expect(LayoutTree::paneIds($tree))->toBe(['a', 'b', 'c', 'd']);
            LayoutTree::validate($tree); // unique split ids, well-formed
        });

        it('tiled: 2 side-by-side', function () {
            $tree = LayoutTree::arrange(['a', 'b'], 'tiled');

            expect($tree['type'])->toBe('split')
                ->and($tree['orientation'])->toBe('horizontal')
                ->and($tree['ratio'])->toBe(0.5)
                ->and($tree['first'])->toBe(['type' => 'pane', 'paneId' => 'a'])
                ->and($tree['second'])->toBe(['type' => 'pane', 'paneId' => 'b']);
        });

        it('tiled: 3 = one tall left + two stacked right', function () {
            $tree = LayoutTree::arrange(['a', 'b', 'c'], 'tiled');

            expect($tree['orientation'])->toBe('horizontal')
                ->and($tree['first']['paneId'])->toBe('a')
                ->and($tree['second']['orientation'])->toBe('vertical')
                ->and(LayoutTree::paneIds($tree['second']))->toBe(['b', 'c']);
        });

        it('tiled: 4 = even 2x2 grid', function () {
            $tree = LayoutTree::arrange(['a', 'b', 'c', 'd'], 'tiled');

            expect($tree['orientation'])->toBe('vertical')      // top row / bottom row
                ->and($tree['ratio'])->toBe(0.5)
                ->and($tree['first']['orientation'])->toBe('horizontal')
                ->and(LayoutTree::paneIds($tree['first']))->toBe(['a', 'b'])
                ->and(LayoutTree::paneIds($tree['second']))->toBe(['c', 'd']);
        });

        it('columns: even ratios so every pane is equal width', function () {
            $tree = LayoutTree::arrange(['a', 'b', 'c', 'd'], 'columns');

            // 1/4, then 1/3, then 1/2 → 25% each.
            expect($tree['ratio'])->toBe(0.25)
                ->and($tree['second']['ratio'])->toBe(1 / 3)
                ->and($tree['second']['second']['ratio'])->toBe(0.5)
                ->and(collect(LayoutTree::paneIds($tree)))->toHaveCount(4);
        });

        it('main-left: big first pane, rest stacked on the right', function () {
            $tree = LayoutTree::arrange(['a', 'b', 'c', 'd'], 'main-left');

            expect($tree['orientation'])->toBe('horizontal')
                ->and($tree['ratio'])->toBe(0.5)
                ->and($tree['first']['paneId'])->toBe('a')
                ->and($tree['second']['orientation'])->toBe('vertical')
                ->and(LayoutTree::paneIds($tree['second']))->toBe(['b', 'c', 'd']);
        });

        it('rows: even stacked rows', function () {
            $tree = LayoutTree::arrange(['a', 'b', 'c'], 'rows');

            expect($tree['orientation'])->toBe('vertical')
                ->and($tree['ratio'])->toBe(1 / 3);
        });

        it('falls back to even columns for an unknown preset', function () {
            $tree = LayoutTree::arrange(['a', 'b'], 'nonsense');

            expect($tree['orientation'])->toBe('horizontal')
                ->and(LayoutTree::paneIds($tree))->toBe(['a', 'b']);
        });
    });

    describe('paneIds', function () {
        it('collects ids in layout order', function () {
            $tree = LayoutTree::pane('p-a');
            $tree = LayoutTree::splitPane($tree, 'p-a', SplitOrientation::Horizontal, 'p-b');
            $tree = LayoutTree::splitPane($tree, 'p-a', SplitOrientation::Vertical, 'p-c');

            expect(LayoutTree::paneIds($tree))->toBe(['p-a', 'p-c', 'p-b']);
        });
    });

    describe('updateRatios', function () {
        it('applies ratios by split id and clamps to the minimum', function () {
            $tree = LayoutTree::splitPane(LayoutTree::pane('p-a'), 'p-a', SplitOrientation::Horizontal, 'p-b');

            expect(LayoutTree::updateRatios($tree, ['s-p-b' => 0.65])['ratio'])->toBe(0.65)
                ->and(LayoutTree::updateRatios($tree, ['s-p-b' => 0.01])['ratio'])->toBe(0.1)
                ->and(LayoutTree::updateRatios($tree, ['s-p-b' => 0.99])['ratio'])->toBe(0.9)
                ->and(LayoutTree::updateRatios($tree, ['s-p-b' => 0.2], 0.25)['ratio'])->toBe(0.25);
        });

        it('ignores unknown split ids', function () {
            $tree = LayoutTree::splitPane(LayoutTree::pane('p-a'), 'p-a', SplitOrientation::Horizontal, 'p-b');

            expect(LayoutTree::updateRatios($tree, ['s-unknown' => 0.8]))->toBe($tree);
        });

        it('reaches nested splits', function () {
            $tree = LayoutTree::pane('p-a');
            $tree = LayoutTree::splitPane($tree, 'p-a', SplitOrientation::Horizontal, 'p-b');
            $tree = LayoutTree::splitPane($tree, 'p-b', SplitOrientation::Vertical, 'p-c');

            $updated = LayoutTree::updateRatios($tree, ['s-p-c' => 0.3]);

            expect($updated['second']['ratio'])->toBe(0.3)
                ->and($updated['ratio'])->toBe(0.5);
        });
    });

    describe('sameTopology', function () {
        it('matches identical structures regardless of ratios', function () {
            $tree = LayoutTree::splitPane(LayoutTree::pane('p-a'), 'p-a', SplitOrientation::Horizontal, 'p-b');
            $resized = LayoutTree::updateRatios($tree, ['s-p-b' => 0.8]);

            expect(LayoutTree::sameTopology($tree, $resized))->toBeTrue();
        });

        it('detects structural differences', function () {
            $a = LayoutTree::splitPane(LayoutTree::pane('p-a'), 'p-a', SplitOrientation::Horizontal, 'p-b');
            $b = LayoutTree::splitPane(LayoutTree::pane('p-a'), 'p-a', SplitOrientation::Vertical, 'p-b');

            expect(LayoutTree::sameTopology($a, $b))->toBeFalse()
                ->and(LayoutTree::sameTopology($a, LayoutTree::pane('p-a')))->toBeFalse();
        });
    });

    describe('validate', function () {
        it('accepts trees produced by the operations', function () {
            $tree = LayoutTree::pane('p-a');
            $tree = LayoutTree::splitPane($tree, 'p-a', SplitOrientation::Horizontal, 'p-b');
            $tree = LayoutTree::splitPane($tree, 'p-b', SplitOrientation::Vertical, 'p-c');

            LayoutTree::validate($tree);

            expect(true)->toBeTrue();
        });

        it('rejects unknown node types', function () {
            LayoutTree::validate(['type' => 'blob']);
        })->throws(InvalidArgumentException::class, 'must be of type');

        it('rejects out-of-range ratios', function () {
            LayoutTree::validate([
                'type' => 'split', 'id' => 's-1', 'orientation' => 'horizontal', 'ratio' => 1.5,
                'first' => LayoutTree::pane('p-a'), 'second' => LayoutTree::pane('p-b'),
            ]);
        })->throws(InvalidArgumentException::class, 'ratio');

        it('rejects duplicate pane ids', function () {
            LayoutTree::validate([
                'type' => 'split', 'id' => 's-1', 'orientation' => 'horizontal', 'ratio' => 0.5,
                'first' => LayoutTree::pane('p-a'), 'second' => LayoutTree::pane('p-a'),
            ]);
        })->throws(InvalidArgumentException::class, 'duplicate');
    });
});
