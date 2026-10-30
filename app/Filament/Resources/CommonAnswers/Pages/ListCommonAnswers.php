<?php

namespace App\Filament\Resources\CommonAnswers\Pages;

use App\Filament\Resources\CommonAnswers\CommonAnswerResource;
use Filament\Resources\Pages\ListRecords;

class ListCommonAnswers extends ListRecords
{
    protected static string $resource = CommonAnswerResource::class;
}
