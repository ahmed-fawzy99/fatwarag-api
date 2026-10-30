<?php

namespace App\Filament\Resources\Topics\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TopicsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('parent:id,name_ar')->withCount('fatwas'))
            ->defaultSort('position')
            ->columns([
                TextColumn::make('name_ar')->label('Name')->description(fn ($record) => $record->name_en)->extraAttributes(['dir' => 'auto'])->searchable(['name_ar', 'name_en', 'key']),
                TextColumn::make('parent.name_ar')->label('Parent')->placeholder('-')->extraAttributes(['dir' => 'auto']),
                TextColumn::make('fatwas_count')->label('Fatwas')->numeric()->sortable(),
                TextColumn::make('position')->sortable(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
