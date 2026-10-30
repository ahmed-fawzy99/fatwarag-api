<?php

namespace App\Http\Controllers\Api;

use App\Content\ContentCache;
use App\Http\Controllers\Controller;
use App\Models\Fatwa;
use App\Models\Hadith;
use App\Models\SharedAnswer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SitemapController extends Controller
{
    /** GET /sitemap/fatwas?page= : every fatwa id with its last change and whether it has an English page. */
    public function fatwas(Request $request): JsonResponse
    {
        $perPage = (int) config('fatwarag.content.sitemap_per_page', 5000);
        $page = max(1, (int) $request->query('page', 1));

        return response()->json(ContentCache::remember(['fatwa-lists'], "sitemap:{$page}", fn () => $this->page($page, $perPage)));
    }

    /** GET /sitemap/hadiths?page= : every hadith whose Dorar lookup was found, with its last change. */
    public function hadiths(Request $request): JsonResponse
    {
        $perPage = (int) config('fatwarag.content.sitemap_per_page', 5000);
        $page = max(1, (int) $request->query('page', 1));

        return response()->json(ContentCache::remember(['hadith-lists'], "sitemap-hadiths:{$page}", function () use ($page, $perPage): array {
            $paginator = Hadith::query()
                ->select(['hadiths.id', 'hadiths.updated_at'])
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('hadith_quotes')->whereColumn('hadith_quotes.hadith_id', 'hadiths.id')->where('hadith_quotes.status', 'found'))
                ->orderBy('hadiths.id')
                ->paginate($perPage, page: $page);

            return [
                'data' => $paginator->getCollection()->map(fn (Hadith $hadith) => ['id' => $hadith->id, 'updatedAt' => $hadith->updated_at?->toIso8601String()])->all(),
                'meta' => ['page' => $paginator->currentPage(), 'perPage' => $perPage, 'total' => $paginator->total(), 'lastPage' => $paginator->lastPage()],
            ];
        }));
    }

    /** GET /sitemap/shares?page= : every shared answer's public id, the locale it was shared in and when. */
    public function shares(Request $request): JsonResponse
    {
        $perPage = (int) config('fatwarag.content.sitemap_per_page', 5000);
        $page = max(1, (int) $request->query('page', 1));

        // Only what a sitemap needs: never the question, the summary or the IP hash. Shares never change, so their
        // last change is when they were made.
        $paginator = SharedAnswer::query()->select(['id', 'public_id', 'locale', 'created_at'])->orderBy('id')->paginate($perPage, page: $page);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (SharedAnswer $share) => [
                'id' => $share->public_id,
                'locale' => $share->locale,
                'updatedAt' => $share->created_at?->toIso8601String(),
            ])->all(),
            'meta' => ['page' => $paginator->currentPage(), 'perPage' => $perPage, 'total' => $paginator->total(), 'lastPage' => $paginator->lastPage()],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function page(int $page, int $perPage): array
    {
        $paginator = Fatwa::query()
            ->select(['fatwas.public_id', 'fatwas.updated_at'])
            ->selectRaw("exists (select 1 from fatwa_translations ft where ft.fatwa_id = fatwas.id and ft.locale = 'en' and ft.answer is not null) as has_english")
            ->orderBy('fatwas.id')
            ->paginate($perPage, page: $page);

        return [
            'data' => $paginator->getCollection()->map(fn (Fatwa $fatwa) => [
                'id' => $fatwa->public_id,
                'updatedAt' => $fatwa->updated_at?->toIso8601String(),
                'hasEnglish' => (bool) $fatwa->has_english,
            ])->all(),
            'meta' => ['page' => $paginator->currentPage(), 'perPage' => $perPage, 'total' => $paginator->total(), 'lastPage' => $paginator->lastPage()],
        ];
    }
}
