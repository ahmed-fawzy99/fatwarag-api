<?php

namespace App\Filament\Resources\CommonAnswers\Schemas;

use App\Filament\Resources\CommonAnswers\Tables\CommonAnswersTable;
use App\Models\CommonAnswer;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CommonAnswerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Answer')->columns(4)->schema([
                TextEntry::make('question')->label('Question')->columnSpanFull()->extraAttributes(['dir' => 'auto'])
                    ->state(fn (CommonAnswer $record) => $record->question?->questionFor($record->locale)),
                TextEntry::make('locale'),
                TextEntry::make('status')->badge()->color(fn (string $state) => CommonAnswersTable::statusColor($state)),
                TextEntry::make('generated_at')->dateTime(),
                TextEntry::make('fatwa_ids')->label('Fatwas')->badge()->columnSpanFull(),
            ]),
            Section::make('Summary')
                ->description('The stored summary as the API serves it. Empty when the summary failed (the fatwa list is served alone).')
                ->schema([
                    TextEntry::make('summary')
                        ->hiddenLabel()
                        ->placeholder('No summary')
                        ->state(fn (CommonAnswer $record) => $record->summary === null ? null : json_encode($record->summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                        ->extraAttributes(['dir' => 'auto', 'class' => 'whitespace-pre-wrap font-mono text-xs']),
                ]),
        ])->columns(1);
    }
}
