<?php

namespace App\Filament\Resources\Reports\Schemas;

use App\Filament\Resources\Reports\ReportStatus;
use App\Filament\Support\Text;
use App\Models\Report;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Report')->columns(4)->schema([
                TextEntry::make('reason')->badge(),
                TextEntry::make('status')->badge()->color(fn (string $state) => ReportStatus::color($state)),
                TextEntry::make('locale'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('comment')->columnSpanFull()->placeholder('No comment')->extraAttributes(['dir' => 'auto']),
            ]),
            Section::make('Context')
                ->description('Where it was reported, what was reported, the question, the summary shown and the device.')
                ->schema([
                    TextEntry::make('context')
                        ->hiddenLabel()
                        ->state(fn (Report $record) => Text::definitionList($record->context))
                        ->html(),
                ]),
        ])->columns(1);
    }
}
