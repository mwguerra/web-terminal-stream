<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Livewire\TerminalBuilder;

describe('TerminalBuilder Stream Methods', function () {
    describe('theme()', function () {
        it('stores theme options', function () {
            $theme = ['background' => '#1a1b26', 'fontSize' => 14];
            $builder = new TerminalBuilder;
            $builder->local()->theme($theme);
            $params = $builder->getParameters();
            expect($params['theme'])->toBe($theme);
        });

        it('defaults to empty array', function () {
            $builder = new TerminalBuilder;
            $builder->local();
            $params = $builder->getParameters();
            expect($params['theme'])->toBe([]);
        });

        it('evaluates a closure theme lazily', function () {
            $builder = new TerminalBuilder;
            $builder->local()->theme(fn () => ['background' => '#000000']);
            $params = $builder->getParameters();
            expect($params['theme'])->toBe(['background' => '#000000']);
        });
    });

    describe('render routing', function () {
        it('always renders the StreamTerminal component', function () {
            $builder = new TerminalBuilder;
            $builder->local();
            $html = $builder->render();
            expect((string) $html)->toContain('stream-web-terminal');
        });

        it('passes the connection config to the component', function () {
            $builder = new TerminalBuilder;
            $builder->local();
            $params = $builder->getParameters();
            expect($params['connectionConfig'])->toBe(['type' => 'local']);
        });
    });
});
