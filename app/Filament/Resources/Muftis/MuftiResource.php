<?php

namespace App\Filament\Resources\Muftis;

use App\Filament\Resources\Muftis\Pages\EditMufti;
use App\Filament\Resources\Muftis\Pages\ListMuftis;
use App\Filament\Resources\Muftis\Schemas\MuftiForm;
use App\Filament\Resources\Muftis\Tables\MuftisTable;
use App\Models\Mufti;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Mufti cards. Only the bios (and the English name) are edited here; the rest comes from the collectors.
 */
class MuftiResource extends Resource
{
    protected static ?string $model = Mufti::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Library';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name_ar';

    public static function form(Schema $schema): Schema
    {
        return MuftiForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MuftisTable::configure($table);
    }

    public static function canCreate(): bool
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
            'index' => ListMuftis::route('/'),
            'edit' => EditMufti::route('/{record}/edit'),
        ];
    }
}
