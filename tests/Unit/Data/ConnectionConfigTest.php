<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Data\ConnectionConfig;
use MWGuerra\WebTerminalStream\Enums\ConnectionType;

describe('ConnectionConfig', function () {
    describe('local connections', function () {
        it('can create a local connection config', function () {
            $config = ConnectionConfig::local();

            expect($config->type)->toBe(ConnectionType::Local)
                ->and($config->host)->toBeNull()
                ->and($config->timeout)->toBe(10);
        });

        it('can create a local connection with custom settings', function () {
            $config = ConnectionConfig::local(
                timeout: 30,
                workingDirectory: '/tmp',
                environment: ['PATH' => '/usr/bin'],
            );

            expect($config->timeout)->toBe(30)
                ->and($config->workingDirectory)->toBe('/tmp')
                ->and($config->environment)->toBe(['PATH' => '/usr/bin']);
        });
    });

    describe('SSH connections', function () {
        it('can create an SSH connection with password', function () {
            $config = ConnectionConfig::sshWithPassword(
                host: 'example.com',
                username: 'admin',
                password: 'secret',
            );

            expect($config->type)->toBe(ConnectionType::SSH)
                ->and($config->host)->toBe('example.com')
                ->and($config->username)->toBe('admin')
                ->and($config->password)->toBe('secret')
                ->and($config->usesPasswordAuthentication())->toBeTrue()
                ->and($config->usesKeyAuthentication())->toBeFalse();
        });

        it('can create an SSH connection with key', function () {
            $config = ConnectionConfig::sshWithKey(
                host: 'example.com',
                username: 'admin',
                privateKey: '/home/user/.ssh/id_rsa',
            );

            expect($config->type)->toBe(ConnectionType::SSH)
                ->and($config->privateKey)->toBe('/home/user/.ssh/id_rsa')
                ->and($config->usesKeyAuthentication())->toBeTrue()
                ->and($config->usesPasswordAuthentication())->toBeFalse();
        });

        it('can create an SSH connection with key and passphrase', function () {
            $config = ConnectionConfig::sshWithKey(
                host: 'example.com',
                username: 'admin',
                privateKey: '/home/user/.ssh/id_rsa',
                passphrase: 'keypass',
            );

            expect($config->passphrase)->toBe('keypass');
        });

        it('throws exception if host is missing for SSH', function () {
            new ConnectionConfig(
                type: ConnectionType::SSH,
                username: 'admin',
                password: 'secret',
            );
        })->throws(InvalidArgumentException::class, 'Host is required');

        it('throws exception if username is missing for SSH', function () {
            new ConnectionConfig(
                type: ConnectionType::SSH,
                host: 'example.com',
                password: 'secret',
            );
        })->throws(InvalidArgumentException::class, 'Username is required');

        it('throws exception if no credentials provided for SSH', function () {
            new ConnectionConfig(
                type: ConnectionType::SSH,
                host: 'example.com',
                username: 'admin',
            );
        })->throws(InvalidArgumentException::class, 'Either password or private key is required');
    });

    describe('effective port', function () {
        it('returns configured port when set', function () {
            $config = ConnectionConfig::sshWithPassword(
                host: 'example.com',
                username: 'admin',
                password: 'secret',
                port: 2222,
            );

            expect($config->effectivePort())->toBe(2222);
        });

        it('returns default port when not configured', function () {
            $config = ConnectionConfig::sshWithPassword(
                host: 'example.com',
                username: 'admin',
                password: 'secret',
            );

            expect($config->effectivePort())->toBe(22);
        });

        it('returns null for local connections', function () {
            $config = ConnectionConfig::local();

            expect($config->effectivePort())->toBeNull();
        });
    });

    describe('fromArray', function () {
        it('can create from array with string type', function () {
            $config = ConnectionConfig::fromArray([
                'type' => 'ssh',
                'host' => 'example.com',
                'username' => 'admin',
                'password' => 'secret',
            ]);

            expect($config->type)->toBe(ConnectionType::SSH);
        });

        it('can create from array with ConnectionType', function () {
            $config = ConnectionConfig::fromArray([
                'type' => ConnectionType::Local,
            ]);

            expect($config->type)->toBe(ConnectionType::Local);
        });

        it('uses default values for missing fields', function () {
            $config = ConnectionConfig::fromArray([
                'type' => 'local',
            ]);

            expect($config->timeout)->toBe(10)
                ->and($config->environment)->toBe([]);
        });
    });

    describe('toArray', function () {
        it('converts to array without sensitive data', function () {
            $config = ConnectionConfig::sshWithPassword(
                host: 'example.com',
                username: 'admin',
                password: 'secret',
                port: 22,
            );

            $array = $config->toArray();

            expect($array)->toHaveKeys(['type', 'host', 'username', 'port', 'timeout', 'uses_key_auth'])
                ->and($array)->not->toHaveKey('password')
                ->and($array['type'])->toBe('ssh')
                ->and($array['uses_key_auth'])->toBeFalse();
        });
    });

    describe('toTransportArray', function () {
        it('includes credentials and environment for the wire format', function () {
            $config = ConnectionConfig::sshWithKey(
                host: 'example.com',
                username: 'admin',
                privateKey: 'PEM-CONTENT',
                passphrase: 'keypass',
                port: 2222,
                workingDirectory: '/srv',
                environment: ['FOO' => 'bar'],
            );

            expect($config->toTransportArray())->toBe([
                'type' => 'ssh',
                'host' => 'example.com',
                'username' => 'admin',
                'password' => null,
                'private_key' => 'PEM-CONTENT',
                'passphrase' => 'keypass',
                'port' => 2222,
                'timeout' => 10,
                'working_directory' => '/srv',
                'environment' => ['FOO' => 'bar'],
            ]);
        });

        it('round-trips through fromArray', function () {
            $config = ConnectionConfig::sshWithPassword(
                host: 'example.com',
                username: 'admin',
                password: 'secret',
            );

            $rebuilt = ConnectionConfig::fromArray($config->toTransportArray());

            expect($rebuilt->toTransportArray())->toBe($config->toTransportArray());
        });

        it('includes the password for password-auth configs', function () {
            $config = ConnectionConfig::sshWithPassword(
                host: 'example.com',
                username: 'admin',
                password: 'secret',
            );

            expect($config->toTransportArray()['password'])->toBe('secret')
                ->and($config->toArray())->not->toHaveKey('password');
        });
    });

    describe('validation', function () {
        it('throws exception for invalid timeout', function () {
            new ConnectionConfig(
                type: ConnectionType::Local,
                timeout: 0,
            );
        })->throws(InvalidArgumentException::class, 'Timeout must be at least 1 second');

        it('throws exception for invalid port (too low)', function () {
            new ConnectionConfig(
                type: ConnectionType::Local,
                port: 0,
            );
        })->throws(InvalidArgumentException::class, 'Port must be between 1 and 65535');

        it('throws exception for invalid port (too high)', function () {
            new ConnectionConfig(
                type: ConnectionType::Local,
                port: 65536,
            );
        })->throws(InvalidArgumentException::class, 'Port must be between 1 and 65535');
    });
});
