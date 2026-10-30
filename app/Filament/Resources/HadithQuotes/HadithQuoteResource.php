<?php

namespace App\Filament\Resources\HadithQuotes;

use App\Filament\Resources\HadithQuotes\Pages\ListHadithQuotes;
use App\Models\HadithQuote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Hadith quotes found in fatwas, most-quoted first. Defaults to the ones Dorar couldn't match.
 */
class HadithQuoteResource extends Resource
{
    protected static ?string $model = HadithQuote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Library';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Hadith quotes';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('fatwa_count', 'desc')
            ->columns([
                TextColumn::make('quote')->limit(120)->tooltip(fn (HadithQuote $record) => $record->quote)->extraAttributes(['dir' => 'auto'])->searchable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'found' => 'success',
                    'not_found' => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('fatwa_count')->label('Fatwas quoting it')->numeric()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['pending' => 'Pending', 'found' => 'Found', 'not_found' => 'Not found'])->default('not_found'),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListHadithQuotes::route('/')];
    }
}
