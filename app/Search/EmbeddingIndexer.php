<?php

namespace App\Search;

use App\Models\AiUsage;
use App\Models\Fatwa;
use App\Models\FatwaChunk;
use App\Services\AiSpend;
use App\Services\Alerts;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Pgvector\HalfVector;
use Throwable;

/**
 * Keeps `fatwa_chunks` in step with the fatwas and embeds every chunk that has no embedding yet.
 *
 * Re-runnable and incremental: new fatwas get chunks, fatwas changed since they were chunked are re-chunked, and only
 * chunks without an embedding are sent. Indexing cost is recorded (outside the monthly budget) and capped in total.
 */
class EmbeddingIndexer
{
    public const PURPOSE = 'embedding_index';

    public const BATCH_SIZE = 64;

    public const DIMENSIONS = 1024;

    /** Voyage embeds fatwa text as documents and questions as queries (FatwaSearch). */
    public const INPUT_TYPE = 'document';

    /** Rough Arabic characters per token, used only to estimate a batch before sending it. */
    private const CHARS_PER_TOKEN = 2.5;

    public function __construct(private Chunker $chunker, private AiSpend $spend, private Alerts $alerts) {}

    /**
     * @return array{chunked: int, embedded: int, costUsd: float, stoppedAtCap: bool, failed: bool}
     */
    public function run(?int $limit = null, float $maxCost = 45.0): array
    {
        $chunked = $this->chunkPending($limit);
        $result = ['chunked' => $chunked, 'embedded' => 0, 'costUsd' => 0.0, 'stoppedAtCap' => false, 'failed' => false];

        $spent = $this->spentSoFar();
        $lastId = 0;
        $phase = 'missing'; // chunks without an embedding first, then chunks embedded before input types were used

        while ($limit === null || $result['embedded'] < $limit) {
            $chunks = FatwaChunk::query()
                ->when(
                    $phase === 'missing',
                    fn ($query) => $query->whereNull('embedding'),
                    fn ($query) => $query->whereNotNull('embedding')->where(fn ($outdated) => $outdated
                        ->whereNull('embedding_input_type')
                        ->orWhere('embedding_input_type', '!=', self::INPUT_TYPE)),
                )
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($limit === null ? self::BATCH_SIZE : min(self::BATCH_SIZE, $limit - $result['embedded']))
                ->get(['id', 'content']);

            if ($chunks->isEmpty()) {
                if ($phase === 'outdated') {
                    break;
                }

                [$phase, $lastId] = ['outdated', 0];

                continue;
            }

            $lastId = $chunks->last()->id;
            $chunks = $chunks->filter(fn (FatwaChunk $chunk): bool => trim($chunk->content) !== '')->values();

            if ($chunks->isEmpty()) {
                continue;
            }

            $estimate = AiSpend::estimate('embedding', (int) ceil($chunks->sum(fn (FatwaChunk $chunk): int => mb_strlen($chunk->content)) / self::CHARS_PER_TOKEN));

            if ($spent + $estimate > $maxCost) {
                $result['stoppedAtCap'] = true;
                $this->alerts->raise(
                    'Embedding cost cap reached',
                    'embeddings:cost-cap',
                    sprintf('Indexing stopped at %.2f USD of the %.2f USD cap; some fatwas are not searchable by meaning yet.', $spent, $maxCost),
                    nextStep: 'Raise the cap: php artisan fatwas:embed --max-cost=<usd>',
                );
                break;
            }

            try {
                $response = Embeddings::for($chunks->pluck('content')->all())
                    ->dimensions(self::DIMENSIONS)
                    ->withProviderOptions(['input_type' => self::INPUT_TYPE])
                    ->timeout(120)
                    ->generate(model: config('fatwarag.openrouter.models.embedding'));
            } catch (Throwable $exception) {
                $result['failed'] = true;
                $this->alerts->raise(
                    'Embedding failed',
                    'embeddings:upstream',
                    'The embeddings provider failed; indexing stopped and resumes on the next run.',
                    ['error' => $exception::class],
                );
                break;
            }

            if (count($response->embeddings) !== $chunks->count()) {
                $result['failed'] = true;
                $this->alerts->raise('Embedding failed', 'embeddings:upstream', 'The embeddings provider returned the wrong number of vectors.');
                break;
            }

            $tokens = $response->usage->inputTokens ?: (int) ceil($chunks->sum(fn (FatwaChunk $chunk): int => mb_strlen($chunk->content)) / self::CHARS_PER_TOKEN);
            $cost = AiSpend::estimate('embedding', $tokens);
            $this->spend->record(self::PURPOSE, (string) config('fatwarag.openrouter.models.embedding'), $tokens, 0, $cost, countsTowardBudget: false);

            $this->store($chunks->pluck('id')->all(), $response->embeddings);

            $spent += $cost;
            $result['costUsd'] += $cost;
            $result['embedded'] += $chunks->count();
        }

        if (! $result['failed']) {
            $this->alerts->resolve('embeddings:upstream');
        }

        if (! $result['stoppedAtCap']) {
            $this->alerts->resolve('embeddings:cost-cap');
        }

        return $result;
    }

    /**
     * Creates chunks for fatwas that have none, and re-chunks fatwas changed since they were chunked.
     */
    public function chunkPending(?int $limit = null): int
    {
        $count = 0;

        Fatwa::query()
            ->where(fn ($query) => $query
                ->whereDoesntHave('chunks')
                ->orWhereHas('chunks', fn ($chunks) => $chunks->whereColumn('fatwa_chunks.created_at', '<', 'fatwas.updated_at')))
            ->select(['id', 'title', 'question', 'answer'])
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->chunkById(200, function ($fatwas) use (&$count): void {
                foreach ($fatwas as $fatwa) {
                    DB::transaction(function () use ($fatwa): void {
                        $chunks = $this->chunker->chunk($fatwa);
                        $now = now();

                        // A change that leaves the searchable text as it was (e.g. a quote linked later) keeps its embeddings.
                        $existing = FatwaChunk::query()->where('fatwa_id', $fatwa->id)->orderBy('position')->pluck('content')->all();

                        if ($existing === $chunks) {
                            FatwaChunk::query()->where('fatwa_id', $fatwa->id)->update(['created_at' => $now]);

                            return;
                        }

                        FatwaChunk::query()->where('fatwa_id', $fatwa->id)->delete();
                        $rows = [];

                        foreach ($chunks as $position => $content) {
                            $rows[] = ['fatwa_id' => $fatwa->id, 'position' => $position, 'content' => $content, 'created_at' => $now, 'updated_at' => $now];
                        }

                        FatwaChunk::query()->insert($rows);
                    });

                    $count++;
                }
            });

        return $count;
    }

    private function spentSoFar(): float
    {
        return (float) AiUsage::query()->where('purpose', self::PURPOSE)->sum('cost_usd');
    }

    /**
     * @param  list<int>  $ids
     * @param  list<array<int, float>>  $embeddings
     */
    private function store(array $ids, array $embeddings): void
    {
        $values = [];
        $bindings = [];

        foreach ($ids as $index => $id) {
            $values[] = '(?::bigint, ?::halfvec)';
            $bindings[] = $id;
            $bindings[] = (string) new HalfVector($embeddings[$index]);
        }

        DB::update(
            'UPDATE fatwa_chunks SET embedding = v.embedding, embedding_input_type = \''.self::INPUT_TYPE.'\', updated_at = now() FROM (VALUES '.implode(', ', $values).') AS v(id, embedding) WHERE fatwa_chunks.id = v.id',
            $bindings,
        );
    }
}
