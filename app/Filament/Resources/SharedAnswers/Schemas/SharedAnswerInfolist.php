<?php

namespace App\Filament\Resources\SharedAnswers\Schemas;

use App\Http\Controllers\Api\ShareController;
use App\Models\SharedAnswer;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SharedAnswerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Shared answer')->columns(4)->schema([
                TextEntry::make('question')->columnSpanFull()->extraAttributes(['dir' => 'auto']),
                TextEntry::make('follow_up')->label('Follow-up it came from')->columnSpanFull()->placeholder('None')->extraAttributes(['dir' => 'auto']),
                TextEntry::make('locale'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('public_id')->label('Id')->copyable(),
                TextEntry::make('page')->label('Page')->state(fn (SharedAnswer $record) => ShareController::url($record))
                    ->url(fn (SharedAnswer $record) => ShareController::url($record), shouldOpenInNewTab: true),
                TextEntry::make('fatwa_ids')->label('Fatwas')->badge()->columnSpanFull(),
            ]),
            Section::make('Summary')
                ->description('The summary as it was shown when the answer was shared. Empty when there was none (the list alone is shown).')
                ->schema([
                    TextEntry::make('summary')
                        ->hiddenLabel()
                        ->placeholder('No summary')
                        ->state(fn (SharedAnswer $record) => $record->summary === null ? null : json_encode($record->summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                        ->extraAttributes(['dir' => 'auto', 'class' => 'whitespace-pre-wrap font-mono text-xs']),
                ]),
        ])->columns(1);
    }
}
