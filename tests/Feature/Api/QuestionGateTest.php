<?php

namespace Tests\Feature\Api;

use App\Answers\FollowUpMerger;
use App\Answers\QuestionGate;
use App\Answers\SummaryWriter;
use App\Models\AiUsage;
use App\Models\Alert;
use App\Models\Setting;
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
use Tests\TestCase;

class QuestionGateTest extends TestCase
{
    use MakesSearchableFatwas;
    use RefreshDatabase;

    private const OFF_TOPIC = 'طريقة عمل الكفتة';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeJudge();
        Http::fake(['*' => Http::response([], 200)]);

        config(['fatwarag.ask.cache_store' => 'array', 'fatwarag.ask.relevance_floor' => 0.35]);
        Embeddings::fake(fn ($prompt) => array_map(fn () => $this->axisVector(0), $prompt->inputs));
        // Every fatwa scores above the floor, as the look-alike «الكفارة» fatwas did for the kofta recipe.
        Reranking::fake(fn (RerankingPrompt $prompt) => array_map(
            fn (int $index) => new RankedDocument($index, $prompt->documents[$index], 0.9),
            array_keys($prompt->documents),
        ));

        $this->embedFatwa($this->searchFatwa('كفارة اليمين'), $this->axisVector(0));
    }

    public function test_an_off_topic_question_gets_no_fatwas_and_no_answer(): void
    {
        QuestionGate::fake(fn (): array => ['religious' => false]);

        $response = $this->ask(self::OFF_TOPIC)->assertStatus(422)->assertJsonPath('error.code', 'off_topic');

        $response->assertJsonMissingPath('answerId')->assertJsonMissingPath('fatwaIds');
        QuestionGate::assertPrompted(fn ($prompt): bool => $prompt->contains(self::OFF_TOPIC));
    }

    public function test_a_meaningless_question_is_off_topic_without_a_model_call_or_a_search(): void
    {
        foreach (['غغغغغغغغغغغغغغغغ', 'ههههههههه', 'asdfghjkl', '؟؟؟؟', '١٢٣٤٥'] as $question) {
            $this->ask($question)->assertStatus(422)->assertJsonPath('error.code', 'off_topic');
        }

        QuestionGate::assertNeverPrompted();
        Embeddings::assertNothingGenerated();
        $this->assertSame(0, AiUsage::query()->count());
    }

    public function test_real_short_questions_pass_the_word_check(): void
    {
        $this->ask('ما حكم الربا')->assertOk();
        $this->ask('الوضوء')->assertOk();

        QuestionGate::assertPromptedTimes(2);
    }

    public function test_an_off_topic_question_counts_toward_the_limit_and_its_spend_is_recorded(): void
    {
        QuestionGate::fake(fn (): array => ['religious' => false]);
        Setting::put('hourly_questions_per_visitor', 1);

        $this->ask(self::OFF_TOPIC)->assertStatus(422);
        $this->ask('كيف أصلح محرك السيارة')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

        $this->assertSame(1, AiUsage::query()->where('purpose', QuestionGate::PURPOSE)->count());
    }

    public function test_an_on_topic_question_is_answered_as_before(): void
    {
        $this->ask('ما حكم أكل الكفتة')
            ->assertOk()
            ->assertJsonStructure(['answerId', 'question', 'mergedFrom', 'sourcesSearched', 'fatwaIds'])
            ->assertJsonCount(1, 'fatwaIds');

        QuestionGate::assertPrompted(fn ($prompt): bool => $prompt->contains('ما حكم أكل الكفتة'));
        $this->assertSame(1, AiUsage::query()->where('purpose', QuestionGate::PURPOSE)->count());
    }

    public function test_the_gate_asks_about_the_whole_question_once(): void
    {
        $question = 'أولا ما حكم الجمع بين الصلاتين؟ ثانيا ما حكم القصر في السفر؟';

        $this->ask($question)->assertOk();

        QuestionGate::assertPromptedTimes(1);
        QuestionGate::assertPrompted(fn ($prompt): bool => $prompt->contains('الجمع بين الصلاتين') && $prompt->contains('القصر في السفر'));
    }

    public function test_a_failing_gate_lets_the_question_through_and_is_logged(): void
    {
        QuestionGate::fake(fn () => throw new RuntimeException('down'));
        Log::spy();

        $this->ask(self::OFF_TOPIC)->assertOk()->assertJsonCount(1, 'fatwaIds');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Question gate failed'))->once();
        $this->assertSame(0, Alert::query()->count());
    }

    public function test_repeated_gate_failures_raise_an_alert(): void
    {
        QuestionGate::fake(fn () => throw new RuntimeException('down'));

        foreach (['سؤال اول عن الصلاة', 'سؤال ثان عن الصلاة', 'سؤال ثالث عن الصلاة'] as $question) {
            $this->ask($question)->assertOk();
        }

        $this->assertSame(1, Alert::query()->where('kind', 'question_gate_failed')->count());
    }

    public function test_a_verdict_without_the_flag_lets_the_question_through(): void
    {
        QuestionGate::fake(fn (): array => ['religious' => 'maybe']);

        $this->ask(self::OFF_TOPIC)->assertOk();
    }

    public function test_the_verdict_is_cached_per_normalized_question(): void
    {
        QuestionGate::fake(fn (): array => ['religious' => false]);

        $this->ask(self::OFF_TOPIC)->assertStatus(422);
        $this->ask('طَريقة  عمل الكفتة؟')->assertStatus(422)->assertJsonPath('error.code', 'off_topic');

        QuestionGate::assertPromptedTimes(1);
        $this->assertSame(1, AiUsage::query()->where('purpose', QuestionGate::PURPOSE)->count());
    }

    public function test_a_cached_religious_verdict_is_reused(): void
    {
        $this->ask('ما حكم أكل الكفتة')->assertOk();
        $this->ask('ما حكم أكل الكفتة')->assertOk();

        QuestionGate::assertPromptedTimes(1);
    }

    public function test_a_follow_up_is_judged_as_the_merged_question(): void
    {
        FollowUpMerger::fake(['ما كفارة اليمين إذا كنت مسافرا؟']);

        $this->postAsk(['question' => 'وماذا لو كنت مسافرا؟', 'followUp' => ['original' => 'ما كفارة اليمين؟']])->assertOk();

        QuestionGate::assertPrompted(fn ($prompt): bool => $prompt->contains('ما كفارة اليمين إذا كنت مسافرا؟'));
    }

    public function test_the_summary_endpoints_refuse_a_question_judged_off_topic(): void
    {
        // The gate failed open for this answer; its verdict arrives afterwards.
        QuestionGate::fake(fn () => throw new RuntimeException('down'));
        $answerId = $this->ask(self::OFF_TOPIC)->assertOk()->json('answerId');

        QuestionGate::fake(fn (): array => ['religious' => false]);
        $this->assertFalse(app(QuestionGate::class)->classify(self::OFF_TOPIC));

        SummaryWriter::fake([]);

        $this->getJson("/api/v1/answers/{$answerId}/summary")->assertStatus(422)->assertJsonPath('error.code', 'off_topic');
        $this->get("/api/v1/answers/{$answerId}/summary/stream")->assertStatus(422)->assertJsonPath('error.code', 'off_topic');

        SummaryWriter::assertNeverPrompted();
    }

    private function ask(string $question): TestResponse
    {
        return $this->postAsk(['question' => $question]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function postAsk(array $body): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->postJson('/api/v1/search', $body + ['locale' => 'ar']);
    }
}
