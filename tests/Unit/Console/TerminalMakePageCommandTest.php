<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use MWGuerra\WebTerminalStream\Console\Commands\TerminalMakePageCommand;

describe('TerminalMakePageCommand::isValidClassName', function () {
    $command = new TerminalMakePageCommand(new Filesystem);

    it('accepts valid PHP class names', function () use ($command) {
        expect($command->isValidClassName('Terminal'))->toBeTrue()
            ->and($command->isValidClassName('ServerTerminal'))->toBeTrue()
            ->and($command->isValidClassName('_Private'))->toBeTrue()
            ->and($command->isValidClassName('Page2'))->toBeTrue();
    });

    it('rejects names that would not compile', function () use ($command) {
        expect($command->isValidClassName('my page'))->toBeFalse()
            ->and($command->isValidClassName('Bad-Name'))->toBeFalse()
            ->and($command->isValidClassName('2Fast'))->toBeFalse()
            ->and($command->isValidClassName('Has!Bang'))->toBeFalse()
            ->and($command->isValidClassName(''))->toBeFalse();
    });
});
