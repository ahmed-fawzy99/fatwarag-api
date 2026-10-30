<?php

namespace App\Filament\Resources\Reports;

/**
 * The report workflow: new, then reviewed, then resolved.
 */
final class ReportStatus
{
    public const OPTIONS = ['new' => 'New', 'reviewed' => 'Reviewed', 'resolved' => 'Resolved'];

    public static function color(string $status): string
    {
        return match ($status) {
            'new' => 'danger',
            'reviewed' => 'warning',
            default => 'success',
        };
    }
}
