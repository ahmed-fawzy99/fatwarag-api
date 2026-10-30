<?php

namespace App\Translation;

use App\Models\Fatwa;
use App\Models\FatwaTranslation;
use App\Services\AiSpend;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Translates a fatwa's body the first time someone opens it in a language, then keeps it for every later reader.
 *
 * Segments are translated in place: text, hadith, scholar and fatwa-reference segments get English text (a hadith
 * keeps its quoteKey, so its link still resolves), while Quran segments keep the Arabic, because the page shows
 * the Saheeh International translation for them. Structure is rebuilt by us; the model only sees plain strings.
 */
class BodyTranslator
{
    private const SECTIONS = ['question', 'answer', 'source_summary'];

    /** Characters per AI call: small enough that one call finishes well within the reader's wait. */
    private const CHUNK_CHARS = 2000;

    private const CHUNK_ITEMS = 40;

    /**
     * How long the lock lives: the reader's wait plus one call (25 + 90), the background job's wait plus one call
     * (200 + 90), with margin. Work also stops starting new calls when the lock has less than one call timeout left.
     */
    public const LOCK_SECONDS = 600;

    public function __construct(private TextTranslator $translator, private AiSpend $spend) {}

    /**
     * The lock that makes sure one request at a time translates a given fatwa, so nobody pays twice. Its lifetime covers a
     * background continuation, which takes the lock over from the request that started the work.
     */
    public function lockFor(Fatwa $fatwa, string $locale): Lock
    {
        return Cache::lock($this->lockName($fatwa, $locale), self::LOCK_SECONDS);
    }

    public function lockName(Fatwa $fatwa, string $locale): string
    {
        return "translate-body:{$fatwa->id}:{$locale}";
    }

    /**
     * Translates the body and saves it. The caller holds the fatwa's lock. New chunks are only started while the reader's
     * $waitSeconds last; a call already in flight is never cut short, so its cost is always recorded.
     *
     *
     * @param  float|null  $lockExpiresAt  when the caller's lock runs out (unix time); no new call starts if less than one call timeout is left
     *
     * @throws TranslationFailed
     * @throws TranslationTimedOut
     * @throws TranslationBudgetExhausted
     */
    public function translate(Fatwa $fatwa, string $locale, ?int $waitSeconds = null, ?float $lockExpiresAt = null): FatwaTranslation
    {
        $existing = FatwaTranslation::query()->where(['fatwa_id' => $fatwa->id, 'locale' => $locale])->first();

        if ($existing?->hasBody()) {
            return $existing;
        }

        return $this->run($fatwa, $locale, $existing, microtime(true) + ($waitSeconds ?? (int) config('fatwarag.translation.body_timeout')), $lockExpiresAt);
    }

    private function run(Fatwa $fatwa, string $locale, ?FatwaTranslation $existing, float $deadline, ?float $lockExpiresAt): FatwaTranslation
    {
        // A fatwa that has a source translation is never machine translated.
        if ($existing?->kind === 'source') {
            return $existing;
        }

        $texts = [];
        $slots = [];

        if ($existing === null) {
            $slots[] = ['title'];
            $texts[] = $fatwa->title;
        }

        $sections = [];

        foreach (self::SECTIONS as $section) {
            $sections[$section] = $fatwa->{$section};

            foreach ($sections[$section] ?? [] as $p => $paragraph) {
                foreach ($paragraph as $s => $segment) {
                    if (($segment['type'] ?? null) !== 'quran' && trim((string) ($segment['text'] ?? '')) !== '') {
                        $slots[] = [$section, $p, $s];
                        $texts[] = $segment['text'];
                    }
                }
            }
        }

        $translated = $this->translateAll($texts, $locale, $deadline, $lockExpiresAt);
        $title = $existing?->title;

        foreach ($slots as $i => $slot) {
            if ($slot === ['title']) {
                $title = $translated[$i];
            } else {
                [$section, $p, $s] = $slot;
                $sections[$section][$p][$s]['text'] = $translated[$i];
            }
        }

        $values = [
            'kind' => 'machine',
            'title' => $title,
            'question' => $sections['question'] ?? [],
            'answer' => $sections['answer'] ?? [],
            'source_summary' => $sections['source_summary'],
            'model' => config('fatwarag.openrouter.models.translation'),
        ];

        // createOrFirst: the titles command may have made the row meanwhile, and a unique-key clash must not become a 500.
        $translation = $existing ?? FatwaTranslation::query()->createOrFirst(['fatwa_id' => $fatwa->id, 'locale' => $locale], $values);

        if ($translation->kind === 'machine' && ! $translation->hasBody()) {
            $translation->fill($values)->save();
        }

        return $translation;
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, string>
     */
    private function translateAll(array $texts, string $locale, float $deadline, ?float $lockExpiresAt): array
    {
        $translated = [];

        foreach ($this->chunks($texts) as $chunk) {
            // A finished chunk is kept for a while, so a retry after a timeout carries on instead of starting over.
            $cacheKey = 'translate-chunk:'.$locale.':'.sha1(json_encode($chunk, JSON_UNESCAPED_UNICODE));
            $result = Cache::get($cacheKey);

            if ($result === null) {
                // Never start a call the lock may not outlive: a second reader could then pay for the same chunks.
                $callTimeout = (int) config('fatwarag.translation.call_timeout');

                if (microtime(true) >= $deadline || ($lockExpiresAt !== null && $lockExpiresAt - microtime(true) < $callTimeout)) {
                    throw new TranslationTimedOut('The translation is taking too long.');
                }

                if ($this->spend->isExhausted()) {
                    throw new TranslationBudgetExhausted('The monthly AI budget ran out.');
                }

                $result = $this->translator->translate($chunk, 'text', countsTowardBudget: true, timeout: $callTimeout)['translations'];
                Cache::put($cacheKey, $result, now()->addHours(6));
            }

            array_push($translated, ...$result);
        }

        return $translated;
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, string>>
     */
    private function chunks(array $texts): array
    {
        $chunks = [];
        $current = [];
        $size = 0;

        foreach ($texts as $text) {
            $length = mb_strlen($text);

            if ($current !== [] && ($size + $length > self::CHUNK_CHARS || count($current) >= self::CHUNK_ITEMS)) {
                $chunks[] = $current;
                $current = [];
                $size = 0;
            }

            $current[] = $text;
            $size += $length;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
