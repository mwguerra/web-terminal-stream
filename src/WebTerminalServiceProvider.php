<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminal;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use MWGuerra\WebTerminal\Console\Commands\TerminalInstallCommand;
use MWGuerra\WebTerminal\Console\Commands\TerminalLogsCleanupCommand;
use MWGuerra\WebTerminal\Console\Commands\TerminalMakePageCommand;
use MWGuerra\WebTerminal\Console\Commands\TerminalServeCommand;
use MWGuerra\WebTerminal\Http\Controllers\TerminalWebSocketController;
use MWGuerra\WebTerminal\Livewire\StreamTerminal;
use MWGuerra\WebTerminal\Services\TerminalLogger;

class WebTerminalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/web-terminal.php',
            'web-terminal'
        );

        $this->app->singleton(TerminalLogger::class, function ($app) {
            return new TerminalLogger(config('web-terminal.logging', []));
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'web-terminal');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'web-terminal');
        $this->registerAssets();

        Livewire::component('stream-terminal', StreamTerminal::class);

        Route::post('terminal/ws-token', [
            TerminalWebSocketController::class,
            'generateToken',
        ])->name('terminal.ws-token')->middleware(['web', 'auth']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                TerminalInstallCommand::class,
                TerminalLogsCleanupCommand::class,
                TerminalMakePageCommand::class,
                TerminalServeCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/web-terminal.php' => config_path('web-terminal.php'),
            ], 'web-terminal-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/web-terminal'),
            ], 'web-terminal-views');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/web-terminal'),
            ], 'web-terminal-lang');
        }
    }

    protected function registerAssets(): void
    {
        // Only register assets when Filament is installed
        if (class_exists(FilamentAsset::class)) {
            $assets = [
                Css::make('web-terminal', __DIR__.'/../resources/dist/web-terminal.css'),
            ];

            if (file_exists(__DIR__.'/../resources/dist/stream-terminal.js')) {
                $assets[] = Js::make('stream-terminal', __DIR__.'/../resources/dist/stream-terminal.js');
            }

            FilamentAsset::register($assets, 'mwguerra/web-terminal');
        }
    }
}
