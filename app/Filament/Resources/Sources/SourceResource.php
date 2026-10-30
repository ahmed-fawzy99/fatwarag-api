<?php

namespace App\Filament\Resources\Sources;

use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\Pages\ViewSource;
use App\Filament\Resources\Sources\Schemas\SourceInfolist;
use App\Filament\Resources\Sources\Tables\SourcesTable;
use App\Models\FatwaLink;
use App\Models\HadithQuote;
use App\Models\Source;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Library';

    protected static ?int $navigationSort = 1;

    public static function infolist(Schema $schema): Schema
    {
        return SourceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SourcesTable::configure($table);
    }

    /**
     * «وانظر الفتوى رقم…» references from this source's fatwas whose target hasn't been collected.
     */
    public static function unresolvedFatwaLinks(Source $source): int
    {
        return FatwaLink::query()
            ->whereNull('target_fatwa_id')
            ->whereHas('fatwa', fn ($query) => $query->where('source_id', $source->id))
            ->count();
    }

    /**
     * Quoted hadiths the Dorar lookup didn't find. Quotes aren't tied to one source (a quote is shared by every fatwa
     * that makes it), so this is a library-wide figure.
     */
    public static function unmatchedHadithQuotes(): int
    {
        return HadithQuote::query()->where('status', 'not_found')->count();
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
            'index' => ListSources::route('/'),
            'view' => ViewSource::route('/{record}'),
        ];
    }
}
