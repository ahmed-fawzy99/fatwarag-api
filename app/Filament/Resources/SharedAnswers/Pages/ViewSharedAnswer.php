<?php

namespace App\Filament\Resources\SharedAnswers\Pages;

use App\Filament\Resources\SharedAnswers\SharedAnswerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSharedAnswer extends ViewRecord
{
    protected static string $resource = SharedAnswerResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
