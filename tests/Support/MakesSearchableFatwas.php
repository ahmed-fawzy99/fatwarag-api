<?php

namespace Tests\Support;

use App\Answers\EntailmentJudge;
use App\Models\Fatwa;
use App\Models\FatwaChunk;
use App\Models\Source;
use App\Support\Arabic;
use Pgvector\Laravel\HalfVector;

/**
 * Builds fatwas, sources and embedded chunks for the search and answer tests, so none of them depends on collected data.
 */
trait MakesSearchableFatwas
{
    protected function searchSource(string $key = 'islamweb', int $tier = 1, bool $enabled = true): Source
    {
        return Source::query()->firstOrCreate(['key' => $key], [
            'kind' => 'fatwa',
            'tier' => $tier,
            'name_ar' => "مصدر {$key}",
            'name_en' => "Source {$key}",
            'base_url' => "https://{$key}.example",
            'is_enabled' => $enabled,
        ]);
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>|null  $answer
     */
    protected function searchFatwa(string $title, string $answerText = 'الحمد لله.', string $sourceKey = 'islamweb', int $tier = 1, ?array $answer = null, string $question = 'ما الحكم؟'): Fatwa
    {
        static $sequence = 0;
        $sequence++;

        $source = $this->searchSource($sourceKey, $tier);
        $answer ??= [[['type' => 'text', 'text' => $answerText]]];
        $plain = collect($answer)->flatten(1)->pluck('text')->implode(' ');

        return Fatwa::query()->create([
            'public_id' => "{$sourceKey}-{$sequence}",
            'source_id' => $source->id,
            'source_fatwa_id' => (string) $sequence,
            'url' => "https://{$sourceKey}.example/{$sequence}",
            'title' => $title,
            'question' => [[['type' => 'text', 'text' => $question]]],
            'answer' => $answer,
            'search_text' => Arabic::normalize("{$title} {$question} {$plain}"),
        ]);
    }

    /**
     * Stores one embedded chunk for the fatwa, pointing at the given unit vector axis (so tests control cosine distance).
     *
     * @param  array<int, float>|null  $vector
     */
    protected function embedFatwa(Fatwa $fatwa, ?array $vector = null, int $position = 0): FatwaChunk
    {
        return FatwaChunk::query()->create([
            'fatwa_id' => $fatwa->id,
            'position' => $position,
            'content' => Arabic::normalize($fatwa->title),
            'embedding' => new HalfVector($vector ?? $this->axisVector(0)),
            'embedding_input_type' => 'document',
        ]);
    }

    /**
     * Fakes the entailment judge: every item it is asked about is "supported", except the ids given here.
     *
     * @param  array<string, string>  $verdicts  item id => verdict
     */
    protected function fakeJudge(array $verdicts = []): void
    {
        EntailmentJudge::fake(function (string $prompt) use ($verdicts): array {
            preg_match_all('/^### (\S+)/mu', $prompt, $ids);

            return ['verdicts' => array_map(fn (string $id): array => [
                'id' => $id,
                'verdict' => $verdicts[$id] ?? 'supported',
                'reason' => 'faked',
            ], $ids[1])];
        });
    }

    /**
     * A 1024-dimension unit vector along one axis, optionally tilted towards a second axis.
     *
     * @return array<int, float>
     */
    protected function axisVector(int $axis, ?int $towards = null, float $tilt = 0.0): array
    {
        $vector = array_fill(0, 1024, 0.0);
        $vector[$axis] = 1.0 - $tilt;

        if ($towards !== null) {
            $vector[$towards] = $tilt;
        }

        $length = sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $vector)));

        return array_map(fn (float $v): float => $v / $length, $vector);
    }
}
