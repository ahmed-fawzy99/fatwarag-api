<?php

namespace App\Filament\Resources\Fatwas;

use App\Filament\Resources\Fatwas\Pages\ListFatwas;
use App\Filament\Resources\Fatwas\Pages\ViewFatwa;
use App\Filament\Resources\Fatwas\Schemas\FatwaInfolist;
use App\Filament\Resources\Fatwas\Tables\FatwasTable;
use App\Models\Fatwa;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Collected fatwas, read-only: the content is the sources' words and is never edited here.
 */
class FatwaResource extends Resource
{
    protected static ?string $model = Fatwa::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Library';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function infolist(Schema $schema): Schema
    {
        return FatwaInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FatwasTable::configure($table);
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
        return [
            'index' => ListFatwas::route('/'),
            'view' => ViewFatwa::route('/{record}'),
        ];
    }
}
