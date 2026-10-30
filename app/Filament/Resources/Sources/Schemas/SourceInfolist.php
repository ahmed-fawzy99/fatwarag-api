<?php

namespace App\Filament\Resources\Sources\Schemas;

use App\Filament\Resources\Sources\SourceResource;
use App\Filament\Resources\Sources\Tables\SourcesTable;
use App\Models\Source;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SourceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Source')->columns(4)->schema([
                TextEntry::make('name_en'),
                TextEntry::make('name_ar')->extraAttributes(['dir' => 'auto']),
                TextEntry::make('key'),
                TextEntry::make('base_url')->url(fn (Source $record) => $record->base_url, shouldOpenInNewTab: true),
                TextEntry::make('kind'),
                TextEntry::make('tier')->numeric(),
                TextEntry::make('is_enabled')->label('Enabled')->badge()->formatStateUsing(fn (bool $state) => $state ? 'Yes' : 'No'),
            ]),
            Section::make('Collector')->columns(4)->schema([
                TextEntry::make('collectorState.status')->label('Status')->badge()->placeholder('Never run')
                    ->color(fn (?string $state) => SourcesTable::statusColor($state)),
                TextEntry::make('collectorState.items_collected')->label('Items collected')->numeric()->placeholder('0'),
                TextEntry::make('collectorState.last_checked_at')->label('Last check')->dateTime()->placeholder('Never'),
                TextEntry::make('collectorState.last_finished_at')->label('Last finished')->dateTime()->placeholder('Never'),
                TextEntry::make('collectorState.last_started_at')->label('Last started')->dateTime()->placeholder('Never'),
                TextEntry::make('collectorState.stop_reason')->label('Stop reason')->placeholder('-')->columnSpan(3)->extraAttributes(['dir' => 'auto']),
            ]),
            Section::make('Unmatched references')->columns(2)->schema([
                TextEntry::make('unresolved_links')
                    ->label('Fatwa references not collected')
                    ->state(fn (Source $record) => SourceResource::unresolvedFatwaLinks($record))
                    ->numeric(),
                TextEntry::make('unmatched_quotes')
                    ->label('Hadith quotes not found (library-wide)')
                    ->state(fn () => SourceResource::unmatchedHadithQuotes())
                    ->numeric(),
            ]),
        ])->columns(1);
    }
}
