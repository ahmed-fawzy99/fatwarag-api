<?php

namespace App\Support;

/**
 * Arabic text cleaning for search and quote matching. Stored text always keeps its harakat; only search copies are cleaned.
 */
final class Arabic
{
    private const HARAKAT = '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u';

    /**
     * Removes harakat and tatweel, unifies letter variants (أإآٱ→ا, ة→ه, ى→ي, ؤ→و, ئ→ي), converts Arabic-Indic digits,
     * and collapses punctuation and whitespace.
     */
    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = preg_replace(self::HARAKAT, '', $text);
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * A stable key for a quote, so the same hadith quoted in different fatwas (with or without harakat) maps to one record.
     */
    public static function quoteKey(string $quote): string
    {
        return hash('sha256', self::normalize($quote));
    }
}
