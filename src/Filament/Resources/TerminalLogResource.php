<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Filament\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource\Pages\ListTerminalLogs;
use MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource\Pages\ViewTerminalLog;
use MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource\Schemas\TerminalLogInfolist;
use MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource\Tables\TerminalLogsTable;
use MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource\Widgets\TerminalLogsStatsOverview;
use MWGuerra\WebTerminalStream\Models\TerminalLog;
use MWGuerra\WebTerminalStream\Services\TerminalLogger;
use MWGuerra\WebTerminalStream\WebTerminalStreamPlugin;

class TerminalLogResource extends Resource
{
    protected static ?string $model = TerminalLog::class;

    protected static ?string $slug = 'terminal-logs';

    public static function getModelLabel(): string
    {
        return __('web-terminal-stream::terminal.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('web-terminal-stream::terminal.resource.plural_label');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return WebTerminalStreamPlugin::current()?->getTerminalLogsNavigationIcon()
            ?? 'heroicon-o-clipboard-document-list';
    }

    public static function getNavigationLabel(): string
    {
        return WebTerminalStreamPlugin::current()?->getTerminalLogsNavigationLabel()
            ?? __('web-terminal-stream::terminal.navigation.terminal_logs');
    }

    public static function getNavigationGroup(): ?string
    {
        return WebTerminalStreamPlugin::current()?->getTerminalLogsNavigationGroup()
            ?? __('web-terminal-stream::terminal.navigation.tools');
    }

    public static function getNavigationSort(): ?int
    {
        return WebTerminalStreamPlugin::current()?->getTerminalLogsNavigationSort()
            ?? 101;
    }

    public static function infolist(Schema $schema): Schema
    {
        return TerminalLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TerminalLogsTable::configure($table);
    }

    /**
     * Scope the log list to the current tenant.
     *
     * When multi-tenancy is configured (logging.tenant_column + a resolver),
     * a tenant must never see another tenant's terminal history. Resolution
     * goes through the same TerminalLogger authority that writes the rows.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $tenantColumn = config('web-terminal-stream.logging.tenant_column');

        if ($tenantColumn) {
            // Fresh instance so it resolves against current config, not the
            // snapshot the singleton captured at boot.
            $tenantId = (new TerminalLogger)->resolveTenantId();

            if ($tenantId !== null) {
                $query->where($tenantColumn, $tenantId);
            }
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getWidgets(): array
    {
        return [
            TerminalLogsStatsOverview::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTerminalLogs::route('/'),
            'view' => ViewTerminalLog::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }
}
