<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use MWGuerra\WebTerminalStream\Console\Commands\TerminalInstallCommand;
use MWGuerra\WebTerminalStream\Console\Commands\TerminalLogsCleanupCommand;
use MWGuerra\WebTerminalStream\Console\Commands\TerminalMakePageCommand;
use MWGuerra\WebTerminalStream\Console\Commands\TerminalServeCommand;
use MWGuerra\WebTerminalStream\Http\Controllers\TerminalWebSocketController;
use MWGuerra\WebTerminalStream\Livewire\StreamTerminal;
use MWGuerra\WebTerminalStream\Services\TerminalLogger;

class WebTerminalStreamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/web-terminal-stream.php',
            'web-terminal-stream');

        $this->app->singleton(TerminalLogger::class, function ($app) {
            return new TerminalLogger(config('web-terminal-stream.logging', []));
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'web-terminal-stream');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'web-terminal-stream');
        $this->registerAssets();

        Livewire::component('web-terminal-stream', StreamTerminal::class);

        Route::post('terminal-stream/ws-token', [
            TerminalWebSocketController::class,
            'generateToken',
        ])->name('web-terminal-stream.ws-token')->middleware(['web', 'auth']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                TerminalInstallCommand::class,
                TerminalLogsCleanupCommand::class,
                TerminalMakePageCommand::class,
                TerminalServeCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/web-terminal-stream.php' => config_path('web-terminal-stream.php'),
            ], 'web-terminal-stream-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/web-terminal-stream'),
            ], 'web-terminal-stream-views');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/web-terminal-stream'),
            ], 'web-terminal-stream-lang');
        }
    }

    protected function registerAssets(): void
    {
        // Only register assets when Filament is installed
        if (class_exists(FilamentAsset::class)) {
            $assets = [
                Css::make('web-terminal-stream', __DIR__.'/../resources/dist/web-terminal-stream.css'),
            ];

            if (file_exists(__DIR__.'/../resources/dist/stream-terminal.js')) {
                $assets[] = Js::make('stream-terminal', __DIR__.'/../resources/dist/stream-terminal.js');
            }

            FilamentAsset::register($assets, 'mwguerra/web-terminal-stream');
        }
    }
}
