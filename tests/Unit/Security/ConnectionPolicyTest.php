<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Security\ConnectionPolicy;

beforeEach(function () {
    $this->policy = new ConnectionPolicy;
});

describe('ConnectionPolicy', function () {
    describe('local', function () {
        it('allows local by default', function () {
            expect($this->policy->allows(['type' => 'local']))->toBeTrue();
        });

        it('denies local when allow_local is false', function () {
            config()->set('web-terminal-stream.security.allow_local', false);

            expect($this->policy->allows(['type' => 'local']))->toBeFalse()
                ->and($this->policy->deniedReason(['type' => 'local']))->toContain('Local terminals are disabled');
        });

        it('treats a missing type as local', function () {
            config()->set('web-terminal-stream.security.allow_local', false);

            expect($this->policy->allows([]))->toBeFalse();
        });
    });

    describe('ssh allow-list', function () {
        it('allows any host when the allow-list is empty', function () {
            expect($this->policy->allows(['type' => 'ssh', 'host' => 'anything.example.com']))->toBeTrue();
        });

        it('allows a host on the list and denies one off it', function () {
            config()->set('web-terminal-stream.security.ssh_allowed_hosts', ['a.example.com', 'b.example.com']);

            expect($this->policy->allows(['type' => 'ssh', 'host' => 'a.example.com']))->toBeTrue()
                ->and($this->policy->allows(['type' => 'ssh', 'host' => 'b.example.com']))->toBeTrue()
                ->and($this->policy->allows(['type' => 'ssh', 'host' => 'evil.example.com']))->toBeFalse();
        });

        it('denies SSH with no host when a list is configured', function () {
            config()->set('web-terminal-stream.security.ssh_allowed_hosts', ['a.example.com']);

            expect($this->policy->allows(['type' => 'ssh']))->toBeFalse();
        });

        it('honors a host:port pin', function () {
            config()->set('web-terminal-stream.security.ssh_allowed_hosts', ['jump.example.com:2222']);

            expect($this->policy->allows(['type' => 'ssh', 'host' => 'jump.example.com', 'port' => 2222]))->toBeTrue()
                ->and($this->policy->allows(['type' => 'ssh', 'host' => 'jump.example.com', 'port' => 22]))->toBeFalse();
        });

        it('a bare host entry matches any port', function () {
            config()->set('web-terminal-stream.security.ssh_allowed_hosts', ['jump.example.com']);

            expect($this->policy->allows(['type' => 'ssh', 'host' => 'jump.example.com', 'port' => 2222]))->toBeTrue();
        });
    });

    it('denies an unknown connection type', function () {
        expect($this->policy->allows(['type' => 'telnet']))->toBeFalse();
    });
});
