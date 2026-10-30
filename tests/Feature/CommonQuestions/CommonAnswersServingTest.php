<?php

namespace Tests\Feature\CommonQuestions;

use App\Answers\AnswerStore;
use App\Answers\QuestionGate;
use App\Answers\SummaryWriter;
use App\Models\CommonAnswer;
use App\Models\CommonQuestion;
use App\Models\Fatwa;
use App\Models\Setting;
use App\Search\FatwaSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Support\MakesSearchableFatwas;
use Tests\TestCase;

class CommonAnswersServingTest extends TestCase
{
    use MakesSearchableFatwas;
    use RefreshDatabase;

    private const QUESTION_AR = 'هل تجب الزكاة في الذهب الملبوس للزينة؟';

    private const QUESTION_EN = 'Is zakat due on gold jewellery worn for adornment?';

    private Fatwa $fatwa;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fatwarag.ask.cache_store' => 'array']);
        $this->fatwa = $this->searchFatwa('زكاة الحلي');
    }

    public function test_exact_common_question_is_answered_from_the_stored_answer_without_searching(): void
    {
        $this->storedAnswer();
        $this->searchMustNotRun();

        $response = $this->ask(['question' => 'هَل تجب   الزكاةُ في الذهب الملبوس للزينة'])->assertOk();

        $response->assertExactJsonStructure(['answerId', 'question', 'mergedFrom', 'sourcesSearched', 'fatwaIds'])
            ->assertJsonPath('fatwaIds', [$this->fatwa->public_id])
            ->assertJsonPath('mergedFrom', null);
        // A stored fatwa that no longer exists is left out.
        $this->assertSame([$this->fatwa->public_id], app(AnswerStore::class)->get($response->json('answerId'))['fatwaIds']);
    }

    public function test_a_common_question_never_goes_through_the_off_topic_gate(): void
    {
        QuestionGate::fake(fn (): array => ['religious' => false]);
        $this->storedAnswer();
        $this->searchMustNotRun();

        $this->ask()->assertOk()->assertJsonPath('fatwaIds', [$this->fatwa->public_id]);

        QuestionGate::assertNeverPrompted();
    }

    public function test_english_match_ignores_case_spacing_and_the_question_mark(): void
    {
        $this->storedAnswer(locale: 'en');
        $this->searchMustNotRun();

        $this->ask(['question' => '  is ZAKAT due on gold jewellery   worn for adornment ', 'locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('fatwaIds', [$this->fatwa->public_id]);
    }

    public function test_cached_summary_is_returned_at_once_by_the_json_endpoint(): void
    {
        $this->storedAnswer();
        $this->summaryMustNotBeWritten();
        $answerId = $this->ask()->json('answerId');

        $this->getJson("/api/v1/answers/{$answerId}/summary")
            ->assertOk()
            ->assertJsonPath('kind', 'agree')
            ->assertJsonPath('extraFatwaIds', []);

        // Served from storage, so the per-answer summary cap does not apply.
        foreach (range(1, AnswerStore::MAX_SUMMARIES + 1) as $ignored) {
            $this->getJson("/api/v1/answers/{$answerId}/summary")->assertOk()->assertJsonPath('kind', 'agree');
        }
    }

    public function test_cached_summary_stream_sends_done_at_once_without_stages(): void
    {
        $this->storedAnswer();
        $this->summaryMustNotBeWritten();
        $answerId = $this->ask()->json('answerId');

        $response = $this->get("/api/v1/answers/{$answerId}/summary/stream")->assertOk();
        $content = $response->streamedContent();

        $this->assertStringStartsWith('event: done', $content);
        $this->assertStringNotContainsString('event: stage', $content);
        $this->assertSame('agree', json_decode(substr(trim($content), strpos($content, 'data: ') + 6), true)['kind']);
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));
    }

    public function test_failed_stored_summary_serves_the_fatwa_list_alone(): void
    {
        $this->storedAnswer(status: CommonAnswer::STATUS_FAILED);
        $this->summaryMustNotBeWritten();
        $answerId = $this->ask()->assertJsonPath('fatwaIds', [$this->fatwa->public_id])->json('answerId');

        $this->getJson("/api/v1/answers/{$answerId}/summary")->assertOk()->assertExactJson(['status' => 'failed']);
        $this->assertSame("event: status\ndata: {\"status\":\"failed\"}\n\n", $this->get("/api/v1/answers/{$answerId}/summary/stream")->streamedContent());
    }

    public function test_cached_answers_still_count_against_the_limits(): void
    {
        $this->storedAnswer();
        $this->searchMustNotRun();
        Setting::put('daily_questions', 1);

        $this->ask()->assertOk();
        $this->ask([], '198.51.100.99')->assertStatus(429)->assertJsonPath('error.code', 'daily_cap_reached');
    }

    public function test_inactive_or_other_questions_are_searched_live_but_old_answers_are_served(): void
    {
        $answer = $this->storedAnswer();
        $this->mock(FatwaSearch::class, fn (MockInterface $mock) => $mock->shouldReceive('search')->times(2)->andReturn([]));

        $this->ask(['question' => 'هل تجب الزكاة في الذهب الملبوس للزينة والتجارة؟'])->assertStatus(404);

        // Fatwas never change: an answer generated long ago is still served from storage.
        $answer->update(['generated_at' => now()->subYears(2)]);
        $this->ask()->assertOk();

        $answer->question->update(['is_active' => false]);
        $this->ask()->assertStatus(404);
    }

    public function test_follow_up_questions_are_never_served_from_storage(): void
    {
        $this->storedAnswer();
        $this->mock(FatwaSearch::class, fn (MockInterface $mock) => $mock->shouldReceive('search')->once()->andReturn([]));

        $this->ask(['followUp' => ['original' => 'سؤال سابق عن الزكاة']])->assertStatus(404);
    }

    public function test_visitor_questions_are_not_stored(): void
    {
        $this->storedAnswer();
        $this->mock(FatwaSearch::class, fn (MockInterface $mock) => $mock->shouldReceive('search')->andReturn([]));

        $this->ask(['question' => 'سؤال زائر لا يطابق شيئا']);

        $this->assertSame(1, CommonQuestion::query()->count());
        $this->assertDatabaseMissing('common_questions', ['question_ar' => 'سؤال زائر لا يطابق شيئا']);
    }

    public function test_rewording_a_question_drops_its_stored_answer(): void
    {
        $answer = $this->storedAnswer();

        $answer->question->update(['question_ar' => 'هَل تجب الزكاة في الذهب الملبوس للزينة؟']);
        $this->assertModelExists($answer);

        $answer->question->update(['question_ar' => 'هل في الحلي زكاة؟']);
        $this->assertModelMissing($answer);
    }

    public function test_common_questions_endpoint_lists_active_questions_in_order_and_locale(): void
    {
        $second = CommonQuestion::factory()->create(['position' => 2, 'question_ar' => 'السؤال الثاني', 'question_en' => 'Second question']);
        $first = CommonQuestion::factory()->create(['position' => 1, 'question_ar' => 'السؤال الأول', 'question_en' => null]);
        CommonQuestion::factory()->inactive()->create(['position' => 0, 'question_ar' => 'سؤال مخفي']);

        $this->getJson('/api/v1/common-questions')->assertOk()->assertExactJson([
            ['id' => $first->id, 'question' => 'السؤال الأول'],
            ['id' => $second->id, 'question' => 'السؤال الثاني'],
        ]);

        $this->getJson('/api/v1/common-questions?locale=en')->assertOk()->assertJsonCount(1)->assertJsonPath('0.question', 'Second question');
    }

    private function storedAnswer(string $locale = 'ar', string $status = CommonAnswer::STATUS_OK): CommonAnswer
    {
        $question = CommonQuestion::query()->first() ?? CommonQuestion::factory()->create(['question_ar' => self::QUESTION_AR, 'question_en' => self::QUESTION_EN]);

        return $question->answers()->create([
            'locale' => $locale,
            'fatwa_ids' => [$this->fatwa->public_id, 'gone-404'],
            'summary' => $status === CommonAnswer::STATUS_OK ? ['kind' => 'agree', 'headline' => 'خلاصة', 'agreed' => null, 'extraFatwaIds' => []] : null,
            'status' => $status,
            'generated_at' => now(),
        ]);
    }

    private function searchMustNotRun(): void
    {
        $this->mock(FatwaSearch::class, fn (MockInterface $mock) => $mock->shouldNotReceive('search'));
    }

    private function summaryMustNotBeWritten(): void
    {
        $this->searchMustNotRun();
        $this->mock(SummaryWriter::class, fn (MockInterface $mock) => $mock->shouldNotReceive('write'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function ask(array $overrides = [], string $ip = '198.51.100.10'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/search', $overrides + [
            'question' => self::QUESTION_AR,
            'locale' => 'ar',
        ]);
    }
}
