<?php

namespace App\Answers;

use App\Support\Arabic;

/**
 * Finds every number in a text, written in digits or in words (Arabic or English, including fractions such as
 * «ربع العشر»), with the words that follow it, so the safeguard can require each number and its qualifier to appear
 * together in the support quotes.
 *
 * Each occurrence carries every value it can mean: «خمس» is both "five" and "a fifth", «خمسة وثمانون» is 85.
 * "One" (واحد/أحد) is not read as a number: it is mostly "one of", not a quantity. Neither is a lone English "two": Arabic
 * says two with the dual («الصلاتين»), which has no number word to match. Attached prefixes are read («بثلاثين»).
 */
final class NumberReader
{
    private const UNITS = [
        'اثنان' => 2, 'اثنين' => 2, 'اثنتان' => 2, 'اثنتين' => 2, 'اثنا' => 2, 'اثني' => 2, 'اثنتا' => 2, 'اثنتي' => 2,
        'ثلاث' => 3, 'ثلاثه' => 3, 'اربع' => 4, 'اربعه' => 4, 'خمسه' => 5, 'ست' => 6, 'سته' => 6, 'سبعه' => 7,
        'ثمان' => 8, 'ثماني' => 8, 'ثمانيه' => 8, 'تسعه' => 9, 'عشره' => 10,
        'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16,
        'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19,
    ];

    /** Words that are a number or a fraction: خمس = 5 or 1/5, عشر = 10 or 1/10. */
    private const AMBIGUOUS = ['خمس' => 5, 'سبع' => 7, 'تسع' => 9, 'عشر' => 10];

    /** Units that may precede «عشر/عشرة» to make 11–19. */
    private const TEEN_UNITS = ['احد' => 1, 'احدي' => 1];

    private const TENS = [
        'عشرون' => 20, 'عشرين' => 20, 'ثلاثون' => 30, 'ثلاثين' => 30, 'اربعون' => 40, 'اربعين' => 40, 'خمسون' => 50,
        'خمسين' => 50, 'ستون' => 60, 'ستين' => 60, 'سبعون' => 70, 'سبعين' => 70, 'ثمانون' => 80, 'ثمانين' => 80,
        'تسعون' => 90, 'تسعين' => 90,
        'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
    ];

    private const HUNDREDS = ['مايه' => 100, 'ميه' => 100, 'مايتان' => 200, 'مايتين' => 200, 'ميتان' => 200, 'ميتين' => 200, 'مايتا' => 200, 'ميتا' => 200, 'hundred' => 100, 'hundreds' => 100];

    private const THOUSANDS = ['الف' => 1000, 'الفا' => 1000, 'الفان' => 2000, 'الفين' => 2000, 'الاف' => 1000, 'thousand' => 1000, 'thousands' => 1000];

    private const FRACTIONS = [
        'نصف' => 2, 'ثلث' => 3, 'ربع' => 4, 'سدس' => 6, // «ثمن» is left out: it is mostly "price".
        'half' => 2, 'halves' => 2, 'third' => 3, 'thirds' => 3, 'quarter' => 4, 'quarters' => 4, 'fourth' => 4,
        'fifth' => 5, 'fifths' => 5, 'sixth' => 6, 'eighth' => 8, 'tenth' => 10, 'tenths' => 10,
    ];

    /**
     * @return list<array{values: list<string>, next: list<string>}>
     */
    public function occurrences(string $text): array
    {
        $sentences = preg_split('/(?<!\d)[.](?!\d)|[!?؟؛;:\n]+/u', $text) ?: [];

        return array_merge(...array_map(fn (string $sentence): array => $this->occurrencesIn($sentence), $sentences) ?: [[]]);
    }

    /**
     * @return list<array{values: list<string>, next: list<string>}>
     */
    private function occurrencesIn(string $text): array
    {
        $tokens = $this->tokens($text);
        $occurrences = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            [$values, $length] = $this->readAt($tokens, $i);

            if ($values === []) {
                $i++;

                continue;
            }

            $occurrences[] = ['values' => $values, 'next' => array_slice($tokens, $i + $length, 3)];
            $i += $length;
        }

        return $occurrences;
    }

    /**
     * @return list<string> every value any number in the text can mean
     */
    public function values(string $text): array
    {
        return array_values(array_unique(array_merge(...array_column($this->occurrences($text), 'values') ?: [[]])));
    }

    /**
     * Reads one number starting at token $i.
     *
     * @param  list<string>  $tokens
     * @return array{0: list<string>, 1: int} its possible values and how many tokens it spans
     */
    private function readAt(array $tokens, int $i): array
    {
        $token = $tokens[$i];

        if (preg_match('/^\d+(?:d\d+)?$/', $token)) {
            return [[(string) (float) str_replace('d', '.', $token)], 1];
        }

        $fraction = $this->fraction($token);

        // English ordinals double as fractions: "the third view" isn't 1/3, "a third" / "one third" is.
        if ($fraction !== null && in_array($token, ['third', 'fourth', 'fifth', 'sixth', 'eighth', 'tenth'], true) && ! in_array($tokens[$i - 1] ?? '', ['a', 'one', 'an'], true)) {
            return [[], 1];
        }

        if ($fraction !== null) {
            return [["1/{$fraction}"], 1];
        }

        // «أثنى على الله» (praised) is not «اثني» (two).
        if (in_array($token, ['اثني', 'واثني', 'ثم اثني'], true) && in_array($tokens[$i + 1] ?? '', ['علي', 'عليه', 'عليها'], true)) {
            return [[], 1];
        }

        $bare = $this->withoutArticle($token);

        if (isset(self::AMBIGUOUS[$bare]) && ! $this->isIntegerWord($tokens[$i + 1] ?? '')) {
            $value = self::AMBIGUOUS[$bare];
            $hasArticle = (bool) preg_match('/^(وال|بال|فال|لل|ال)/u', $token);

            return [$hasArticle ? ["1/{$value}"] : [(string) $value, "1/{$value}"], 1];
        }

        if (! $this->isIntegerWord($token) && ! isset(self::TEEN_UNITS[$token]) && ! isset(self::AMBIGUOUS[$token])) {
            return [[], 1];
        }

        $total = 0;
        $current = 0;
        $j = $i;

        while ($j < count($tokens)) {
            $word = $this->withoutConjunction($tokens[$j]);
            $next = $tokens[$j + 1] ?? '';

            if ((isset(self::UNITS[$word]) || isset(self::TEEN_UNITS[$word])) && in_array($next, ['عشر', 'عشره'], true)) {
                $current += (self::UNITS[$word] ?? self::TEEN_UNITS[$word]) + 10;
                $j += 2;

                continue;
            }

            if (isset(self::UNITS[$word])) {
                $current += self::UNITS[$word];
            } elseif (isset(self::AMBIGUOUS[$word])) {
                $current += self::AMBIGUOUS[$word];
            } elseif (isset(self::TENS[$word])) {
                $current += self::TENS[$word];
            } elseif (isset(self::HUNDREDS[$word])) {
                $current = $current > 0 && $current < 10 ? $current * 100 : $current + self::HUNDREDS[$word];
            } elseif (($fused = $this->fusedHundred($word)) !== null) {
                $current += $fused;
            } elseif (isset(self::THOUSANDS[$word])) {
                $total += ($current > 0 && self::THOUSANDS[$word] === 1000 ? $current : 1) * self::THOUSANDS[$word];
                $current = 0;
            } else {
                break;
            }

            $j++;
        }

        $value = $total + $current;

        // English "two" on its own is left out like "one" (Arabic says two with the dual); "two hundred" is read.
        if ($j - $i === 1 && $tokens[$i] === 'two') {
            return [[], 1];
        }

        return $value > 1 ? [[(string) $value], max(1, $j - $i)] : [[], max(1, $j - $i)];
    }

    private function isIntegerWord(string $token): bool
    {
        $word = $this->withoutConjunction($token);

        return isset(self::UNITS[$word]) || isset(self::TENS[$word]) || isset(self::HUNDREDS[$word])
            || isset(self::THOUSANDS[$word]) || $this->fusedHundred($word) !== null;
    }

    /** «ثلاثمائة» → 300 (normalized spelling: ئ is written ي). */
    private function fusedHundred(string $word): ?int
    {
        if (preg_match('/^(ثلاث|اربع|خمس|ست|سبع|ثمان|تسع)(مايه|ميه)$/u', $word, $match)) {
            return ['ثلاث' => 3, 'اربع' => 4, 'خمس' => 5, 'ست' => 6, 'سبع' => 7, 'ثمان' => 8, 'تسع' => 9][$match[1]] * 100;
        }

        return null;
    }

    private function fraction(string $token): ?int
    {
        $bare = $this->withoutArticle($token);

        return self::FRACTIONS[$bare] ?? self::FRACTIONS[$token] ?? null;
    }

    private function withoutArticle(string $token): string
    {
        foreach (['وال', 'بال', 'فال', 'لل', 'ال', 'و', 'ب', 'ف'] as $prefix) {
            if (str_starts_with($token, $prefix) && mb_strlen($token) - mb_strlen($prefix) >= 3) {
                return mb_substr($token, mb_strlen($prefix));
            }
        }

        return $token;
    }

    /**
     * The number word inside a token with attached prefixes («وثمانون», «بثلاثين», «لخمسة», «الأربعة»), or the token.
     */
    private function withoutConjunction(string $token): string
    {
        foreach (['', 'و', 'ب', 'ل', 'ك', 'ف', 'وب', 'ول', 'فب', 'فل', 'وال', 'ال', 'بال', 'لل', 'فال', 'كال', 'وبال', 'ولل'] as $prefix) {
            if ($prefix !== '' && (! str_starts_with($token, $prefix) || mb_strlen($token) - mb_strlen($prefix) < 3)) {
                continue;
            }

            $word = mb_substr($token, mb_strlen($prefix));

            if (isset(self::UNITS[$word]) || isset(self::TENS[$word]) || isset(self::HUNDREDS[$word]) || isset(self::THOUSANDS[$word])
                || isset(self::AMBIGUOUS[$word]) || $this->fusedHundred($word) !== null) {
                return $word;
            }
        }

        return $token;
    }

    /**
     * Normalized, lower-cased tokens; a decimal point between digits survives as "d" ("2.5" → "2d5").
     *
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $text = (string) preg_replace('/(\d),(?=\d{3}\b)/u', '$1', $text);
        $text = (string) preg_replace('/(\d)[.,٫](\d)/u', '$1d$2', $text);
        $text = (string) preg_replace('/(\d)([^\d\sd])/u', '$1 $2', $text);
        $normalized = mb_strtolower(Arabic::normalize($text));

        return $normalized === '' ? [] : explode(' ', $normalized);
    }
}
