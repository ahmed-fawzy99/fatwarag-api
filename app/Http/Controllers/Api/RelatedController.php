<?php

namespace App\Http\Controllers\Api;

use App\Content\ContentCache;
use App\Content\ErrorResponse;
use App\Content\Locale;
use App\Http\Controllers\Controller;
use App\Http\Resources\FatwaListItemResource;
use App\Models\Fatwa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelatedController extends Controller
{
    private const LIMIT = 5;

    /** GET /fatwas/{publicId}/related?exclude=&locale= : up to five fatwas sharing the most topics with this one. */
    public function forFatwa(Request $request, string $publicId): JsonResponse
    {
        $exclude = [...Locale::list($request->query('exclude')), $publicId];
        $locale = Locale::from($request);

        $related = ContentCache::remember(['fatwa-lists', "fatwa:{$publicId}"], 'related:'.sha1(json_encode([$publicId, $exclude, $locale])), function () use ($publicId, $exclude, $locale): ?array {
            $fatwa = Fatwa::query()->with('topics:id,key')->where('public_id', $publicId)->first();

            return $fatwa === null ? null : $this->related($fatwa->topics->pluck('key')->all(), $exclude, $locale);
        });

        return $related === null ? ErrorResponse::notFound('Fatwa not found.') : response()->json($related);
    }

    /** GET /related?topicKeys=a,b&exclude=x,y&locale= : related fatwas for the topics of an answer. */
    public function byTopics(Request $request): JsonResponse
    {
        $topicKeys = Locale::list($request->query('topicKeys'));
        $exclude = Locale::list($request->query('exclude'));
        $locale = Locale::from($request);

        return response()->json(ContentCache::remember(['fatwa-lists'], 'related:'.sha1(json_encode([$topicKeys, $exclude, $locale])), fn () => $this->related($topicKeys, $exclude, $locale)));
    }

    /**
     * Most shared topics first; the source's internal tier only breaks ties, then the newest.
     *
     * @param  array<int, string>  $topicKeys
     * @param  array<int, string>  $exclude
     * @return array<int, array<string, mixed>>
     */
    private function related(array $topicKeys, array $exclude, string $locale): array
    {
        if ($topicKeys === []) {
            return [];
        }

        $fatwas = Fatwa::query()
            ->select('fatwas.*')
            ->join('fatwa_topic', 'fatwa_topic.fatwa_id', '=', 'fatwas.id')
            ->join('topics', 'topics.id', '=', 'fatwa_topic.topic_id')
            ->join('sources', 'sources.id', '=', 'fatwas.source_id')
            ->whereIn('topics.key', $topicKeys)
            ->whereNotIn('fatwas.public_id', $exclude)
            ->groupBy('fatwas.id', 'sources.tier')
            ->orderByRaw('count(*) desc')
            ->orderBy('sources.tier')
            ->orderByDesc('fatwas.id')
            ->limit(self::LIMIT)
            ->with(FatwaListItemResource::relations())
            ->get();

        return $fatwas->map(fn (Fatwa $fatwa) => FatwaListItemResource::make($fatwa)->forLocale($locale)->resolve())->all();
    }
}
