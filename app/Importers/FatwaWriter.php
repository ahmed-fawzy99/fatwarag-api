<?php

namespace App\Importers;

use App\Content\HadithQuoteRegistry;
use App\Content\RichTextBuilder;
use App\Models\Fatwa;
use App\Models\FatwaLink;
use App\Models\FatwaTranslation;
use App\Models\Source;
use App\Support\Arabic;
use Illuminate\Support\Facades\DB;

/**
 * Saves one parsed fatwa: upserts it on (source, source_fatwa_id), keeps hadith quote counts in step with the quotes
 * it gained or lost, syncs its categories and its cross-reference links, and stores the source's own translation.
 */
class FatwaWriter
{
    public function __construct(private HadithQuoteRegistry $registry) {}

    /**
     * @param  array{source_fatwa_id: string, url: string, title: string, question: list<list<array<string, mixed>>>, answer: list<list<array<string, mixed>>>, source_summary?: ?list<list<array<string, mixed>>>, origin?: ?string, hijri?: ?array{day: int, month: int, year: int}, date_gregorian?: ?string, is_cross_reference?: bool, mufti_id?: ?int, collected_at?: mixed, parser_version?: int}  $data
     * @param  array<string, string>  $quotes  hadith quote texts by quote key, from RichTextBuilder::collectedQuotes()
     * @param  ?list<int>  $categoryIds  source_categories ids; null leaves them as they are
     * @param  ?array{locale: string, title: string, question: ?list<list<array<string, mixed>>>, answer: ?list<list<array<string, mixed>>>, source_summary?: ?list<list<array<string, mixed>>>, url: ?string}  $translation  the source's own translation
     */
    public function save(Source $source, array $data, array $quotes = [], ?array $categoryIds = null, ?array $translation = null): Fatwa
    {
        return DB::transaction(function () use ($source, $data, $quotes, $categoryIds, $translation): Fatwa {
            $fatwa = Fatwa::query()->firstOrNew(['source_id' => $source->id, 'source_fatwa_id' => $data['source_fatwa_id']]);
            $previousKeys = $fatwa->exists ? self::quoteKeys($fatwa) : [];

            $fatwa->fill([
                'public_id' => "{$source->key}-{$data['source_fatwa_id']}",
                'url' => $data['url'],
                'mufti_id' => $data['mufti_id'] ?? null,
                'title' => $data['title'],
                'question' => $data['question'],
                'answer' => $data['answer'],
                'source_summary' => $data['source_summary'] ?? null,
                'origin' => $data['origin'] ?? null,
                'hijri_day' => $data['hijri']['day'] ?? null,
                'hijri_month' => $data['hijri']['month'] ?? null,
                'hijri_year' => $data['hijri']['year'] ?? null,
                'date_gregorian' => $data['date_gregorian'] ?? null,
                'is_cross_reference' => $data['is_cross_reference'] ?? false,
                'search_text' => Arabic::normalize(implode("\n", [
                    $data['title'],
                    RichTextBuilder::plainText($data['source_summary'] ?? null),
                    RichTextBuilder::plainText($data['question']),
                    RichTextBuilder::plainText($data['answer']),
                ])),
                'collected_at' => $data['collected_at'] ?? now(),
                'parser_version' => $data['parser_version'] ?? null,
            ])->save();

            $this->syncQuotes($previousKeys, self::quoteKeys($fatwa), $quotes);
            $this->syncLinks($fatwa);

            if ($categoryIds !== null) {
                $fatwa->categories()->sync($categoryIds);
            }

            if ($translation !== null) {
                FatwaTranslation::query()->updateOrCreate(
                    ['fatwa_id' => $fatwa->id, 'locale' => $translation['locale']],
                    ['kind' => 'source', 'title' => $translation['title'], 'question' => $translation['question'], 'answer' => $translation['answer'],
                        'source_summary' => $translation['source_summary'] ?? null, 'url' => $translation['url'], 'model' => null],
                );
            }

            return $fatwa;
        });
    }

    /**
     * Distinct hadith quote keys in a fatwa's Arabic text.
     *
     * @return list<string>
     */
    public static function quoteKeys(Fatwa $fatwa): array
    {
        $keys = [];

        foreach ([$fatwa->question, $fatwa->answer, $fatwa->source_summary] as $paragraphs) {
            foreach ($paragraphs ?? [] as $paragraph) {
                foreach ($paragraph as $segment) {
                    if ($segment['type'] === 'hadith' && isset($segment['quoteKey'])) {
                        $keys[$segment['quoteKey']] = true;
                    }
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * @param  list<string>  $previous
     * @param  list<string>  $current
     * @param  array<string, string>  $quotes
     */
    private function syncQuotes(array $previous, array $current, array $quotes): void
    {
        foreach (array_diff($current, $previous) as $key) {
            if (isset($quotes[$key])) {
                $this->registry->register($quotes[$key]);
            }
        }

        foreach (array_diff($previous, $current) as $key) {
            $this->registry->release($key);
        }
    }

    /**
     * One link row per fatwa the text points to, keeping the sentence around the reference as its anchor text.
     */
    private function syncLinks(Fatwa $fatwa): void
    {
        $targets = [];

        foreach ([$fatwa->question, $fatwa->answer] as $paragraphs) {
            foreach ($paragraphs as $paragraph) {
                foreach ($paragraph as $segment) {
                    if ($segment['type'] !== 'fatwaRef') {
                        continue;
                    }

                    $target = preg_replace('/^[a-z]+-/', '', $segment['fatwaId']);
                    if ($target !== $fatwa->source_fatwa_id && (int) $target !== 0 && ! isset($targets[$target])) {
                        $targets[$target] = mb_substr(RichTextBuilder::plainText([$paragraph]), 0, 500);
                    }
                }
            }
        }

        $fatwa->links()->whereNotIn('target_source_fatwa_id', array_keys($targets) ?: [''])->delete();

        $position = 0;
        foreach ($targets as $target => $anchor) {
            FatwaLink::query()->updateOrCreate(
                ['fatwa_id' => $fatwa->id, 'target_source_fatwa_id' => (string) $target],
                ['anchor_text' => $anchor, 'position' => $position++],
            );
        }
    }
}
