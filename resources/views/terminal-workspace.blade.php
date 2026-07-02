<div
    class="wts-workspace relative overflow-hidden"
    style="height: {{ $height }}; min-height: 200px;"
    data-wts-workspace="{{ $componentId }}"
    x-bind:class="{ 'wts-prefix-armed': prefixArmed }"
    x-data="{
        tree: @js($tree),
        keymap: @js($keymap),
        shortcutsEnabled: @js($shortcutsEnabled),
        minRatio: {{ $minPaneRatio }},
        resizeStep: {{ $resizeStep }},
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
            const layout = StreamWeb.workspace.computeRects(this.tree);
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

            const key = StreamWeb.workspace.eventToKeyString(e);

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
            const action = StreamWeb.workspace.matchBinding(this.keymap.bindings, key);

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

            const neighbor = StreamWeb.workspace.findNeighbor(this.rects, this.focusedPaneId, direction);

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
            const split = StreamWeb.workspace.findResizeSplit(this.tree, this.focusedPaneId, orientation);

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
                const splitRect = StreamWeb.workspace.findSplitRect(this.tree, this.drag.splitId);

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
            this.tree = StreamWeb.workspace.withRatio(this.tree, splitId, ratio, this.minRatio);
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
    }"
>
    @if ($tree === null)
        <div class="wts-workspace-empty absolute inset-0 flex items-center justify-center">
            <button
                type="button"
                class="wts-workspace-spawn px-4 py-2 rounded-lg text-sm font-medium bg-slate-800 text-slate-100 hover:bg-slate-700 transition-colors"
                x-on:click="spawn()"
            >
                {{ __('Open terminal') }}
            </button>
        </div>
    @endif

    {{-- Flat keyed pane list: splits append a sibling, closes remove one.
         Livewire skips keyed matched children on re-render, so a live
         pane's canvas and WebSocket never re-render when a sibling
         changes. Geometry is Alpine-bound, never server-rendered. --}}
    @foreach ($panes as $paneId => $pane)
        <div
            wire:key="{{ $componentId }}-pane-{{ $paneId }}"
            data-wts-pane="{{ $paneId }}"
            class="wts-pane absolute"
            tabindex="-1"
            x-bind:style="paneStyle('{{ $paneId }}')"
            x-bind:class="{ 'wts-pane-zoomed': zoomedPaneId === '{{ $paneId }}' }"
        >
            @livewire('web-terminal-stream', $pane, key($componentId.'-lw-'.$paneId))
        </div>
    @endforeach

    {{-- Divider strips live outside Livewire's morph (wire:ignore) —
         Alpine owns this DOM entirely. --}}
    <div wire:ignore class="wts-divider-layer">
        <template x-for="divider in dividers" :key="divider.splitId">
            <div
                class="wts-divider absolute"
                x-bind:class="divider.orientation === 'horizontal' ? 'wts-divider-vertical' : 'wts-divider-horizontal'"
                x-bind:style="divider.orientation === 'horizontal'
                    ? `left:${divider.x}%;top:${divider.y}%;height:${divider.h}%;`
                    : `left:${divider.x}%;top:${divider.y}%;width:${divider.w}%;`"
                x-show="zoomedPaneId === null"
                x-on:pointerdown="startDrag($event, divider)"
                x-on:pointermove="moveDrag($event)"
                x-on:pointerup="endDrag()"
                x-on:pointercancel="endDrag()"
            ></div>
        </template>
    </div>

    {{-- Armed-prefix indicator --}}
    <div
        class="wts-prefix-badge absolute top-2 right-2 z-40 px-2 py-1 rounded text-xs font-mono bg-sky-500/90 text-white shadow"
        x-show="prefixArmed"
        x-transition.opacity.duration.150ms
        x-cloak
    >
        <span x-text="(keymap.prefix ?? '').replace('ctrl+', '⌃').toUpperCase()"></span>
    </div>
</div>
