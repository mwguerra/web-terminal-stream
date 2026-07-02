<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Data\Layout;

use InvalidArgumentException;
use MWGuerra\WebTerminalStream\Enums\SplitOrientation;

/**
 * Pure operations on a workspace's binary split tree.
 *
 * The tree is the plain-array wire shape shared by the Livewire
 * component (as a #[Locked] prop) and the frontend:
 *
 *   { "type": "pane",  "paneId": "p-..." }
 *   { "type": "split", "id": "s-...", "orientation": "horizontal",
 *     "ratio": 0.5, "first": <node>, "second": <node> }
 *
 * `ratio` is the first child's share of the split, in (0, 1).
 * All operations return new arrays; inputs are never mutated.
 */
final class LayoutTree
{
    /**
     * A leaf node for one pane.
     *
     * @return array{type: string, paneId: string}
     */
    public static function pane(string $paneId): array
    {
        return ['type' => 'pane', 'paneId' => $paneId];
    }

    /**
     * Replace the pane leaf with a split holding the old pane first and
     * the new pane second, at an even ratio — tmux split semantics.
     *
     * @throws InvalidArgumentException when the pane is not in the tree
     */
    public static function splitPane(array $tree, string $paneId, SplitOrientation $orientation, string $newPaneId): array
    {
        $result = self::replacePane($tree, $paneId, [
            'type' => 'split',
            'id' => 's-'.$newPaneId,
            'orientation' => $orientation->value,
            'ratio' => 0.5,
            'first' => self::pane($paneId),
            'second' => self::pane($newPaneId),
        ]);

        if ($result === null) {
            throw new InvalidArgumentException("Pane \"{$paneId}\" is not in the layout tree.");
        }

        return $result;
    }

    /**
     * Remove a pane; its parent split collapses into the sibling subtree.
     * Returns null when the last pane closes.
     *
     * @throws InvalidArgumentException when the pane is not in the tree
     */
    public static function removePane(array $tree, string $paneId): ?array
    {
        if (! in_array($paneId, self::paneIds($tree), true)) {
            throw new InvalidArgumentException("Pane \"{$paneId}\" is not in the layout tree.");
        }

        if (self::isPane($tree, $paneId)) {
            return null;
        }

        return self::collapse($tree, $paneId);
    }

    /**
     * Every pane id in the tree, in layout order.
     *
     * @return list<string>
     */
    public static function paneIds(array $tree): array
    {
        if ($tree['type'] === 'pane') {
            return [$tree['paneId']];
        }

        return [...self::paneIds($tree['first']), ...self::paneIds($tree['second'])];
    }

    /**
     * Apply new ratios keyed by split-node id. Unknown ids are ignored
     * (client state may lag one topology change behind); every applied
     * ratio is clamped to [$minRatio, 1 - $minRatio].
     *
     * @param  array<string, float|int>  $ratios
     */
    public static function updateRatios(array $tree, array $ratios, float $minRatio = 0.1): array
    {
        if ($tree['type'] === 'pane') {
            return $tree;
        }

        if (array_key_exists($tree['id'], $ratios)) {
            $tree['ratio'] = self::clampRatio((float) $ratios[$tree['id']], $minRatio);
        }

        $tree['first'] = self::updateRatios($tree['first'], $ratios, $minRatio);
        $tree['second'] = self::updateRatios($tree['second'], $ratios, $minRatio);

        return $tree;
    }

    /**
     * Whether two trees have identical structure and ids (ratios ignored).
     */
    public static function sameTopology(array $a, array $b): bool
    {
        if ($a['type'] !== $b['type']) {
            return false;
        }

        if ($a['type'] === 'pane') {
            return $a['paneId'] === $b['paneId'];
        }

        return $a['id'] === $b['id']
            && $a['orientation'] === $b['orientation']
            && self::sameTopology($a['first'], $b['first'])
            && self::sameTopology($a['second'], $b['second']);
    }

    /**
     * Validate the array shape of an untrusted tree (recursive), throwing
     * on structural problems: wrong keys, bad orientation, out-of-range
     * ratio, or duplicate pane ids.
     */
    public static function validate(array $tree): void
    {
        self::validateNode($tree);

        $ids = self::paneIds($tree);

        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Layout tree contains duplicate pane ids.');
        }
    }

    private static function validateNode(array $node): void
    {
        $type = $node['type'] ?? null;

        if ($type === 'pane') {
            if (! is_string($node['paneId'] ?? null) || $node['paneId'] === '') {
                throw new InvalidArgumentException('Pane node is missing its paneId.');
            }

            return;
        }

        if ($type !== 'split') {
            throw new InvalidArgumentException('Layout node must be of type "pane" or "split".');
        }

        if (! is_string($node['id'] ?? null) || $node['id'] === '') {
            throw new InvalidArgumentException('Split node is missing its id.');
        }

        if (SplitOrientation::tryFrom($node['orientation'] ?? '') === null) {
            throw new InvalidArgumentException('Split node has an invalid orientation.');
        }

        $ratio = $node['ratio'] ?? null;

        if (! is_float($ratio) && ! is_int($ratio) || $ratio <= 0 || $ratio >= 1) {
            throw new InvalidArgumentException('Split ratio must be a number in (0, 1).');
        }

        if (! is_array($node['first'] ?? null) || ! is_array($node['second'] ?? null)) {
            throw new InvalidArgumentException('Split node must have first and second children.');
        }

        self::validateNode($node['first']);
        self::validateNode($node['second']);
    }

    private static function clampRatio(float $ratio, float $minRatio): float
    {
        return max($minRatio, min(1.0 - $minRatio, $ratio));
    }

    private static function isPane(array $node, string $paneId): bool
    {
        return $node['type'] === 'pane' && $node['paneId'] === $paneId;
    }

    /**
     * Replace the pane leaf with a replacement node; null when not found.
     */
    private static function replacePane(array $node, string $paneId, array $replacement): ?array
    {
        if ($node['type'] === 'pane') {
            return self::isPane($node, $paneId) ? $replacement : null;
        }

        if (($first = self::replacePane($node['first'], $paneId, $replacement)) !== null) {
            $node['first'] = $first;

            return $node;
        }

        if (($second = self::replacePane($node['second'], $paneId, $replacement)) !== null) {
            $node['second'] = $second;

            return $node;
        }

        return null;
    }

    /**
     * Remove the pane by collapsing its parent split into the sibling.
     * The caller guarantees the pane exists and is not the root.
     */
    private static function collapse(array $node, string $paneId): array
    {
        if (self::isPane($node['first'], $paneId)) {
            return $node['second'];
        }

        if (self::isPane($node['second'], $paneId)) {
            return $node['first'];
        }

        if ($node['first']['type'] === 'split' && in_array($paneId, self::paneIds($node['first']), true)) {
            $node['first'] = self::collapse($node['first'], $paneId);
        } else {
            $node['second'] = self::collapse($node['second'], $paneId);
        }

        return $node;
    }
}
