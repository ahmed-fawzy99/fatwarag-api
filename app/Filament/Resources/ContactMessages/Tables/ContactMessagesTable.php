<?php

namespace App\Filament\Resources\ContactMessages\Tables;

use App\Models\ContactMessage;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContactMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('name')->placeholder('-')->extraAttributes(['dir' => 'auto'])->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('message')->limit(70)->extraAttributes(['dir' => 'auto'])->searchable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'new' ? 'danger' : 'success'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['new' => 'New', 'answered' => 'Answered'])->default('new'),
            ])
            ->recordActions([
                ViewAction::make(),
                self::markAnswered(),
            ]);
    }

    public static function markAnswered(): Action
    {
        return Action::make('markAnswered')
            ->label('Mark answered')
            ->icon('heroicon-o-check-circle')
            ->visible(fn (ContactMessage $record) => $record->status !== 'answered')
            ->action(function (ContactMessage $record, Action $action): void {
                $record->update(['status' => 'answered']);
                $action->success();
            })
            ->successNotificationTitle('Marked answered');
    }
}
