<?php

namespace App\Content;

use App\Support\Arabic;

/**
 * Parses the Hijri dates sources publish («17/جمادى الأولى/1448», «26/12/1418هـ», «24/Ramadan/1431», «الأحد 14 رجب 1425 هـ»).
 */
final class HijriDate
{
    /**
     * Month spellings after Arabic::normalize() (Arabic) or lowercased letters only (English). Longer, more specific
     * spellings come first so «ربيع الاخر» is not read as «ربيع».
     *
     * @var array<int, array{int, string}>
     */
    private const MONTHS = [
        [4, 'ربيع الاخر'], [4, 'ربيع الثاني'], [4, 'ربيع ثاني'], [3, 'ربيع الاول'], [3, 'ربيع اول'],
        [6, 'جمادي الاخره'], [6, 'جمادي الاخر'], [6, 'جمادي الثانيه'], [6, 'جمادي الثاني'], [6, 'جمادي ثاني'],
        [5, 'جمادي الاولي'], [5, 'جمادي الاول'], [5, 'جمادي اول'],
        [11, 'ذو القعده'], [11, 'ذي القعده'], [12, 'ذو الحجه'], [12, 'ذي الحجه'],
        [1, 'محرم'], [2, 'صفر'], [7, 'رجب'], [8, 'شعبان'], [9, 'رمضان'], [10, 'شوال'],
        [4, 'rabialthani'], [4, 'rabiathani'], [4, 'rabialakhir'], [4, 'rabialakhar'], [4, 'rabiii'], [4, 'rabiulakhir'],
        [3, 'rabialawwal'], [3, 'rabiulawwal'], [3, 'rabiawwal'], [3, 'rabii'],
        [6, 'jumadaalthani'], [6, 'jumadalthani'], [6, 'jumadaalakhirah'], [6, 'jumadaalakhira'], [6, 'jumadalakhir'], [6, 'jumadaii'], [6, 'jumadiulakhir'],
        [5, 'jumadaalula'], [5, 'jumadaaloola'], [5, 'jumadaalawwal'], [5, 'jumadalawwal'], [5, 'jumadai'], [5, 'jumadiulawwal'],
        [11, 'qadah'], [11, 'qidah'], [11, 'qaadah'], [11, 'qada'], [12, 'hijjah'], [12, 'hijja'],
        [1, 'muharram'], [2, 'safar'], [7, 'rajab'], [8, 'shaban'], [8, 'shaaban'], [9, 'ramadan'], [9, 'ramadhan'], [10, 'shawwal'], [10, 'shawal'],
    ];

    /**
     * @return ?array{day:int, month:int, year:int}
     */
    public static function parse(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        // Only the Hijri part: islamqa appends «الموافق 28/أكتوبر/2026», islamweb «هـ - 4-11-2004 م».
        $text = preg_split('/الموافق|corresponding|\(|هـ|ه\s*-/u', $text)[0];
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        if (preg_match('/(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{3,4})/u', $text, $m)
            && ($date = self::valid((int) $m[1], (int) $m[2], (int) $m[3])) !== null) {
            return $date;
        }

        if (preg_match('/(\d{3,4})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{1,2})/u', $text, $m)
            && ($date = self::valid((int) $m[3], (int) $m[2], (int) $m[1])) !== null) {
            return $date;
        }

        return self::fromMonthName($text);
    }

    /**
     * @return ?array{day:int, month:int, year:int}
     */
    private static function fromMonthName(string $text): ?array
    {
        $tokens = preg_split('/[\s\/\-.,،]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($tokens as $i => $token) {
            if (! ctype_digit($token)) {
                continue;
            }

            $j = $i + 1;
            $words = [];
            while (isset($tokens[$j]) && ! ctype_digit($tokens[$j])) {
                $words[] = $tokens[$j];
                $j++;
            }

            if ($words === [] || ! isset($tokens[$j])) {
                continue;
            }

            $month = self::month(implode(' ', $words));
            if ($month !== null) {
                return self::valid((int) $token, $month, (int) $tokens[$j]);
            }
        }

        return null;
    }

    private static function month(string $words): ?int
    {
        $arabic = Arabic::normalize($words);
        $latin = preg_replace('/[^a-z]/', '', strtolower($words));

        foreach (self::MONTHS as [$number, $spelling]) {
            if (preg_match('/^[a-z]+$/', $spelling)) {
                if ($latin !== '' && str_contains($latin, $spelling)) {
                    return $number;
                }
            } elseif (str_contains($arabic, $spelling)) {
                return $number;
            }
        }

        return null;
    }

    /**
     * @return ?array{day:int, month:int, year:int}
     */
    private static function valid(int $day, int $month, int $year): ?array
    {
        if ($day < 1 || $day > 30 || $month < 1 || $month > 12 || $year < 1 || $year > 1600) {
            return null;
        }

        return ['day' => $day, 'month' => $month, 'year' => $year];
    }
}
