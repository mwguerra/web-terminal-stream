<?php

declare(strict_types=1);

use MWGuerra\WebTerminal\Data\ConnectionConfig;
use MWGuerra\WebTerminal\Livewire\TerminalBuilder;

describe('TerminalBuilder', function () {
    describe('connection configuration', function () {
        it('defaults to an empty connection config', function () {
            $builder = new TerminalBuilder;

            $params = $builder->getParameters();

            expect($params['connectionConfig'])->toBe([]);
        });

        it('sets local connection', function () {
            $builder = new TerminalBuilder;
            $builder->local();

            $params = $builder->getParameters();

            expect($params['connectionConfig']['type'])->toBe('local');
        });

        it('sets SSH connection with password', function () {
            $builder = new TerminalBuilder;
            $builder->sshWithPassword('example.com', 'deploy', 'secret', 2222);

            $config = $builder->getParameters()['connectionConfig'];

            expect($config['type'])->toBe('ssh')
                ->and($config['host'])->toBe('example.com')
                ->and($config['username'])->toBe('deploy')
                ->and($config['password'])->toBe('secret')
                ->and($config['port'])->toBe(2222);
        });

        it('sets SSH connection with key', function () {
            $builder = new TerminalBuilder;
            $builder->sshWithKey('example.com', 'deploy', 'PRIVATE-KEY', 'phrase');

            $config = $builder->getParameters()['connectionConfig'];

            expect($config['type'])->toBe('ssh')
                ->and($config['private_key'])->toBe('PRIVATE-KEY')
                ->and($config['passphrase'])->toBe('phrase');
        });

        it('accepts a ConnectionConfig value object', function () {
            $builder = new TerminalBuilder;
            $builder->withConfig(ConnectionConfig::local(workingDirectory: '/tmp'));

            $config = $builder->getParameters()['connectionConfig'];

            expect($config['type'])->toBe('local')
                ->and($config['working_directory'])->toBe('/tmp');
        });
    });

    describe('appearance configuration', function () {
        it('sets title', function () {
            $builder = new TerminalBuilder;
            $builder->local()->title('My Terminal');

            $params = $builder->getParameters();

            expect($params['title'])->toBe('My Terminal');
        });

        it('sets windowControls to false', function () {
            $builder = new TerminalBuilder;
            $builder->local()->windowControls(false);

            $params = $builder->getParameters();

            expect($params['showWindowControls'])->toBeFalse();
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
