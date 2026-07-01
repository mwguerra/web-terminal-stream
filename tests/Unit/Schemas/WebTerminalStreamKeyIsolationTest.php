<?php

declare(strict_types=1);

use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use MWGuerra\WebTerminalStream\Schemas\Components\WebTerminalStream;

/*
 * Two WebTerminalStream::make() calls on the same Filament page must not produce
 * the same Livewire wire:key — otherwise morphing conflates the two
 * instances and the second terminal never mounts correctly.
 *
 * Regression test for multi-terminal isolation.
 */

beforeEach(function () {
    if (! class_exists(Livewire::class)) {
        $this->markTestSkipped('Filament is not installed. These tests require filament/filament package.');
    }
});

it('generates a unique key for each WebTerminalStream::make() invocation', function () {
    // getKey() resolves against the owning schema, so attach one as a
    // real Filament page would.
    $a = WebTerminalStream::make()->container(Schema::make());
    $b = WebTerminalStream::make()->container(Schema::make());

    expect($a->getKey())->not->toBe($b->getKey());
    expect($a->getKey())->toStartWith('web-terminal-stream-');
    expect($b->getKey())->toStartWith('web-terminal-stream-');
});

it('honors an explicit ->key() call over the auto-generated default', function () {
    $component = WebTerminalStream::make()->key('my-custom-terminal')->container(Schema::make());

    expect($component->getKey())->toBe('my-custom-terminal');
});

it('produces a stable key across repeated accessors on the same instance', function () {
    $component = WebTerminalStream::make()->container(Schema::make());
    $first = $component->getKey();
    $second = $component->getKey();

    expect($first)->toBe($second);
});
