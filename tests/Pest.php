<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Tests\IntegrationTestCase;
use MWGuerra\WebTerminalStream\Tests\TestCase;
use MWGuerra\WebTerminalStream\WebSocket\TerminalPtyBridge;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('Feature', 'Unit', 'Integration/Ssh', 'Integration/LocalPty');

// The full-stack WebSocket tests spawn the server as a second process and
// need the fixed APP_KEY + file cache store contract.
uses(IntegrationTestCase::class)->in('Integration/WebSocket');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ...
}

/**
 * Connection details for the throwaway sshd container used by the
 * Integration suite (tests/docker/compose.yaml). Overridable via env
 * so the same tests run inside the php-tests container (WTS_SSH_HOST=sshd)
 * and in CI.
 *
 * @return array{host: string, port: int, username: string, password: string, key_path: string, key_pw_path: string, key_passphrase: string}
 */
function sshTestConfig(): array
{
    $keysDir = __DIR__.'/docker/keys';

    return [
        'host' => getenv('WTS_SSH_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('WTS_SSH_PORT') ?: 2299),
        'username' => 'wts',
        'password' => 'wts-secret',
        'key_path' => getenv('WTS_SSH_KEY_PATH') ?: $keysDir.'/wts_test_key',
        'key_pw_path' => getenv('WTS_SSH_KEY_PW_PATH') ?: $keysDir.'/wts_test_key_pw',
        'key_passphrase' => 'wts-passphrase',
    ];
}

/**
 * Guard for Integration tests: skip gracefully when the sshd container is
 * down on a dev machine, but hard-fail where the container is mandatory
 * (CI, or WTS_REQUIRE_DOCKER=1).
 */
function requireSshTarget(): void
{
    if (sshTargetReachable()) {
        return;
    }

    $config = sshTestConfig();
    $target = "{$config['host']}:{$config['port']}";

    if (getenv('CI') || getenv('WTS_REQUIRE_DOCKER')) {
        test()->fail("sshd test container unreachable at {$target} and CI/WTS_REQUIRE_DOCKER demands it. Run: composer test:integration:up");
    }

    test()->markTestSkipped("sshd test container not running at {$target} (composer test:integration:up)");
}

/**
 * Poll a PTY bridge's non-blocking read() until $needle appears in the
 * accumulated output or the timeout elapses. Returns everything read.
 */
function pollPtyOutput(
    TerminalPtyBridge $bridge,
    string $needle,
    float $timeoutSeconds = 10.0,
): string {
    $output = '';
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $output .= $bridge->read();

        if (str_contains($output, $needle)) {
            return $output;
        }

        usleep(50_000);
    }

    return $output;
}

/**
 * Quick TCP probe for the sshd test container. Integration tests skip
 * gracefully when it is down (unless CI / WTS_REQUIRE_DOCKER demand it).
 */
function sshTargetReachable(): bool
{
    $config = sshTestConfig();

    $socket = @fsockopen($config['host'], $config['port'], $errno, $errstr, 0.5);

    if ($socket === false) {
        return false;
    }

    fclose($socket);

    return true;
}
