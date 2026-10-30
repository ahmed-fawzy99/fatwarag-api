<?php

namespace App\Content;

use App\Http\Resources\FatwaListItemResource;
use App\Models\Fatwa;
use Illuminate\Database\Eloquent\Builder;

/**
 * One page of FatwaListItems, newest first, in the {data, meta} shape shared by topic and mufti lists.
 */
class FatwaListPage
{
    /**
     * @param  Builder<Fatwa>  $query
     * @return array<string, mixed>
     */
    public static function of(Builder $query, string $locale, int $page): array
    {
        $perPage = (int) config('fatwarag.content.topic_per_page', 20);

        $paginator = $query
            ->orderByRaw('fatwas.date_gregorian desc nulls last')
            ->orderByDesc('fatwas.id')
            ->with(FatwaListItemResource::relations())
            ->paginate($perPage, page: $page);

        return [
            'data' => $paginator->getCollection()->map(fn (Fatwa $fatwa) => FatwaListItemResource::make($fatwa)->forLocale($locale)->resolve())->all(),
            'meta' => ['page' => $paginator->currentPage(), 'perPage' => $perPage, 'total' => $paginator->total(), 'lastPage' => $paginator->lastPage()],
        ];
    }
}
