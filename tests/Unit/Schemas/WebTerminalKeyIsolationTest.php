<?php

declare(strict_types=1);

use MWGuerra\WebTerminal\Schemas\Components\WebTerminal;

/*
 * Two WebTerminal::make() calls on the same Filament page must not produce
 * the same Livewire wire:key — otherwise morphing conflates the two
 * instances and the second terminal never mounts correctly.
 *
 * Regression test for multi-terminal isolation.
 */

beforeEach(function () {
    if (! class_exists(\Filament\Schemas\Components\Livewire::class)) {
        $this->markTestSkipped('Filament is not installed. These tests require filament/filament package.');
    }
});

it('generates a unique key for each WebTerminal::make() invocation', function () {
    $a = WebTerminal::make();
    $b = WebTerminal::make();

    expect($a->getKey())->not->toBe($b->getKey());
    expect($a->getKey())->toStartWith('web-terminal-');
    expect($b->getKey())->toStartWith('web-terminal-');
});

it('honors an explicit ->key() call over the auto-generated default', function () {
    $component = WebTerminal::make()->key('my-custom-terminal');

    expect($component->getKey())->toBe('my-custom-terminal');
});

it('produces a stable key across repeated accessors on the same instance', function () {
    $component = WebTerminal::make();
    $first = $component->getKey();
    $second = $component->getKey();

    expect($first)->toBe($second);
});
