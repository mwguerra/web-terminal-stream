<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Tests;

use Livewire\LivewireServiceProvider;
use MWGuerra\WebTerminalStream\WebTerminalStreamServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            WebTerminalStreamServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
