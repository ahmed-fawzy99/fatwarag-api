<?php

namespace App\Filament\Resources\SourceCategories\Tables;

use App\Models\Source;
use App\Models\SourceCategory;
use App\Models\Topic;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SourceCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['source:id,name_en', 'topic:id,name_ar']))
            ->defaultSort('fatwa_count', 'desc')
            ->columns([
                TextColumn::make('source.name_en')->label('Source')->sortable(),
                TextColumn::make('name')->description(fn (SourceCategory $record) => $record->path)->extraAttributes(['dir' => 'auto'])->searchable(['name', 'path']),
                TextColumn::make('fatwa_count')->label('Fatwas')->numeric()->sortable()->placeholder('-'),
                TextColumn::make('topic.name_ar')->label('Mapped to')->placeholder('Unmapped')->extraAttributes(['dir' => 'auto']),
                TextColumn::make('mapping_status')->label('Status')->badge()->color(fn (string $state) => match ($state) {
                    'approved' => 'success',
                    'proposed' => 'warning',
                    default => 'gray',
                }),
            ])
            ->filters([
                SelectFilter::make('source_id')->label('Source')->options(fn () => Source::query()->orderBy('name_en')->pluck('name_en', 'id')),
                SelectFilter::make('mapping_status')->label('Status')->options(['unmapped' => 'Unmapped', 'proposed' => 'Proposed', 'approved' => 'Approved'])->default('proposed'),
            ])
            ->recordActions([
                Action::make('approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (SourceCategory $record) => $record->topic_id !== null && $record->mapping_status !== 'approved')
                    ->action(function (SourceCategory $record, Action $action): void {
                        $record->update(['mapping_status' => 'approved']);
                        $action->success();
                    })
                    ->successNotificationTitle('Mapping approved'),
                Action::make('changeTopic')
                    ->label('Change topic')
                    ->icon('heroicon-o-pencil-square')
                    ->fillForm(fn (SourceCategory $record) => ['topic_id' => $record->topic_id])
                    ->schema([
                        Select::make('topic_id')
                            ->label('Topic')
                            ->options(fn () => Topic::query()->orderBy('name_ar')->pluck('name_ar', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (SourceCategory $record, array $data, Action $action): void {
                        $record->update(['topic_id' => $data['topic_id'], 'mapping_status' => 'approved']);
                        $action->success();
                    })
                    ->successNotificationTitle('Mapping changed and approved'),
            ])
            ->toolbarActions([
                BulkAction::make('approveSelected')
                    ->label('Approve selected')
                    ->icon('heroicon-o-check')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $records->whereNotNull('topic_id')->each->update(['mapping_status' => 'approved'])),
            ]);
    }
}
