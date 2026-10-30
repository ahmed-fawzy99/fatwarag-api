<?php

namespace App\Filament\Resources\CommonAnswers\Tables;

use App\Jobs\GenerateCommonAnswer;
use App\Models\CommonAnswer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CommonAnswersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('question'))
            ->defaultSort('common_question_id')
            ->columns([
                TextColumn::make('question.question_ar')->label('Question')->wrap()->extraAttributes(['dir' => 'auto'])
                    ->state(fn (CommonAnswer $record) => $record->question?->questionFor($record->locale)),
                TextColumn::make('locale')->badge(),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('fatwa_ids')->label('Fatwas')->state(fn (CommonAnswer $record) => count($record->fatwa_ids))->numeric(),
                TextColumn::make('generated_at')->dateTime()->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('locale')->options(['ar' => 'Arabic', 'en' => 'English']),
                SelectFilter::make('status')->options([CommonAnswer::STATUS_OK => 'OK', CommonAnswer::STATUS_FAILED => 'Failed']),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('regenerate')
                    ->label('Regenerate')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription('Runs the search and the summary again for this language (a few cents of AI).')
                    ->action(function (CommonAnswer $record, Action $action): void {
                        GenerateCommonAnswer::dispatch($record->common_question_id, [$record->locale]);
                        $action->success();
                    })
                    ->successNotificationTitle('Regeneration queued'),
                DeleteAction::make(),
            ]);
    }

    public static function statusColor(string $status): string
    {
        return $status === CommonAnswer::STATUS_OK ? 'success' : 'danger';
    }
}
