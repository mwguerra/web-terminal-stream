<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\WebSocket\ReactPhpProvider;
use MWGuerra\WebTerminalStream\WebSocket\WebSocketProviderInterface;

describe('ReactPhpProvider', function () {
    it('implements WebSocketProviderInterface', function () {
        $provider = new ReactPhpProvider(app());
        expect($provider)->toBeInstanceOf(WebSocketProviderInterface::class);
    });
});
