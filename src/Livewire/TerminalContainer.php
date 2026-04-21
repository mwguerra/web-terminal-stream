<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminal\Livewire;

use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class TerminalContainer extends Component
{
    #[Locked]
    public array $classicParams = [];

    #[Locked]
    public array $streamParams = [];

    #[Locked]
    public string $defaultMode = 'classic';

    /**
     * Unique suffix so multiple TerminalContainer instances on one page
     * don't collide on the inner @livewire wire:key values.
     */
    #[Locked]
    public string $instanceId = '';

    public string $height = '400px';
    public string $title = 'Terminal';
    public bool $showWindowControls = true;
    public string $chrome = 'full';

    public function mount(
        array $classicParams = [],
        array $streamParams = [],
        string $defaultMode = 'classic',
        string $height = '400px',
        string $title = 'Terminal',
        bool $showWindowControls = true,
        string $chrome = 'full',
    ): void {
        $this->classicParams = $classicParams;
        $this->streamParams = $streamParams;
        $this->defaultMode = $defaultMode;
        $this->height = $height;
        $this->title = $title;
        $this->chrome = in_array($chrome, ['full', 'minimal', 'none'], true) ? $chrome : 'full';
        $this->showWindowControls = ($this->chrome === 'full') ? $showWindowControls : false;
        $this->instanceId = Str::random(8);
    }

    public function render()
    {
        return view('web-terminal::terminal-container');
    }
}
