<?php

namespace App\Filament\Resources\SharedAnswers\Tables;

use App\Http\Controllers\Api\ShareController;
use App\Models\SharedAnswer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SharedAnswersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('question')->limit(80)->wrap()->extraAttributes(['dir' => 'auto'])->searchable(),
                TextColumn::make('locale')->badge(),
                TextColumn::make('fatwa_ids')->label('Fatwas')->state(fn (SharedAnswer $record) => count($record->fatwa_ids))->numeric(),
                IconColumn::make('has_summary')->label('Summary')->boolean()->state(fn (SharedAnswer $record) => $record->summary !== null),
                TextColumn::make('public_id')->label('Id')->copyable()->fontFamily('mono'),
            ])
            ->filters([
                SelectFilter::make('locale')->options(['ar' => 'Arabic', 'en' => 'English']),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('open')
                    ->label('Open page')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (SharedAnswer $record) => ShareController::url($record), shouldOpenInNewTab: true),
                DeleteAction::make(),
            ]);
    }
}
