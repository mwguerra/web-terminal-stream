<div
    @class([
        'stream-web-terminal relative font-mono text-[13px] leading-tight text-zinc-800 dark:text-zinc-200 overflow-hidden flex flex-col text-left',
        'rounded-xl shadow-2xl ring-1 ring-slate-200 dark:ring-white/5' => ! ($squareCorners ?? false),
    ])
    style="height: {{ $height }}; min-height: 200px; background: {{ $theme['background'] ?? '#1a1a2e' }};"
    data-connection-behavior="{{ $connectionBehavior }}"
    {{-- Byte-injection channel for the workspace (literal prefix passthrough). --}}
    x-on:wts-pane-send="ws && ws.readyState === WebSocket.OPEN && ws.send($event.detail.data)"
    x-data="{
        isConnected: $wire.entangle('isConnected'),
        // 'manual' | 'auto' | 'always' — see ConnectionBehavior.
        behavior: '{{ $connectionBehavior }}',
        // Client-side connection state machine:
        // idle → connecting → connected → disconnected (→ connecting → …)
        state: 'idle',
        showInfoPanel: false,
        copyFeedback: false,
        // Key of a script awaiting user confirmation before running.
        // Empty string = nothing pending. This gate is client-only because
        // Stream already grants raw PTY access — this is UX, not security.
        pendingScriptKey: '',
        ws: null,
        terminal: null,
        fitAddon: null,
        dataDisposable: null,
        resizeDisposable: null,
        // Stable handler references so add/removeEventListener match.
        _teardownHandler: null,

        async initStream() {
            try {
                if (typeof StreamWeb === 'undefined') {
                    console.error('[StreamTerminal] StreamWeb not loaded. Ensure stream-terminal.js is included.');
                    return;
                }

                await StreamWeb.init();

                // Clear any residual DOM from a previous mount. Livewire wire:ignore
                // preserves this container across morphs; if we don`t reset it, an
                // orphaned canvas/textarea from a previous session can stay visible
                // until the new Terminal paints over it — the stale-buffer bug.
                if (this.$refs.streamContainer) {
                    this.$refs.streamContainer.replaceChildren();
                }

                this.terminal = new StreamWeb.Terminal({
                    cursorBlink: true,
                    fontSize: {{ $fontSize ?? 13 }},
                    fontFamily: @js($fontFamily ?? 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace'),
                    theme: {{ json_encode($theme) }},
                });

                this.fitAddon = new StreamWeb.FitAddon();
                this.terminal.loadAddon(this.fitAddon);
                this.terminal.open(this.$refs.streamContainer);
                this.fitAddon.fit();
                this.fitAddon.observeResize();

                // Hide the blinking caret on ghostty-web's hidden textarea
                const ta = this.$refs.streamContainer.querySelector('textarea');
                if (ta) {
                    ta.style.setProperty('caret-color', 'transparent', 'important');
                }

                // ghostty-web does not emit Escape through onData, so we
                // intercept it on the container (where keys actually fire)
                // and send the ESC byte directly to the WebSocket PTY
                this.$el.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        e.stopPropagation();
                        e.preventDefault();
                        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                            this.ws.send('\x1b');
                        }
                    }
                }, true);
            } catch (e) {
                console.error('[StreamTerminal] Failed to load stream-web module:', e);
            }
        },

        // The centered Connect/Reconnect affordance over the canvas. Manual
        // panes show it whenever no session is live; Auto only
        // offers it after a disconnect (it auto-connected to begin with);
        // Always never shows any connection UI.
        get overlayVisible() {
            if (this.behavior === 'manual') {
                return this.state !== 'connected';
            }

            if (this.behavior === 'auto') {
                return this.state === 'disconnected';
            }

            return false;
        },

        // User-initiated connect (overlay button / header toggle). Boots the
        // ghostty terminal lazily — a Manual pane that was never opened costs
        // no canvas, no WebSocket, and no PTY.
        async startSession() {
            if (this.state === 'connecting' || this.state === 'connected') {
                return;
            }

            if (!this.terminal) {
                await this.initStream();
            }

            await this.connect();
        },

        async connect() {
            this.state = 'connecting';

            try {
                // Defensive: if a stale WebSocket survived a previous teardown
                // (old handlers weren't bound correctly, etc.), close it before
                // opening a new one so we don't get two parallel streams writing
                // to the same Terminal.
                if (this.ws) {
                    try { this.ws.close(); } catch (e) { /* ignore */ }
                    this.ws = null;
                }
                if (this.dataDisposable) { this.dataDisposable.dispose(); this.dataDisposable = null; }
                if (this.resizeDisposable) { this.resizeDisposable.dispose(); this.resizeDisposable = null; }

                const result = await $wire.getWebSocketUrl();

                // The user hit disconnect while the token round trip was in
                // flight — don't open a socket nobody wants anymore.
                if (this.state !== 'connecting') {
                    return;
                }

                if (result.error) {
                    console.error('[StreamTerminal] Auth error:', result.error);
                    this.state = 'disconnected';
                    return;
                }

                this.ws = new WebSocket(result.url);

                this.ws.onopen = () => {
                    this.state = 'connected';
                    $wire.connect();
                    if (this.terminal) {
                        this.ws.send(JSON.stringify({
                            type: 'resize',
                            cols: this.terminal.cols,
                            rows: this.terminal.rows,
                        }));
                    }
                };

                this.ws.onmessage = (event) => {
                    if (this.terminal) {
                        this.terminal.write(event.data);
                    }
                };

                this.ws.onerror = (error) => {
                    console.error('[StreamTerminal] WebSocket error:', error);
                };

                this.ws.onclose = () => {
                    this.state = 'disconnected';
                    $wire.disconnect();
                    this.ws = null;
                };

                if (this.terminal) {
                    if (this.dataDisposable) this.dataDisposable.dispose();
                    if (this.resizeDisposable) this.resizeDisposable.dispose();

                    this.dataDisposable = this.terminal.onData((data) => {
                        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                            this.ws.send(data);
                        }
                    });

                    this.resizeDisposable = this.terminal.onResize(({ cols, rows }) => {
                        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                            this.ws.send(JSON.stringify({ type: 'resize', cols, rows }));
                        }
                    });
                }
            } catch (e) {
                console.error('[StreamTerminal] Connect error:', e);
                this.state = 'disconnected';
            }
        },

        disconnect() {
            if (this.ws) {
                // Client-initiated clean close (1000): the server terminates
                // the PTY on the socket close event, per the registry rules.
                try { this.ws.close(1000, 'client disconnect'); } catch (e) { /* ignore */ }
                this.ws = null;
            }
            this.state = 'disconnected';
            $wire.disconnect();
        },

        handleToggle() {
            if (this.state === 'connected' || this.state === 'connecting') {
                this.disconnect();
            } else {
                this.startSession();
            }
        },

        async copyAllOutput() {
            if (!this.terminal) return;

            try {
                const buffer = this.terminal.buffer.active;
                let text = '';
                for (let i = 0; i < buffer.length; i++) {
                    const line = buffer.getLine(i);
                    if (line) {
                        text += line.translateToString(true) + '\n';
                    }
                }
                await navigator.clipboard.writeText(text.trimEnd());
                this.copyFeedback = true;
                setTimeout(() => { this.copyFeedback = false; }, 2000);
            } catch (e) {
                console.error('[StreamTerminal] Copy failed:', e);
            }
        },

        async runScript(key, requiresConfirmation = false) {
            // Confirmation gate. First click arms; a second call with pendingScriptKey already
            // set to this key passes through (triggered by the Confirm button).
            if (requiresConfirmation && this.pendingScriptKey !== key) {
                this.pendingScriptKey = key;

                return;
            }

            this.pendingScriptKey = '';

            const commands = await $wire.getScriptsForExecution(key);
            if (!commands || !commands.length) return;

            for (const cmd of commands) {
                if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                    this.ws.send(cmd + '\n');
                }
            }
        },

        cancelPendingScript() {
            this.pendingScriptKey = '';
        },

        destroy() {
            // Remove all teardown listeners — the bound handler is stored once
            // so these removeEventListener calls actually match.
            if (this._teardownHandler) {
                window.removeEventListener('beforeunload', this._teardownHandler);
                window.removeEventListener('pagehide', this._teardownHandler);
                document.removeEventListener('livewire:navigating', this._teardownHandler);
                this._teardownHandler = null;
            }

            if (this.dataDisposable) { this.dataDisposable.dispose(); this.dataDisposable = null; }
            if (this.resizeDisposable) { this.resizeDisposable.dispose(); this.resizeDisposable = null; }

            if (this.ws) {
                try { this.ws.close(); } catch (e) { /* ignore */ }
                this.ws = null;
            }

            if (this.terminal) {
                try { this.terminal.dispose(); } catch (e) { /* ignore */ }
                this.terminal = null;
                this.fitAddon = null;
            }
        },

        init() {
            // Bind teardown once so add/remove match. We listen to three events:
            //   - beforeunload: full browser navigation (tab close, URL bar nav)
            //   - pagehide: bfcache + reliable mobile-safari teardown signal
            //   - livewire:navigating: Filament/Livewire SPA navigation — THIS is
            //     the one that the old code missed, causing WebSocket + PTY +
            //     ghostty Terminal scrollback to leak between page visits.
            this._teardownHandler = this.destroy.bind(this);
            window.addEventListener('beforeunload', this._teardownHandler);
            window.addEventListener('pagehide', this._teardownHandler);
            document.addEventListener('livewire:navigating', this._teardownHandler);

            const observer = new IntersectionObserver((entries) => {
                if (entries[0].isIntersecting && !this.terminal) {
                    // Manual panes wait for the user's Connect click — no
                    // canvas, no WebSocket, no PTY until then. The observer
                    // stays alive to handle refits after they do connect.
                    if (this.behavior === 'manual') {
                        return;
                    }

                    this.initStream();
                    this.$nextTick(() => this.connect());
                    observer.disconnect();
                } else if (entries[0].isIntersecting && this.fitAddon) {
                    this.fitAddon.fit();
                }
            });
            observer.observe(this.$el);
        },
    }"
>
    {{-- Header bar.

         When chrome === 'none' (frameless), the same action group renders as an
         absolutely-positioned floating overlay on the terminal body. Same Alpine
         state, same event wiring — only the outer container's layout changes,
         so every existing setting (window-control dots, scripts dropdown,
         info panel) continues to work unchanged. --}}
    <div @class([
        'flex items-center px-4 py-3 bg-slate-200/80 dark:bg-black/30 border-b border-slate-300 dark:border-white/5 shrink-0' => $chrome !== 'none',
        'flex items-center' => $chrome === 'none',
    ])
    @if($chrome === 'none') style="position:absolute; top:0.5rem; right:0.5rem; z-index:20;" @endif
    >
        @if($chrome === 'full')
        <div class="flex gap-2 shrink-0">
            <span class="w-3 h-3 rounded-full bg-[#ff5f56] hover:opacity-80 transition-opacity"></span>
            <span class="w-3 h-3 rounded-full bg-[#ffbd2e] hover:opacity-80 transition-opacity"></span>
            <span class="w-3 h-3 rounded-full bg-[#27c93f] hover:opacity-80 transition-opacity"></span>
        </div>
        @endif
        @if($chrome !== 'none')
        <div class="flex-1 min-w-0 ml-3 text-xs font-medium text-slate-500 dark:text-white/50 tracking-wide truncate">{{ $title }}</div>
        @endif

        {{-- Header Actions --}}
        <div class="flex items-center gap-2 shrink-0">
            {{-- Scripts Dropdown --}}
            @if(!empty($scripts))
            <div class="relative" x-data="{ showScriptsDropdown: false }">
                <button
                    type="button"
                    @click="showScriptsDropdown = !showScriptsDropdown"
                    @click.away="showScriptsDropdown = false"
                    class="flex items-center justify-center w-7 h-7 rounded-full transition-all duration-200"
                    :class="{
                        'bg-purple-500/20 text-purple-600 ring-1 ring-purple-500/40 dark:bg-purple-500/30 dark:text-purple-400 dark:ring-purple-500/50': showScriptsDropdown,
                        'bg-slate-300/50 text-slate-500 hover:bg-slate-300 hover:text-slate-700 dark:bg-white/5 dark:text-white/40 dark:hover:bg-white/10 dark:hover:text-white/60': !showScriptsDropdown
                    }"
                    :disabled="!isConnected"
                    title="Run scripts"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                        <path fill-rule="evenodd" d="M6.28 5.22a.75.75 0 0 1 0 1.06L2.56 10l3.72 3.72a.75.75 0 0 1-1.06 1.06L.97 10.53a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Zm7.44 0a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L17.44 10l-3.72-3.72a.75.75 0 0 1 0-1.06ZM11.377 2.011a.75.75 0 0 1 .612.867l-2.5 14.5a.75.75 0 0 1-1.478-.255l2.5-14.5a.75.75 0 0 1 .866-.612Z" clip-rule="evenodd" />
                    </svg>
                </button>

                <div
                    x-show="showScriptsDropdown"
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute right-0 mt-2 min-w-64 rounded-lg bg-white dark:bg-gray-900 shadow-lg ring-1 ring-black/5 dark:ring-white/10 z-50 overflow-hidden"
                    @click.away="showScriptsDropdown = false"
                >
                    <div class="px-3 py-2 border-b border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-gray-800/50">
                        <p class="text-xs font-medium text-slate-500 dark:text-gray-400 uppercase tracking-wide">Available Scripts</p>
                    </div>
                    <div class="py-1">
                        @foreach($scripts as $script)
                            @php $needsConfirm = ! empty($script['confirmBeforeRun']); @endphp
                            <template x-if="pendingScriptKey === '{{ $script['key'] ?? '' }}'">
                                {{-- Confirmation prompt rendered when this script is armed. --}}
                                <div class="px-3 py-3 bg-amber-50 dark:bg-amber-900/20 border-b border-amber-200 dark:border-amber-700/30">
                                    <p class="text-sm font-medium text-amber-800 dark:text-amber-300 mb-1">Run "{{ $script['label'] ?? $script['key'] ?? 'Script' }}"?</p>
                                    @if(!empty($script['description']))
                                    <p class="text-xs text-amber-700 dark:text-amber-400 mb-2">{{ $script['description'] }}</p>
                                    @endif
                                    <p class="text-xs text-amber-600 dark:text-amber-400 mb-3">This script requires confirmation.</p>
                                    <div class="flex items-center gap-2">
                                        <button
                                            type="button"
                                            @click="runScript('{{ $script['key'] ?? '' }}', true); showScriptsDropdown = false"
                                            class="px-3 py-1.5 text-xs font-medium rounded-md bg-amber-600 text-white hover:bg-amber-700 transition-colors"
                                        >Confirm</button>
                                        <button
                                            type="button"
                                            @click="cancelPendingScript()"
                                            class="px-3 py-1.5 text-xs font-medium rounded-md bg-slate-200 text-slate-700 hover:bg-slate-300 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20 transition-colors"
                                        >Cancel</button>
                                    </div>
                                </div>
                            </template>
                            <template x-if="pendingScriptKey !== '{{ $script['key'] ?? '' }}'">
                                <button
                                    type="button"
                                    @click="runScript('{{ $script['key'] ?? '' }}', {{ $needsConfirm ? 'true' : 'false' }}); @if(!$needsConfirm) showScriptsDropdown = false @endif"
                                    class="w-full px-3 py-2.5 text-left hover:bg-slate-100 dark:hover:bg-white/10 transition-colors"
                                >
                                    <div class="flex items-center gap-3">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5 text-purple-500 dark:text-purple-400 shrink-0">
                                            <path fill-rule="evenodd" d="M6.28 5.22a.75.75 0 0 1 0 1.06L2.56 10l3.72 3.72a.75.75 0 0 1-1.06 1.06L.97 10.53a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
                                        </svg>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-slate-800 dark:text-gray-100 truncate">{{ $script['label'] ?? $script['key'] ?? 'Script' }}</p>
                                            @if(!empty($script['description']))
                                            <p class="text-xs text-slate-500 dark:text-gray-400 truncate">{{ $script['description'] }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </button>
                            </template>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            {{-- Connect / Disconnect Toggle.
                 Hidden for Always (today's chromeless auto-connect look).
                 Placement follows TerminalChrome automatically: header action
                 when a header exists, floating overlay button when frameless. --}}
            @if($connectionBehavior !== 'always')
            <button
                type="button"
                @click="handleToggle()"
                class="flex items-center justify-center w-7 h-7 rounded-full transition-all duration-200"
                :class="{
                    'bg-emerald-500/20 text-emerald-600 ring-1 ring-emerald-500/40 dark:bg-emerald-500/30 dark:text-emerald-400 dark:ring-emerald-500/50': state === 'connected',
                    'bg-slate-300/50 text-slate-500 hover:bg-slate-300 hover:text-slate-700 dark:bg-white/5 dark:text-white/40 dark:hover:bg-white/10 dark:hover:text-white/60': state !== 'connected'
                }"
                :title="state === 'connected' ? '{{ __('web-terminal-stream::terminal.stream.disconnect') }}' : '{{ __('web-terminal-stream::terminal.stream.connect') }}'"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                    <path fill-rule="evenodd" d="M10 2a.75.75 0 0 1 .75.75v7.5a.75.75 0 0 1-1.5 0v-7.5A.75.75 0 0 1 10 2ZM5.404 4.343a.75.75 0 0 1 0 1.06 6.5 6.5 0 1 0 9.192 0 .75.75 0 1 1 1.06-1.06 8 8 0 1 1-11.313 0 .75.75 0 0 1 1.061 0Z" clip-rule="evenodd" />
                </svg>
            </button>
            @endif

            {{-- Copy All Button --}}
            <button
                type="button"
                @click="copyAllOutput()"
                class="flex items-center justify-center w-7 h-7 rounded-full transition-all duration-200"
                :class="{
                    'bg-emerald-500/20 text-emerald-600 ring-1 ring-emerald-500/40 dark:bg-emerald-500/30 dark:text-emerald-400': copyFeedback,
                    'bg-slate-300/50 text-slate-500 hover:bg-slate-300 hover:text-slate-700 dark:bg-white/5 dark:text-white/40 dark:hover:bg-white/10 dark:hover:text-white/60': !copyFeedback
                }"
                :title="copyFeedback ? 'Copied!' : 'Copy all output'"
                :disabled="!isConnected"
            >
                <svg x-show="!copyFeedback" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                    <path d="M7 3.5A1.5 1.5 0 0 1 8.5 2h3A1.5 1.5 0 0 1 13 3.5H7ZM5.5 5A1.5 1.5 0 0 0 4 6.5v10A1.5 1.5 0 0 0 5.5 18h9a1.5 1.5 0 0 0 1.5-1.5v-10A1.5 1.5 0 0 0 14.5 5h-9Z"/>
                </svg>
                <svg x-show="copyFeedback" x-cloak xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                </svg>
            </button>

            {{-- Info Toggle Button --}}
            <button
                type="button"
                @click="showInfoPanel = !showInfoPanel"
                class="flex items-center justify-center w-7 h-7 rounded-full transition-all duration-200"
                :class="{
                    'bg-blue-500/20 text-blue-600 ring-1 ring-blue-500/40 dark:bg-blue-500/30 dark:text-blue-400 dark:ring-blue-500/50': showInfoPanel,
                    'bg-slate-300/50 text-slate-500 hover:bg-slate-300 hover:text-slate-700 dark:bg-white/5 dark:text-white/40 dark:hover:bg-white/10 dark:hover:text-white/60': !showInfoPanel
                }"
                title="Connection info"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd" />
                </svg>
            </button>

        </div>
    </div>

    {{-- Info Panel Overlay --}}
    <div
        x-show="showInfoPanel"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-1"
        class="absolute inset-x-0 top-[49px] z-40 bg-white/95 dark:bg-gray-900/95 backdrop-blur-sm border-b border-slate-200 dark:border-white/10 p-4 text-xs"
    >
        <div class="space-y-1.5 text-slate-600 dark:text-gray-300">
            <div class="flex items-center gap-2">
                <span class="font-medium text-slate-500 dark:text-gray-400 w-24">Status</span>
                <span :class="isConnected ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400 dark:text-gray-500'">
                    <span x-text="isConnected ? 'Connected' : 'Disconnected'"></span>
                </span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-medium text-slate-500 dark:text-gray-400 w-24">Mode</span>
                <span>Stream WebSocket PTY</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-medium text-slate-500 dark:text-gray-400 w-24">Component</span>
                <span class="font-mono text-[10px] text-slate-400 dark:text-gray-500">{{ $componentId }}</span>
            </div>
        </div>
    </div>

    {{-- Stream Terminal Body --}}
    <div class="relative flex-1 overflow-hidden">
        <div
            x-ref="streamContainer"
            wire:ignore
            class="absolute inset-0 overflow-hidden p-2"
            style="background: {{ $theme['background'] ?? '#1a1a2e' }};"
        ></div>

        {{-- Connect Affordance Overlay.

             Sits over the canvas (outside the wire:ignore container so
             initStream()'s replaceChildren() can never wipe it). Manual panes
             show it until a session is live; Auto shows it after a
             disconnect; Always never renders it at all. --}}
        @if($connectionBehavior !== 'always')
        <div
            x-show="overlayVisible"
            x-cloak
            data-connect-overlay
            class="absolute inset-0 z-10 flex items-center justify-center"
            style="background: {{ $theme['background'] ?? '#1a1a2e' }}; color: {{ $theme['foreground'] ?? '#e2e8f0' }};"
        >
            <button
                type="button"
                x-show="state !== 'connecting'"
                @click="startSession()"
                class="group flex flex-col items-center gap-3 focus:outline-none"
            >
                <span class="flex items-center justify-center w-14 h-14 rounded-full border border-current opacity-60 group-hover:opacity-100 group-focus-visible:opacity-100 group-focus-visible:ring-2 group-focus-visible:ring-current transition-opacity">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-6 h-6">
                        <path fill-rule="evenodd" d="M10 2a.75.75 0 0 1 .75.75v7.5a.75.75 0 0 1-1.5 0v-7.5A.75.75 0 0 1 10 2ZM5.404 4.343a.75.75 0 0 1 0 1.06 6.5 6.5 0 1 0 9.192 0 .75.75 0 1 1 1.06-1.06 8 8 0 1 1-11.313 0 .75.75 0 0 1 1.061 0Z" clip-rule="evenodd" />
                    </svg>
                </span>
                <span
                    class="text-xs font-medium tracking-widest uppercase opacity-60 group-hover:opacity-100 transition-opacity"
                    x-text="state === 'disconnected' ? '{{ __('web-terminal-stream::terminal.stream.reconnect') }}' : '{{ __('web-terminal-stream::terminal.stream.connect') }}'"
                ></span>
            </button>

            <div
                x-show="state === 'connecting'"
                x-cloak
                class="flex items-center gap-2 text-xs font-medium tracking-widest uppercase opacity-60"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 animate-spin">
                    <path stroke-linecap="round" d="M12 3a9 9 0 1 0 9 9" />
                </svg>
                <span>{{ __('web-terminal-stream::terminal.stream.connecting') }}</span>
            </div>
        </div>
        @endif
    </div>
</div>
