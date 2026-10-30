<?php

namespace App\Topics;

use App\Models\Source;
use App\Models\SourceCategory;
use App\Models\Topic;
use App\Support\Arabic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the shared topic tree from islamqa's topic categories (`topics/<id>`, gathered from its breadcrumbs). Each
 * islamqa category maps to its own topic, so that mapping is approved as is. Re-running adds topics for categories
 * found since and never removes one.
 *
 * Once other sources are mapped onto it, `enrich()` deepens islamqa's leaves with those sources' own subcategories
 * (islamweb and binbaz trees are deeper than islamqa's), so the tree is built from both trees.
 */
class TopicTreeBuilder
{
    public const SOURCE = 'islamqa';

    /** Keys of topics that come from islamqa itself; every other key belongs to a child topic added by `enrich()`. */
    public const ISLAMQA_KEY_PATTERN = '^topic-[0-9]+$';

    /** Subcategories that only gather "the rest" (أحكام أخرى، مسائل متفرقة…) are never a topic of their own. */
    private const CATCH_ALL_PATTERN = '/^((احكام|مسايل|قضايا|وسايل|فضايل|امور|مصطلحات|فتاوي)( فقهيه)? (اخري|متفرقه|متنوعه)|متفرقات|اخري)( |$)/u';

    /** Leading words dropped when comparing names, so «أحكام القرض» is the same topic as «القرض». */
    private const GENERIC_WORDS = ['احكام', 'حكم', 'مسايل'];

    /**
     * @return int topics in the tree
     */
    public function build(): int
    {
        $source = Source::query()->where('key', self::SOURCE)->first();

        if ($source === null) {
            return Topic::query()->count();
        }

        $categories = SourceCategory::query()
            ->where('source_id', $source->id)
            ->where('external_id', 'like', 'topics/%')
            ->get()
            ->sortBy(fn (SourceCategory $category): int => (int) substr($category->external_id, 7))
            ->keyBy('id');

        $topicIds = [];
        $position = 0;
        $place = function (SourceCategory $category) use (&$place, &$topicIds, &$position, $categories): int {
            if (isset($topicIds[$category->id])) {
                return $topicIds[$category->id];
            }

            $parent = $category->parent_id !== null ? $categories->get($category->parent_id) : null;
            $topic = Topic::query()->updateOrCreate(
                ['key' => 'topic-'.substr($category->external_id, 7)],
                ['name_ar' => $category->name, 'parent_id' => $parent !== null ? $place($parent) : null, 'position' => $position++],
            );

            if ($category->topic_id !== $topic->id || $category->mapping_status !== 'approved') {
                $category->update(['topic_id' => $topic->id, 'mapping_status' => 'approved']);
            }

            return $topicIds[$category->id] = $topic->id;
        };

        $categories->each(fn (SourceCategory $category): int => $place($category));

        return Topic::query()->count();
    }

    /**
     * Adds child topics under islamqa's leaves from the subcategories of the categories mapped to them, using the
     * source's names verbatim. A subcategory becomes a topic when it (with its own subcategories) has at least
     * `$minFatwas` fatwas and at least two siblings qualify; names that match after normalising (and dropping «ال» and
     * a leading «أحكام») are merged, and a subcategory named like its parent, or a catch-all, stays part of the parent.
     * Each such category is then pointed at its most specific topic, so its fatwas follow it there; fatwas with no
     * specific child stay on the leaf. Existing topics keep their keys; re-running reuses them.
     * Approved mappings are left where the owner put them.
     *
     * @return array{leaves: int, created: int, moved: int} leaves that have child topics, topics created, categories re-pointed
     */
    public function enrich(?int $minFatwas = null): array
    {
        $minFatwas ??= (int) config('fatwarag.topics.enrich_min_fatwas', 10);
        $result = ['leaves' => 0, 'created' => 0, 'moved' => 0];
        $topics = Topic::query()->get(['id', 'key', 'parent_id', 'name_ar', 'position'])->keyBy('id');
        $leafOf = $this->leafOwners($topics);

        if ($leafOf === []) {
            return $result;
        }

        $categories = SourceCategory::query()
            ->whereIn('topic_id', array_keys($leafOf))
            ->where('mapping_status', '!=', 'approved')
            ->whereHas('source', fn ($query) => $query->whereIn('key', (array) config('fatwarag.topics.enrich_sources', [])))
            ->with('source:id,key')
            ->get();

        $fatwaIds = [];
        foreach (DB::table('fatwa_source_category')->whereIn('source_category_id', $categories->modelKeys())->get(['source_category_id', 'fatwa_id']) as $row) {
            $fatwaIds[$row->source_category_id][] = $row->fatwa_id;
        }

        $claimed = [];
        $position = (int) $topics->filter(fn (Topic $topic): bool => self::isIslamqa($topic->key))->max('position') + 1;

        foreach ($categories->groupBy(fn (SourceCategory $category): int => $leafOf[$category->topic_id]) as $leafId => $members) {
            $members = $members->keyBy('id');
            $children = $members->groupBy(fn (SourceCategory $category): int => (int) $category->parent_id);
            $anchors = $members->filter(fn (SourceCategory $category): bool => $category->parent_id === null || ! $members->has($category->parent_id));
            $leaf = $topics->get($leafId);

            $node = $this->node($anchors->modelKeys(), self::looseName($leaf->name_ar), $children, $fatwaIds, $minFatwas);
            $targets = [];
            $this->place($node, $leaf, $topics, $targets, $claimed, $position, $result);

            if ($node['children'] !== []) {
                $result['leaves']++;
            }

            foreach ($members as $category) {
                $target = $targets[$category->id] ?? $leaf->id;

                if ($category->topic_id !== $target) {
                    $category->update(['topic_id' => $target]);
                    $result['moved']++;
                }
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, Topic>  $topics
     * @return array<int, int> topic id => its islamqa leaf, for every islamqa leaf and the child topics added under it
     */
    private function leafOwners(Collection $topics): array
    {
        $byParent = $topics->groupBy(fn (Topic $topic): int => (int) $topic->parent_id);
        $owners = [];

        foreach ($topics as $topic) {
            $children = $byParent->get($topic->id, collect());

            if (! self::isIslamqa($topic->key) || $children->contains(fn (Topic $child): bool => self::isIslamqa($child->key))) {
                continue;
            }

            $stack = [$topic->id];
            while ($stack !== []) {
                $id = array_pop($stack);
                $owners[$id] = $topic->id;

                foreach ($byParent->get($id, collect()) as $child) {
                    $stack[] = $child->id;
                }
            }
        }

        return $owners;
    }

    /**
     * Splits the categories filed under one topic into the ones that stay on it and the child topics below it.
     *
     * @param  array<int, int>  $memberIds
     * @param  Collection<int, Collection<int, SourceCategory>>  $children  parent category id => its subcategories under this leaf
     * @param  array<int, array<int, int>>  $fatwaIds
     * @return array{members: array<int, int>, children: array<int, array<string, mixed>>}
     */
    private function node(array $memberIds, string $loose, Collection $children, array $fatwaIds, int $minFatwas): array
    {
        $members = [];
        $groups = [];

        for ($queue = $memberIds; $queue !== [];) {
            $id = array_shift($queue);
            $members[] = $id;

            foreach ($children->get($id, collect()) as $child) {
                $childLoose = self::looseName($child->name);

                if ($childLoose === $loose || self::isCatchAll($child->name)) {
                    $queue[] = $child->id;
                } else {
                    $groups[$childLoose][] = $child;
                }
            }
        }

        $counts = array_map(fn (array $group): int => count($this->fatwasUnder(array_map(fn (SourceCategory $category): int => $category->id, $group), $children, $fatwaIds)), $groups);
        $qualifying = array_filter($counts, fn (int $count): bool => $count >= $minFatwas);
        $nodes = [];

        if (count($qualifying) >= 2) {
            arsort($qualifying);

            foreach ($qualifying as $groupLoose => $count) {
                $group = $groups[$groupLoose];
                unset($groups[$groupLoose]);
                usort($group, fn (SourceCategory $a, SourceCategory $b): int => [count($this->fatwasUnder([$b->id], $children, $fatwaIds)), $this->sourceRank($a)] <=> [count($this->fatwasUnder([$a->id], $children, $fatwaIds)), $this->sourceRank($b)]);

                $nodes[] = $this->node(array_map(fn (SourceCategory $category): int => $category->id, $group), (string) $groupLoose, $children, $fatwaIds, $minFatwas) + [
                    'name' => $group[0]->name,
                    'loose' => (string) $groupLoose,
                    'keys' => array_map(fn (SourceCategory $category): string => 'topic-'.$category->source->key.'-'.str_replace('/', '-', $category->external_id), $group),
                ];
            }
        }

        foreach ($groups as $group) {
            $members = [...$members, ...$this->subtree(array_map(fn (SourceCategory $category): int => $category->id, $group), $children)];
        }

        return ['members' => $members, 'children' => $nodes];
    }

    /**
     * Files a node's categories under `$topic` and creates or reuses a topic for each of its children.
     *
     * @param  array{members: array<int, int>, children: array<int, array<string, mixed>>}  $node
     * @param  Collection<int, Topic>  $topics
     * @param  array<int, int>  $targets  category id => topic id
     * @param  array<int, true>  $claimed  topics already used in this run
     * @param  array{leaves: int, created: int, moved: int}  $result
     */
    private function place(array $node, Topic $topic, Collection $topics, array &$targets, array &$claimed, int &$position, array &$result): void
    {
        foreach ($node['members'] as $id) {
            $targets[$id] = $topic->id;
        }

        foreach ($node['children'] as $child) {
            $existing = $topics->first(fn (Topic $candidate): bool => ! isset($claimed[$candidate->id]) && in_array($candidate->key, $child['keys'], true))
                ?? $topics->first(fn (Topic $candidate): bool => ! isset($claimed[$candidate->id]) && (int) $candidate->parent_id === $topic->id && ! self::isIslamqa($candidate->key) && self::looseName($candidate->name_ar) === $child['loose']);

            if ($existing !== null) {
                $existing->update(['parent_id' => $topic->id, 'name_ar' => $child['name'], 'position' => $position++]);
            } else {
                $taken = $topics->pluck('key')->all();
                $key = collect($child['keys'])->first(fn (string $key): bool => ! in_array($key, $taken, true)) ?? $child['keys'][0].'-'.$topic->id;
                $existing = Topic::query()->create(['key' => $key, 'parent_id' => $topic->id, 'name_ar' => $child['name'], 'position' => $position++]);
                $topics->put($existing->id, $existing);
                $result['created']++;
            }

            $claimed[$existing->id] = true;
            $this->place($child, $existing, $topics, $targets, $claimed, $position, $result);
        }
    }

    /**
     * @param  array<int, int>  $ids
     * @param  Collection<int, Collection<int, SourceCategory>>  $children
     * @return array<int, int> the categories and all their subcategories under this leaf
     */
    private function subtree(array $ids, Collection $children): array
    {
        $all = [];

        for ($queue = $ids; $queue !== [];) {
            $id = array_shift($queue);
            $all[] = $id;

            foreach ($children->get($id, collect()) as $child) {
                $queue[] = $child->id;
            }
        }

        return $all;
    }

    /**
     * @param  array<int, int>  $ids
     * @param  Collection<int, Collection<int, SourceCategory>>  $children
     * @param  array<int, array<int, int>>  $fatwaIds
     * @return array<int, true> distinct fatwas in the categories and their subcategories
     */
    private function fatwasUnder(array $ids, Collection $children, array $fatwaIds): array
    {
        $fatwas = [];

        foreach ($this->subtree($ids, $children) as $id) {
            foreach ($fatwaIds[$id] ?? [] as $fatwaId) {
                $fatwas[$fatwaId] = true;
            }
        }

        return $fatwas;
    }

    private function sourceRank(SourceCategory $category): int
    {
        $rank = array_search($category->source->key, (array) config('fatwarag.topics.enrich_sources', []), true);

        return $rank === false ? PHP_INT_MAX : $rank;
    }

    public static function isIslamqa(string $key): bool
    {
        return preg_match('/'.self::ISLAMQA_KEY_PATTERN.'/', $key) === 1;
    }

    private static function isCatchAll(string $name): bool
    {
        return preg_match(self::CATCH_ALL_PATTERN, Arabic::normalize($name)) === 1;
    }

    /**
     * The name used to spot near-duplicates: normalised, «ال» dropped from each word, and a leading «أحكام/حكم/مسائل» dropped.
     */
    private static function looseName(string $name): string
    {
        $words = array_map(
            fn (string $word): string => mb_strlen($word) > 4 && str_starts_with($word, 'ال') ? mb_substr($word, 2) : $word,
            explode(' ', Arabic::normalize($name)),
        );

        if (count($words) > 1 && in_array($words[0], self::GENERIC_WORDS, true)) {
            array_shift($words);
        }

        return implode(' ', $words);
    }
}
