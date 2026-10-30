<?php

namespace App\Filament\Resources\Sources\Tables;

use App\Filament\Resources\Sources\SourceResource;
use App\Models\Source;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('collectorState')->withCount('fatwas'))
            ->defaultSort('name_en')
            ->paginated(false)
            ->columns([
                TextColumn::make('name_en')->label('Source')->description(fn (Source $record) => $record->name_ar)->searchable(),
                TextColumn::make('kind')->badge(),
                TextColumn::make('tier')->numeric(),
                IconColumn::make('is_enabled')->label('Enabled')->boolean(),
                TextColumn::make('collectorState.status')->label('Collector')->badge()->placeholder('Never run')
                    ->color(fn (?string $state) => self::statusColor($state)),
                TextColumn::make('fatwas_count')->label('Fatwas')->numeric(),
                TextColumn::make('collectorState.items_collected')->label('Items collected')->numeric()->placeholder('0'),
                TextColumn::make('collectorState.last_checked_at')->label('Last check')->dateTime()->since()->placeholder('Never'),
                TextColumn::make('unresolved_links')->label('Unresolved refs')
                    ->state(fn (Source $record) => SourceResource::unresolvedFatwaLinks($record))
                    ->numeric(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'running' => 'info',
            'done' => 'success',
            'stopped' => 'danger',
            default => 'gray',
        };
    }
}
