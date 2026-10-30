<?php

namespace Tests\Feature\Topics;

use App\Models\Fatwa;
use App\Models\Source;
use App\Models\SourceCategory;
use App\Models\Topic;
use App\Topics\CategoryMapper;
use App\Topics\TopicTreeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicTreeBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Source $islamweb;

    private Topic $politics;

    private SourceCategory $politicsCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->islamweb = Source::query()->create(['key' => 'islamweb', 'kind' => 'fatwa', 'tier' => 1, 'name_ar' => 'إسلام ويب', 'name_en' => 'Islamweb', 'base_url' => 'https://www.islamweb.net']);
        $this->politics = Topic::query()->create(['key' => 'topic-269', 'name_ar' => 'السياسة الشرعية']);
        $this->politicsCategory = $this->category($this->islamweb, '950', 'سياسة شرعية');
    }

    private function category(Source $source, string $externalId, string $name, ?SourceCategory $parent = null, string $status = 'proposed', ?Topic $topic = null): SourceCategory
    {
        return SourceCategory::query()->create([
            'source_id' => $source->id, 'external_id' => $externalId, 'name' => $name, 'parent_id' => $parent?->id,
            'path' => $parent !== null ? "{$parent->name} / {$name}" : $name, 'topic_id' => ($topic ?? $this->politics)->id, 'mapping_status' => $status,
        ]);
    }

    /**
     * @return array<int, Fatwa>
     */
    private function fatwas(SourceCategory $category, int $count): array
    {
        return array_map(function () use ($category): Fatwa {
            $id = (string) fake()->unique()->numberBetween(1, 1_000_000);
            $fatwa = Fatwa::query()->create([
                'public_id' => "{$category->source->key}-{$id}", 'source_id' => $category->source_id, 'source_fatwa_id' => $id, 'url' => "https://example.test/{$id}",
                'title' => fake()->sentence(), 'question' => [], 'answer' => [], 'search_text' => 'نص',
            ]);
            $fatwa->categories()->attach($category);

            return $fatwa;
        }, range(1, $count));
    }

    public function test_islamqa_tree_becomes_shared_tree(): void
    {
        $islamqa = Source::query()->create(['key' => 'islamqa', 'kind' => 'fatwa', 'tier' => 1, 'name_ar' => 'الإسلام سؤال وجواب', 'name_en' => 'IslamQA', 'base_url' => 'https://islamqa.info']);
        $fiqh = SourceCategory::query()->create(['source_id' => $islamqa->id, 'external_id' => 'topics/55', 'name' => 'الفقه وأصوله', 'path' => 'الفقه وأصوله']);
        $zakah = SourceCategory::query()->create(['source_id' => $islamqa->id, 'external_id' => 'topics/99', 'name' => 'الزكاة', 'path' => 'الفقه وأصوله / الزكاة', 'parent_id' => $fiqh->id]);
        SourceCategory::query()->create(['source_id' => $islamqa->id, 'external_id' => 'topics/103', 'name' => 'زكاة الفطر', 'path' => 'الفقه وأصوله / الزكاة / زكاة الفطر', 'parent_id' => $zakah->id]);

        app(TopicTreeBuilder::class)->build();
        app(TopicTreeBuilder::class)->build();

        $this->assertSame(4, Topic::query()->count());
        $leaf = Topic::query()->where('name_ar', 'زكاة الفطر')->sole();
        $this->assertSame('topic-103', $leaf->key);
        $this->assertSame('topic-99', $leaf->parent->key);
        $this->assertNull(Topic::query()->where('key', 'topic-55')->value('parent_id'));

        $mapped = SourceCategory::query()->where('external_id', 'topics/103')->sole();
        $this->assertSame($leaf->id, $mapped->topic_id);
        $this->assertSame('approved', $mapped->mapping_status);
    }

    public function test_leaf_is_enriched_with_another_sources_subcategories(): void
    {
        $binbaz = Source::query()->create(['key' => 'binbaz', 'kind' => 'fatwa', 'tier' => 1, 'name_ar' => 'ابن باز', 'name_en' => 'Bin Baz', 'base_url' => 'https://binbaz.org.sa']);
        $this->fatwas($this->category($this->islamweb, '954', 'البيعة والإمامة والولاية', $this->politicsCategory), 12);
        $this->fatwas($this->category($this->islamweb, '962', 'الشُّورى في الإسلام', $this->politicsCategory), 6);
        $binbazRoot = $this->category($binbaz, 'fiqhi/195', 'السياسة الشرعية');
        $this->fatwas($this->category($binbaz, 'fiqhi/900', 'الشورى في الاسلام', $binbazRoot), 5);

        $result = app(TopicTreeBuilder::class)->enrich(10);

        $this->assertSame(['leaves' => 1, 'created' => 2, 'moved' => 3], $result);
        $children = $this->politics->children()->get();
        $this->assertSame(['البيعة والإمامة والولاية', 'الشُّورى في الإسلام'], $children->pluck('name_ar')->sort()->values()->all());
        $shura = $children->firstWhere('name_ar', 'الشُّورى في الإسلام');
        $this->assertSame('topic-islamweb-962', $shura->key);
        $this->assertSame($shura->id, SourceCategory::query()->where('external_id', 'fiqhi/900')->value('topic_id'));
        $this->assertSame('proposed', SourceCategory::query()->where('external_id', 'fiqhi/900')->value('mapping_status'));
    }

    public function test_topic_keys_stay_stable_on_rebuild(): void
    {
        $this->fatwas($this->category($this->islamweb, '954', 'البيعة والإمامة والولاية', $this->politicsCategory), 10);
        $this->fatwas($this->category($this->islamweb, '956', 'سلطات الإمام', $this->politicsCategory), 10);

        app(TopicTreeBuilder::class)->enrich(10);
        $before = Topic::query()->orderBy('id')->pluck('key', 'id')->all();

        $this->fatwas($this->category($this->islamweb, '958', 'نظام الحكم في الإسلام', $this->politicsCategory), 10);
        $second = app(TopicTreeBuilder::class)->enrich(10);
        $after = Topic::query()->orderBy('id')->pluck('key', 'id')->all();

        $this->assertSame(1, $second['created']);
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertSame($after, Topic::query()->orderBy('id')->pluck('key', 'id')->all());
        $this->assertSame(0, app(TopicTreeBuilder::class)->enrich(10)['moved']);
    }

    public function test_fatwas_move_to_the_specific_child_and_the_rest_stay_on_the_parent(): void
    {
        $general = $this->fatwas($this->politicsCategory, 3);
        $bayah = $this->fatwas($this->category($this->islamweb, '954', 'البيعة والإمامة والولاية', $this->politicsCategory), 10);
        $this->fatwas($this->category($this->islamweb, '956', 'سلطات الإمام', $this->politicsCategory), 10);
        $other = $this->fatwas($this->category($this->islamweb, '970', 'أحكام أخرى', $this->politicsCategory), 15);
        $bayah[0]->categories()->attach($this->politicsCategory);

        app(TopicTreeBuilder::class)->enrich(10);
        app(CategoryMapper::class)->syncFatwaTopics();

        $child = Topic::query()->where('key', 'topic-islamweb-954')->sole();
        $this->assertSame([$child->id], $bayah[0]->topics()->pluck('topics.id')->all());
        $this->assertSame([$child->id], $bayah[1]->topics()->pluck('topics.id')->all());
        $this->assertSame([$this->politics->id], $general[0]->topics()->pluck('topics.id')->all());
        $this->assertSame([$this->politics->id], $other[0]->topics()->pluck('topics.id')->all());
        $this->assertSame(2, $this->politics->children()->count());
    }

    public function test_subcategories_below_the_threshold_stay_on_the_parent(): void
    {
        $this->fatwas($this->category($this->islamweb, '954', 'البيعة والإمامة والولاية', $this->politicsCategory), 10);
        $this->fatwas($this->category($this->islamweb, '956', 'سلطات الإمام', $this->politicsCategory), 10);
        $small = $this->category($this->islamweb, '960', 'تقنين الشريعة', $this->politicsCategory);
        $this->fatwas($small, 9);

        app(TopicTreeBuilder::class)->enrich(10);

        $this->assertSame(2, $this->politics->children()->count());
        $this->assertSame($this->politics->id, $small->fresh()->topic_id);

        app(TopicTreeBuilder::class)->enrich(11);
        $this->assertSame($this->politics->id, SourceCategory::query()->where('external_id', '954')->value('topic_id'));
    }

    public function test_a_single_qualifying_subcategory_or_one_named_like_its_parent_adds_no_child(): void
    {
        $loans = Topic::query()->create(['key' => 'topic-157', 'name_ar' => 'القرض']);
        $root = $this->category($this->islamweb, '2000', 'القرض', topic: $loans);
        $this->fatwas($this->category($this->islamweb, '2001', 'أحكام القرض', $root, topic: $loans), 30);
        $this->fatwas($this->category($this->islamweb, '2002', 'أركان القرض', $root, topic: $loans), 12);

        app(TopicTreeBuilder::class)->enrich(10);

        $this->assertSame(0, $loans->children()->count());
        $this->assertSame([$loans->id], SourceCategory::query()->where('external_id', 'like', '200%')->distinct()->pluck('topic_id')->all());
    }

    public function test_approved_mappings_and_non_leaf_topics_are_left_alone(): void
    {
        $sub = Topic::query()->create(['key' => 'topic-270', 'name_ar' => 'الإمامة', 'parent_id' => $this->politics->id]);
        $this->fatwas($this->category($this->islamweb, '954', 'البيعة والإمامة والولاية', $this->politicsCategory), 10);
        $this->fatwas($this->category($this->islamweb, '956', 'سلطات الإمام', $this->politicsCategory), 10);
        $approved = $this->category($this->islamweb, '5000', 'الإمامة العظمى', status: 'approved', topic: $sub);
        $this->fatwas($this->category($this->islamweb, '5001', 'شروط الإمام', $approved, 'approved', $sub), 10);
        $this->fatwas($this->category($this->islamweb, '5002', 'عزل الإمام', $approved, 'approved', $sub), 10);

        $result = app(TopicTreeBuilder::class)->enrich(10);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $this->politics->children()->count());
        $this->assertSame(0, $sub->children()->count());
    }
}
