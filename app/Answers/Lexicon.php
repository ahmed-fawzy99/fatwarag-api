<?php

namespace App\Answers;

use App\Support\Arabic;

/**
 * Word-level helpers for the grounding safeguard: normalized tokens, light Arabic stemming (clitics only), function
 * words to ignore, and the boilerplate formulas that open and close fatwas.
 */
final class Lexicon
{
    /**
     * Function words (normalized spelling) that say nothing about what a point claims.
     */
    public const STOPWORDS = [
        'في', 'من', 'الي', 'علي', 'عن', 'مع', 'او', 'ام', 'ثم', 'بل', 'لا', 'لم', 'لن', 'ما', 'ماذا', 'هل', 'قد', 'لقد',
        'ان', 'انه', 'انها', 'انهم', 'لان', 'لانه', 'لانها', 'كان', 'كانت', 'يكون', 'تكون', 'ليس', 'ليست', 'هذا', 'هذه',
        'ذلك', 'تلك', 'هو', 'هي', 'هم', 'هما', 'هن', 'التي', 'الذي', 'الذين', 'اللاتي', 'عند', 'عنده', 'عندهم', 'بعد',
        'قبل', 'كل', 'بعض', 'غير', 'سوي', 'الا', 'اذا', 'اذ', 'حتي', 'كما', 'مثل', 'به', 'بها', 'بهم', 'له', 'لها', 'لهم',
        'فيه', 'فيها', 'فيهم', 'منه', 'منها', 'منهم', 'عليه', 'عليها', 'عليهم', 'اليه', 'اليها', 'وهو', 'وهي', 'وهذا',
        'وذلك', 'ولا', 'وما', 'وقد', 'وان', 'فان', 'فلا', 'اي', 'ايضا', 'اما', 'وكذلك', 'كذلك', 'لكن', 'ولكن', 'حيث',
        'بين', 'دون', 'عنها', 'عنه', 'يعني', 'اذن', 'كيف', 'متي', 'اين', 'لماذا', 'نحو', 'ضمن', 'خلال', 'ذات', 'ذو',
        'the', 'a', 'an', 'of', 'to', 'in', 'on', 'and', 'or', 'is', 'are', 'be', 'it', 'that', 'this', 'for', 'as',
        'with', 'by', 'at', 'from', 'not', 'no', 'but', 'if', 'so', 'was', 'were', 'has', 'have', 'its', 'their', 'they',
    ];

    /**
     * Words a summary uses to frame and attribute views rather than to state a ruling (stems). The grounding check
     * doesn't expect them in the fatwas.
     */
    public const FRAMING = [
        'قول', 'اقوال', 'اول', 'ثاني', 'ثالث', 'رابع', 'اخر', 'اخري', 'راي', 'اراء', 'مصادر', 'مصدر', 'فتوي', 'فتاوي', 'موقع', 'شيخ',
        'سماحه', 'علامه', 'لجنه', 'يري', 'يرون', 'تري', 'رجح', 'رجحه', 'رجحها', 'رجحوا', 'ذكر', 'ذكرت', 'يذكر', 'تذكر',
        'ذكرا', 'نقل', 'نقلت', 'ينقل', 'افتي', 'افتت', 'اختار', 'اختاره', 'اتفق', 'اتفقت', 'تتفق', 'اختلف', 'اختلفت',
        'تختلف', 'خلاف', 'خلاصه', 'سايل', 'سوال', 'جواب', 'يجيب', 'اجاب', 'اجابت', 'ويري', 'وذكر', 'يستثني', 'استثني',
        'المساله', 'مساله', 'ايضا', 'كذلك', 'خاصه', 'عامه',
    ];

    /**
     * Formulas a fatwa opens or closes with: they prove nothing about any ruling.
     */
    private const BOILERPLATE = [
        'بسم الله الرحمن الرحيم', 'الحمد لله رب العالمين', 'الحمد لله وحده', 'الحمد لله', 'والصلاه والسلام علي رسول الله',
        'والصلاه والسلام علي نبينا محمد', 'وصلي الله علي نبينا محمد', 'وعلي اله وصحبه اجمعين', 'وعلي اله وصحبه وسلم',
        'وعلي اله وصحبه', 'وسلم تسليما كثيرا', 'اما بعد', 'وبعد', 'والله تعالي اعلم', 'والله اعلم', 'والله الموفق',
        'وبالله التوفيق', 'والله المستعان', 'السلام عليكم ورحمه الله وبركاته', 'وعليكم السلام ورحمه الله وبركاته',
        'جزاكم الله خيرا', 'نسال الله التوفيق', 'والله ولي التوفيق',
    ];

    /**
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $normalized = mb_strtolower(Arabic::normalize($text));

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    public static function isStopword(string $token): bool
    {
        return in_array($token, self::STOPWORDS, true);
    }

    /**
     * Strips attached conjunctions, prepositions, the article and pronoun suffixes, so «للحائض» and «الحائض» meet.
     */
    public static function stem(string $token): string
    {
        foreach (['وبال', 'وال', 'بال', 'كال', 'فال', 'ولل', 'لل', 'ال'] as $prefix) {
            if (str_starts_with($token, $prefix) && mb_strlen($token) - mb_strlen($prefix) >= 2) {
                return self::stripSuffix(mb_substr($token, mb_strlen($prefix)));
            }
        }

        if (mb_strlen($token) >= 4 && in_array(mb_substr($token, 0, 1), ['و', 'ف', 'ب', 'ل', 'ك'], true)) {
            $token = mb_substr($token, 1);
        }

        return self::stripSuffix($token);
    }

    /**
     * Stems of the text's content words (not function words, at least three letters).
     *
     * @return list<string>
     */
    public static function contentStems(string $text): array
    {
        $stems = [];

        foreach (self::tokens($text) as $token) {
            if (mb_strlen($token) >= 3 && ! self::isStopword($token) && ! is_numeric($token)) {
                $stems[] = self::stem($token);
            }
        }

        return $stems;
    }

    /**
     * The text without opening and closing formulas, normalized.
     */
    public static function withoutBoilerplate(string $text): string
    {
        $normalized = ' '.Arabic::normalize($text).' ';

        foreach (self::BOILERPLATE as $formula) {
            $normalized = str_replace(' '.Arabic::normalize($formula).' ', ' ', $normalized);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $normalized));
    }

    private static function stripSuffix(string $token): string
    {
        foreach (['هما', 'كما', 'هم', 'هن', 'ها', 'كم', 'نا', 'ه'] as $suffix) {
            if (str_ends_with($token, $suffix) && mb_strlen($token) - mb_strlen($suffix) >= 3) {
                return mb_substr($token, 0, -mb_strlen($suffix));
            }
        }

        return $token;
    }
}
