<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProviderResource\Pages;
use App\Models\Provider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProviderResource extends Resource
{
    protected static ?string $model = Provider::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Marketplaces';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'marketplace';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identity')
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Code')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Immutable — it is the key used by the provider registry.'),
                    Forms\Components\TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(160),
                    Forms\Components\TextInput::make('provider_class')
                        ->label('Implementation')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make('Capabilities')
                ->schema([
                    Forms\Components\Toggle::make('enabled')
                        ->label('Enabled')
                        ->helperText('Disabled marketplaces are skipped by the search fan-out.'),
                    Forms\Components\Toggle::make('supports_realtime_search')
                        ->label('Supports realtime search'),
                    Forms\Components\Toggle::make('supports_catalog_sync')
                        ->label('Supports catalog sync'),
                ])
                ->columns(3),

            Forms\Components\Section::make('Limits')
                ->schema([
                    Forms\Components\TextInput::make('rate_limit_per_minute')
                        ->label('Rate limit (req/min)')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Leave empty for no client-side limit.'),
                    Forms\Components\TextInput::make('cache_ttl_seconds')
                        ->label('Cache TTL (seconds)')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                ])
                ->columns(2),

            Forms\Components\Section::make('Health')
                ->schema([
                    Forms\Components\Placeholder::make('last_health_status')
                        ->label('Last health status')
                        ->content(fn (?Provider $record): string => $record?->last_health_status ?? 'unknown'),
                    Forms\Components\Placeholder::make('last_checked_at')
                        ->label('Last checked at')
                        ->content(fn (?Provider $record): string => $record?->last_checked_at?->toDayDateTimeString() ?? 'never'),
                    Forms\Components\Placeholder::make('credentials')
                        ->label('Credentials')
                        ->content('API keys and secrets are read from the environment only (see config/marketplace.php); they are never stored in or edited from this panel.')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('enabled')
                    ->label('Enabled'),
                Tables\Columns\IconColumn::make('supports_realtime_search')
                    ->label('Realtime')
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('supports_catalog_sync')
                    ->label('Catalog sync')
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_health_status')
                    ->label('Health')
                    ->badge()
                    ->placeholder('unknown')
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'down' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('last_checked_at')
                    ->label('Checked')
                    ->dateTime()
                    ->since()
                    ->placeholder('never')
                    ->sortable(),
                Tables\Columns\TextColumn::make('rate_limit_per_minute')
                    ->label('Rate limit')
                    ->numeric()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Enabled'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('code')
            ->paginated([10, 25, 50]);
    }

    public static function canCreate(): bool
    {
        // Rows are seeded from config/marketplace.php, never hand-created.
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviders::route('/'),
            'edit' => Pages\EditProvider::route('/{record}/edit'),
        ];
    }
}
