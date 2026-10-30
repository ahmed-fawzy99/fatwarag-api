<?php

namespace App\Filament\Resources\SourceCategories\Pages;

use App\Filament\Resources\SourceCategories\SourceCategoryResource;
use Filament\Resources\Pages\ListRecords;

class ListSourceCategories extends ListRecords
{
    protected static string $resource = SourceCategoryResource::class;
}
