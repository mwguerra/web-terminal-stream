<?php

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use MWGuerra\WebTerminal\Livewire\StreamTerminal as StreamTerminalComponent;
use MWGuerra\WebTerminal\Schemas\Components\WebTerminal;

// Skip all tests if Filament is not installed
beforeEach(function () {
    if (! class_exists(Livewire::class)) {
        $this->markTestSkipped('Filament is not installed. These tests require filament/filament package.');
    }
});

describe('make', function () {
    it('creates instance with default component', function () {
        $component = WebTerminal::make();

        expect($component)->toBeInstanceOf(WebTerminal::class);
    });

    it('always mounts the StreamTerminal Livewire component', function () {
        $component = WebTerminal::make();

        expect($component->getComponent())->toBe(StreamTerminalComponent::class);
    });
});

describe('ssh', function () {
    it('configures SSH connection with password', function () {
        $component = WebTerminal::make()
            ->ssh(
                host: '192.168.1.100',
                username: 'admin',
                password: 'secret123',
                port: 22
            );

        $config = $component->getConnectionConfig();

        expect($config['type'])->toBe('ssh')
            ->and($config['host'])->toBe('192.168.1.100')
            ->and($config['username'])->toBe('admin')
            ->and($config['password'])->toBe('secret123')
            ->and($config['port'])->toBe(22);
    });

    it('configures SSH connection with key content', function () {
        $keyContent = '-----BEGIN OPENSSH PRIVATE KEY-----
test-key-content
-----END OPENSSH PRIVATE KEY-----';

        $component = WebTerminal::make()
            ->ssh(
                host: '192.168.1.100',
                username: 'admin',
                key: $keyContent,
                port: 2222
            );

        $config = $component->getConnectionConfig();

        expect($config['type'])->toBe('ssh')
            ->and($config['host'])->toBe('192.168.1.100')
            ->and($config['username'])->toBe('admin')
            ->and($config['private_key'])->toBe($keyContent)
            ->and($config['port'])->toBe(2222);
    });

    it('configures SSH connection with key and passphrase', function () {
        $keyContent = '-----BEGIN OPENSSH PRIVATE KEY-----
encrypted-key
-----END OPENSSH PRIVATE KEY-----';

        $component = WebTerminal::make()
            ->ssh(
                host: 'localhost',
                username: 'root',
                key: $keyContent,
                passphrase: 'my-secret-passphrase',
                port: 22
            );

        $config = $component->getConnectionConfig();

        expect($config['passphrase'])->toBe('my-secret-passphrase');
    });

    it('uses default port 22 when not specified', function () {
        $component = WebTerminal::make()
            ->ssh(
                host: 'localhost',
                username: 'root'
            );

        $config = $component->getConnectionConfig();

        expect($config['port'])->toBe(22);
    });

    it('returns self for method chaining', function () {
        $component = WebTerminal::make();

        expect($component->ssh(host: 'localhost', username: 'root'))->toBe($component);
    });
});

describe('local', function () {
    it('configures local connection', function () {
        $component = WebTerminal::make()
            ->local();

        $config = $component->getConnectionConfig();

        expect($config['type'])->toBe('local');
    });

    it('returns self for method chaining', function () {
        $component = WebTerminal::make();

        expect($component->local())->toBe($component);
    });
});

describe('height', function () {
    it('has default height of 350px', function () {
        $component = WebTerminal::make();

        expect($component->getHeight())->toBe('350px');
    });

    it('sets custom height', function () {
        $component = WebTerminal::make()
            ->height('600px');

        expect($component->getHeight())->toBe('600px');
    });

    it('evaluates closure for height', function () {
        $component = WebTerminal::make()
            ->height(fn () => '500px');

        expect($component->getHeight())->toBe('500px');
    });

    it('returns self for method chaining', function () {
        $component = WebTerminal::make();

        expect($component->height('400px'))->toBe($component);
    });
});

describe('workingDirectory', function () {
    it('has null working directory by default', function () {
        $component = WebTerminal::make();

        expect($component->getWorkingDirectory())->toBeNull();
    });

    it('sets working directory', function () {
        $component = WebTerminal::make()
            ->workingDirectory('/home/user');

        expect($component->getWorkingDirectory())->toBe('/home/user');
    });

    it('includes working directory in the component connection config', function () {
        $component = WebTerminal::make()
            ->local()
            ->workingDirectory('/home/user');

        $props = $component->getComponentProperties();

        expect($props['connectionConfig']['working_directory'])->toBe('/home/user');
    });

    it('returns self for method chaining', function () {
        $component = WebTerminal::make();

        expect($component->workingDirectory('/tmp'))->toBe($component);
    });
});

describe('title', function () {
    it('has default title of Terminal', function () {
        $component = WebTerminal::make();

        expect($component->getTitle())->toBe('Terminal');
    });

    it('sets custom title', function () {
        $component = WebTerminal::make()
            ->title('My Server Console');

        expect($component->getTitle())->toBe('My Server Console');
    });

    it('evaluates closure for title', function () {
        $component = WebTerminal::make()
            ->title(fn () => 'Dynamic Title');

        expect($component->getTitle())->toBe('Dynamic Title');
    });

    it('returns self for method chaining', function () {
        $component = WebTerminal::make();

        expect($component->title('Custom Title'))->toBe($component);
    });
});

describe('windowControls', function () {
    it('shows window controls by default', function () {
        $component = WebTerminal::make();

        expect($component->getShowWindowControls())->toBeTrue();
    });

    it('hides window controls when set to false', function () {
        $component = WebTerminal::make()
            ->windowControls(false);

        expect($component->getShowWindowControls())->toBeFalse();
    });

    it('shows window controls when set to true', function () {
        $component = WebTerminal::make()
            ->windowControls(false)
            ->windowControls(true);

        expect($component->getShowWindowControls())->toBeTrue();
    });

    it('returns self for method chaining', function () {
        $component = WebTerminal::make();

        expect($component->windowControls(false))->toBe($component);
    });
});

describe('component properties', function () {
    it('exposes the stream component props', function () {
        $component = WebTerminal::make()
            ->local()
            ->height('500px')
            ->title('Console')
            ->streamTheme(['background' => '#000000'])
            ->log(enabled: true, connections: true, identifier: 'console');

        $props = $component->getComponentProperties();

        expect($props['connectionConfig'])->toBe(['type' => 'local'])
            ->and($props['height'])->toBe('500px')
            ->and($props['title'])->toBe('Console')
            ->and($props['streamTheme'])->toBe(['background' => '#000000'])
            ->and($props['loggingEnabled'])->toBeTrue()
            ->and($props['logConnections'])->toBeTrue()
            ->and($props['logIdentifier'])->toBe('console')
            ->and($props['chrome'])->toBe('full')
            ->and($props['autoConnect'])->toBeFalse();
    });
});

describe('inheritance', function () {
    it('extends Filament Livewire component', function () {
        expect(is_subclass_of(WebTerminal::class, Livewire::class))->toBeTrue();
    });

    it('is a Filament schema component', function () {
        expect(is_subclass_of(WebTerminal::class, Component::class))->toBeTrue();
    });
});
