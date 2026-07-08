<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use MWGuerra\WebTerminalStream\Models\TerminalLog;

class TerminalLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)->columnSpanFull()->schema([
                    Grid::make(1)->schema([
                        Section::make(__('web-terminal-stream::terminal.infolist.event_information'))
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        TextEntry::make('event_type')
                                            ->label(__('web-terminal-stream::terminal.infolist.event_type'))
                                            ->badge()
                                            ->color(fn (string $state): string => match ($state) {
                                                TerminalLog::EVENT_CONNECTED => 'success',
                                                TerminalLog::EVENT_DISCONNECTED => 'warning',
                                                default => 'gray',
                                            })
                                            ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                                        TextEntry::make('connection_type')
                                            ->label(__('web-terminal-stream::terminal.infolist.connection_type'))
                                            ->badge()
                                            ->color(fn (string $state): string => match ($state) {
                                                TerminalLog::CONNECTION_LOCAL => 'primary',
                                                TerminalLog::CONNECTION_SSH => 'warning',
                                                default => 'gray',
                                            })
                                            ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                                    ]),
                            ]),

                        Section::make(__('web-terminal-stream::terminal.infolist.user_session'))
                            ->icon('heroicon-o-user')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        TextEntry::make('user.name')
                                            ->label(__('web-terminal-stream::terminal.infolist.user'))
                                            ->placeholder(__('web-terminal-stream::terminal.table.system')),

                                        TextEntry::make('terminal_identifier')
                                            ->label(__('web-terminal-stream::terminal.infolist.terminal_identifier'))
                                            ->fontFamily('mono')
                                            ->placeholder('—'),
                                    ]),

                                TextEntry::make('terminal_session_id')
                                    ->label(__('web-terminal-stream::terminal.infolist.session_id'))
                                    ->fontFamily('mono')
                                    ->copyable()
                                    ->copyMessage(__('web-terminal-stream::terminal.infolist.session_id_copied')),
                            ]),

                        Section::make(__('web-terminal-stream::terminal.infolist.client_information'))
                            ->icon('heroicon-o-computer-desktop')
                            ->collapsed()
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        TextEntry::make('ip_address')
                                            ->label(__('web-terminal-stream::terminal.infolist.ip_address'))
                                            ->fontFamily('mono')
                                            ->placeholder('—'),

                                        TextEntry::make('user_agent')
                                            ->label(__('web-terminal-stream::terminal.infolist.user_agent'))
                                            ->limit(80)
                                            ->tooltip(fn ($record): ?string => $record->user_agent)
                                            ->placeholder('—'),
                                    ]),
                            ]),

                    ]),

                    Grid::make(1)->schema([
                        Section::make(__('web-terminal-stream::terminal.infolist.timing'))
                            ->icon('heroicon-o-clock')
                            ->schema([
                                TextEntry::make('created_at')
                                    ->label(__('web-terminal-stream::terminal.infolist.timestamp'))
                                    ->dateTime('F j, Y g:i:s A'),
                            ]),

                        Section::make(__('web-terminal-stream::terminal.infolist.ssh_connection_details'))
                            ->icon('heroicon-o-server')
                            ->visible(fn ($record): bool => $record->connection_type === TerminalLog::CONNECTION_SSH)
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        TextEntry::make('host')
                                            ->label(__('web-terminal-stream::terminal.infolist.host'))
                                            ->fontFamily('mono'),

                                        TextEntry::make('port')
                                            ->label(__('web-terminal-stream::terminal.infolist.port'))
                                            ->fontFamily('mono'),

                                        TextEntry::make('ssh_username')
                                            ->label(__('web-terminal-stream::terminal.infolist.ssh_username'))
                                            ->fontFamily('mono'),
                                    ]),
                            ]),

                        Section::make(__('web-terminal-stream::terminal.infolist.metadata'))
                            ->icon('heroicon-o-code-bracket')
                            ->visible(fn ($record): bool => ! empty($record->metadata))
                            ->collapsed()
                            ->schema([
                                KeyValueEntry::make('metadata')
                                    ->label('')
                                    ->keyLabel(__('web-terminal-stream::terminal.infolist.metadata_key'))
                                    ->valueLabel(__('web-terminal-stream::terminal.infolist.metadata_value')),
                            ]),
                    ]),
                ]),
            ]);
    }
}
