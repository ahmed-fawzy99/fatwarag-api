<?php

namespace App\Filament\Resources\TranslationJobs\Schemas;

use App\Filament\Resources\TranslationJobs\Tables\TranslationJobsTable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TranslationJobInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('locale'),
                TextEntry::make('scope'),
                TextEntry::make('status')->badge()->color(fn (string $state) => TranslationJobsTable::statusColor($state)),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('total')->numeric(),
                TextEntry::make('done')->numeric(),
                TextEntry::make('failed')->numeric(),
                TextEntry::make('updated_at')->dateTime(),
                TextEntry::make('stop_reason')->columnSpanFull()->placeholder('-')->extraAttributes(['dir' => 'auto']),
            ]),
        ])->columns(1);
    }
}
