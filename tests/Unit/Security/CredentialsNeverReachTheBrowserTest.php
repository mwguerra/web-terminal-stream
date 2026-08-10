<?php

declare(strict_types=1);

use Livewire\Livewire;
use MWGuerra\WebTerminalStream\Livewire\StreamDashboard;
use MWGuerra\WebTerminalStream\Livewire\StreamTerminal;
use MWGuerra\WebTerminalStream\Livewire\StreamWorkspace;
use MWGuerra\WebTerminalStream\Schemas\Components\TerminalDashboard;
use MWGuerra\WebTerminalStream\Schemas\Components\WebTerminalStream;
use MWGuerra\WebTerminalStream\Security\ConnectionVault;

/**
 * Regression guard for the 1.1.0 disclosure.
 *
 * Until 1.0.1 the resolved connection config lived in a public Livewire
 * property. `#[Locked]` was mistaken for privacy — it only stops the CLIENT
 * from writing the value back; Livewire still serializes every public property
 * into the `wire:snapshot` attribute it renders into the page. The result was
 * the SSH host, username and PRIVATE KEY sitting in the HTML of every terminal.
 *
 * These tests assert the property that actually matters and is easy to lose in
 * a refactor: whatever the component holds, none of it renders.
 */
const LEAKY_KEY = '-----BEGIN OPENSSH PRIVATE KEY-----b3BlbnNzaC1rZXktdjEAAAAA-----END OPENSSH PRIVATE KEY-----';

function sshConfigWithSecrets(): array
{
    return [
        'type' => 'ssh',
        'host' => '203.0.113.10',
        'port' => 6985,
        'username' => 'root',
        'private_key' => LEAKY_KEY,
        'passphrase' => 'PASSPHRASE-SHOULD-NOT-RENDER',
    ];
}

function expectNoSecretsIn(string $html): void
{
    expect($html)->not->toContain(LEAKY_KEY)
        ->and($html)->not->toContain('BEGIN OPENSSH PRIVATE KEY')
        ->and($html)->not->toContain('PASSPHRASE-SHOULD-NOT-RENDER')
        ->and($html)->not->toContain('203.0.113.10');
}

describe('credentials never reach the browser', function () {
    it('keeps a standalone terminal\'s ssh config out of the rendered HTML', function () {
        $html = Livewire::test(StreamTerminal::class, [
            'connectionConfig' => sshConfigWithSecrets(),
        ])->html();

        expectNoSecretsIn($html);
    });

    it('still connects to the right target even though the config never rendered', function () {
        $component = Livewire::test(StreamTerminal::class, [
            'connectionConfig' => sshConfigWithSecrets(),
        ]);

        // The point of the vault is custody, not amnesia: the server side must
        // still resolve the exact config it was given.
        expect($component->instance()->connectionConfig())->toBe(sshConfigWithSecrets());
    });

    it('keeps EVERY dashboard source out of the HTML, including unopened ones', function () {
        // The roster is serialized whole, so an unopened source used to leak
        // exactly as much as an open one.
        $props = TerminalDashboard::make()
            ->sources([
                'open' => WebTerminalStream::make()->title('Open')->ssh(sshConfigWithSecrets()),
                'shut' => WebTerminalStream::make()->title('Shut')->ssh([
                    'host' => '198.51.100.7',
                    'username' => 'root',
                    'private_key' => LEAKY_KEY,
                ]),
            ])
            ->defaultOpen(['open'])
            ->getComponentProperties();

        $html = Livewire::test(StreamDashboard::class, $props)->html();

        expectNoSecretsIn($html);
        expect($html)->not->toContain('198.51.100.7');

        // The labels are what the operator picks from, so they must survive.
        expect($html)->toContain('Open')->toContain('Shut');
    });

    it('keeps a workspace\'s pane defaults out of the HTML', function () {
        $html = Livewire::test(StreamWorkspace::class, [
            'paneDefaults' => ['connectionConfig' => sshConfigWithSecrets(), 'height' => '100%'],
        ])->html();

        expectNoSecretsIn($html);
    });

    it('does not expose the config through a public component property', function () {
        // A future refactor that re-adds `public array $connectionConfig` would
        // reintroduce the bug silently; this fails the moment it does.
        $component = Livewire::test(StreamTerminal::class, [
            'connectionConfig' => sshConfigWithSecrets(),
        ]);

        expect(property_exists($component->instance(), 'connectionConfig'))->toBeFalse();

        $payload = json_encode(Livewire::test(StreamTerminal::class, [
            'connectionConfig' => sshConfigWithSecrets(),
        ])->getData());

        expect($payload)->not->toContain('BEGIN OPENSSH PRIVATE KEY')
            ->and($payload)->not->toContain('203.0.113.10');
    });

    it('refuses to connect once the vault entry is gone, instead of falling back to a local shell', function () {
        $component = Livewire::test(StreamTerminal::class, [
            'connectionConfig' => sshConfigWithSecrets(),
        ]);

        app(ConnectionVault::class)
            ->forget($component->get('connectionRef'));

        $result = $component->instance()->getWebSocketUrl();

        // An empty config reads as "local" to every layer below, so silently
        // continuing would open a shell on the APP server instead of the box.
        expect($result)->toHaveKey('error')
            ->and($result)->not->toHaveKey('url');
    });
});
