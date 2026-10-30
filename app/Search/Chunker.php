<?php

namespace App\Search;

use App\Models\Fatwa;
use App\Support\Arabic;

/**
 * Splits a fatwa into the normalized text chunks that get embedded for meaning search.
 *
 * The first chunk is the title plus the question. The answer follows in chunks of whole paragraphs, each at most
 * MAX_CHARS long, and each chunk repeats the previous chunk's last paragraph so a point spanning a boundary isn't lost.
 */
class Chunker
{
    public const MAX_CHARS = 1800;

    /**
     * @return list<string>
     */
    public function chunk(Fatwa $fatwa): array
    {
        $head = trim(Arabic::normalize($fatwa->title).' '.implode(' ', $this->paragraphs($fatwa->question ?? [])));
        $chunks = [mb_substr($head, 0, self::MAX_CHARS)];

        $pieces = [];

        foreach ($this->paragraphs($fatwa->answer ?? []) as $paragraph) {
            array_push($pieces, ...$this->splitLong($paragraph));
        }

        $current = [];

        foreach ($pieces as $piece) {
            if ($current !== [] && $this->length([...$current, $piece]) > self::MAX_CHARS) {
                $chunks[] = implode(' ', $current);
                $overlap = end($current);
                $current = $this->length([$overlap, $piece]) <= self::MAX_CHARS ? [$overlap] : [];
            }

            $current[] = $piece;
        }

        if ($current !== []) {
            $chunks[] = implode(' ', $current);
        }

        return array_values(array_filter($chunks, fn (string $chunk): bool => $chunk !== ''));
    }

    /**
     * Normalized text of each non-empty paragraph.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $paragraphs
     * @return list<string>
     */
    private function paragraphs(array $paragraphs): array
    {
        $texts = [];

        foreach ($paragraphs as $paragraph) {
            $text = Arabic::normalize(implode(' ', array_map(fn (array $segment): string => (string) ($segment['text'] ?? ''), $paragraph)));

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * Splits a paragraph longer than MAX_CHARS on word boundaries.
     *
     * @return list<string>
     */
    private function splitLong(string $paragraph): array
    {
        if (mb_strlen($paragraph) <= self::MAX_CHARS) {
            return [$paragraph];
        }

        $pieces = [];
        $current = '';

        foreach (explode(' ', $paragraph) as $word) {
            $word = mb_substr($word, 0, self::MAX_CHARS);
            $candidate = $current === '' ? $word : "{$current} {$word}";

            if (mb_strlen($candidate) > self::MAX_CHARS) {
                $pieces[] = $current;
                $candidate = $word;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * @param  list<string>  $parts
     */
    private function length(array $parts): int
    {
        return mb_strlen(implode(' ', $parts));
    }
}
