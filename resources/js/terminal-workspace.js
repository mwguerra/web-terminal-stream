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

    const parts = [];

    if (event.ctrlKey) parts.push('ctrl');
    if (event.altKey) parts.push('alt');
    if (event.shiftKey && key.length > 1) parts.push('shift');
    if (event.metaKey) parts.push('meta');

    parts.push(key.toLowerCase());

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
 * The Alpine component behind <x-data="wtsWorkspace">, registered as
 * Alpine.data('wtsWorkspace') by the bundle entrypoint. Initial state
 * is read from $wire in init() — NEVER inlined into the x-data
 * attribute, which must stay render-stable or Livewire morphs would
 * destroy and re-initialize the component (losing zoom/focus/armed
 * state and orphaning bindings).
 */
export function component() {
    return {
        tree: null,
        keymap: { prefix: null, prefix_timeout: 1500, bindings: {} },
        shortcutsEnabled: true,
        minRatio: 0.1,
        resizeStep: 0.03,
        rects: {},
        dividers: [],
        focusedPaneId: null,
        zoomedPaneId: null,
        prefixArmed: false,
        busy: false,
        drag: null,
        _prefixTimer: null,
        _keydownHandler: null,
        _focusinHandler: null,
        _ratioSyncTimer: null,
        _pendingRatios: {},

        init() {
            // Detach from the Livewire snapshot proxies — Alpine owns the
            // live tree; the server confirms through method return values.
            this.tree = JSON.parse(JSON.stringify(this.$wire.tree ?? null));
            this.keymap = JSON.parse(JSON.stringify(this.$wire.keymap ?? this.keymap));
            this.shortcutsEnabled = Boolean(this.$wire.shortcutsEnabled);
            this.minRatio = Number(this.$wire.minPaneRatio ?? 0.1);
            this.resizeStep = Number(this.$wire.resizeStep ?? 0.03);

            this.recompute();
            this.focusedPaneId = Object.keys(this.rects)[0] ?? null;

            // Document-level capture-phase interception: the only way to
            // run before ghostty-web's hidden textarea swallows the key.
            this._keydownHandler = (e) => this.onKeydown(e);
            document.addEventListener('keydown', this._keydownHandler, true);

            this._focusinHandler = (e) => {
                const pane = e.target.closest('[data-wts-pane]');
                if (pane) {
                    this.focusedPaneId = pane.dataset.wtsPane;
                }
            };
            this.$el.addEventListener('focusin', this._focusinHandler);
        },

        destroy() {
            document.removeEventListener('keydown', this._keydownHandler, true);
            this.$el.removeEventListener('focusin', this._focusinHandler);
            clearTimeout(this._prefixTimer);
            clearTimeout(this._ratioSyncTimer);
        },

        recompute() {
            const layout = computeRects(this.tree);
            this.rects = layout.panes;
            this.dividers = layout.dividers;
        },

        paneStyle(paneId) {
            if (this.zoomedPaneId === paneId) {
                return 'left:0;top:0;width:100%;height:100%;z-index:30;visibility:visible;';
            }

            const rect = this.rects[paneId];

            if (!rect) {
                return 'visibility:hidden;';
            }

            // visibility (not display) keeps hidden panes at full size while
            // zoomed — no ResizeObserver fire, no PTY reflow, WS stays live.
            const hidden = this.zoomedPaneId !== null ? 'visibility:hidden;' : 'visibility:visible;';

            return `left:${rect.x}%;top:${rect.y}%;width:${rect.w}%;height:${rect.h}%;` + hidden;
        },

        // ── Keyboard: tmux prefix state machine ─────────────────────────

        onKeydown(e) {
            if (!this.shortcutsEnabled || !this.$el.contains(e.target)) {
                return;
            }

            const key = eventToKeyString(e);

            if (key === null) {
                return;
            }

            if (!this.prefixArmed) {
                if (this.keymap.prefix !== null && key === this.keymap.prefix) {
                    this.swallow(e);
                    this.arm();
                } else if (this.keymap.prefix === null) {
                    this.runBoundAction(key, e);
                }

                return;
            }

            this.disarm();

            // Prefix twice sends the literal prefix byte to the pane (tmux).
            if (key === this.keymap.prefix) {
                this.swallow(e);
                this.sendLiteralPrefix();

                return;
            }

            if (key === 'escape') {
                this.swallow(e);

                return;
            }

            // Armed but unbound keys are swallowed too — tmux fidelity.
            this.swallow(e);
            this.runBoundAction(key, e, { alreadySwallowed: true });
        },

        runBoundAction(key, e, { alreadySwallowed = false } = {}) {
            const action = matchBinding(this.keymap.bindings, key);

            if (!action) {
                return;
            }

            if (!alreadySwallowed) {
                this.swallow(e);
            }

            this.perform(action);
        },

        swallow(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
        },

        arm() {
            this.prefixArmed = true;
            clearTimeout(this._prefixTimer);
            this._prefixTimer = setTimeout(() => this.disarm(), this.keymap.prefix_timeout);
        },

        disarm() {
            this.prefixArmed = false;
            clearTimeout(this._prefixTimer);
        },

        perform(action) {
            switch (action) {
                case 'split_horizontal': return this.split('horizontal');
                case 'split_vertical': return this.split('vertical');
                case 'close_pane': return this.close();
                case 'zoom_pane': return this.zoomToggle();
                case 'focus_left': return this.focusDir('left');
                case 'focus_right': return this.focusDir('right');
                case 'focus_up': return this.focusDir('up');
                case 'focus_down': return this.focusDir('down');
                case 'resize_left': return this.resizeDir('left');
                case 'resize_right': return this.resizeDir('right');
                case 'resize_up': return this.resizeDir('up');
                case 'resize_down': return this.resizeDir('down');
            }
        },

        sendLiteralPrefix() {
            // Only ctrl+<letter> prefixes map to a single control byte.
            const match = /^ctrl\+([a-z])$/.exec(this.keymap.prefix ?? '');

            if (!match || !this.focusedPaneId) {
                return;
            }

            const byte = String.fromCharCode(match[1].charCodeAt(0) - 96);
            const paneEl = this.paneEl(this.focusedPaneId)?.querySelector('.stream-web-terminal');
            paneEl?.dispatchEvent(new CustomEvent('wts-pane-send', { detail: { data: byte } }));
        },

        // ── Pane operations ──────────────────────────────────────────────

        async split(orientation) {
            if (this.busy || !this.focusedPaneId) {
                return;
            }

            if (this.zoomedPaneId !== null) {
                this.zoomedPaneId = null;
            }

            this.busy = true;

            try {
                const result = await this.$wire.splitPane(this.focusedPaneId, orientation);

                if (result?.tree) {
                    this.tree = result.tree;
                    this.recompute();
                    this.$nextTick(() => this.focusPane(result.newPaneId));
                }
            } finally {
                this.busy = false;
            }
        },

        async close() {
            if (this.busy || !this.focusedPaneId) {
                return;
            }

            if (this.zoomedPaneId !== null) {
                this.zoomedPaneId = null;
            }

            this.busy = true;

            try {
                const result = await this.$wire.closePane(this.focusedPaneId);

                if (result && !result.error) {
                    this.tree = result.tree;
                    this.recompute();
                    const survivor = Object.keys(this.rects)[0] ?? null;
                    this.focusedPaneId = survivor;

                    if (survivor) {
                        this.$nextTick(() => this.focusPane(survivor));
                    }
                }
            } finally {
                this.busy = false;
            }
        },

        async spawn() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                const result = await this.$wire.spawnPane();

                if (result?.tree) {
                    this.tree = result.tree;
                    this.recompute();
                    this.focusedPaneId = result.newPaneId;
                    this.$nextTick(() => this.focusPane(result.newPaneId));
                }
            } finally {
                this.busy = false;
            }
        },

        zoomToggle() {
            if (!this.focusedPaneId) {
                return;
            }

            this.zoomedPaneId = this.zoomedPaneId === this.focusedPaneId ? null : this.focusedPaneId;
        },

        focusDir(direction) {
            if (this.zoomedPaneId !== null || !this.focusedPaneId) {
                return;
            }

            const neighbor = findNeighbor(this.rects, this.focusedPaneId, direction);

            if (neighbor) {
                this.focusPane(neighbor);
            }
        },

        focusPane(paneId) {
            const el = this.paneEl(paneId);

            if (!el) {
                return;
            }

            const input = el.querySelector('textarea') ?? el;
            input.focus({ preventScroll: true });
            this.focusedPaneId = paneId;
        },

        paneEl(paneId) {
            return this.$el.querySelector(`[data-wts-pane='${paneId}']`);
        },

        // ── Resize: keyboard + divider drag ─────────────────────────────

        resizeDir(direction) {
            if (this.zoomedPaneId !== null || !this.focusedPaneId) {
                return;
            }

            const orientation = (direction === 'left' || direction === 'right') ? 'horizontal' : 'vertical';
            const split = findResizeSplit(this.tree, this.focusedPaneId, orientation);

            if (!split) {
                return;
            }

            const grow = direction === 'right' || direction === 'down';
            const delta = (split.paneInFirst === grow) ? this.resizeStep : -this.resizeStep;

            this.applyRatio(split.splitId, split.ratio + delta);
        },

        startDrag(event, divider) {
            event.preventDefault();
            this.drag = { splitId: divider.splitId, orientation: divider.orientation, raf: null };
            event.target.setPointerCapture(event.pointerId);
        },

        moveDrag(event) {
            if (!this.drag) {
                return;
            }

            if (this.drag.raf) {
                cancelAnimationFrame(this.drag.raf);
            }

            this.drag.raf = requestAnimationFrame(() => {
                const container = this.$el.getBoundingClientRect();
                const splitRect = findSplitRect(this.tree, this.drag.splitId);

                if (!splitRect) {
                    return;
                }

                const ratio = this.drag.orientation === 'horizontal'
                    ? (((event.clientX - container.left) / container.width) * 100 - splitRect.x) / splitRect.w
                    : (((event.clientY - container.top) / container.height) * 100 - splitRect.y) / splitRect.h;

                this.applyRatio(this.drag.splitId, ratio);
            });
        },

        endDrag() {
            if (this.drag?.raf) {
                cancelAnimationFrame(this.drag.raf);
            }

            this.drag = null;
        },

        applyRatio(splitId, ratio) {
            this.tree = withRatio(this.tree, splitId, ratio, this.minRatio);
            this.recompute();
            this.queueRatioSync(splitId);
        },

        queueRatioSync(splitId) {
            const find = (node) => node.type === 'split'
                ? (node.id === splitId ? node : (find(node.first) ?? find(node.second)))
                : null;
            const node = find(this.tree);

            if (node) {
                this._pendingRatios[splitId] = node.ratio;
            }

            clearTimeout(this._ratioSyncTimer);
            this._ratioSyncTimer = setTimeout(() => {
                const ratios = this._pendingRatios;
                this._pendingRatios = {};
                this.$wire.updateRatios(ratios);
            }, 400);
        },
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
