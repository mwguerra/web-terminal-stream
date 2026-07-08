<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use MWGuerra\WebTerminalStream\Models\TerminalLog;

class TerminalLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('web-terminal-stream::terminal.table.time'))
                    ->dateTime('M d, Y H:i:s')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('event_type')
                    ->label(__('web-terminal-stream::terminal.table.event'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        TerminalLog::EVENT_CONNECTED => 'success',
                        TerminalLog::EVENT_DISCONNECTED => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('terminal_identifier')
                    ->label(__('web-terminal-stream::terminal.table.terminal'))
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('connection_type')
                    ->label(__('web-terminal-stream::terminal.table.type'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        TerminalLog::CONNECTION_LOCAL => 'primary',
                        TerminalLog::CONNECTION_SSH => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label(__('web-terminal-stream::terminal.table.user'))
                    ->searchable()
                    ->sortable()
                    ->placeholder(__('web-terminal-stream::terminal.table.system')),

                TextColumn::make('host')
                    ->label(__('web-terminal-stream::terminal.table.host'))
                    ->placeholder(__('web-terminal-stream::terminal.table.localhost'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('terminal_session_id')
                    ->label(__('web-terminal-stream::terminal.table.session_id'))
                    ->limit(8)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ip_address')
                    ->label(__('web-terminal-stream::terminal.table.ip_address'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event_type')
                    ->label(__('web-terminal-stream::terminal.filters.event_type'))
                    ->options([
                        TerminalLog::EVENT_CONNECTED => __('web-terminal-stream::terminal.events.connected'),
                        TerminalLog::EVENT_DISCONNECTED => __('web-terminal-stream::terminal.events.disconnected'),
                    ]),

                SelectFilter::make('connection_type')
                    ->label(__('web-terminal-stream::terminal.filters.connection_type'))
                    ->options([
                        TerminalLog::CONNECTION_LOCAL => __('web-terminal-stream::terminal.connection_types.local'),
                        TerminalLog::CONNECTION_SSH => __('web-terminal-stream::terminal.connection_types.ssh'),
                    ]),

                SelectFilter::make('user_id')
                    ->label(__('web-terminal-stream::terminal.filters.user'))
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('terminal_identifier')
                    ->label(__('web-terminal-stream::terminal.filters.terminal'))
                    ->options(fn () => TerminalLog::query()
                        ->whereNotNull('terminal_identifier')
                        ->distinct()
                        ->pluck('terminal_identifier', 'terminal_identifier')
                        ->toArray()
                    ),

                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')
                            ->label(__('web-terminal-stream::terminal.filters.from')),
                        DatePicker::make('until')
                            ->label(__('web-terminal-stream::terminal.filters.until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->poll('30s')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
