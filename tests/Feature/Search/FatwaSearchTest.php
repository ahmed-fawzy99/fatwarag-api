<?php

namespace Tests\Feature\Search;

use App\Models\Alert;
use App\Search\FatwaSearch;
use App\Search\SearchUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;
use RuntimeException;
use Tests\Support\MakesSearchableFatwas;
use Tests\TestCase;

class FatwaSearchTest extends TestCase
{
    use MakesSearchableFatwas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        config(['fatwarag.ask.relevance_floor' => 0.35]);
    }

    public function test_keyword_and_vector_results_are_merged_by_rrf(): void
    {
        $this->fakeQuestionVector($this->axisVector(0));

        $keywordOnly = $this->searchFatwa('زكاة الذهب المستعمل');
        $this->embedFatwa($keywordOnly, $this->axisVector(9));
        $vectorOnly = $this->searchFatwa('حلي النساء');
        $this->embedFatwa($vectorOnly, $this->axisVector(0, 1, 0.1));
        $both = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($both, $this->axisVector(0, 1, 0.05));
        $neither = $this->searchFatwa('صلاة المسافر');
        $this->embedFatwa($neither, $this->axisVector(500));

        $candidates = app(FatwaSearch::class)->candidates('زكاة الذهب', $this->axisVector(0));

        $this->assertSame($both->id, $candidates[0]);
        $this->assertContains($keywordOnly->id, $candidates);
        $this->assertContains($vectorOnly->id, $candidates);
        $this->assertLessThan(array_search($neither->id, $candidates), array_search($vectorOnly->id, $candidates));
    }

    public function test_reranker_orders_final_list(): void
    {
        $this->fakeQuestionVector($this->axisVector(0));
        $first = $this->searchFatwa('زكاة الذهب أولا');
        $second = $this->searchFatwa('زكاة الذهب ثانيا');
        $this->embedFatwa($first, $this->axisVector(0));
        $this->embedFatwa($second, $this->axisVector(0, 1, 0.3));

        $this->fakeRerank(['ثانيا' => 0.9, 'أولا' => 0.6]);

        $results = app(FatwaSearch::class)->search('زكاة الذهب', 'ar');

        $this->assertSame([$second->id, $first->id], array_column($results, 'fatwaId'));
        $this->assertEqualsWithDelta(0.9, $results[0]['score'], 0.0001);
    }

    public function test_an_answer_lists_at_most_the_configured_number_of_fatwas(): void
    {
        $this->fakeQuestionVector($this->axisVector(0));
        $scores = [];

        foreach (['أول', 'ثان', 'ثالث', 'رابع', 'خامس', 'سادس', 'سابع'] as $i => $marker) {
            $this->embedFatwa($this->searchFatwa("زكاة الذهب {$marker}"), $this->axisVector(0, 1, 0.01 * $i));
            $scores[$marker] = 0.9 - 0.01 * $i;
        }

        $this->fakeRerank($scores);

        $this->assertCount(5, app(FatwaSearch::class)->search('زكاة الذهب', 'ar'));

        config(['fatwarag.search.max_results' => 3]);
        $this->fakeRerank($scores);

        $this->assertCount(3, app(FatwaSearch::class)->search('زكاة الذهب', 'ar'));
    }

    public function test_near_ties_broken_by_tier(): void
    {
        $this->fakeQuestionVector($this->axisVector(0));
        $tierThree = $this->searchFatwa('زكاة الذهب فوزان', sourceKey: 'fawzan', tier: 3);
        $tierOne = $this->searchFatwa('زكاة الذهب ويب', sourceKey: 'islamweb', tier: 1);
        $tierTwo = $this->searchFatwa('زكاة الذهب باز', sourceKey: 'binbaz', tier: 2);
        $clearWinner = $this->searchFatwa('زكاة الذهب سؤال', sourceKey: 'binbaz', tier: 2);

        foreach ([$tierThree, $tierOne, $tierTwo, $clearWinner] as $fatwa) {
            $this->embedFatwa($fatwa, $this->axisVector(0));
        }

        $this->fakeRerank(['سؤال' => 0.95, 'فوزان' => 0.80, 'باز' => 0.79, 'ويب' => 0.785]);

        $results = app(FatwaSearch::class)->search('زكاة الذهب', 'ar');

        $this->assertSame([$clearWinner->id, $tierOne->id, $tierTwo->id, $tierThree->id], array_column($results, 'fatwaId'));
    }

    public function test_irrelevant_question_returns_not_found(): void
    {
        $this->fakeQuestionVector($this->axisVector(0));
        $fatwa = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($fatwa, $this->axisVector(0));

        $this->fakeRerank(['زكاة' => 0.2]);

        $this->assertSame([], app(FatwaSearch::class)->search('ما لون السماء يوم الثلاثاء', 'ar'));
    }

    public function test_tangential_results_far_below_the_best_are_dropped(): void
    {
        $this->fakeQuestionVector($this->axisVector(0));
        $direct = $this->searchFatwa('زكاة الذهب مباشر');
        $tangential = $this->searchFatwa('زكاة الذهب بعيد');
        $this->embedFatwa($direct, $this->axisVector(0));
        $this->embedFatwa($tangential, $this->axisVector(0));

        $this->fakeRerank(['مباشر' => 0.95, 'بعيد' => 0.5]);

        $this->assertSame([$direct->id], array_column(app(FatwaSearch::class)->search('زكاة الذهب', 'ar'), 'fatwaId'));
    }

    public function test_reranker_failure_falls_back_to_rrf_order(): void
    {
        $this->fakeQuestionVector($this->axisVector(0));
        $close = $this->searchFatwa('زكاة الذهب');
        $far = $this->searchFatwa('أحكام الحج');
        $this->embedFatwa($close, $this->axisVector(0));
        $this->embedFatwa($far, $this->axisVector(0, 1, 0.5));

        Reranking::fake(fn () => throw new RuntimeException('reranker down'));

        $results = app(FatwaSearch::class)->search('زكاة الذهب', 'ar');

        $this->assertSame([$close->id, $far->id], array_column($results, 'fatwaId'));
        $this->assertSame([false, false], array_column($results, 'ranked'));
    }

    public function test_reranked_results_are_marked_ranked(): void
    {
        $this->fakeQuestionVector($this->axisVector(0));
        $this->embedFatwa($this->searchFatwa('زكاة الذهب'), $this->axisVector(0));
        $this->fakeRerank(['زكاة' => 0.9]);

        $this->assertSame([true], array_column(app(FatwaSearch::class)->search('زكاة الذهب', 'ar'), 'ranked'));
        Embeddings::assertGenerated(fn ($prompt): bool => ($prompt->providerOptions['input_type'] ?? null) === 'query');
    }

    public function test_database_failure_in_one_pass_degrades_to_the_other_without_logging_the_question(): void
    {
        $match = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($match, $this->axisVector(0));

        // A vector of the wrong size makes the meaning pass fail inside PostgreSQL.
        $candidates = app(FatwaSearch::class)->candidates('زكاة الذهب', [0.1, 0.2, 0.3]);

        $this->assertSame([$match->id], $candidates);
        $alert = Alert::query()->where('fingerprint', 'search:vector-query')->sole();
        $this->assertStringNotContainsString('زكاة', json_encode($alert->context, JSON_UNESCAPED_UNICODE).$alert->message);
    }

    public function test_every_pass_failing_is_unavailable(): void
    {
        $this->embedFatwa($this->searchFatwa('زكاة الذهب'), $this->axisVector(0));

        $this->expectException(SearchUnavailable::class);

        app(FatwaSearch::class)->candidates('Is there zakat on gold?', [0.1, 0.2, 0.3]);
    }

    public function test_stopword_only_arabic_question_during_embedding_outage_is_unavailable(): void
    {
        Embeddings::fake(fn () => throw new RuntimeException('embeddings down'));

        $this->expectException(SearchUnavailable::class);

        app(FatwaSearch::class)->search('هل من في ما', 'ar');
    }

    public function test_english_question_skips_keyword_search_and_uses_meaning(): void
    {
        $this->fakeQuestionVector($this->axisVector(0));
        $match = $this->searchFatwa('زكاة الذهب');
        $this->embedFatwa($match, $this->axisVector(0));

        $this->fakeRerank(['زكاة' => 0.8]);

        $results = app(FatwaSearch::class)->search('Is there zakat on gold jewellery?', 'en');

        $this->assertSame([$match->id], array_column($results, 'fatwaId'));
        Embeddings::assertGenerated(fn ($prompt): bool => $prompt->contains('Is there zakat on gold jewellery?'));
    }

    public function test_disabled_sources_are_not_searched(): void
    {
        $this->fakeQuestionVector($this->axisVector(0));
        $this->searchSource('fawzan', 3, enabled: false);
        $hidden = $this->searchFatwa('زكاة الذهب', sourceKey: 'fawzan', tier: 3);
        $this->embedFatwa($hidden, $this->axisVector(0));

        $this->fakeRerank(['زكاة' => 0.9]);

        $this->assertSame([], app(FatwaSearch::class)->search('زكاة الذهب', 'ar'));
    }

    public function test_embedding_failure_falls_back_to_keywords_for_arabic(): void
    {
        Embeddings::fake(fn () => throw new RuntimeException('embeddings down'));
        $match = $this->searchFatwa('زكاة الذهب');

        $this->fakeRerank(['زكاة' => 0.8]);

        $this->assertSame([$match->id], array_column(app(FatwaSearch::class)->search('زكاة الذهب', 'ar'), 'fatwaId'));
    }

    public function test_embedding_failure_on_english_question_is_unavailable(): void
    {
        Embeddings::fake(fn () => throw new RuntimeException('embeddings down'));

        $this->expectException(SearchUnavailable::class);

        app(FatwaSearch::class)->search('Is there zakat on gold?', 'en');
    }

    /**
     * @param  array<int, float>  $vector
     */
    private function fakeQuestionVector(array $vector): void
    {
        Embeddings::fake(fn ($prompt) => array_map(fn () => $vector, $prompt->inputs));
    }

    /**
     * Scores each document by the first marker word it contains.
     *
     * @param  array<string, float>  $scores
     */
    private function fakeRerank(array $scores): void
    {
        Reranking::fake(function (RerankingPrompt $prompt) use ($scores): array {
            $ranked = [];

            foreach ($prompt->documents as $index => $document) {
                $score = 0.0;

                foreach ($scores as $marker => $value) {
                    if (str_contains($document, $marker)) {
                        $score = $value;
                        break;
                    }
                }

                $ranked[] = new RankedDocument($index, $document, $score);
            }

            usort($ranked, fn (RankedDocument $a, RankedDocument $b): int => $b->score <=> $a->score);

            return $ranked;
        });
    }
}
