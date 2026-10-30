<?php

namespace App\Filament\Resources\CommonQuestions\Pages;

use App\Filament\Resources\CommonQuestions\CommonQuestionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCommonQuestions extends ListRecords
{
    protected static string $resource = CommonQuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
