<?php

namespace App\Filament\Resources\CommonQuestions;

use App\Jobs\GenerateCommonAnswer;
use App\Models\CommonQuestion;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

/**
 * Queues a regeneration of a common question's answers (the real search and summary, so it costs AI money).
 */
class RegenerateAnswers
{
    public static function action(): Action
    {
        return Action::make('regenerate')
            ->label('Regenerate answers')
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalDescription('Runs the search and the summary again for both languages (a few cents of AI each). The new answers replace the stored ones in a few minutes.')
            ->action(function (CommonQuestion $record, Action $action): void {
                GenerateCommonAnswer::dispatch($record->id);
                $action->success();
            })
            ->successNotificationTitle('Regeneration queued');
    }

    public static function bulkAction(): BulkAction
    {
        return BulkAction::make('regenerateSelected')
            ->label('Regenerate answers')
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalDescription('Runs the search and the summary again for each selected question, in both languages (a few cents of AI each).')
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => $records->each(fn (CommonQuestion $record) => GenerateCommonAnswer::dispatch($record->id)));
    }
}
