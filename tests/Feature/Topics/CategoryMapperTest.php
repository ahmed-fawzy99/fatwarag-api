<?php

namespace Tests\Feature\Topics;

use App\Models\AiUsage;
use App\Models\Fatwa;
use App\Models\Source;
use App\Models\SourceCategory;
use App\Models\Topic;
use App\Topics\CategoryMapper;
use App\Topics\CategoryMappingAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;
use RuntimeException;
use Tests\TestCase;

class CategoryMapperTest extends TestCase
{
    use RefreshDatabase;

    private Source $islamweb;

    private Topic $zakah;

    private Topic $prayer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        $this->islamweb = Source::query()->create(['key' => 'islamweb', 'kind' => 'fatwa', 'tier' => 1, 'name_ar' => 'إسلام ويب', 'name_en' => 'Islamweb', 'base_url' => 'https://www.islamweb.net']);
        $fiqh = Topic::query()->create(['key' => 'topic-55', 'name_ar' => 'الفقه وأصوله']);
        $this->zakah = Topic::query()->create(['key' => 'topic-99', 'name_ar' => 'الزكاة', 'parent_id' => $fiqh->id]);
        $this->prayer = Topic::query()->create(['key' => 'topic-60', 'name_ar' => 'الصلاة', 'parent_id' => $fiqh->id]);
    }

    private function category(string $externalId, string $name, ?int $topicId = null, string $status = 'unmapped'): SourceCategory
    {
        return SourceCategory::query()->create(['source_id' => $this->islamweb->id, 'external_id' => $externalId, 'name' => $name, 'path' => "العبادات / {$name}", 'topic_id' => $topicId, 'mapping_status' => $status]);
    }

    /**
     * @param  array<int, array{category: string, topic: string}>  $mappings
     */
    private function fakeAgent(array $mappings): void
    {
        CategoryMappingAgent::fake(fn () => new StructuredTextResponse(['mappings' => $mappings], json_encode(['mappings' => $mappings]), new TextUsage(2000, 300), new Meta('openrouter', 'fake')));
    }

    public function test_exact_name_match_maps_without_ai(): void
    {
        CategoryMappingAgent::fake()->preventStrayPrompts();
        $this->category('71', 'الزَّكاة');

        $result = app(CategoryMapper::class)->map();

        $this->assertSame(1, $result['exact']);
        $category = SourceCategory::query()->sole();
        $this->assertSame($this->zakah->id, $category->topic_id);
        $this->assertSame('proposed', $category->mapping_status);
        CategoryMappingAgent::assertNeverPrompted();
    }

    public function test_ai_proposal_is_used_for_remaining_categories(): void
    {
        $this->fakeAgent([['category' => '501', 'topic' => 'topic-60'], ['category' => '502', 'topic' => 'no-such-topic']]);
        $this->category('501', 'صلاة الجماعة');
        $this->category('502', 'مسائل متفرقة');

        $result = app(CategoryMapper::class)->map();

        $this->assertSame(1, $result['ai']);
        $this->assertSame($this->prayer->id, SourceCategory::query()->where('external_id', '501')->value('topic_id'));
        $this->assertSame('proposed', SourceCategory::query()->where('external_id', '501')->value('mapping_status'));
        $this->assertNull(SourceCategory::query()->where('external_id', '502')->value('topic_id'));
        CategoryMappingAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'صلاة الجماعة') && str_contains($prompt->prompt, 'topic-60'));

        $usage = AiUsage::query()->sole();
        $this->assertSame('topic_mapping', $usage->purpose);
        $this->assertFalse($usage->counts_toward_budget);
        $this->assertGreaterThan(0, (float) $usage->cost_usd);
    }

    public function test_approved_and_proposed_categories_are_left_alone(): void
    {
        CategoryMappingAgent::fake()->preventStrayPrompts();
        $this->category('1', 'الصلاة', $this->zakah->id, 'approved');

        app(CategoryMapper::class)->map();

        $this->assertSame($this->zakah->id, SourceCategory::query()->value('topic_id'));
        $this->assertSame('approved', SourceCategory::query()->value('mapping_status'));
    }

    public function test_ai_failure_leaves_categories_unmapped_and_raises_an_alert(): void
    {
        CategoryMappingAgent::fake(fn () => throw new RuntimeException('provider down'));
        $this->category('501', 'صلاة الجماعة');

        $result = app(CategoryMapper::class)->map();

        $this->assertSame(1, $result['unmapped']);
        $this->assertNull(SourceCategory::query()->value('topic_id'));
        $this->assertDatabaseHas('alerts', ['kind' => 'topics']);
    }

    public function test_no_ai_option_skips_proposals(): void
    {
        CategoryMappingAgent::fake()->preventStrayPrompts();
        $this->category('501', 'صلاة الجماعة');

        $result = app(CategoryMapper::class)->map(useAi: false);

        $this->assertSame(1, $result['unmapped']);
        CategoryMappingAgent::assertNeverPrompted();
    }

    public function test_fatwa_topics_follow_category_mapping(): void
    {
        $category = $this->category('71', 'الزكاة', $this->zakah->id, 'proposed');
        $fatwa = Fatwa::query()->create([
            'public_id' => 'islamweb-1', 'source_id' => $this->islamweb->id, 'source_fatwa_id' => '1', 'url' => 'https://www.islamweb.net/ar/fatwa/1/',
            'title' => 'زكاة', 'question' => [], 'answer' => [], 'search_text' => 'زكاه',
        ]);
        $fatwa->categories()->attach($category);

        app(CategoryMapper::class)->syncFatwaTopics();
        $this->assertSame([$this->zakah->id], $fatwa->topics()->pluck('topics.id')->all());

        $category->update(['topic_id' => $this->prayer->id]);
        app(CategoryMapper::class)->syncFatwaTopics();
        $this->assertSame([$this->prayer->id], $fatwa->topics()->pluck('topics.id')->all());
    }

    public function test_command_builds_tree_maps_and_creates_mufti_cards(): void
    {
        CategoryMappingAgent::fake()->preventStrayPrompts();

        $this->artisan('topics:build', ['--no-ai' => true])->assertSuccessful();

        $this->assertDatabaseHas('muftis', ['key' => 'islamweb', 'kind' => 'institution', 'name_ar' => 'مركز الفتوى بإسلام ويب', 'bio_ar' => null]);
        $this->assertDatabaseHas('muftis', ['key' => 'islamqa', 'kind' => 'institution', 'name_ar' => 'الإسلام سؤال وجواب']);
        $this->assertDatabaseHas('muftis', ['key' => 'binbaz', 'kind' => 'person', 'name_ar' => 'الشيخ عبد العزيز بن باز']);
        $this->assertDatabaseHas('muftis', ['key' => 'fawzan', 'kind' => 'person', 'name_ar' => 'الشيخ صالح بن فوزان الفوزان']);
        $this->assertDatabaseHas('muftis', ['key' => 'lajnah', 'kind' => 'institution', 'name_ar' => 'اللجنة الدائمة للبحوث العلمية والإفتاء']);
    }
}
