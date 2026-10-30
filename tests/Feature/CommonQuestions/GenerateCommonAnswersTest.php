<?php

namespace Tests\Feature\CommonQuestions;

use App\Answers\SummaryWriter;
use App\Jobs\GenerateCommonAnswer;
use App\Models\Alert;
use App\Models\CommonAnswer;
use App\Models\CommonQuestion;
use App\Models\Fatwa;
use App\Search\FatwaSearch;
use App\Search\SearchUnavailable;
use App\Services\CommonAnswerGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\MakesSearchableFatwas;
use Tests\TestCase;

class GenerateCommonAnswersTest extends TestCase
{
    use MakesSearchableFatwas;
    use RefreshDatabase;

    private Fatwa $first;

    private Fatwa $second;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 200)]);
        $this->first = $this->searchFatwa('صلاة المسافر');
        $this->second = $this->searchFatwa('قصر الصلاة');
    }

    public function test_it_stores_the_fatwa_list_and_summary_for_each_question_and_locale(): void
    {
        $question = CommonQuestion::factory()->create();
        $this->searchReturns([['fatwaId' => $this->second->id, 'score' => 0.9, 'ranked' => true], ['fatwaId' => $this->first->id, 'score' => 0.8, 'ranked' => true]]);
        $writer = $this->writerReturns(['status' => 'ok', 'summary' => ['kind' => 'agree', 'extraFatwaIds' => []]]);

        $this->artisan('common:generate')->assertSuccessful();

        foreach (['ar', 'en'] as $locale) {
            $answer = $question->answers()->where('locale', $locale)->sole();
            $this->assertSame([$this->second->public_id, $this->first->public_id], $answer->fatwa_ids);
            $this->assertSame(CommonAnswer::STATUS_OK, $answer->status);
            $this->assertSame('agree', $answer->summary['kind']);
            $this->assertTrue($answer->isGenerated());
        }

        $writer->shouldHaveReceived('forClient')->with(CommonAnswerGenerator::CLIENT)->twice();
        $writer->shouldHaveReceived('write')->withArgs(fn (string $text, $fatwas, string $locale): bool => $locale === 'en'
            && $text === $question->question_en
            && $fatwas->pluck('public_id')->all() === [$this->second->public_id, $this->first->public_id])->once();
    }

    public function test_a_failed_summary_is_stored_as_failed_with_its_fatwa_list(): void
    {
        $question = CommonQuestion::factory()->create();
        $this->searchReturns([['fatwaId' => $this->first->id, 'score' => 0.9, 'ranked' => true]]);
        $this->writerReturns(['status' => 'failed']);

        $this->artisan('common:generate --locale=ar')->assertSuccessful();

        $answer = $question->answers()->sole();
        $this->assertSame('ar', $answer->locale);
        $this->assertSame(CommonAnswer::STATUS_FAILED, $answer->status);
        $this->assertNull($answer->summary);
        $this->assertSame([$this->first->public_id], $answer->fatwa_ids);
        $this->assertTrue(Alert::query()->where('kind', 'common_answers')->exists());
    }

    public function test_a_run_tells_the_owner_once_however_many_answers_failed(): void
    {
        config(['fatwarag.telegram.token' => 'test-token', 'fatwarag.telegram.chat_id' => '42']);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $questions = CommonQuestion::factory()->count(2)->create();
        $this->searchReturns([['fatwaId' => $this->first->id, 'score' => 0.9, 'ranked' => true]]);
        $this->writerReturns(['status' => 'failed']);

        $this->artisan('common:generate')->assertSuccessful();

        $sent = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org'));
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('4 common answer(s) failed', $sent[0][0]['text']);
        // Each failure is still recorded for the admin.
        foreach ($questions as $question) {
            $this->assertTrue(Alert::query()->where('fingerprint', "common-answer:{$question->id}:ar")->whereNull('resolved_at')->exists());
        }

        // The next run with failures tells the owner again, once; a clean run resolves the run's alert.
        $this->artisan('common:generate --locale=ar --force')->assertSuccessful();
        $this->assertCount(2, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org')));

        // A clean run of only some questions leaves the run's alert standing; a clean full run resolves it.
        $this->writerReturns(['status' => 'ok', 'summary' => ['kind' => 'agree', 'extraFatwaIds' => []]]);
        $this->artisan("common:generate --only={$questions[0]->id}")->assertSuccessful();
        $this->assertTrue(Alert::query()->where('fingerprint', CommonAnswerGenerator::RUN_ALERT)->whereNull('resolved_at')->exists());
        $this->artisan('common:generate --force')->assertSuccessful();
        $this->assertFalse(Alert::query()->where('fingerprint', CommonAnswerGenerator::RUN_ALERT)->whereNull('resolved_at')->exists());
    }

    public function test_only_option_limits_the_run_to_the_given_questions(): void
    {
        $chosen = CommonQuestion::factory()->create();
        $other = CommonQuestion::factory()->create();
        CommonQuestion::factory()->inactive()->create();
        $this->searchReturns([['fatwaId' => $this->first->id, 'score' => 0.9, 'ranked' => true]]);
        $this->writerReturns(['status' => 'ok', 'summary' => ['kind' => 'agree', 'extraFatwaIds' => []]]);

        $this->artisan("common:generate --only={$chosen->id} --locale=en")->assertSuccessful();

        $this->assertSame(['en'], $chosen->answers()->pluck('locale')->all());
        $this->assertSame(0, $other->answers()->count());
        $this->assertSame(1, CommonAnswer::query()->count());
    }

    public function test_search_outage_or_spent_budget_keeps_the_previous_answer(): void
    {
        $question = CommonQuestion::factory()->create();
        $previous = $question->answers()->create(['locale' => 'ar', 'fatwa_ids' => ['old-1'], 'status' => 'ok', 'summary' => ['kind' => 'agree'], 'generated_at' => now()->subDays(3)]);

        $this->mock(FatwaSearch::class, fn (MockInterface $mock) => $mock->shouldReceive('search')->once()->andThrow(new SearchUnavailable('down')));
        $this->artisan('common:generate --locale=ar --force')->assertSuccessful();
        $this->assertSame(['old-1'], $previous->refresh()->fatwa_ids);

        $this->searchReturns([['fatwaId' => $this->first->id, 'score' => 0.9, 'ranked' => true]]);
        $this->writerReturns(['status' => 'paused']);
        $this->artisan('common:generate --locale=ar --force')->assertFailed();
        $this->assertSame(['old-1'], $previous->refresh()->fatwa_ids);
    }

    public function test_a_regeneration_that_fails_is_retried_once_then_keeps_the_good_stored_answer(): void
    {
        $question = CommonQuestion::factory()->create();
        $good = $question->answers()->create(['locale' => 'ar', 'fatwa_ids' => ['old-1'], 'status' => 'ok', 'summary' => ['kind' => 'agree'], 'generated_at' => now()->subDays(3)]);
        $this->searchReturns([['fatwaId' => $this->first->id, 'score' => 0.9, 'ranked' => true]]);
        $writer = $this->writerReturns(['status' => 'failed']);

        $this->artisan('common:generate --locale=ar --force')->assertSuccessful();

        $writer->shouldHaveReceived('write')->twice();
        $this->assertSame(['old-1'], $good->refresh()->fatwa_ids);
        $this->assertSame(CommonAnswer::STATUS_OK, $good->status);
        $this->assertTrue(Alert::query()->where('fingerprint', "common-answer:{$question->id}:ar")->where('message', 'like', '%stored answer is kept%')->exists());
    }

    public function test_unranked_results_are_not_stored(): void
    {
        $question = CommonQuestion::factory()->create();
        $this->searchReturns([['fatwaId' => $this->first->id, 'score' => 0.2, 'ranked' => false]]);
        $this->mock(SummaryWriter::class, fn (MockInterface $mock) => $mock->shouldNotReceive('write'));

        $this->artisan('common:generate --locale=ar')->assertSuccessful();

        $this->assertSame(0, $question->answers()->count());
    }

    public function test_stored_answers_are_kept_unless_asked_for_by_id_or_forced(): void
    {
        $stored = CommonQuestion::factory()->create();
        $missing = CommonQuestion::factory()->create();
        $stored->answers()->create(['locale' => 'ar', 'fatwa_ids' => ['old-1'], 'status' => 'ok', 'summary' => ['kind' => 'agree'], 'generated_at' => now()->subYear()]);
        $this->searchReturns([['fatwaId' => $this->first->id, 'score' => 0.9, 'ranked' => true]]);
        $writer = $this->writerReturns(['status' => 'ok', 'summary' => ['kind' => 'agree', 'extraFatwaIds' => []]]);

        // By default only the missing answer is generated; the year-old one stays as it is.
        $this->artisan('common:generate --locale=ar')->assertSuccessful()->expectsOutputToContain("#{$stored->id} ar: kept");
        $this->assertSame(['old-1'], $stored->answers()->sole()->fatwa_ids);
        $this->assertSame(1, $missing->answers()->count());
        $writer->shouldHaveReceived('write')->once();

        $this->artisan("common:generate --locale=ar --only={$stored->id}")->assertSuccessful();
        $this->assertSame([$this->first->public_id], $stored->answers()->sole()->fatwa_ids);

        $this->artisan('common:generate --locale=ar --force')->assertSuccessful();
        $writer->shouldHaveReceived('write')->times(4);
    }

    public function test_job_regenerates_the_requested_locales(): void
    {
        $question = CommonQuestion::factory()->create();
        $this->searchReturns([['fatwaId' => $this->first->id, 'score' => 0.9, 'ranked' => true]]);
        $this->writerReturns(['status' => 'ok', 'summary' => ['kind' => 'agree', 'extraFatwaIds' => []]]);

        GenerateCommonAnswer::dispatchSync($question->id, ['en']);

        $this->assertSame(['en'], $question->answers()->pluck('locale')->all());
    }

    /**
     * @param  list<array{fatwaId: int, score: float, ranked: bool}>  $results
     */
    private function searchReturns(array $results): void
    {
        $this->mock(FatwaSearch::class, fn (MockInterface $mock) => $mock->shouldReceive('search')->andReturn($results));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function writerReturns(array $result): MockInterface
    {
        $writer = Mockery::spy(SummaryWriter::class);
        $writer->shouldReceive('forClient')->andReturnSelf();
        $writer->shouldReceive('write')->andReturn($result);
        $this->app->instance(SummaryWriter::class, $writer);

        return $writer;
    }
}
