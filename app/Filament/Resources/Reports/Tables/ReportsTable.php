<?php

namespace App\Filament\Resources\Reports\Tables;

use App\Filament\Resources\Reports\ReportStatus;
use App\Models\Report;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('reason')->badge()->searchable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => ReportStatus::color($state))->sortable(),
                TextColumn::make('locale'),
                TextColumn::make('comment')->limit(60)->extraAttributes(['dir' => 'auto'])->searchable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ReportStatus::OPTIONS)->default('new'),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([self::statusAction('markReviewed', 'Mark reviewed', 'reviewed'), self::statusAction('markResolved', 'Mark resolved', 'resolved')]),
            ]);
    }

    public static function statusAction(string $name, string $label, string $status): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($status === 'resolved' ? 'heroicon-o-check-circle' : 'heroicon-o-eye')
            ->visible(fn (Report $record) => $record->status !== $status)
            ->action(function (Report $record, Action $action) use ($status): void {
                $record->update(['status' => $status]);
                $action->success();
            })
            ->successNotificationTitle("Report marked {$status}");
    }
}
