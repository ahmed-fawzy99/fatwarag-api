<?php

namespace App\Filament\Resources\TranslationJobs\Tables;

use App\Models\TranslationJob;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TranslationJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('locale'),
                TextColumn::make('scope'),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('progress')
                    ->state(fn (TranslationJob $record) => $record->total > 0
                        ? sprintf('%s / %s (%d%%)', number_format($record->done), number_format($record->total), round($record->done / $record->total * 100))
                        : '-'),
                TextColumn::make('failed')->numeric()->color(fn (int $state) => $state > 0 ? 'danger' : null),
                TextColumn::make('stop_reason')->limit(50)->placeholder('-')->extraAttributes(['dir' => 'auto']),
            ])
            ->filters([
                SelectFilter::make('status')->options(['pending' => 'Pending', 'running' => 'Running', 'done' => 'Done', 'stopped' => 'Stopped']),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'running' => 'info',
            'done' => 'success',
            'stopped' => 'danger',
            default => 'gray',
        };
    }
}
