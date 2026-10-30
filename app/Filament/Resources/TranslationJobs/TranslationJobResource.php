<?php

namespace App\Filament\Resources\TranslationJobs;

use App\Filament\Resources\TranslationJobs\Pages\ListTranslationJobs;
use App\Filament\Resources\TranslationJobs\Pages\ViewTranslationJob;
use App\Filament\Resources\TranslationJobs\Schemas\TranslationJobInfolist;
use App\Filament\Resources\TranslationJobs\Tables\TranslationJobsTable;
use App\Models\TranslationJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class TranslationJobResource extends Resource
{
    protected static ?string $model = TranslationJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    public static function infolist(Schema $schema): Schema
    {
        return TranslationJobInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TranslationJobsTable::configure($table);
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
            'index' => ListTranslationJobs::route('/'),
            'view' => ViewTranslationJob::route('/{record}'),
        ];
    }
}
