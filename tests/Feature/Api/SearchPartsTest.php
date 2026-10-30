<?php

namespace Tests\Feature\Api;

use App\Answers\QuestionParts;
use App\Answers\SummaryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;
use RuntimeException;
use Tests\Support\MakesSearchableFatwas;
use Tests\Support\WritesSummaryLines;
use Tests\TestCase;

/**
 * A question asking several things is searched part by part (owner request); a single question as before.
 */
class SearchPartsTest extends TestCase
{
    use MakesSearchableFatwas;
    use RefreshDatabase;
    use WritesSummaryLines;

    private const QUESTION = 'شككت في عدد الركعات، أولا لو كنت داخل الصلاة، ثانيا لو بعد الصلاة؟';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeJudge();
        Http::fake(['*' => Http::response([], 200)]);
        config(['fatwarag.ask.cache_store' => 'array', 'fatwarag.ask.relevance_floor' => 0.35]);
        Embeddings::fake(fn ($prompt) => array_map(fn () => $this->axisVector(0), $prompt->inputs));
        // Each part's query finds only the fatwa about its own case.
        Reranking::fake(fn (RerankingPrompt $prompt) => array_map(
            fn (int $index) => new RankedDocument($index, $prompt->documents[$index], $this->sameCase($prompt->query, $prompt->documents[$index]) ? 0.9 : 0.1),
            array_keys($prompt->documents),
        ));
    }

    public function test_each_part_is_searched_on_its_own_and_the_summary_knows_the_parts(): void
    {
        $during = $this->searchFatwa('الشك في عدد الركعات أثناء الصلاة', 'من شك أثناء الصلاة بنى على الأقل.');
        $after = $this->searchFatwa('الشك في عدد الركعات بعد السلام', 'من شك بعد السلام لا يلتفت إلى شكه.', 'binbaz', 2);
        $this->embedFatwa($during, $this->axisVector(0));
        $this->embedFatwa($after, $this->axisVector(0));
        $this->fakeParts();

        $response = $this->ask()->assertOk();

        $response->assertJsonPath('fatwaIds', [$during->public_id, $after->public_id])
            ->assertJsonPath('parts', [
                ['label' => 'إذا كان الشك أثناء الصلاة', 'fatwaIds' => [$during->public_id]],
                ['label' => 'إذا كان الشك بعد السلام', 'fatwaIds' => [$after->public_id]],
            ]);

        SummaryWriter::fake([$this->summaryLines($this->headerLine('agree', 'من شك أثناء الصلاة بنى على الأقل'))]);
        $this->getJson('/api/v1/answers/'.$response->json('answerId').'/summary?locale=ar');

        SummaryWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('أجزاء السؤال')
            && $prompt->contains('1. «إذا كان الشك أثناء الصلاة» — الفتاوى التي وُجدت له أولًا: F1')
            && $prompt->contains('2. «إذا كان الشك بعد السلام» — الفتاوى التي وُجدت له أولًا: F2'));
    }

    public function test_merged_parts_keep_each_parts_best_hits_without_repeats_within_the_cap(): void
    {
        config(['fatwarag.search.max_results_parts' => 3]);
        $during = array_map(fn (int $i) => $this->searchFatwa("الشك أثناء الصلاة {$i}"), [1, 2, 3]);
        $after = array_map(fn (int $i) => $this->searchFatwa("الشك بعد السلام {$i}"), [1, 2, 3]);

        foreach ([...$during, ...$after] as $fatwa) {
            $this->embedFatwa($fatwa, $this->axisVector(0));
        }

        $this->fakeParts();

        $ids = $this->ask()->assertOk()->json('fatwaIds');

        $this->assertCount(3, $ids);
        $this->assertCount(3, array_unique($ids));
        // Each part's best hit is listed first.
        $this->assertContains($ids[0], array_column($during, 'public_id'));
        $this->assertContains($ids[1], array_column($after, 'public_id'));
    }

    public function test_a_single_question_is_searched_as_before_with_no_extra_call(): void
    {
        $this->embedFatwa($this->searchFatwa('الشك في عدد الركعات أثناء الصلاة'), $this->axisVector(0));
        QuestionParts::fake();

        $this->ask(['question' => 'ماذا يفعل من شك أثناء الصلاة في عدد الركعات'])->assertOk()->assertJsonMissingPath('parts');

        QuestionParts::assertNeverPrompted();
    }

    public function test_when_the_parts_cant_be_found_the_question_is_searched_as_one(): void
    {
        $this->embedFatwa($this->searchFatwa('الشك في عدد الركعات أثناء الصلاة'), $this->axisVector(0));
        QuestionParts::fake(fn () => throw new RuntimeException('down'));

        $this->ask(['question' => 'شككت أثناء الصلاة في عدد الركعات؟ وماذا لو كان بعد السلام؟'])->assertOk()->assertJsonMissingPath('parts');
        QuestionParts::assertPrompted(fn (AgentPrompt $prompt): bool => true);
    }

    public function test_a_part_whose_search_fails_leaves_the_other_parts_their_results(): void
    {
        $during = $this->searchFatwa('الشك في عدد الركعات أثناء الصلاة', 'من شك أثناء الصلاة بنى على الأقل.');
        $this->embedFatwa($during, $this->axisVector(0));
        // The second part's query can't be embedded and has no Arabic words to search by keyword.
        Embeddings::fake(fn ($prompt) => array_map(fn (string $input) => str_contains($input, 'salam') ? throw new RuntimeException('down') : $this->axisVector(0), $prompt->inputs));
        QuestionParts::fake([[
            'parts' => [
                ['label' => 'إذا كان الشك أثناء الصلاة', 'query' => 'ماذا يفعل من شك أثناء الصلاة في عدد الركعات'],
                ['label' => 'إذا كان الشك بعد السلام', 'query' => 'doubt after the salam'],
            ],
        ]]);

        $this->ask()->assertOk()
            ->assertJsonPath('fatwaIds', [$during->public_id])
            ->assertJsonPath('parts.0.fatwaIds', [$during->public_id])
            ->assertJsonPath('parts.1.fatwaIds', []);
    }

    public function test_the_wording_check_lets_only_questions_that_may_have_parts_through(): void
    {
        $this->assertTrue(QuestionParts::mayHaveParts('أولاً لو كنت داخل الصلاة، ثانيا لو بعد الصلاة شكيت'));
        $this->assertTrue(QuestionParts::mayHaveParts('هل يجوز الجمع؟ وهل يجب القصر؟'));
        $this->assertTrue(QuestionParts::mayHaveParts('Can I combine prayers while travelling? And what if I stay a week?'));
        $this->assertFalse(QuestionParts::mayHaveParts('هل يجوز للحائض قراءة القرآن من حفظها؟'));
        $this->assertFalse(QuestionParts::mayHaveParts('What is the zakat on gold jewellery that is worn?'));
        $this->assertFalse(QuestionParts::mayHaveParts('Is it also permissible to pray sitting on a train'));
        $this->assertFalse(QuestionParts::mayHaveParts('هل يجوز أيضا أن أصلي جالسا وكذلك في القطار'));
        $this->assertFalse(QuestionParts::mayHaveParts('First I wash my hands, then my face: is my wudu valid?'));
        $this->assertTrue(QuestionParts::mayHaveParts('Firstly, may I combine prayers; secondly, may I shorten them'));
    }

    private function fakeParts(): void
    {
        QuestionParts::fake([[
            'parts' => [
                ['label' => 'إذا كان الشك أثناء الصلاة', 'query' => 'ماذا يفعل من شك أثناء الصلاة في عدد الركعات'],
                ['label' => 'إذا كان الشك بعد السلام', 'query' => 'ماذا يفعل من شك بعد السلام في عدد الركعات'],
            ],
        ]]);
    }

    private function sameCase(string $query, string $document): bool
    {
        foreach (['أثناء', 'بعد السلام'] as $case) {
            if (str_contains($query, $case) && str_contains($document, $case)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function ask(array $overrides = []): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->postJson('/api/v1/search', $overrides + [
            'question' => self::QUESTION,
            'locale' => 'ar',
        ]);
    }
}
