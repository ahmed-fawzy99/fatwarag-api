<?php

namespace App\Content;

use App\Http\Resources\FatwaResource;
use App\Models\Fatwa;

/**
 * Builds and caches the public Fatwa payload (one cache entry per fatwa and locale), so the single-fatwa endpoint and the
 * ids endpoint share their entries.
 */
class FatwaPayloads
{
    /**
     * @return array<string, mixed>|null
     */
    public function find(string $publicId, string $locale): ?array
    {
        return $this->many([$publicId], $locale)[$publicId] ?? null;
    }

    /**
     * The payloads of the given fatwas, keyed by public id. Unknown ids are left out; the order of $publicIds is kept.
     *
     * @param  array<int, string>  $publicIds
     * @return array<string, array<string, mixed>>
     */
    public function many(array $publicIds, string $locale): array
    {
        $payloads = [];
        $missing = [];

        foreach ($publicIds as $id) {
            $cached = ContentCache::get(self::tags($id), self::key($id, $locale));

            if ($cached === null) {
                $missing[] = $id;
            } else {
                $payloads[$id] = $cached;
            }
        }

        if ($missing !== []) {
            foreach ($this->load($missing, $locale) as $id => $payload) {
                $payloads[$id] = $payload;
            }
        }

        $ordered = [];

        foreach ($publicIds as $id) {
            if (isset($payloads[$id])) {
                $ordered[$id] = $payloads[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param  array<int, string>  $publicIds
     * @return array<string, array<string, mixed>>
     */
    private function load(array $publicIds, string $locale): array
    {
        $fatwas = Fatwa::query()->with(FatwaResource::relations())->whereIn('public_id', $publicIds)->get();
        $presenter = SegmentPresenter::resolving($fatwas->flatMap(fn (Fatwa $fatwa) => FatwaResource::paragraphLists($fatwa)));
        $payloads = [];

        foreach ($fatwas as $fatwa) {
            $payload = FatwaResource::make($fatwa)->forLocale($locale)->presentedBy($presenter)->resolve();
            $payloads[$fatwa->public_id] = $payload;

            // Indexed by quote, so a late hadith lookup clears exactly the cached fatwas that quote it.
            ContentCache::put(self::tags($fatwa->public_id), self::key($fatwa->public_id, $locale), $payload);
            ContentCache::indexQuotes($fatwa->public_id, self::quoteKeys($fatwa));
        }

        return $payloads;
    }

    /**
     * `fatwa-payloads` is shared by every cached fatwa, so a bulk change (topic assignment) can clear them all at once.
     *
     * @return array<int, string>
     */
    private static function tags(string $publicId): array
    {
        return ["fatwa:{$publicId}", ContentCache::PAYLOADS];
    }

    private static function key(string $publicId, string $locale): string
    {
        return "fatwa:{$publicId}:{$locale}";
    }

    /**
     * @return array<int, string>
     */
    private static function quoteKeys(Fatwa $fatwa): array
    {
        $keys = [];

        foreach (FatwaResource::paragraphLists($fatwa) as $paragraphs) {
            foreach ($paragraphs ?? [] as $paragraph) {
                foreach ($paragraph as $segment) {
                    if (($segment['type'] ?? null) === 'hadith' && isset($segment['quoteKey'])) {
                        $keys[] = $segment['quoteKey'];
                    }
                }
            }
        }

        return $keys;
    }
}
