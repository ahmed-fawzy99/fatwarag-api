<?php

namespace App\Filament\Resources\SharedAnswers\Pages;

use App\Filament\Resources\SharedAnswers\SharedAnswerResource;
use Filament\Resources\Pages\ListRecords;

class ListSharedAnswers extends ListRecords
{
    protected static string $resource = SharedAnswerResource::class;
}
