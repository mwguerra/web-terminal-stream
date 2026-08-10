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

        // ConnectionVault takes custody of every connection config through the
        // cache, so mounting a terminal touches the cache store. Laravel's
        // default store is `database`, which on Testbench means the in-memory
        // SQLite — where no `cache` table exists, so every mount died with
        // "no such table: cache". Unit tests need no cross-process visibility,
        // so `array` is both correct and free. IntegrationTestCase replaces
        // this method wholesale and keeps `file`, which its spawned server
        // needs in order to read what the test wrote.
        config()->set('cache.default', 'array');
    }
}
