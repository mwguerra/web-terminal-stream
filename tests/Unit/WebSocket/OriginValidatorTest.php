<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\WebSocket\OriginValidator;

/*
 * Unit coverage for the WebSocket handshake Origin allow-list matcher.
 *
 * The contract: normalized exact matching (scheme + case-insensitive host +
 * port with scheme defaults filled in), missing Origin always allowed
 * (non-browser clients — the token stays the auth gate), literal '*'
 * disables the check, malformed Origin values never match.
 */

it('allows an origin that exactly matches the allow-list', function () {
    $validator = new OriginValidator(['https://app.test']);

    expect($validator->allows('https://app.test'))->toBeTrue();
});

it('denies an origin that is not in the allow-list', function () {
    $validator = new OriginValidator(['https://app.test']);

    expect($validator->allows('https://evil.test'))->toBeFalse();
});

it('denies on port mismatch', function () {
    $validator = new OriginValidator(['https://app.test:8443']);

    expect($validator->allows('https://app.test'))->toBeFalse();
    expect($validator->allows('https://app.test:9443'))->toBeFalse();
});

it('treats a missing port as the scheme default', function () {
    $validator = new OriginValidator(['https://app.test']);

    expect($validator->allows('https://app.test:443'))->toBeTrue();

    $validator = new OriginValidator(['http://app.test:80']);

    expect($validator->allows('http://app.test'))->toBeTrue();
});

it('does not conflate default ports across schemes', function () {
    $validator = new OriginValidator(['https://app.test']);

    expect($validator->allows('http://app.test'))->toBeFalse();
});

it('matches hosts case-insensitively', function () {
    $validator = new OriginValidator(['https://App.TEST']);

    expect($validator->allows('https://app.test'))->toBeTrue();
    expect($validator->allows('HTTPS://APP.TEST'))->toBeTrue();
});

it('allows a request without an Origin header', function () {
    $validator = new OriginValidator(['https://app.test']);

    expect($validator->allows(null))->toBeTrue();
    expect($validator->allows(''))->toBeTrue();
});

it('disables the check when the allow-list contains a literal wildcard', function () {
    $validator = new OriginValidator(['*']);

    expect($validator->allows('https://anything.example'))->toBeTrue();

    $validator = new OriginValidator(['https://app.test', '*']);

    expect($validator->allows('https://evil.test'))->toBeTrue();
});

it('denies a malformed origin string', function () {
    $validator = new OriginValidator(['https://app.test']);

    expect($validator->allows('not a url'))->toBeFalse();
    expect($validator->allows('app.test'))->toBeFalse();
    expect($validator->allows('https://'))->toBeFalse();
});

it('ignores a path on the allow-list entry or the origin', function () {
    $validator = new OriginValidator(['https://app.test/admin']);

    expect($validator->allows('https://app.test'))->toBeTrue();
});

it('allows any origin when the allow-list is empty (check unconfigured)', function () {
    $validator = new OriginValidator([]);

    expect($validator->allows('https://anything.example'))->toBeTrue();
});

it('skips non-string and empty allow-list entries', function () {
    $validator = new OriginValidator([null, '', 42, 'https://app.test']);

    expect($validator->allows('https://app.test'))->toBeTrue();
    expect($validator->allows('https://evil.test'))->toBeFalse();
});
