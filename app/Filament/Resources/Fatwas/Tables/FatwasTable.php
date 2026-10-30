<?php

namespace App\Filament\Resources\Fatwas\Tables;

use App\Models\Source;
use App\Support\Arabic;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FatwasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // The list skips the large question/answer/search columns; the view page loads the whole row.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select(['id', 'public_id', 'source_id', 'mufti_id', 'title', 'url', 'date_gregorian', 'is_cross_reference', 'collected_at'])
                ->with(['source:id,name_en', 'mufti:id,name_ar']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('public_id')->label('ID')->copyable(),
                TextColumn::make('title')
                    ->limit(90)
                    ->tooltip(fn ($record) => $record->title)
                    ->extraAttributes(['dir' => 'auto'])
                    ->searchable(query: fn (Builder $query, string $search) => self::search($query, $search)),
                TextColumn::make('source.name_en')->label('Source'),
                TextColumn::make('mufti.name_ar')->label('Mufti')->placeholder('-')->extraAttributes(['dir' => 'auto']),
                TextColumn::make('date_gregorian')->label('Date')->date()->placeholder('-')->sortable(),
                IconColumn::make('is_cross_reference')->label('Ref only')->boolean(),
            ])
            ->filters([
                SelectFilter::make('source_id')->label('Source')->options(fn () => Source::query()->orderBy('name_en')->pluck('name_en', 'id')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /**
     * Title match as typed, or a whole-word match on the normalized text (no harakat, unified letters), the same way
     * public search sees the fatwa. The tsvector index keeps it fast on the full library.
     */
    private static function search(Builder $query, string $search): Builder
    {
        $normalized = Arabic::normalize($search);

        $escaped = addcslashes($search, '%_\\');

        return $query->where(function (Builder $query) use ($escaped, $normalized) {
            $query->where('public_id', 'ilike', $escaped)->orWhere('title', 'ilike', '%'.$escaped.'%');

            if ($normalized !== '') {
                $query->orWhereRaw("search_vector @@ plainto_tsquery('simple', ?)", [$normalized]);
            }
        });
    }
}
