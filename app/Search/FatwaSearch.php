<?php

namespace App\Search;

use App\Models\Fatwa;
use App\Services\AiSpend;
use App\Services\Alerts;
use App\Support\Arabic;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;
use Pgvector\HalfVector;
use Throwable;

/**
 * Two-pass search: keyword search and meaning search find candidates, merged by reciprocal rank fusion;
 * a re-ranker then scores how well each candidate answers the question and sets the "nothing found" cut-off.
 * Source tier only breaks near-ties.
 */
class FatwaSearch
{
    public const KEYWORD_LIMIT = 40;

    /** Time limit for the any-word keyword pass, which can match much of the library through a common word. */
    public const KEYWORD_OR_TIMEOUT_MS = 1500;

    public const VECTOR_CHUNK_LIMIT = 60;

    public const VECTOR_FATWA_LIMIT = 40;

    public const RRF_K = 60;

    public const CANDIDATES = 30;

    /** Default cap on the fatwas an answer lists (config `fatwarag.search.max_results`). */
    public const MAX_RESULTS = 5;

    public const NEAR_TIE = 0.02;

    /** Results scoring this far below the best one are tangential, even when they clear the absolute floor. */
    public const RELATIVE_MARGIN = 0.35;

    public const RERANK_ANSWER_CHARS = 1500;

    /**
     * Words too common to help keyword ranking (normalized spelling). The `simple` text config has no stopwords.
     */
    private const STOPWORDS = [
        'هل', 'من', 'في', 'على', 'الى', 'عن', 'ان', 'او', 'ما', 'ماذا', 'لا', 'يا', 'و', 'ثم', 'هذا', 'هذه', 'ذلك', 'تلك',
        'التي', 'الذي', 'الذين', 'كان', 'كانت', 'مع', 'قد', 'كل', 'بعد', 'قبل', 'عند', 'اذا', 'لم', 'لن', 'هو', 'هي', 'انا',
        'نحن', 'انت', 'هم', 'به', 'بها', 'له', 'لها', 'فيه', 'فيها', 'منه', 'منها', 'عليه', 'عليها', 'حكم', 'يجوز', 'ايجوز',
        'وهل', 'وما', 'لي', 'ولا', 'لكن', 'اي', 'كيف', 'متي', 'لماذا', 'الله', 'شيخ', 'السلام', 'عليكم',
    ];

    public function __construct(private AiSpend $spend, private Alerts $alerts) {}

    /**
     * Results best first, each marked `ranked` (false when the re-ranker failed and they are in keyword/meaning order,
     * without the relevance cut-off). Empty when nothing passes the relevance floor.
     *
     * @return list<array{fatwaId: int, score: float, ranked: bool}>
     *
     * @throws SearchUnavailable when the question can't be searched at all
     */
    public function search(string $question, string $locale, ?int $limit = null): array
    {
        $vector = $this->embedQuestion($question);

        if ($vector === null && $this->keywordTerms($question) === []) {
            throw new SearchUnavailable('The question could not be embedded and has no Arabic words for keyword search.');
        }

        $candidates = $this->candidates($question, $vector);

        if ($candidates === []) {
            return [];
        }

        return $this->rerank($question, $candidates, $limit ?? $this->maxResults());
    }

    /**
     * Keyword and meaning candidates merged by reciprocal rank fusion, best first. A pass that fails in the database
     * is skipped; if every pass that could run failed, search is unavailable.
     *
     * @param  array<int, float>|null  $vector
     * @return list<int>
     */
    public function candidates(string $question, ?array $vector): array
    {
        $terms = $this->keywordTerms($question);
        $passes = array_filter([
            'keyword' => $terms !== [] ? fn (): array => $this->keywordMatches($terms) : null,
            'vector' => $vector !== null ? fn (): array => $this->vectorMatches($vector) : null,
        ]);

        $lists = [];

        foreach ($passes as $name => $pass) {
            try {
                $lists[] = $pass();
            } catch (QueryException $exception) {
                // The exception carries the SQL bindings, which hold the question: never report or log it.
                $this->alerts->raise('Search query failed', "search:{$name}-query", "The {$name} search pass failed in the database; search used the other pass.", [
                    'sqlState' => (string) ($exception->errorInfo[0] ?? 'unknown'),
                ]);
            }
        }

        if ($passes !== [] && $lists === []) {
            throw new SearchUnavailable('Every search pass failed.');
        }

        $scores = [];

        foreach ($lists as $list) {
            foreach ($list as $rank => $fatwaId) {
                $scores[$fatwaId] = ($scores[$fatwaId] ?? 0) + 1 / (self::RRF_K + $rank + 1);
            }
        }

        arsort($scores);

        return array_slice(array_keys($scores), 0, self::CANDIDATES);
    }

    /**
     * The question's searchable words: Arabic, normalized, without stopwords. English questions have none (the
     * keyword index is Arabic) and are searched by meaning.
     *
     * @return list<string>
     */
    private function keywordTerms(string $question): array
    {
        if (! preg_match('/\p{Arabic}/u', $question)) {
            return [];
        }

        return array_slice(array_values(array_unique(array_filter(
            explode(' ', Arabic::normalize($question)),
            fn (string $word): bool => mb_strlen($word) > 1 && preg_match('/\p{Arabic}/u', $word) && ! in_array($word, self::STOPWORDS, true),
        ))), 0, 16);
    }

    /**
     * Fatwas with every word first, then fatwas with any of them, each ranked by `ts_rank_cd`. The any-word pass may
     * match much of the library through a common word, so it runs under a time limit and is skipped if it hits it.
     *
     * @param  list<string>  $terms
     * @return list<int>
     */
    private function keywordMatches(array $terms): array
    {
        $all = $this->rankedMatches(implode(' ', $terms));

        if (count($all) >= self::KEYWORD_LIMIT || count($terms) === 1) {
            return $all;
        }

        try {
            $any = DB::transaction(function () use ($terms): array {
                DB::statement("SET LOCAL statement_timeout = '".self::KEYWORD_OR_TIMEOUT_MS."ms'");

                return $this->rankedMatches(implode(' or ', $terms));
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '57014') { // anything but "statement timed out"
                throw $exception;
            }

            $any = [];
        }

        return array_slice(array_values(array_unique([...$all, ...$any])), 0, self::KEYWORD_LIMIT);
    }

    /**
     * @return list<int>
     */
    private function rankedMatches(string $websearch): array
    {
        return array_map('intval', DB::table('fatwas')
            ->join('sources', 'sources.id', '=', 'fatwas.source_id')
            ->where('sources.is_enabled', true)
            ->whereRaw("fatwas.search_vector @@ websearch_to_tsquery('simple', ?)", [$websearch])
            ->orderByRaw("ts_rank_cd(fatwas.search_vector, websearch_to_tsquery('simple', ?)) DESC", [$websearch])
            ->orderBy('fatwas.id')
            ->limit(self::KEYWORD_LIMIT)
            ->pluck('fatwas.id')
            ->all());
    }

    /**
     * @param  array<int, float>  $vector
     * @return list<int>
     */
    private function vectorMatches(array $vector): array
    {
        $literal = (string) new HalfVector($vector);

        return DB::transaction(function () use ($literal): array {
            // HNSW returns at most ef_search rows; the default (40) is below the 60 chunks we want.
            DB::statement('SET LOCAL hnsw.ef_search = 200');

            $rows = DB::select(
                'SELECT nearest.fatwa_id, MIN(nearest.distance) AS distance
                 FROM (
                     SELECT fatwa_id, embedding <=> ?::halfvec AS distance
                     FROM fatwa_chunks
                     WHERE embedding IS NOT NULL
                     ORDER BY embedding <=> ?::halfvec
                     LIMIT ?
                 ) AS nearest
                 JOIN fatwas ON fatwas.id = nearest.fatwa_id
                 JOIN sources ON sources.id = fatwas.source_id AND sources.is_enabled
                 GROUP BY nearest.fatwa_id
                 ORDER BY distance, nearest.fatwa_id
                 LIMIT ?',
                [$literal, $literal, self::VECTOR_CHUNK_LIMIT, self::VECTOR_FATWA_LIMIT],
            );

            return array_map(fn (object $row): int => (int) $row->fatwa_id, $rows);
        });
    }

    /**
     * @return array<int, float>|null
     */
    private function embedQuestion(string $question): ?array
    {
        $model = (string) config('fatwarag.openrouter.models.embedding');

        try {
            $response = Embeddings::for([$question])
                ->dimensions(EmbeddingIndexer::DIMENSIONS)
                ->withProviderOptions(['input_type' => 'query'])
                ->timeout(15)
                ->generate(model: $model);
        } catch (Throwable $exception) {
            // Only the exception class: a provider message can echo the input, and questions are never logged.
            $this->alerts->raise('Search embedding failed', 'search:embedding', 'Questions are searched by keywords only until the embeddings provider recovers.', [
                'error' => $exception::class,
            ]);

            return null;
        }

        $this->alerts->resolve('search:embedding');
        $tokens = $response->usage->inputTokens;

        if ($tokens > 0) {
            $this->spend->record('search_embedding', $model, $tokens, 0, AiSpend::estimate('embedding', $tokens));
        }

        return $response->embeddings[0] ?? null;
    }

    /**
     * @param  list<int>  $candidates  RRF order
     * @return list<array{fatwaId: int, score: float, ranked: bool}>
     */
    private function rerank(string $question, array $candidates, int $limit): array
    {
        $fatwas = Fatwa::query()
            ->with('source:id,tier')
            ->whereKey($candidates)
            ->get(['id', 'source_id', 'title', 'answer'])
            ->keyBy('id');

        $candidates = array_values(array_filter($candidates, fn (int $id): bool => $fatwas->has($id)));
        $documents = array_map(fn (int $id): string => $this->document($fatwas[$id]), $candidates);
        $model = (string) config('fatwarag.openrouter.models.rerank');

        try {
            $response = Reranking::of($documents)->timeout(15)->rerank($question, model: $model);
        } catch (Throwable $exception) {
            $this->alerts->raise('Re-ranker failed', 'search:rerank', 'Search results fall back to keyword and meaning order, without the relevance cut-off.', [
                'error' => $exception::class,
            ]);

            return array_map(
                fn (int $id, int $rank): array => ['fatwaId' => $id, 'score' => round(1 / (self::RRF_K + $rank + 1), 6), 'ranked' => false],
                array_slice($candidates, 0, $limit),
                array_keys(array_slice($candidates, 0, $limit)),
            );
        }

        $this->alerts->resolve('search:rerank');

        if ($response->usage->inputTokens > 0) {
            $this->spend->record('search_rerank', $model, $response->usage->inputTokens, 0, AiSpend::estimate('rerank', $response->usage->inputTokens));
        }

        $best = collect($response->results)->max(fn (RankedDocument $result): float => $result->score) ?? 0.0;
        $floor = max((float) config('fatwarag.ask.relevance_floor'), $best - self::RELATIVE_MARGIN);
        $ranked = collect($response->results)
            ->filter(fn (RankedDocument $result): bool => $result->score >= $floor && isset($candidates[$result->index]))
            ->map(fn (RankedDocument $result): array => [
                'fatwaId' => $candidates[$result->index],
                'score' => $result->score,
                'tier' => (int) ($fatwas[$candidates[$result->index]]->source->tier ?? 9),
            ])
            ->sortByDesc('score')
            ->values()
            ->all();

        return array_map(
            fn (array $result): array => ['fatwaId' => $result['fatwaId'], 'score' => $result['score'], 'ranked' => true],
            array_slice($this->breakNearTies($ranked), 0, $limit),
        );
    }

    /**
     * How many fatwas an answer lists at most, after re-ranking.
     */
    private function maxResults(): int
    {
        return max(1, (int) config('fatwarag.search.max_results', self::MAX_RESULTS));
    }

    /**
     * Within a run of scores no more than NEAR_TIE below the run's best, the better tier (1 first) comes first.
     *
     * @param  list<array{fatwaId: int, score: float, tier: int}>  $ranked  sorted by score, best first
     * @return list<array{fatwaId: int, score: float, tier: int}>
     */
    private function breakNearTies(array $ranked): array
    {
        $ordered = [];
        $group = [];

        foreach ($ranked as $result) {
            if ($group !== [] && $group[0]['score'] - $result['score'] > self::NEAR_TIE) {
                array_push($ordered, ...$this->byTier($group));
                $group = [];
            }

            $group[] = $result;
        }

        array_push($ordered, ...$this->byTier($group));

        return $ordered;
    }

    /**
     * @param  list<array{fatwaId: int, score: float, tier: int}>  $group
     * @return list<array{fatwaId: int, score: float, tier: int}>
     */
    private function byTier(array $group): array
    {
        usort($group, fn (array $a, array $b): int => [$a['tier'], -$a['score']] <=> [$b['tier'], -$b['score']]);

        return $group;
    }

    /**
     * What the re-ranker reads for a fatwa: its title and the start of its answer, without harakat.
     */
    private function document(Fatwa $fatwa): string
    {
        $answer = collect($fatwa->answer ?? [])
            ->map(fn (array $paragraph): string => implode('', array_map(fn (array $segment): string => (string) ($segment['text'] ?? ''), $paragraph)))
            ->implode("\n");

        $text = $fatwa->title."\n".mb_substr($answer, 0, self::RERANK_ANSWER_CHARS);
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text);

        return trim($text) !== '' ? $text : (string) $fatwa->id;
    }
}
