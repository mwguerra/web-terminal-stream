<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Data\ConnectionConfig;
use MWGuerra\WebTerminalStream\Enums\TerminalChrome;
use MWGuerra\WebTerminalStream\Livewire\TerminalBuilder;

describe('TerminalBuilder', function () {
    describe('connection configuration', function () {
        it('defaults to a local connection config', function () {
            $builder = new TerminalBuilder;

            $params = $builder->getParameters();

            expect($params['connectionConfig'])->toBe(['type' => 'local']);
        });

        it('sets local connection', function () {
            $builder = new TerminalBuilder;
            $builder->local(workingDirectory: '/srv', environment: ['FOO' => 'bar']);

            $config = $builder->getParameters()['connectionConfig'];

            expect($config['type'])->toBe('local')
                ->and($config['working_directory'])->toBe('/srv')
                ->and($config['environment'])->toBe(['FOO' => 'bar']);
        });

        it('sets SSH connection with password', function () {
            $builder = new TerminalBuilder;
            $builder->ssh(host: 'example.com', username: 'deploy', password: 'secret', port: 2222);

            $config = $builder->getParameters()['connectionConfig'];

            expect($config['type'])->toBe('ssh')
                ->and($config['host'])->toBe('example.com')
                ->and($config['username'])->toBe('deploy')
                ->and($config['password'])->toBe('secret')
                ->and($config['port'])->toBe(2222);
        });

        it('sets SSH connection with key', function () {
            $builder = new TerminalBuilder;
            $builder->ssh(host: 'example.com', username: 'deploy', privateKey: 'PRIVATE-KEY', passphrase: 'phrase');

            $config = $builder->getParameters()['connectionConfig'];

            expect($config['type'])->toBe('ssh')
                ->and($config['private_key'])->toBe('PRIVATE-KEY')
                ->and($config['passphrase'])->toBe('phrase');
        });

        it('accepts a ConnectionConfig value object', function () {
            $builder = new TerminalBuilder;
            $builder->connection(ConnectionConfig::local(workingDirectory: '/tmp'));

            $config = $builder->getParameters()['connectionConfig'];

            expect($config['type'])->toBe('local')
                ->and($config['working_directory'])->toBe('/tmp');
        });

        it('keeps SSH credentials from a ConnectionConfig value object', function () {
            // Regression: the old withConfig() path went through toArray(),
            // which strips credentials — SSH could never authenticate.
            $builder = new TerminalBuilder;
            $builder->connection(ConnectionConfig::sshWithKey(
                host: 'example.com',
                username: 'deploy',
                privateKey: 'PEM-CONTENT',
                passphrase: 'phrase',
            ));

            $config = $builder->getParameters()['connectionConfig'];

            expect($config['private_key'])->toBe('PEM-CONTENT')
                ->and($config['passphrase'])->toBe('phrase');
        });

        it('applies workingDirectory() when the connection config has none', function () {
            $builder = new TerminalBuilder;
            $builder->local()->workingDirectory('/var/www');

            expect($builder->getParameters()['connectionConfig']['working_directory'])->toBe('/var/www');
        });
    });

    describe('appearance configuration', function () {
        it('sets title', function () {
            $builder = new TerminalBuilder;
            $builder->local()->title('My Terminal');

            $params = $builder->getParameters();

            expect($params['title'])->toBe('My Terminal');
        });

        it('sets chrome to minimal', function () {
            $builder = new TerminalBuilder;
            $builder->local()->chrome(TerminalChrome::Minimal);

            $params = $builder->getParameters();

            expect($params['chrome'])->toBe('minimal');
        });

        it('sets height', function () {
            $builder = new TerminalBuilder;
            $builder->local()->height('600px');

            $params = $builder->getParameters();

            expect($params['height'])->toBe('600px');
        });

        it('sets squareCorners', function () {
            $builder = new TerminalBuilder;
            $builder->local()->squareCorners();

            $params = $builder->getParameters();

            expect($params['squareCorners'])->toBeTrue();
        });
    });

    describe('logging configuration', function () {
        it('sets log configuration', function () {
            $builder = new TerminalBuilder;
            $builder->local()->log(
                enabled: true,
                connections: true,
                identifier: 'test-terminal',
            );

            $params = $builder->getParameters();

            expect($params['loggingEnabled'])->toBeTrue()
                ->and($params['logConnections'])->toBeTrue()
                ->and($params['logIdentifier'])->toBe('test-terminal');
        });

        it('leaves logging params null when not configured', function () {
            $builder = new TerminalBuilder;
            $builder->local();

            $params = $builder->getParameters();

            expect($params['loggingEnabled'])->toBeNull()
                ->and($params['logConnections'])->toBeNull()
                ->and($params['logIdentifier'])->toBeNull()
                ->and($params['logMetadata'])->toBe([]);
        });
    });

    describe('scripts configuration', function () {
        it('sets scripts and normalizes them to array form', function () {
            $builder = new TerminalBuilder;
            $builder->local()->scripts([
                [
                    'key' => 'greet',
                    'label' => 'Greet',
                    'commands' => ['echo hello', 'echo world'],
                ],
            ]);

            $params = $builder->getParameters();

            expect($params['scripts'])->toBeArray()
                ->and($params['scripts'][0]['key'])->toBe('greet')
                ->and($params['scripts'][0]['commands'])->toBe(['echo hello', 'echo world']);
        });
    });

    describe('render', function () {
        it('renders the stream terminal component', function () {
            $builder = new TerminalBuilder;
            $builder->local();

            $html = $builder->render();

            expect((string) $html)->toContain('stream-web-terminal');
        });
    });
});
