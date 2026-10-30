<?php

namespace App\Filament\Resources\CommonQuestions;

use App\Filament\Resources\CommonQuestions\Pages\CreateCommonQuestion;
use App\Filament\Resources\CommonQuestions\Pages\EditCommonQuestion;
use App\Filament\Resources\CommonQuestions\Pages\ListCommonQuestions;
use App\Filament\Resources\CommonQuestions\Schemas\CommonQuestionForm;
use App\Filament\Resources\CommonQuestions\Tables\CommonQuestionsTable;
use App\Models\CommonQuestion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The home page's common questions («أسئلة شائعة»). Their answers are generated once (`common:generate`), kept for good, and
 * served at once when a visitor asks exactly one of them; rewording a question drops its stored answer.
 */
class CommonQuestionResource extends Resource
{
    protected static ?string $model = CommonQuestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Library';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'question_ar';

    public static function form(Schema $schema): Schema
    {
        return CommonQuestionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CommonQuestionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommonQuestions::route('/'),
            'create' => CreateCommonQuestion::route('/create'),
            'edit' => EditCommonQuestion::route('/{record}/edit'),
        ];
    }
}
