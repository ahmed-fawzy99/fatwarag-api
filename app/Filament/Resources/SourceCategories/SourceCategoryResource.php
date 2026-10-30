<?php

namespace App\Filament\Resources\SourceCategories;

use App\Filament\Resources\SourceCategories\Pages\ListSourceCategories;
use App\Filament\Resources\SourceCategories\Tables\SourceCategoriesTable;
use App\Models\SourceCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Each source's own category tree, mapped onto the shared topics. AI proposals are applied right away and reviewed here.
 */
class SourceCategoryResource extends Resource
{
    protected static ?string $model = SourceCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Library';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Category mapping';

    protected static ?string $modelLabel = 'category mapping';

    public static function table(Table $table): Table
    {
        return SourceCategoriesTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $proposed = SourceCategory::query()->where('mapping_status', 'proposed')->count();

        return $proposed > 0 ? (string) $proposed : null;
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
        return ['index' => ListSourceCategories::route('/')];
    }
}
