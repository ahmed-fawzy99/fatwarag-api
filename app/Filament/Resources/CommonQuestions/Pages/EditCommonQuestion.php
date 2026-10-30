<?php

namespace App\Filament\Resources\CommonQuestions\Pages;

use App\Filament\Resources\CommonQuestions\CommonQuestionResource;
use App\Filament\Resources\CommonQuestions\RegenerateAnswers;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCommonQuestion extends EditRecord
{
    protected static string $resource = CommonQuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [RegenerateAnswers::action(), DeleteAction::make()];
    }
}
