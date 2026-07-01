<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use MWGuerra\WebTerminalStream\Schemas\Components\WebTerminalStream;
use MWGuerra\WebTerminalStream\WebTerminalStreamPlugin;

class Terminal extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'web-terminal-stream::filament.pages.terminal';

    protected static string $routePath = 'terminal';

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return static::$navigationIcon
            ?? WebTerminalStreamPlugin::current()?->getTerminalNavigationIcon()
            ?? 'heroicon-o-command-line';
    }

    public function getTitle(): string|Htmlable
    {
        return __('web-terminal-stream::terminal.pages.terminal.title');
    }

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel
            ?? WebTerminalStreamPlugin::current()?->getTerminalNavigationLabel()
            ?? __('web-terminal-stream::terminal.navigation.terminal');
    }

    public static function getNavigationSort(): ?int
    {
        return static::$navigationSort
            ?? WebTerminalStreamPlugin::current()?->getTerminalNavigationSort()
            ?? 100;
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$navigationGroup
            ?? WebTerminalStreamPlugin::current()?->getTerminalNavigationGroup()
            ?? __('web-terminal-stream::terminal.navigation.tools');
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return static::$slug ?? 'terminal';
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('web-terminal-stream::terminal.pages.terminal.local_terminal'))
                    ->description(__('web-terminal-stream::terminal.pages.terminal.local_terminal_description'))
                    ->icon('heroicon-o-command-line')
                    ->schema([
                        WebTerminalStream::make()
                            ->key('local-terminal')
                            ->local()
                            ->workingDirectory(base_path())
                            ->height('400px')
                            ->title(__('web-terminal-stream::terminal.pages.terminal.local_terminal'))
                            ->log(
                                enabled: true,
                                connections: true,
                                identifier: 'local-terminal',
                            ),
                    ]),
            ]);
    }
}
