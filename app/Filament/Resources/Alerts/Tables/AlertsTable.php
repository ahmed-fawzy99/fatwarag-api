<?php

namespace App\Filament\Resources\Alerts\Tables;

use App\Models\Alert;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('last_seen_at')->dateTime()->sortable(),
                TextColumn::make('kind')->badge()->searchable(),
                TextColumn::make('message')->limit(80)->extraAttributes(['dir' => 'auto'])->searchable(),
                TextColumn::make('occurrences')->numeric()->sortable(),
                TextColumn::make('resolved_at')->dateTime()->placeholder('Open')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('open')
                    ->label('Open only')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('resolved_at'),
                        false: fn (Builder $query) => $query->whereNotNull('resolved_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                self::resolve(),
            ])
            ->toolbarActions([
                BulkAction::make('resolveSelected')
                    ->label('Resolve selected')
                    ->icon('heroicon-o-check-circle')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $records->each->update(['resolved_at' => now()])),
            ]);
    }

    public static function resolve(): Action
    {
        return Action::make('resolve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Alert $record) => $record->resolved_at === null)
            ->action(function (Alert $record, Action $action): void {
                $record->update(['resolved_at' => now()]);
                $action->success();
            })
            ->successNotificationTitle('Alert resolved');
    }
}
