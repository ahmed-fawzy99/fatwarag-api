<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Reports\Tables\ReportsTable;
use Filament\Resources\Pages\ViewRecord;

class ViewReport extends ViewRecord
{
    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ReportsTable::statusAction('markReviewed', 'Mark reviewed', 'reviewed'),
            ReportsTable::statusAction('markResolved', 'Mark resolved', 'resolved'),
        ];
    }
}
