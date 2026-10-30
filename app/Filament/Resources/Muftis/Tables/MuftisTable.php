<?php

namespace App\Filament\Resources\Muftis\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MuftisTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('fatwas'))
            ->defaultSort('fatwas_count', 'desc')
            ->columns([
                TextColumn::make('name_ar')->label('Name')->description(fn ($record) => $record->name_en)->extraAttributes(['dir' => 'auto'])->searchable(['name_ar', 'name_en']),
                TextColumn::make('kind')->badge(),
                TextColumn::make('fatwas_count')->label('Fatwas')->numeric()->sortable(),
                IconColumn::make('bio_ar')->label('Arabic bio')->boolean()->state(fn ($record) => filled($record->bio_ar)),
                IconColumn::make('bio_en')->label('English bio')->boolean()->state(fn ($record) => filled($record->bio_en)),
            ])
            ->filters([
                SelectFilter::make('kind')->options(['person' => 'Person', 'institution' => 'Institution']),
            ])
            ->recordActions([EditAction::make()]);
    }
}
