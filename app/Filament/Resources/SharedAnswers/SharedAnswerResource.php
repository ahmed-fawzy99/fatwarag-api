<?php

namespace App\Filament\Resources\SharedAnswers;

use App\Filament\Resources\SharedAnswers\Pages\ListSharedAnswers;
use App\Filament\Resources\SharedAnswers\Pages\ViewSharedAnswer;
use App\Filament\Resources\SharedAnswers\Schemas\SharedAnswerInfolist;
use App\Filament\Resources\SharedAnswers\Tables\SharedAnswersTable;
use App\Models\SharedAnswer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Answers visitors chose to share (stored with their consent). They never expire; deleting one here takes its page down.
 */
class SharedAnswerResource extends Resource
{
    protected static ?string $model = SharedAnswer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Shared answers';

    public static function infolist(Schema $schema): Schema
    {
        return SharedAnswerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SharedAnswersTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = SharedAnswer::query()->count();

        return $count > 0 ? (string) $count : null;
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
            'index' => ListSharedAnswers::route('/'),
            'view' => ViewSharedAnswer::route('/{record}'),
        ];
    }
}
