<?php

namespace App\Filament\Resources\CommonAnswers;

use App\Filament\Resources\CommonAnswers\Pages\ListCommonAnswers;
use App\Filament\Resources\CommonAnswers\Pages\ViewCommonAnswer;
use App\Filament\Resources\CommonAnswers\Schemas\CommonAnswerInfolist;
use App\Filament\Resources\CommonAnswers\Tables\CommonAnswersTable;
use App\Models\CommonAnswer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The stored answers to the common questions, to check what a visitor gets. Deleting one makes its question searched
 * live until it is regenerated.
 */
class CommonAnswerResource extends Resource
{
    protected static ?string $model = CommonAnswer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Library';

    protected static ?int $navigationSort = 7;

    public static function infolist(Schema $schema): Schema
    {
        return CommonAnswerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CommonAnswersTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommonAnswers::route('/'),
            'view' => ViewCommonAnswer::route('/{record}'),
        ];
    }
}
