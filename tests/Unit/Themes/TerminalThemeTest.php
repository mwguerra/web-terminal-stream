<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Themes\Dracula;
use MWGuerra\WebTerminalStream\Themes\TerminalTheme;
use MWGuerra\WebTerminalStream\Themes\TokyoNight;

describe('TerminalTheme', function () {
    describe('defaults', function () {
        it('ships sensible base defaults', function () {
            $theme = TerminalTheme::make();

            expect($theme->getFontSize())->toBe(13)
                ->and($theme->getFontFamily())->toContain('monospace')
                ->and($theme->toColors())->toMatchArray([
                    'background' => '#1a1a2e',
                    'foreground' => '#e2e8f0',
                ])
                ->and($theme->toCssVariables())->toMatchArray([
                    '--wts-divider-width' => '1px',
                    '--wts-divider-style' => 'solid',
                ]);
        });
    });

    describe('fluent partial overrides', function () {
        it('changes only what is named, keeping the rest', function () {
            $theme = TerminalTheme::make()->fontSize(16)->background('#000000');

            expect($theme->getFontSize())->toBe(16)
                ->and($theme->toColors()['background'])->toBe('#000000')
                // untouched defaults survive
                ->and($theme->toColors()['foreground'])->toBe('#e2e8f0')
                ->and($theme->getFontFamily())->toContain('monospace');
        });

        it('exposes divider styling as CSS custom properties', function () {
            $vars = TerminalTheme::make()
                ->dividerWidth(3)
                ->dividerStyle('dashed')
                ->dividerColor('#ff0000')
                ->dividerFocusColor('#00ff00')
                ->toCssVariables();

            expect($vars['--wts-divider-width'])->toBe('3px')
                ->and($vars['--wts-divider-style'])->toBe('dashed')
                ->and($vars['--wts-divider-color'])->toBe('#ff0000')
                ->and($vars['--wts-divider-focus'])->toBe('#00ff00');
        });

        it('clamps font size and divider width to non-negative', function () {
            expect(TerminalTheme::make()->fontSize(0)->getFontSize())->toBe(1)
                ->and(TerminalTheme::make()->dividerWidth(-5)->toCssVariables()['--wts-divider-width'])->toBe('0px');
        });

        it('merges palette entries into the colors array', function () {
            $colors = TerminalTheme::make()->palette(['red' => '#f00'])->palette(['green' => '#0f0'])->toColors();

            expect($colors)->toMatchArray(['red' => '#f00', 'green' => '#0f0']);
        });

        it('omits null cursor/selection from the colors array', function () {
            expect(TerminalTheme::make()->toColors())
                ->not->toHaveKey('cursor')
                ->not->toHaveKey('selectionBackground');
        });
    });

    describe('shipped presets (subclasses)', function () {
        it('override defaults and stay fluently tweakable', function () {
            $theme = TokyoNight::make()->fontSize(15);

            // Preset default kept...
            expect($theme->toColors()['background'])->toBe('#1a1b26')
                ->and($theme->toColors()['cursor'])->toBe('#c0caf5')
                // ...fluent override applied on top...
                ->and($theme->getFontSize())->toBe(15)
                // ...and make() returns the subclass, not the base.
                ->and($theme)->toBeInstanceOf(TokyoNight::class);
        });

        it('Dracula is a distinct preset', function () {
            expect(Dracula::make()->toColors()['background'])->toBe('#282a36')
                ->and(Dracula::make())->toBeInstanceOf(TerminalTheme::class);
        });

        it('a user subclass inherits the base contract', function () {
            $brand = new class extends TerminalTheme
            {
                protected string $background = '#0b1021';

                protected string $dividerColor = '#312e81';
            };

            $theme = $brand::make()->fontFamily('Berkeley Mono');

            expect($theme->toColors()['background'])->toBe('#0b1021')
                ->and($theme->toCssVariables()['--wts-divider-color'])->toBe('#312e81')
                ->and($theme->getFontFamily())->toBe('Berkeley Mono')
                ->and($theme->getFontSize())->toBe(13); // base default kept
        });
    });

    describe('toCssVariableString', function () {
        it('renders declarations for an inline style attribute', function () {
            $css = TerminalTheme::make()->dividerWidth(2)->toCssVariableString();

            expect($css)->toContain('--wts-divider-width: 2px;')
                ->and($css)->toContain('--wts-terminal-bg:');
        });
    });
});
