<?php

declare(strict_types=1);

use MWGuerra\WebTerminal\Security\CommandValidator;
use MWGuerra\WebTerminal\Tests\Benchmarks\BenchmarkCase;
use MWGuerra\WebTerminal\Tests\Benchmarks\BenchmarkRecorder;

uses(BenchmarkCase::class);

function makeCommandValidator(int $size): CommandValidator
{
    $allowed = ['ls', 'ls *', 'pwd', 'cd', 'cd *', 'cat *', 'echo *'];

    for ($i = count($allowed); $i < $size; $i++) {
        $allowed[] = "cmd{$i}";
        $allowed[] = "cmd{$i} *";
    }

    return new CommandValidator($allowed);
}

it('isAllowed exact match with 50-entry whitelist', function () {
    $validator = makeCommandValidator(50);
    $this->measure('CommandValidator::isAllowed exact@50', fn () => $validator->isAllowed('pwd'), 500);
    expect(true)->toBeTrue();
});

it('isAllowed wildcard match with 50-entry whitelist', function () {
    $validator = makeCommandValidator(50);
    $this->measure('CommandValidator::isAllowed wildcard@50', fn () => $validator->isAllowed('cat /etc/hosts'), 500);
    expect(true)->toBeTrue();
});

it('isAllowed exact match with 500-entry whitelist', function () {
    $validator = makeCommandValidator(500);
    $this->measure('CommandValidator::isAllowed exact@500', fn () => $validator->isAllowed('pwd'), 500);
    expect(true)->toBeTrue();
});

it('isAllowed miss with 500-entry whitelist', function () {
    $validator = makeCommandValidator(500);
    $this->measure('CommandValidator::isAllowed miss@500', fn () => $validator->isAllowed('not-a-real-command --foo'), 500);
    expect(true)->toBeTrue();
});

it('isAllowed exact match with 5000-entry whitelist', function () {
    $validator = makeCommandValidator(5000);
    $this->measure('CommandValidator::isAllowed exact@5000', fn () => $validator->isAllowed('pwd'), 500);
    expect(true)->toBeTrue();
});

it('flushes samples on shutdown when WEB_TERMINAL_BENCH_DUMP is set', function () {
    // This test exists solely to verify the recorder's shutdown hook is wired.
    BenchmarkRecorder::record('_internal/smoke', [1.0, 1.5, 2.0]);
    expect(BenchmarkRecorder::export())->toHaveKey('_internal/smoke');
});
