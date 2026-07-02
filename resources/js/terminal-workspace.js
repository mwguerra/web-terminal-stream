// Terminal Workspace — pure layout/keymap helpers for the tmux-style
// split workspace. No DOM access here: everything takes plain data and
// returns plain data so the logic stays testable and the Alpine
// component in terminal-workspace.blade.php stays thin.

/**
 * Compute pane rectangles and divider strips from a split tree.
 * All values are percentages of the workspace container (0–100).
 *
 * Returns { panes: {paneId: {x, y, w, h}}, dividers: [{splitId,
 * orientation, x, y, w, h}] } where a divider is the boundary line of
 * one split (horizontal split → vertical divider).
 */
export function computeRects(tree) {
    const panes = {};
    const dividers = [];

    if (!tree) {
        return { panes, dividers };
    }

    const walk = (node, rect) => {
        if (node.type === 'pane') {
            panes[node.paneId] = rect;
            return;
        }

        const ratio = node.ratio;

        if (node.orientation === 'horizontal') {
            const firstW = rect.w * ratio;
            walk(node.first, { x: rect.x, y: rect.y, w: firstW, h: rect.h });
            walk(node.second, { x: rect.x + firstW, y: rect.y, w: rect.w - firstW, h: rect.h });
            dividers.push({
                splitId: node.id,
                orientation: node.orientation,
                x: rect.x + firstW,
                y: rect.y,
                w: 0,
                h: rect.h,
            });
        } else {
            const firstH = rect.h * ratio;
            walk(node.first, { x: rect.x, y: rect.y, w: rect.w, h: firstH });
            walk(node.second, { x: rect.x, y: rect.y + firstH, w: rect.w, h: rect.h - firstH });
            dividers.push({
                splitId: node.id,
                orientation: node.orientation,
                x: rect.x,
                y: rect.y + firstH,
                w: rect.w,
                h: 0,
            });
        }
    };

    walk(tree, { x: 0, y: 0, w: 100, h: 100 });

    return { panes, dividers };
}

/**
 * Normalize a KeyboardEvent into the keymap's key-string grammar
 * ('ctrl+b', 'shift+arrowleft', '%'). Returns null for bare modifier
 * presses. Shift is only spelled out for non-printable keys — printable
 * characters (%, ", |) already encode it.
 */
export function eventToKeyString(event) {
    const key = event.key;

    if (key === 'Control' || key === 'Alt' || key === 'Shift' || key === 'Meta') {
        return null;
    }

    const normalizedKey = key.length === 1 ? key.toLowerCase() : key.toLowerCase();
    const parts = [];

    if (event.ctrlKey) parts.push('ctrl');
    if (event.altKey) parts.push('alt');
    if (event.shiftKey && key.length > 1) parts.push('shift');
    if (event.metaKey) parts.push('meta');

    parts.push(normalizedKey);

    return parts.join('+');
}

/**
 * Match a normalized key string against the keymap bindings.
 * Returns the action name or null.
 */
export function matchBinding(bindings, keyString) {
    for (const [action, keys] of Object.entries(bindings)) {
        if (keys.includes(keyString)) {
            return action;
        }
    }

    return null;
}

/**
 * Find the pane geometrically adjacent to `fromId` in a direction
 * ('left' | 'right' | 'up' | 'down'). Prefers the nearest pane whose
 * span overlaps the source pane's center line — tmux-like navigation.
 * Returns a paneId or null.
 */
export function findNeighbor(rects, fromId, direction) {
    const from = rects[fromId];

    if (!from) {
        return null;
    }

    const centerX = from.x + from.w / 2;
    const centerY = from.y + from.h / 2;
    let best = null;
    let bestDistance = Infinity;

    for (const [paneId, rect] of Object.entries(rects)) {
        if (paneId === fromId) continue;

        let distance;

        if (direction === 'left' && rect.x + rect.w <= from.x + 0.01) {
            distance = from.x - (rect.x + rect.w);
        } else if (direction === 'right' && rect.x >= from.x + from.w - 0.01) {
            distance = rect.x - (from.x + from.w);
        } else if (direction === 'up' && rect.y + rect.h <= from.y + 0.01) {
            distance = from.y - (rect.y + rect.h);
        } else if (direction === 'down' && rect.y >= from.y + from.h - 0.01) {
            distance = rect.y - (from.y + from.h);
        } else {
            continue;
        }

        // Cross-axis alignment: penalize panes that don't overlap the
        // source center line so navigation lands where the eye expects.
        const overlaps = (direction === 'left' || direction === 'right')
            ? rect.y <= centerY && centerY <= rect.y + rect.h
            : rect.x <= centerX && centerX <= rect.x + rect.w;

        const score = distance + (overlaps ? 0 : 1000);

        if (score < bestDistance) {
            bestDistance = score;
            best = paneId;
        }
    }

    return best;
}

/**
 * Find the nearest ancestor split of the given orientation that
 * contains the pane — the split a keyboard resize should adjust.
 * Returns { splitId, ratio, paneInFirst } or null.
 */
export function findResizeSplit(tree, paneId, orientation) {
    let result = null;

    const contains = (node, id) => {
        if (node.type === 'pane') return node.paneId === id;
        return contains(node.first, id) || contains(node.second, id);
    };

    const walk = (node) => {
        if (node.type === 'pane') {
            return node.paneId === paneId;
        }

        const inFirst = walk(node.first);
        const inSecond = inFirst ? false : walk(node.second);

        if ((inFirst || inSecond) && node.orientation === orientation) {
            result ??= { splitId: node.id, ratio: node.ratio, paneInFirst: contains(node.first, paneId) };
        }

        return inFirst || inSecond;
    };

    walk(tree);

    return result;
}

/**
 * Return a copy of the tree with one split's ratio replaced (clamped).
 */
export function withRatio(tree, splitId, ratio, minRatio) {
    if (!tree || tree.type === 'pane') {
        return tree;
    }

    const clamped = Math.max(minRatio, Math.min(1 - minRatio, ratio));

    return {
        ...tree,
        ratio: tree.id === splitId ? clamped : tree.ratio,
        first: withRatio(tree.first, splitId, ratio, minRatio),
        second: withRatio(tree.second, splitId, ratio, minRatio),
    };
}

/**
 * The split node's own rectangle (the region it subdivides), needed to
 * translate a pointer position into a ratio while dragging.
 */
export function findSplitRect(tree, splitId, rect = { x: 0, y: 0, w: 100, h: 100 }) {
    if (!tree || tree.type === 'pane') {
        return null;
    }

    if (tree.id === splitId) {
        return rect;
    }

    const ratio = tree.ratio;

    if (tree.orientation === 'horizontal') {
        const firstW = rect.w * ratio;

        return findSplitRect(tree.first, splitId, { x: rect.x, y: rect.y, w: firstW, h: rect.h })
            ?? findSplitRect(tree.second, splitId, { x: rect.x + firstW, y: rect.y, w: rect.w - firstW, h: rect.h });
    }

    const firstH = rect.h * ratio;

    return findSplitRect(tree.first, splitId, { x: rect.x, y: rect.y, w: rect.w, h: firstH })
        ?? findSplitRect(tree.second, splitId, { x: rect.x, y: rect.y + firstH, w: rect.w, h: rect.h - firstH });
}
