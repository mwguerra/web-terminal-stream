<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Console\Commands\TerminalServeCommand;

describe('TerminalServeCommand::capabilityWarnings', function () {
    it('is silent on a fully-capable Linux host', function () {
        expect(TerminalServeCommand::capabilityWarnings(hasPosix: true, hasPcntl: true, osFamily: 'Linux'))
            ->toBe([]);
    });

    it('warns when ext-posix is missing', function () {
        $warnings = TerminalServeCommand::capabilityWarnings(hasPosix: false, hasPcntl: true, osFamily: 'Linux');

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('ext-posix');
    });

    it('warns when ext-pcntl is missing', function () {
        $warnings = TerminalServeCommand::capabilityWarnings(hasPosix: true, hasPcntl: false, osFamily: 'Linux');

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('ext-pcntl');
    });

    it('warns about local PTY on a non-Linux host', function () {
        $warnings = TerminalServeCommand::capabilityWarnings(hasPosix: true, hasPcntl: true, osFamily: 'Darwin');

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('Linux')
            ->and($warnings[0])->toContain('Darwin');
    });

    it('reports every gap at once', function () {
        $warnings = TerminalServeCommand::capabilityWarnings(hasPosix: false, hasPcntl: false, osFamily: 'Windows');

        expect($warnings)->toHaveCount(3);
    });
});
