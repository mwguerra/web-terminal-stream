<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Tests\TestCase;

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

uses(TestCase::class)->in('Feature', 'Unit', 'Integration');

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
