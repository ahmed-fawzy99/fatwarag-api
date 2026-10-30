<?php

namespace App\Filament\Resources\CommonQuestions\Tables;

use App\Filament\Resources\CommonQuestions\RegenerateAnswers;
use App\Models\CommonAnswer;
use App\Models\CommonQuestion;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CommonQuestionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('answers'))
            ->defaultSort('position')
            ->reorderable('position')
            ->paginated(false)
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('question_ar')->label('Question')->description(fn (CommonQuestion $record) => $record->question_en)->wrap()->extraAttributes(['dir' => 'auto'])->searchable(['question_ar', 'question_en']),
                ToggleColumn::make('is_active')->label('Shown'),
                self::answerColumn('ar'),
                self::answerColumn('en'),
            ])
            ->recordActions([EditAction::make(), RegenerateAnswers::action(), DeleteAction::make()])
            ->toolbarActions([RegenerateAnswers::bulkAction()]);
    }

    /** The stored answer's status in one language, and when it was generated. */
    private static function answerColumn(string $locale): TextColumn
    {
        $answer = fn (CommonQuestion $record): ?CommonAnswer => $record->answers->firstWhere('locale', $locale);

        return TextColumn::make("answer_{$locale}")
            ->label('Answer ('.strtoupper($locale).')')
            ->badge()
            ->state(fn (CommonQuestion $record): string => match (true) {
                $answer($record) === null => 'none',
                default => $answer($record)->status,
            })
            ->color(fn (string $state): string => match ($state) {
                CommonAnswer::STATUS_OK => 'success',
                CommonAnswer::STATUS_FAILED => 'danger',
                default => 'gray',
            })
            ->description(fn (CommonQuestion $record): ?string => $answer($record)?->generated_at?->diffForHumans());
    }
}
