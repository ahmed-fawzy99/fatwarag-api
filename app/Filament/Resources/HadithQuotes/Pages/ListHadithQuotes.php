<?php

namespace App\Filament\Resources\HadithQuotes\Pages;

use App\Filament\Resources\HadithQuotes\HadithQuoteResource;
use Filament\Resources\Pages\ListRecords;

class ListHadithQuotes extends ListRecords
{
    protected static string $resource = HadithQuoteResource::class;
}
