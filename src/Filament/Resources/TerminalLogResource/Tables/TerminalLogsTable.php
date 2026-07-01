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
                        TerminalLog::EVENT_COMMAND => 'info',
                        TerminalLog::EVENT_OUTPUT => 'gray',
                        TerminalLog::EVENT_ERROR => 'danger',
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

                TextColumn::make('command')
                    ->label(__('web-terminal-stream::terminal.table.command'))
                    ->limit(50)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->searchable()
                    ->placeholder('—')
                    ->fontFamily('mono'),

                TextColumn::make('exit_code')
                    ->label(__('web-terminal-stream::terminal.table.exit'))
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state === 0 => 'success',
                        default => 'danger',
                    })
                    ->placeholder('—')
                    ->toggleable(),

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

                TextColumn::make('execution_time_seconds')
                    ->label(__('web-terminal-stream::terminal.table.duration'))
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state}s" : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event_type')
                    ->label(__('web-terminal-stream::terminal.filters.event_type'))
                    ->options([
                        TerminalLog::EVENT_CONNECTED => __('web-terminal-stream::terminal.events.connected'),
                        TerminalLog::EVENT_DISCONNECTED => __('web-terminal-stream::terminal.events.disconnected'),
                        TerminalLog::EVENT_COMMAND => __('web-terminal-stream::terminal.events.command'),
                        TerminalLog::EVENT_OUTPUT => __('web-terminal-stream::terminal.events.output'),
                        TerminalLog::EVENT_ERROR => __('web-terminal-stream::terminal.events.error'),
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

                Filter::make('exit_code_failed')
                    ->label(__('web-terminal-stream::terminal.filters.failed_commands_only'))
                    ->query(fn (Builder $query): Builder => $query->where('exit_code', '!=', 0)->whereNotNull('exit_code')),

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
