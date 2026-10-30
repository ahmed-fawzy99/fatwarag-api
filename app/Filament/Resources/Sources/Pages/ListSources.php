<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\SourceResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListSources extends ListRecords
{
    protected static string $resource = SourceResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Hadith quotes not found in Dorar (library-wide): '.number_format(SourceResource::unmatchedHadithQuotes());
    }
}
