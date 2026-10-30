<?php

namespace App\Topics;

use App\Models\Source;
use App\Models\SourceCategory;
use App\Models\Topic;
use App\Services\AiSpend;
use App\Services\Alerts;
use App\Support\Arabic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Enums\Lab;
use Throwable;

/**
 * Maps every other source's categories onto the shared topic tree. A category whose name matches a topic's name is
 * mapped directly; the rest are proposed by the translation model, 50 categories per call. Every mapping is stored as
 * «proposed» for the owner to review; approved mappings are never touched. Fatwas then follow their categories' topics.
 */
class CategoryMapper
{
    private const BATCH = 50;

    public function __construct(private AiSpend $spend, private Alerts $alerts) {}

    /**
     * @param  float  $maxCost  stop asking the model once this run has cost this much (USD)
     * @return array{exact: int, ai: int, unmapped: int, cost: float}
     */
    public function map(bool $useAi = true, float $maxCost = 3.0): array
    {
        $topics = Topic::query()->get(['id', 'key', 'parent_id', 'name_ar'])->keyBy('id');
        $result = ['exact' => 0, 'ai' => 0, 'unmapped' => 0, 'cost' => 0.0];

        if ($topics->isEmpty()) {
            return $result;
        }

        $pending = SourceCategory::query()
            ->whereNull('topic_id')
            ->whereHas('source', fn ($query) => $query->where('key', '!=', TopicTreeBuilder::SOURCE)->where('kind', 'fatwa'))
            ->with('parent:id,name', 'source:id,key')
            ->get();

        $remaining = $pending->reject(function (SourceCategory $category) use ($topics, &$result): bool {
            $topic = $this->exactMatch($category, $topics);
            if ($topic === null) {
                return false;
            }

            $category->update(['topic_id' => $topic->id, 'mapping_status' => 'proposed']);
            $result['exact']++;

            return true;
        });

        if ($useAi && $remaining->isNotEmpty()) {
            $paths = $this->topicPaths($topics);

            foreach ($remaining->groupBy('source_id') as $categories) {
                foreach ($categories->chunk(self::BATCH) as $batch) {
                    if ($result['cost'] >= $maxCost) {
                        break 2;
                    }

                    [$mapped, $cost] = $this->propose($batch->values(), $paths);
                    $result['ai'] += $mapped;
                    $result['cost'] += $cost;
                }
            }
        }

        $result['unmapped'] = SourceCategory::query()->whereNull('topic_id')->whereHas('source', fn ($query) => $query->where('key', '!=', TopicTreeBuilder::SOURCE)->where('kind', 'fatwa'))->count();

        return $result;
    }

    /**
     * Rebuilds `fatwa_topic` from the fatwas' categories and those categories' topics.
     *
     * @return int fatwa–topic rows
     */
    public function syncFatwaTopics(): int
    {
        DB::transaction(function (): void {
            DB::statement(
                'delete from fatwa_topic where not exists (
                    select 1 from fatwa_source_category fsc join source_categories sc on sc.id = fsc.source_category_id
                    where fsc.fatwa_id = fatwa_topic.fatwa_id and sc.topic_id = fatwa_topic.topic_id)',
            );
            DB::statement(
                'insert into fatwa_topic (fatwa_id, topic_id)
                 select distinct fsc.fatwa_id, sc.topic_id from fatwa_source_category fsc
                 join source_categories sc on sc.id = fsc.source_category_id
                 where sc.topic_id is not null
                 on conflict do nothing',
            );
            // A fatwa filed under a child topic added by TopicTreeBuilder::enrich() leaves that topic's ancestors.
            DB::statement(
                "with recursive below(ancestor_id, topic_id) as (
                    select parent_id, id from topics where parent_id is not null and key !~ '".TopicTreeBuilder::ISLAMQA_KEY_PATTERN."'
                    union all
                    select topics.parent_id, below.topic_id from below join topics on topics.id = below.ancestor_id
                    where topics.parent_id is not null and topics.key !~ '".TopicTreeBuilder::ISLAMQA_KEY_PATTERN."'
                )
                delete from fatwa_topic using below, fatwa_topic deeper
                where fatwa_topic.topic_id = below.ancestor_id and deeper.topic_id = below.topic_id and deeper.fatwa_id = fatwa_topic.fatwa_id",
            );
        });

        return DB::table('fatwa_topic')->count();
    }

    /**
     * A topic with the same name (ignoring harakat and letter variants); with several, the one under the same parent name.
     *
     * @param  Collection<int, Topic>  $topics
     */
    private function exactMatch(SourceCategory $category, Collection $topics): ?Topic
    {
        $name = Arabic::normalize($category->name);
        $candidates = $topics->filter(fn (Topic $topic): bool => Arabic::normalize($topic->name_ar) === $name);

        if ($candidates->count() > 1 && $category->parent !== null) {
            $parentName = Arabic::normalize($category->parent->name);
            $sameParent = $candidates->first(fn (Topic $topic): bool => $topic->parent_id !== null && Arabic::normalize($topics->get($topic->parent_id)?->name_ar) === $parentName);

            if ($sameParent !== null) {
                return $sameParent;
            }
        }

        return $candidates->first();
    }

    /**
     * @param  Collection<int, Topic>  $topics
     * @return array<string, string> topic key => path
     */
    private function topicPaths(Collection $topics): array
    {
        $paths = [];

        foreach ($topics as $topic) {
            $names = [];
            for ($node = $topic; $node !== null; $node = $node->parent_id !== null ? $topics->get($node->parent_id) : null) {
                array_unshift($names, $node->name_ar);
            }
            $paths[$topic->key] = implode(' / ', $names);
        }

        return $paths;
    }

    /**
     * @param  Collection<int, SourceCategory>  $batch
     * @param  array<string, string>  $paths
     * @return array{int, float} [categories mapped, cost]
     */
    private function propose(Collection $batch, array $paths): array
    {
        $model = (string) config('fatwarag.openrouter.models.translation');
        $prompt = json_encode([
            'topics' => array_map(fn (string $key, string $path): array => ['key' => $key, 'path' => $path], array_keys($paths), $paths),
            'categories' => $batch->map(fn (SourceCategory $category): array => ['id' => $category->external_id, 'path' => $category->path ?: $category->name])->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = (new CategoryMappingAgent)->prompt($prompt, provider: Lab::OpenRouter, model: $model, timeout: 120);
        } catch (Throwable $exception) {
            $this->alerts->raise('topics', 'topics:mapping', 'Topic mapping call failed; those categories stay unmapped until the next run.', [
                'source' => $batch->first()->source->key,
                'error' => mb_substr($exception->getMessage(), 0, 300),
            ], 'Run: php artisan topics:build');

            return [0, 0.0];
        }

        $pricing = config('fatwarag.translation.pricing');
        $cost = ($response->usage->inputTokens * $pricing['input_per_million'] + $response->usage->outputTokens * $pricing['output_per_million']) / 1_000_000;
        $this->spend->record('topic_mapping', $model, $response->usage->inputTokens, $response->usage->outputTokens, $cost, countsTowardBudget: false);

        $topicIds = Topic::query()->whereIn('key', array_keys($paths))->pluck('id', 'key');
        $byExternalId = $batch->keyBy('external_id');
        $mapped = 0;

        foreach ($response['mappings'] ?? [] as $mapping) {
            $category = $byExternalId->get((string) ($mapping['category'] ?? ''));
            $topicId = $topicIds->get((string) ($mapping['topic'] ?? ''));

            if ($category !== null && $topicId !== null && $category->topic_id === null) {
                $category->update(['topic_id' => $topicId, 'mapping_status' => 'proposed']);
                $mapped++;
            }
        }

        return [$mapped, $cost];
    }
}
