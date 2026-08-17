<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SyncLogResource\Pages;
use App\Models\SyncLog;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Arr;

/**
 * Append-only audit trail written by the search / sync pipeline.
 */
class SyncLogResource extends Resource
{
    protected static ?string $model = SyncLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Sync logs';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'sync log';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('provider_code')
                    ->label('Provider')
                    ->badge()
                    ->color('gray')
                    ->placeholder('aggregate')
                    ->sortable(),
                Tables\Columns\TextColumn::make('operation')
                    ->label('Operation')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'succeeded' => 'success',
                        'failed' => 'danger',
                        'partial' => 'warning',
                        'running' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->numeric()
                    ->suffix(' ms')
                    ->placeholder('—')
                    ->alignRight()
                    ->sortable(),
                Tables\Columns\TextColumn::make('error_class')
                    ->label('Error')
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(fn (SyncLog $record): ?string => $record->error_message)
                    ->color('danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('provider_code')
                    ->label('Provider')
                    ->options(fn (): array => SyncLog::query()
                        ->whereNotNull('provider_code')
                        ->distinct()
                        ->orderBy('provider_code')
                        ->pluck('provider_code', 'provider_code')
                        ->all()),
                Tables\Filters\SelectFilter::make('operation')
                    ->label('Operation')
                    ->options([
                        'search' => 'search',
                        'catalog_sync' => 'catalog_sync',
                        'product_refresh' => 'product_refresh',
                        'health_check' => 'health_check',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'running' => 'running',
                        'succeeded' => 'succeeded',
                        'partial' => 'partial',
                        'failed' => 'failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('started_at', 'desc')
            ->poll('30s');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Run')
                ->schema([
                    Infolists\Components\TextEntry::make('provider_code')
                        ->label('Provider')
                        ->badge()
                        ->color('gray')
                        ->placeholder('aggregate'),
                    Infolists\Components\TextEntry::make('operation')
                        ->label('Operation')
                        ->badge()
                        ->color('info'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->color(fn (?string $state): string => match ($state) {
                            'succeeded' => 'success',
                            'failed' => 'danger',
                            'partial' => 'warning',
                            'running' => 'info',
                            default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('duration_ms')
                        ->label('Duration')
                        ->suffix(' ms')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('started_at')
                        ->label('Started at')
                        ->dateTime(),
                    Infolists\Components\TextEntry::make('finished_at')
                        ->label('Finished at')
                        ->dateTime()
                        ->placeholder('—'),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Request')
                ->schema([
                    Infolists\Components\KeyValueEntry::make('request_summary')
                        ->hiddenLabel()
                        ->keyLabel('Field')
                        ->valueLabel('Value')
                        ->state(fn (SyncLog $record): array => self::readableSummary($record->request_summary))
                        ->columnSpanFull(),
                ])
                ->collapsible(),

            Infolists\Components\Section::make('Response')
                ->schema([
                    Infolists\Components\KeyValueEntry::make('response_summary')
                        ->hiddenLabel()
                        ->keyLabel('Field')
                        ->valueLabel('Value')
                        ->state(fn (SyncLog $record): array => self::readableSummary($record->response_summary))
                        ->columnSpanFull(),
                ])
                ->collapsible(),

            Infolists\Components\Section::make('Failure')
                ->schema([
                    Infolists\Components\TextEntry::make('error_class')
                        ->label('Exception')
                        ->color('danger')
                        ->copyable(),
                    Infolists\Components\TextEntry::make('error_message')
                        ->label('Message')
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->visible(fn (SyncLog $record): bool => filled($record->error_class) || filled($record->error_message)),
        ]);
    }

    /**
     * Summaries are nested jsonb blobs; KeyValueEntry can only print scalars,
     * so flatten them to dotted keys and stringify the leaves.
     *
     * @param array<string, mixed>|null $summary
     * @return array<string, string>
     */
    private static function readableSummary(?array $summary): array
    {
        if (blank($summary)) {
            return [];
        }

        return array_map(static function ($value): string {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            if ($value === null) {
                return 'null';
            }

            if (is_array($value)) {
                return $value === [] ? '[]' : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            return (string) $value;
        }, Arr::dot($summary));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncLogs::route('/'),
            'view' => Pages\ViewSyncLog::route('/{record}'),
        ];
    }
}
