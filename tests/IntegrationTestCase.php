<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Tests;

/**
 * Base case for integration tests that spawn the WebSocket server as a
 * separate `vendor/bin/testbench terminal-stream:serve` process.
 *
 * Cross-process contract: both the in-test app and the spawned server must
 * share the SAME encryption key (token minting vs token decryption) and the
 * SAME cache backend (Cache::put here, Cache::pull in the server). A fixed
 * APP_KEY plus the file cache store on the shared Testbench skeleton
 * (vendor/orchestra/testbench-core/laravel) satisfies both.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Fixed throwaway key — shared with the spawned server via env.
     */
    public const APP_KEY = 'base64:0RfPHocD87HvmRq/lXRorEjfoiPb0ZHYwJFCJzCCvg4=';

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('app.key', self::APP_KEY);
        config()->set('cache.default', 'file');
    }
}
