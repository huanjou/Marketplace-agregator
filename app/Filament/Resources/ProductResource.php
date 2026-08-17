<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Arr;

/**
 * Read-only view over the canonical catalogue: rows here are written by the
 * search pipeline (ProductResultNormalizer), not by hand.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Products';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ImageColumn::make('image_url')
                    ->label('')
                    ->square()
                    ->size(40),
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(80),
                Tables\Columns\TextColumn::make('brand')
                    ->label('Brand')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('provider_products_count')
                    ->label('Offers')
                    ->counts('providerProducts')
                    ->alignRight(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('brand')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('brand')
                            ->label('Brand contains'),
                    ])
                    ->query(fn ($query, array $data) => filled($data['brand'] ?? null)
                        ? $query->whereRaw('lower(brand) like ?', ['%' . mb_strtolower($data['brand']) . '%'])
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => filled($data['brand'] ?? null)
                        ? 'Brand: ' . $data['brand']
                        : null),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()
                ->schema([
                    Infolists\Components\ImageEntry::make('image_url')
                        ->label('Image')
                        ->square()
                        ->height(160)
                        ->placeholder('—'),
                    Infolists\Components\Group::make([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Name')
                            ->weight(\Filament\Support\Enums\FontWeight::SemiBold)
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large),
                        Infolists\Components\TextEntry::make('brand')
                            ->label('Brand')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('category.name')
                            ->label('Category')
                            ->placeholder('—'),
                        Infolists\Components\IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
                    ]),
                ])
                ->columns(2),

            Infolists\Components\Section::make('Identity')
                ->schema([
                    Infolists\Components\TextEntry::make('canonical_sku')
                        ->label('Canonical SKU')
                        ->copyable()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('slug')
                        ->label('Slug')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Created')
                        ->dateTime(),
                    Infolists\Components\TextEntry::make('updated_at')
                        ->label('Updated')
                        ->dateTime(),
                ])
                ->columns(2),

            Infolists\Components\Section::make('Description')
                ->schema([
                    Infolists\Components\TextEntry::make('description')
                        ->hiddenLabel()
                        ->placeholder('No description captured.')
                        ->columnSpanFull(),
                ])
                ->collapsible(),

            Infolists\Components\Section::make('Attributes')
                ->schema([
                    Infolists\Components\KeyValueEntry::make('attributes')
                        ->hiddenLabel()
                        // Attribute blobs can nest; KeyValueEntry only prints scalars.
                        ->state(fn (Product $record): array => array_map(
                            static fn ($value): string => is_scalar($value) || $value === null
                                ? (string) $value
                                : (string) json_encode($value, JSON_UNESCAPED_UNICODE),
                            Arr::dot($record->attributes ?? [])
                        ))
                        ->columnSpanFull(),
                ])
                ->collapsed()
                ->collapsible(),
        ]);
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'view' => Pages\ViewProduct::route('/{record}'),
        ];
    }
}
