<?php

use MWGuerra\WebTerminalStream\WebSocket\TerminalPtyBridge;
use phpseclib3\Exception\TimeoutException;

/**
 * A read timeout must NOT kill the session.
 *
 * startSsh() sets a 1ms read budget so a single session can never stall the
 * shared event loop. phpseclib treats that budget as a hard deadline and throws
 * TimeoutException ("Timed out waiting for server") whenever a complete channel
 * packet does not arrive inside it — which is routine, because the loop wakes
 * the bridge whenever the SOCKET is readable, and a keepalive or a window
 * adjustment makes it readable while carrying no channel data.
 *
 * That exception used to escape read() and reach the event loop's
 * `catch (\Throwable)`, which closes the session. Field symptom: run anything
 * slow — an `acme.sh --issue` taking minutes — and the terminal stops
 * responding entirely; the command echo appears and nothing ever comes back,
 * even for later commands. Reconnecting fixes it, which is what makes it read
 * like a hang rather than the teardown it actually is.
 *
 * With a 1ms budget a timeout means "nothing to read right now".
 */
it('returns an empty string when the ssh read times out', function () {
    $shell = new class
    {
        public function read(string $pattern): string
        {
            throw new TimeoutException('Timed out waiting for server');
        }

        // false de proposito: o destrutor do bridge chama terminate() quando
        // isRunning() e verdadeiro, e terminate() precisa de um registry que
        // esta ausente na instancia criada por reflexao. O alvo aqui e read().
        public function isConnected(): bool
        {
            return false;
        }
    };

    $bridge = (new ReflectionClass(TerminalPtyBridge::class))->newInstanceWithoutConstructor();
    $prop = new ReflectionProperty(TerminalPtyBridge::class, 'sshShell');
    $prop->setAccessible(true);
    $prop->setValue($bridge, $shell);

    expect($bridge->read())->toBe('');
});

it('still surfaces a real transport failure', function () {
    // Só o timeout é benigno. Uma queda de transporte continua subindo, para o
    // laço fechar a sessão como deve — engolir tudo esconderia uma conexão morta
    // e deixaria o terminal preso para sempre.
    $shell = new class
    {
        public function read(string $pattern): string
        {
            throw new RuntimeException('Connection closed by server');
        }

        public function isConnected(): bool
        {
            return false;
        }
    };

    $bridge = (new ReflectionClass(TerminalPtyBridge::class))->newInstanceWithoutConstructor();
    $prop = new ReflectionProperty(TerminalPtyBridge::class, 'sshShell');
    $prop->setAccessible(true);
    $prop->setValue($bridge, $shell);

    expect(fn () => $bridge->read())->toThrow(RuntimeException::class);
});
