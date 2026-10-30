<?php

namespace Tests\Feature\Api;

use App\Answers\AnswerStore;
use App\Answers\FollowUpMerger;
use App\Answers\SummaryWriter;
use App\Http\Controllers\Api\SummaryStreamController;
use App\Limits\SummaryStreamLimiter;
use App\Models\AiUsage;
use App\Models\FatwaLink;
use App\Models\Setting;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;
use RuntimeException;
use Tests\Support\MakesSearchableFatwas;
use Tests\Support\WritesSummaryLines;
use Tests\TestCase;

class AskTest extends TestCase
{
    use MakesSearchableFatwas;
    use RefreshDatabase;
    use WritesSummaryLines;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeJudge();

        Http::fake([
            '*' => Http::response([], 200),
        ]);

        config(['fatwarag.ask.cache_store' => 'array', 'fatwarag.ask.relevance_floor' => 0.35]);
        Embeddings::fake(fn ($prompt) => array_map(fn () => $this->axisVector(0), $prompt->inputs));
        Reranking::fake(fn (RerankingPrompt $prompt) => array_map(
            fn (int $index) => new RankedDocument($index, $prompt->documents[$index], str_contains($prompt->documents[$index], 'الذهب') ? 0.9 : 0.1),
            array_keys($prompt->documents),
        ));
    }

    public function test_question_length_is_validated(): void
    {
        $this->ask(['question' => 'ما'])->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->ask(['question' => '   ما   '])->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->ask(['question' => str_repeat('س', 1001)])->assertStatus(422)->assertJsonPath('error.code', 'validation');
        $this->ask(['locale' => 'fr'])->assertStatus(422)->assertJsonPath('error.code', 'validation');
    }

    public function test_search_needs_no_turnstile_token(): void
    {
        $this->embedFatwa($this->searchFatwa('زكاة الذهب المستعمل'), $this->axisVector(0));

        $this->ask()->assertOk()->assertJsonStructure(['answerId', 'fatwaIds']);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'challenges.cloudflare.com'));
    }

    public function test_search_returns_search_result_shape(): void
    {
        $first = $this->searchFatwa('زكاة الذهب المستعمل');
        $this->embedFatwa($first, $this->axisVector(0));
        $second = $this->searchFatwa('نصاب الذهب', sourceKey: 'binbaz', tier: 2);
        $this->embedFatwa($second, $this->axisVector(0, 1, 0.2));
        $this->searchSource('fawzan', 3, enabled: false);

        $response = $this->ask()->assertOk();

        $response->assertExactJsonStructure(['answerId', 'question', 'mergedFrom', 'sourcesSearched', 'fatwaIds']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $response->json('answerId'));
        $response->assertJsonPath('question', 'هل في الذهب المستعمل زكاة؟')
            ->assertJsonPath('mergedFrom', null)
            ->assertJsonPath('sourcesSearched', Source::query()->where('kind', 'fatwa')->where('is_enabled', true)->count());
        $this->assertEqualsCanonicalizing([$first->public_id, $second->public_id], $response->json('fatwaIds'));
    }

    public function test_not_found_returns_404_code(): void
    {
        $unrelated = $this->searchFatwa('صلاة المسافر');
        $this->embedFatwa($unrelated, $this->axisVector(0));

        $this->ask(['question' => 'ما لون السماء يوم الثلاثاء؟'])->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }

    public function test_summary_for_unknown_answer_id_is_404(): void
    {
        $this->getJson('/api/v1/answers/'.str_repeat('a', 32).'/summary')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/v1/answers/not-an-id/summary')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }

    public function test_summary_is_written_for_the_stored_answer(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب', 'في الحلي المستعمل زكاة إذا بلغ النصاب عند بعض العلماء من أهل العلم.');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        $answerId = $this->ask()->json('answerId');

        SummaryWriter::fake([$this->summaryOutput()]);

        $this->getJson("/api/v1/answers/{$answerId}/summary?locale=ar")
            ->assertOk()
            ->assertJsonPath('kind', 'agree')
            ->assertJsonPath('agreed.citations', [$fatwa->public_id])
            ->assertJsonPath('extraFatwaIds', [])
            ->assertJsonMissingPath('status');

        SummaryWriter::assertPrompted(fn ($prompt): bool => $prompt->contains('هل في الذهب المستعمل زكاة؟'));
    }

    public function test_cited_cross_reference_is_added_to_the_answer(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب', 'في الحلي المستعمل زكاة إذا بلغ النصاب عند بعض العلماء من أهل العلم.');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        $crossRef = $this->searchFatwa('نصاب الحلي', 'يضم الحلي بعضه إلى بعض في تكميل النصاب عند من يوجب الزكاة فيه.');
        FatwaLink::query()->create(['fatwa_id' => $fatwa->id, 'target_source_fatwa_id' => $crossRef->source_fatwa_id, 'target_fatwa_id' => $crossRef->id]);

        // The re-ranker sees only the asked-about fatwa.
        Reranking::fake(fn (RerankingPrompt $prompt) => array_map(
            fn (int $index) => new RankedDocument($index, $prompt->documents[$index], str_contains($prompt->documents[$index], 'زكاة الذهب') ? 0.9 : 0.1),
            array_keys($prompt->documents),
        ));
        $answerId = $this->ask()->assertJsonPath('fatwaIds', [$fatwa->public_id])->json('answerId');

        SummaryWriter::fake([$this->summaryLines(
            $this->headerLine('agree', 'في الحلي المستعمل زكاة إذا بلغ النصاب'),
            $this->pointLine('agreed', 'زكاة الحلي المستعمل', 'في الحلي المستعمل زكاة إذا بلغ النصاب. ويضم الحلي بعضه إلى بعض في تكميل النصاب.', ['F1', 'F2'], ['F1.S1', 'F2.S1']),
        )]);

        $this->getJson("/api/v1/answers/{$answerId}/summary")->assertOk()->assertJsonPath('extraFatwaIds', [$crossRef->public_id]);

        $this->assertSame([$fatwa->public_id, $crossRef->public_id], app(AnswerStore::class)->get($answerId)['fatwaIds']);
    }

    public function test_unranked_answer_gets_no_summary(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        Reranking::fake(fn () => throw new RuntimeException('reranker down'));

        $answerId = $this->ask()->assertOk()->assertJsonPath('fatwaIds', [$fatwa->public_id])->json('answerId');

        SummaryWriter::fake()->preventStrayPrompts();
        $this->getJson("/api/v1/answers/{$answerId}/summary")->assertOk()->assertExactJson(['status' => 'failed']);
        SummaryWriter::assertNeverPrompted();
    }

    private function summaryOutput(): string
    {
        return $this->summaryLines(
            $this->headerLine('agree', 'في الحلي المستعمل زكاة إذا بلغ النصاب'),
            $this->pointLine('agreed', 'زكاة الحلي المستعمل', 'في الحلي المستعمل زكاة إذا بلغ النصاب.', ['F1'], ['F1.S1']),
        );
    }

    public function test_summary_stream_sends_stages_checked_items_and_the_final_summary(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب', 'في الحلي المستعمل زكاة إذا بلغ النصاب عند بعض العلماء من أهل العلم.');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        $answerId = $this->ask()->json('answerId');

        SummaryWriter::fake([$this->summaryOutput()]);

        $response = $this->get("/api/v1/answers/{$answerId}/summary/stream?locale=ar")->assertOk();

        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        $events = $this->events($response);
        $this->assertSame(['stage', 'stage', 'stage', 'item', 'done'], array_column($events, 'event'));
        $this->assertSame(['reading', 'writing', 'checking'], array_column(array_column(array_slice($events, 0, 3), 'data'), 'stage'));
        $this->assertSame(['section' => 'agreed', 'index' => 0], array_diff_key($events[3]['data'], ['item' => true]));
        $this->assertSame([$fatwa->public_id], $events[3]['data']['item']['citations']);
        $this->assertSame($events[3]['data']['item'], $events[4]['data']['agreed']);
        $this->assertSame([], $events[4]['data']['extraFatwaIds']);
    }

    public function test_summary_stream_failure_and_pause_are_terminal_status_events_without_the_question(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        $answerId = $this->ask()->json('answerId');
        Log::spy();

        SummaryWriter::fake(fn () => throw new RuntimeException('timeout هل في الذهب المستعمل زكاة؟'));
        $events = $this->events($this->get("/api/v1/answers/{$answerId}/summary/stream"));
        $this->assertSame(['event' => 'status', 'data' => ['status' => 'failed']], end($events));

        Setting::put('monthly_budget_usd', 1);
        AiUsage::query()->create(['purpose' => 'summary', 'model' => 'x', 'input_tokens' => 1, 'output_tokens' => 1, 'cost_usd' => 2, 'counts_toward_budget' => true]);
        $this->assertSame([['event' => 'status', 'data' => ['status' => 'paused']]], $this->events($this->get("/api/v1/answers/{$answerId}/summary/stream")));

        foreach (['info', 'warning', 'error'] as $level) {
            Log::shouldNotHaveReceived($level, [fn (string $message, array $context = []): bool => str_contains($message.json_encode($context, JSON_UNESCAPED_UNICODE), 'الذهب المستعمل')]);
        }
    }

    /**
     * Review fix round 1: whatever breaks, the stream ends with a terminal event, and the time limit covers the worst case.
     */
    public function test_summary_stream_always_ends_with_a_terminal_event(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        $answerId = $this->ask()->json('answerId');
        $this->mock(SummaryWriter::class, fn ($mock) => $mock->shouldReceive('forClient')->andReturnSelf()->getMock()->shouldReceive('forParts')->andReturnSelf())->shouldReceive('write')->andReturnUsing(function (string $question, $fatwas, string $locale, \Closure $emit): never {
            $emit('stage', ['stage' => 'reading']);

            throw new RuntimeException('broken');
        });

        $events = $this->events($this->get("/api/v1/answers/{$answerId}/summary/stream"));

        $this->assertSame([['event' => 'stage', 'data' => ['stage' => 'reading']], ['event' => 'status', 'data' => ['status' => 'failed']]], $events);
        $this->assertGreaterThanOrEqual(25 + 150 + 120, SummaryStreamController::MAX_SECONDS);
    }

    public function test_summary_stream_sends_heartbeats_while_it_works(): void
    {
        config(['fatwarag.summary.heartbeat_seconds' => 0]);
        $fatwa = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        $answerId = $this->ask()->json('answerId');
        $this->mock(SummaryWriter::class, fn ($mock) => $mock->shouldReceive('forClient')->andReturnSelf()->getMock()->shouldReceive('forParts')->andReturnSelf())->shouldReceive('write')->andReturnUsing(function (string $question, $fatwas, string $locale, \Closure $emit, \Closure $alive): array {
            $this->assertTrue($alive());

            return ['status' => 'failed'];
        });

        $content = $this->get("/api/v1/answers/{$answerId}/summary/stream")->streamedContent();

        $this->assertStringContainsString(": ping\n\n", $content);
        $this->assertStringEndsWith("event: status\ndata: {\"status\":\"failed\"}\n\n", $content);
    }

    /**
     * Review fix round 2: one visitor may start only so many summaries a minute, on either endpoint.
     */
    public function test_summaries_are_throttled_per_visitor(): void
    {
        config(['fatwarag.summary.per_client_per_minute' => 2]);
        $fatwa = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        $first = $this->ask()->json('answerId');
        $second = $this->ask([], '198.51.100.11')->json('answerId');
        SummaryWriter::fake(fn () => throw new RuntimeException('timeout'));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->getJson("/api/v1/answers/{$first}/summary")->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->get("/api/v1/answers/{$first}/summary/stream")->assertOk()->streamedContent();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->getJson("/api/v1/answers/{$first}/summary/stream")->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->getJson("/api/v1/answers/{$first}/summary")->assertStatus(429);

        // Another visitor is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])->getJson("/api/v1/answers/{$second}/summary")->assertOk();
    }

    public function test_summary_stream_shares_the_answer_cap_and_rules_of_the_json_summary(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        $answerId = $this->ask()->json('answerId');
        SummaryWriter::fake(fn () => throw new RuntimeException('timeout'));

        $this->getJson("/api/v1/answers/{$answerId}/summary")->assertOk();
        $this->get("/api/v1/answers/{$answerId}/summary/stream")->assertOk()->streamedContent();
        $this->getJson("/api/v1/answers/{$answerId}/summary")->assertOk();

        $this->getJson("/api/v1/answers/{$answerId}/summary/stream")->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->getJson('/api/v1/answers/'.str_repeat('a', 32).'/summary/stream')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }

    public function test_unranked_answer_streams_failed(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        Reranking::fake(fn () => throw new RuntimeException('reranker down'));
        $answerId = $this->ask()->json('answerId');

        SummaryWriter::fake()->preventStrayPrompts();
        $this->assertSame([['event' => 'status', 'data' => ['status' => 'failed']]], $this->events($this->get("/api/v1/answers/{$answerId}/summary/stream")));
        SummaryWriter::assertNeverPrompted();
    }

    /**
     * @return list<array{event: string, data: array<string, mixed>}>
     */
    private function events(TestResponse $response): array
    {
        $events = [];

        foreach (array_filter(explode("\n\n", $response->streamedContent()), fn (string $block): bool => $block !== '' && ! str_starts_with($block, ':')) as $block) {
            preg_match('/^event: (.+)\ndata: (.+)$/u', trim($block), $match);
            $events[] = ['event' => $match[1], 'data' => json_decode($match[2], true)];
        }

        return $events;
    }

    public function test_summary_failure_and_pause_return_status(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        $answerId = $this->ask()->json('answerId');

        SummaryWriter::fake(fn () => throw new RuntimeException('timeout'));
        $this->getJson("/api/v1/answers/{$answerId}/summary")->assertOk()->assertExactJson(['status' => 'failed']);

        Setting::put('monthly_budget_usd', 1);
        AiUsage::query()->create(['purpose' => 'summary', 'model' => 'x', 'input_tokens' => 1, 'output_tokens' => 1, 'cost_usd' => 2, 'counts_toward_budget' => true]);
        $this->getJson("/api/v1/answers/{$answerId}/summary")->assertOk()->assertExactJson(['status' => 'paused']);
    }

    public function test_summary_generations_per_answer_are_limited(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        $answerId = $this->ask()->json('answerId');
        SummaryWriter::fake(fn () => throw new RuntimeException('timeout'));

        foreach (range(1, 3) as $attempt) {
            $this->getJson("/api/v1/answers/{$answerId}/summary")->assertOk();
        }

        $this->getJson("/api/v1/answers/{$answerId}/summary")->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_follow_up_is_merged_then_searched(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب للمسافر');
        $this->embedFatwa($fatwa, $this->axisVector(0));

        FollowUpMerger::fake(['هل على المسافر زكاة في الذهب المستعمل؟']);

        $this->ask(['question' => 'وماذا لو كنت مسافرا؟', 'followUp' => ['original' => 'هل في الذهب المستعمل زكاة؟']])
            ->assertOk()
            ->assertJsonPath('question', 'هل على المسافر زكاة في الذهب المستعمل؟')
            ->assertJsonPath('mergedFrom', ['original' => 'هل في الذهب المستعمل زكاة؟', 'followUp' => 'وماذا لو كنت مسافرا؟']);

        FollowUpMerger::assertPrompted(fn ($prompt): bool => $prompt->contains('وماذا لو كنت مسافرا؟') && $prompt->contains('هل في الذهب المستعمل زكاة؟'));
        Embeddings::assertGenerated(fn ($prompt): bool => $prompt->contains('هل على المسافر زكاة في الذهب المستعمل؟'));
    }

    public function test_follow_up_merge_failure_falls_back_to_both_questions(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب للمسافر');
        $this->embedFatwa($fatwa, $this->axisVector(0));

        FollowUpMerger::fake(fn () => throw new RuntimeException('down'));

        $this->ask(['question' => 'وماذا لو كنت مسافرا؟', 'followUp' => ['original' => 'هل في الذهب المستعمل زكاة؟']])
            ->assertOk()
            ->assertJsonPath('question', 'هل في الذهب المستعمل زكاة؟ وماذا لو كنت مسافرا؟');
    }

    public function test_limits_return_429_codes(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($fatwa, $this->axisVector(0));

        Setting::put('hourly_questions_per_visitor', 1);
        $this->ask()->assertOk();
        $this->ask()->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

        Setting::put('daily_questions', 1);
        $this->ask([], '198.51.100.20')->assertStatus(429)->assertJsonPath('error.code', 'daily_cap_reached');
    }

    public function test_search_failure_returns_upstream_failed(): void
    {
        Embeddings::fake(fn () => throw new RuntimeException('down'));

        $this->ask(['question' => 'Is there zakat on gold?', 'locale' => 'en'])->assertStatus(502)->assertJsonPath('error.code', 'upstream_failed');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    /**
     * Pre-release fix: once an answer has a summary in a locale, it is served again from storage; no new paid summary,
     * and the per-answer cap no longer applies to it.
     */
    public function test_a_written_summary_is_served_again_without_a_new_ai_call(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب', 'في الحلي المستعمل زكاة إذا بلغ النصاب عند بعض العلماء من أهل العلم.');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        $answerId = $this->ask()->json('answerId');
        SummaryWriter::fake([$this->summaryOutput()])->preventStrayPrompts();

        $summary = $this->getJson("/api/v1/answers/{$answerId}/summary?locale=ar")->assertOk()->assertJsonPath('kind', 'agree')->json();

        foreach (range(1, 4) as $attempt) {
            $this->getJson("/api/v1/answers/{$answerId}/summary?locale=ar")->assertOk()->assertExactJson($summary);
            $this->assertSame([['event' => 'done', 'data' => $summary]], $this->events($this->get("/api/v1/answers/{$answerId}/summary/stream?locale=ar")));
        }
    }

    /**
     * Pre-release fix: a visitor may start only so many paid summaries an hour; stored summaries don't count.
     */
    public function test_paid_summaries_per_visitor_are_capped_per_hour(): void
    {
        Setting::put('hourly_summaries_per_visitor', 2);
        $this->travelTo(now()->startOfHour()->addMinutes(50));
        $fatwa = $this->searchFatwa('زكاة الذهب', 'في الحلي المستعمل زكاة إذا بلغ النصاب عند بعض العلماء من أهل العلم.');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        [$first, $second, $third] = [$this->ask()->json('answerId'), $this->ask()->json('answerId'), $this->ask()->json('answerId')];
        $other = $this->ask([], '198.51.100.11')->json('answerId');
        SummaryWriter::fake(fn () => $this->summaryOutput());

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);
        $this->getJson("/api/v1/answers/{$first}/summary")->assertOk()->assertJsonPath('kind', 'agree');
        $this->getJson("/api/v1/answers/{$first}/summary")->assertOk()->assertJsonPath('kind', 'agree');
        $this->assertSame('done', $this->events($this->get("/api/v1/answers/{$second}/summary/stream"))[4]['event']);

        $this->getJson("/api/v1/answers/{$third}/summary")->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->getJson("/api/v1/answers/{$third}/summary/stream")->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        // Stored summaries are still served.
        $this->getJson("/api/v1/answers/{$second}/summary")->assertOk()->assertJsonPath('kind', 'agree');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])->getJson("/api/v1/answers/{$other}/summary")->assertOk();

        // The next hour, while the answer is still kept.
        $this->travel(15)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->getJson("/api/v1/answers/{$third}/summary")->assertOk();
    }

    /**
     * Pre-release fix: each running summary holds an Octane worker, so one visitor may have only two at once. A third
     * gets 429 as JSON before any event, and a finished stream gives its slot back.
     */
    public function test_a_visitor_may_run_only_two_summaries_at_once(): void
    {
        $fatwa = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($fatwa, $this->axisVector(0));
        $answerId = $this->ask()->json('answerId');
        $other = $this->ask([], '198.51.100.11')->json('answerId');
        SummaryWriter::fake(fn () => throw new RuntimeException('timeout'));
        $running = app(SummaryStreamLimiter::class);
        $this->assertTrue($running->acquire('198.51.100.10'));
        $this->assertTrue($running->acquire('198.51.100.10'));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);
        $refused = $this->get("/api/v1/answers/{$answerId}/summary/stream")->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->assertStringStartsWith('application/json', (string) $refused->headers->get('Content-Type'));
        $this->getJson("/api/v1/answers/{$answerId}/summary")->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        SummaryWriter::assertNeverPrompted();

        // Another visitor is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])->get("/api/v1/answers/{$other}/summary/stream")->assertOk()->streamedContent();

        // One ends: the next stream runs, and gives its own slot back when it ends.
        $running->release('198.51.100.10');
        $events = $this->events($this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->get("/api/v1/answers/{$answerId}/summary/stream")->assertOk());
        $this->assertSame(['event' => 'status', 'data' => ['status' => 'failed']], end($events));
        $this->assertTrue($running->acquire('198.51.100.10'));
        $this->assertFalse($running->acquire('198.51.100.10'));
    }

    private function ask(array $overrides = [], string $ip = '198.51.100.10'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/search', $overrides + [
            'question' => 'هل في الذهب المستعمل زكاة؟',
            'locale' => 'ar',
        ]);
    }
}
