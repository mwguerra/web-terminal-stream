<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Enums\ConnectionType;

describe('ConnectionType', function () {
    it('has local case', function () {
        expect(ConnectionType::Local->value)->toBe('local');
    });

    it('has ssh case', function () {
        expect(ConnectionType::SSH->value)->toBe('ssh');
    });

    it('can be created from string value', function () {
        expect(ConnectionType::from('local'))->toBe(ConnectionType::Local);
        expect(ConnectionType::from('ssh'))->toBe(ConnectionType::SSH);
    });

    it('returns correct labels', function () {
        expect(ConnectionType::Local->label())->toBe('Local');
        expect(ConnectionType::SSH->label())->toBe('SSH');
    });

    it('returns correct descriptions', function () {
        expect(ConnectionType::Local->description())->toBe('Execute commands on the local server');
        expect(ConnectionType::SSH->description())->toBe('Execute commands via SSH connection');
    });

    it('correctly identifies credential requirements', function () {
        expect(ConnectionType::Local->requiresCredentials())->toBeFalse();
        expect(ConnectionType::SSH->requiresCredentials())->toBeTrue();
    });

    it('returns correct default ports', function () {
        expect(ConnectionType::Local->defaultPort())->toBeNull();
        expect(ConnectionType::SSH->defaultPort())->toBe(22);
    });

    it('returns options array for form selects', function () {
        $options = ConnectionType::options();

        expect($options)->toBeArray()
            ->and($options)->toHaveCount(2)
            ->and($options['local'])->toBe('Local')
            ->and($options['ssh'])->toBe('SSH');
    });

    it('throws exception for invalid value', function () {
        ConnectionType::from('invalid');
    })->throws(ValueError::class);
});
