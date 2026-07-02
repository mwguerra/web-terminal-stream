{{--
    The Alpine component lives in resources/js/terminal-workspace.js and
    is registered as Alpine.data('wtsWorkspace') by the StreamWeb bundle.

    IMPORTANT: every x-* attribute string on morphed elements here must be
    render-STABLE. A server re-render that changes an x-data/x-bind
    attribute string makes Alpine destroy and re-initialize the component
    (losing zoom/focus/armed state and orphaning bindings). Initial state
    is therefore read from $wire inside init(), never inlined via @js().
--}}
<div
    class="wts-workspace relative overflow-hidden"
    style="height: {{ $height }}; min-height: 200px;"
    data-wts-workspace="{{ $componentId }}"
    x-data="wtsWorkspace"
    x-bind:class="{ 'wts-prefix-armed': prefixArmed }"
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
