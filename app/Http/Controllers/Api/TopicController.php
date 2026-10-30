<?php

namespace App\Http\Controllers\Api;

use App\Content\ContentCache;
use App\Content\ErrorResponse;
use App\Content\FatwaListPage;
use App\Content\Locale;
use App\Http\Controllers\Controller;
use App\Models\Fatwa;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TopicController extends Controller
{
    /** GET /topics : the shared topic tree; each node's fatwaCount includes its descendants (each fatwa counted once). */
    public function index(): JsonResponse
    {
        return response()->json(ContentCache::remember(['topics', 'fatwa-lists'], 'topics:tree', fn () => $this->tree()));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tree(): array
    {
        $topics = Topic::query()->orderBy('position')->orderBy('id')->get();
        $counts = $this->counts();
        $byParent = $topics->groupBy('parent_id');

        $build = function (?int $parentId) use (&$build, $byParent, $counts): array {
            return ($byParent->get($parentId) ?? collect())->map(fn (Topic $topic) => [
                'key' => $topic->key,
                'labelAr' => $topic->name_ar,
                'labelEn' => $topic->name_en,
                'children' => $build($topic->id),
                'fatwaCount' => (int) ($counts[$topic->id] ?? 0),
            ])->values()->all();
        };

        return $build(null);
    }

    /** GET /topics/{key}/fatwas?page=&locale= : the fatwas of a topic and its sub-topics. */
    public function fatwas(Request $request, string $key): JsonResponse
    {
        $locale = Locale::from($request);
        $page = max(1, (int) $request->query('page', 1));

        $payload = ContentCache::remember(['topics', 'fatwa-lists'], "topics:{$key}:{$locale}:{$page}", function () use ($key, $locale, $page): ?array {
            $topic = Topic::query()->where('key', $key)->first();

            return $topic === null ? null : $this->page($topic, $locale, $page);
        });

        return $payload === null ? ErrorResponse::notFound('Topic not found.') : response()->json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function page(Topic $topic, string $locale, int $page): array
    {
        return FatwaListPage::of(
            Fatwa::query()->whereIn('fatwas.id', DB::table('fatwa_topic')->select('fatwa_id')->whereIn('topic_id', $this->descendantIds($topic))),
            $locale,
            $page,
        );
    }

    /**
     * @return array<int, int> topic id => distinct fatwas in it and its descendants
     */
    private function counts(): array
    {
        $rows = DB::select(<<<'SQL'
            with recursive closure(ancestor_id, topic_id) as (
                select id, id from topics
                union all
                select closure.ancestor_id, topics.id from closure join topics on topics.parent_id = closure.topic_id
            )
            select closure.ancestor_id as topic_id, count(distinct fatwa_topic.fatwa_id) as total
            from closure join fatwa_topic on fatwa_topic.topic_id = closure.topic_id
            group by closure.ancestor_id
        SQL);

        return collect($rows)->pluck('total', 'topic_id')->map(fn ($total) => (int) $total)->all();
    }

    /**
     * @return array<int, int>
     */
    private function descendantIds(Topic $topic): array
    {
        $parents = Topic::query()->pluck('parent_id', 'id');
        $ids = [$topic->id];

        do {
            $found = $parents->filter(fn ($parentId, $id) => in_array($parentId, $ids, true) && ! in_array($id, $ids, true))->keys()->all();
            $ids = [...$ids, ...$found];
        } while ($found !== []);

        return $ids;
    }
}
