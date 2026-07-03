{{--
    Toggle-driven terminal dashboard. Buttons open/close sources; the server
    re-arranges the open panes and returns the tree, which Alpine turns into
    pane geometry. The Alpine component is Alpine.data('wtsDashboard') in the
    bundle; the x-data attribute stays render-stable (see terminal-workspace).
--}}
<div class="wts-dashboard" data-wts-dashboard="{{ $componentId }}" x-data="wtsDashboard">
    {{-- Source toggle bar --}}
    <div class="wts-dashboard-bar" role="group" aria-label="{{ __('Terminals') }}">
        @foreach ($sources as $sourceId => $source)
            <button
                type="button"
                class="wts-dashboard-toggle"
                data-wts-source="{{ $sourceId }}"
                x-bind:class="{ 'wts-dashboard-toggle-open': isOpen('{{ $sourceId }}') }"
                x-bind:aria-pressed="isOpen('{{ $sourceId }}') ? 'true' : 'false'"
                x-on:click="toggle('{{ $sourceId }}')"
            >
                <span class="wts-dashboard-dot" aria-hidden="true"></span>
                {{ $source['label'] }}
            </button>
        @endforeach
    </div>

    {{-- Pane area: flat keyed panes, absolutely positioned from the tree.
         Livewire skips matched keyed children, so toggling one source never
         re-renders the others. --}}
    <div
        class="wts-workspace wts-dashboard-panes relative overflow-hidden"
        style="height: {{ $height }}; min-height: 200px;@foreach ($themeCss as $property => $value) {{ $property }}: {{ $value }};@endforeach"
    >
        @if ($panes === [])
            <div class="wts-dashboard-empty absolute inset-0 flex items-center justify-center">
                <span class="text-sm text-slate-400">{{ __('Toggle a terminal above to open it.') }}</span>
            </div>
        @endif

        @foreach ($panes as $paneId => $pane)
            <div
                wire:key="{{ $componentId }}-pane-{{ $paneId }}"
                data-wts-pane="{{ $paneId }}"
                class="wts-pane absolute"
                x-bind:style="paneStyle('{{ $paneId }}')"
            >
                @livewire('web-terminal-stream', $pane, key($componentId.'-lw-'.$paneId))
            </div>
        @endforeach

        {{-- Visual divider lines (non-interactive; the layout is automatic). --}}
        <div wire:ignore class="wts-divider-layer">
            <template x-for="divider in dividers" :key="divider.splitId">
                <div
                    class="wts-divider wts-divider-static absolute"
                    x-bind:class="divider.orientation === 'horizontal' ? 'wts-divider-vertical' : 'wts-divider-horizontal'"
                    x-bind:style="divider.orientation === 'horizontal'
                        ? `left:${divider.x}%;top:${divider.y}%;height:${divider.h}%;`
                        : `left:${divider.x}%;top:${divider.y}%;width:${divider.w}%;`"
                ></div>
            </template>
        </div>
    </div>
</div>
