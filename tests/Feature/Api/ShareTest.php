<?php

namespace Tests\Feature\Api;

use App\Answers\AnswerStore;
use App\Answers\FollowUpMerger;
use App\Answers\SummaryWriter;
use App\Limits\ShareLimiter;
use App\Models\Fatwa;
use App\Models\SharedAnswer;
use App\Search\FatwaSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Support\MakesContent;
use Tests\TestCase;

class ShareTest extends TestCase
{
    use MakesContent;
    use RefreshDatabase;

    private const IP = '198.51.100.20';

    private Fatwa $fatwa;

    private bool $turnstilePasses = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fatwarag.ask.cache_store' => 'array', 'fatwarag.site_url' => 'https://fatwarag.test']);
        $this->fatwa = $this->makeFatwa(['title' => 'زكاة الحلي']);
        Http::fake(['challenges.cloudflare.com/*' => fn () => Http::response(['success' => $this->turnstilePasses])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(string $headline = 'خلاصة'): array
    {
        return ['kind' => 'agree', 'headline' => $headline, 'agreed' => null, 'views' => [], 'preferences' => [], 'caution' => null, 'extraFatwaIds' => []];
    }

    private function writerReturns(array $summary): void
    {
        $this->mock(SummaryWriter::class, function (MockInterface $mock) use ($summary): void {
            $mock->shouldReceive('forClient')->andReturnSelf();
            $mock->shouldReceive('forParts')->andReturnSelf();
            $mock->shouldReceive('write')->andReturn(['status' => 'ok', 'summary' => $summary]);
        });
    }

    private function searchReturnsTheFatwa(): void
    {
        $this->mock(FatwaSearch::class, fn (MockInterface $mock) => $mock->shouldReceive('search')
            ->andReturn([['fatwaId' => $this->fatwa->id, 'score' => 0.9, 'ranked' => true]]));
    }

    private function handoff(string $question = 'ما حكم زكاة الحلي؟'): string
    {
        return app(AnswerStore::class)->put($question, 'ar', [$this->fatwa->public_id]);
    }

    private function share(string $answerId, array $overrides = [], string $ip = self::IP): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/shares', $overrides + ['answerId' => $answerId, 'locale' => 'ar', 'turnstileToken' => 'ok-token']);
    }

    public function test_share_saves_the_answer_with_its_written_summary(): void
    {
        $this->writerReturns($this->summary('الزكاة واجبة'));
        $answerId = $this->handoff();
        $this->getJson("/api/v1/answers/{$answerId}/summary?locale=ar")->assertOk();

        $response = $this->share($answerId)->assertCreated()->assertExactJsonStructure(['id', 'url']);

        $id = $response->json('id');
        $this->assertGreaterThanOrEqual(10, strlen($id));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $id);
        $response->assertJsonPath('url', "https://fatwarag.test/s/{$id}");

        $share = SharedAnswer::query()->sole();
        $this->assertSame('ما حكم زكاة الحلي؟', $share->question);
        $this->assertSame([$this->fatwa->public_id], $share->fatwa_ids);
        $this->assertSame('الزكاة واجبة', $share->summary['headline']);
        $this->assertNotNull($share->ip_hash);
        $this->assertStringNotContainsString(self::IP, $share->ip_hash);
    }

    public function test_streamed_summary_is_kept_for_sharing_too(): void
    {
        $this->writerReturns($this->summary('من البث'));
        $answerId = $this->handoff();
        $this->get("/api/v1/answers/{$answerId}/summary/stream?locale=ar")->assertOk()->streamedContent();

        $this->share($answerId)->assertCreated();

        $this->assertSame('من البث', SharedAnswer::query()->sole()->summary['headline']);
    }

    public function test_share_without_a_finished_summary_keeps_the_list_only(): void
    {
        $this->share($this->handoff())->assertCreated();

        $share = SharedAnswer::query()->sole();
        $this->assertNull($share->summary);
        $this->assertSame([$this->fatwa->public_id], $share->fatwa_ids);
    }

    public function test_summary_in_another_locale_is_not_shared(): void
    {
        $this->writerReturns($this->summary('Sources agree'));
        $answerId = $this->handoff();
        $this->getJson("/api/v1/answers/{$answerId}/summary?locale=en")->assertOk();

        $this->share($answerId, ['locale' => 'ar'])->assertCreated();

        $this->assertNull(SharedAnswer::query()->sole()->summary);
    }

    public function test_follow_up_share_keeps_the_follow_up_it_came_from(): void
    {
        $this->searchReturnsTheFatwa();
        $this->mock(FollowUpMerger::class, fn (MockInterface $mock) => $mock->shouldReceive('merge')->andReturn('ما حكم زكاة الحلي للمسافر؟'));

        $answerId = $this->postJson('/api/v1/search', ['question' => 'وماذا لو كنت مسافرًا؟', 'locale' => 'ar', 'followUp' => ['original' => 'ما حكم زكاة الحلي؟']])
            ->assertOk()
            ->json('answerId');

        $this->share($answerId)->assertCreated();

        $share = SharedAnswer::query()->sole();
        $this->assertSame('ما حكم زكاة الحلي للمسافر؟', $share->question);
        $this->assertSame('وماذا لو كنت مسافرًا؟', $share->follow_up);
    }

    public function test_nothing_is_stored_without_an_explicit_share(): void
    {
        $this->searchReturnsTheFatwa();
        $this->writerReturns($this->summary());

        $answerId = $this->postJson('/api/v1/search', ['question' => 'ما حكم زكاة الحلي؟', 'locale' => 'ar'])->assertOk()->json('answerId');
        $this->getJson("/api/v1/answers/{$answerId}/summary")->assertOk();
        $this->get("/api/v1/answers/{$answerId}/summary/stream")->assertOk()->streamedContent();

        $this->assertDatabaseCount('shared_answers', 0);
    }

    public function test_failed_turnstile_stores_nothing(): void
    {
        $this->turnstilePasses = false;

        $this->share($this->handoff())->assertStatus(403)->assertJsonPath('error.code', 'turnstile_failed');
        $this->share($this->handoff(), ['turnstileToken' => null])->assertStatus(403);

        $this->assertDatabaseCount('shared_answers', 0);
    }

    public function test_expired_or_unknown_answer_is_not_found(): void
    {
        $this->share(str_repeat('a', 32))->assertNotFound()->assertJsonPath('error.code', 'not_found');

        $this->assertDatabaseCount('shared_answers', 0);
    }

    public function test_input_is_validated(): void
    {
        $this->share('not-an-answer-id')->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->share($this->handoff(), ['locale' => 'fr'])->assertStatus(422);
    }

    public function test_each_visitor_may_share_only_so_many_answers_an_hour(): void
    {
        $answerId = $this->handoff();

        foreach (range(1, ShareLimiter::PER_HOUR) as $ignored) {
            $this->share($answerId)->assertCreated();
        }

        $this->share($answerId)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->assertDatabaseCount('shared_answers', ShareLimiter::PER_HOUR);

        // Another visitor is not affected.
        $this->share($answerId, [], '203.0.113.7')->assertCreated();
    }

    public function test_shared_answer_is_returned_as_saved(): void
    {
        $share = SharedAnswer::factory()->withSummary(['headline' => 'خلاصة محفوظة'])->create(['locale' => 'en', 'follow_up' => 'And if travelling?']);

        $this->getJson("/api/v1/shares/{$share->public_id}")
            ->assertOk()
            ->assertExactJsonStructure(['id', 'question', 'followUp', 'locale', 'fatwaIds', 'summary', 'createdAt'])
            ->assertJsonPath('question', $share->question)
            ->assertJsonPath('followUp', 'And if travelling?')
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('fatwaIds', $share->fatwa_ids)
            ->assertJsonPath('summary.headline', 'خلاصة محفوظة');
    }

    public function test_unknown_share_is_not_found(): void
    {
        $this->getJson('/api/v1/shares/doesNotExist123')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/v1/shares/x')->assertNotFound();
    }

    public function test_shares_are_listed_for_the_sitemap(): void
    {
        $share = SharedAnswer::factory()->create(['locale' => 'en', 'question' => 'Is zakat due on worn gold?']);

        $this->getJson('/api/v1/sitemap/shares')
            ->assertOk()
            ->assertExactJsonStructure(['data' => [['id', 'locale', 'updatedAt']], 'meta' => ['page', 'perPage', 'total', 'lastPage']])
            ->assertDontSee($share->question)
            ->assertDontSee($share->ip_hash)
            ->assertJsonPath('data.0.id', $share->public_id)
            ->assertJsonPath('data.0.locale', 'en')
            ->assertJsonPath('meta.total', 1);
    }
}
