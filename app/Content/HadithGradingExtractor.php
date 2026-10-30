<?php

namespace App\Content;

/**
 * Captures the fatwa's own grading of a hadith it quotes («رواه أبو داود وصححه الألباني», «إسناده ضعيف», «وهي أحاديث
 * صحيحة»), verbatim, into the hadith segment: `gradingInFatwa` (the phrase), `gradingContext` (the whole sentence(s)
 * around it, including any scholar said to disagree) and `gradingScope` («this», or «group» when a plural phrase
 * directly follows several hadiths quoted together).
 *
 * Conservative by design (a grading on the wrong thing is worse than none):
 * - only the rest of the hadith's own sentence and the one right after it are read, never past the next quote;
 * - the grade must have a hadith subject («الحديث», «إسناده», «صححه»…), never a bare «هو/هذا»;
 * - a question («هل هذا ثابت؟») or a grade about a narrator («سعيد بن سنان ضعفه ابن معين») is ignored;
 * - a group grading applies only right after a contiguous run of hadiths, in the same paragraph, and never when the
 *   phrase introduces what follows («وردت أحاديث صحيحة منها:»).
 */
final class HadithGradingExtractor
{
    /** The grading phrase must start within this many characters after the hadith. */
    private const REACH = 300;

    /** Whole sentences are kept for the context, up to this much text. */
    private const MAX_CONTEXT = 1500;

    /** Hadiths count as quoted together when at most this much text (and no sentence end) separates them. */
    private const MAX_GAP = 60;

    /** Harakat and tatweel may sit between any two letters of a phrase. */
    private const MARKS = '[\x{064B}-\x{065F}\x{0670}\x{0640}]*';

    private const GRADE = 'صحيح لغيره|حسن لغيره|حسن صحيح|ضعيف جدا|صحيح الإسناد|صحيح|حسن|ضعيف|جيد|موضوع|منكر|باطل|شاذ|مرسل|لا يصح|لا يثبت|لا أصل له|ثابت';

    private const GRADE_PLURAL = 'ضعيفة جدا|صحيحة|حسنة|ضعيفة|ثابتة|جيدة|موضوعة|منكرة|باطلة|لا تصح|لا تثبت|لا يصح منها شيء|لا يثبت منها شيء';

    /** «صحيح البخاري», «ضعيف الجامع»: a book title, not a grading. */
    private const NOT_A_BOOK = '(?!\s*(?:البخاري|مسلم|الجامع|سنن|ابن|أبي|الترغيب|الأدب|الترمذي|النسائي|السنن))';

    /** Who graded it: up to three words, ending before the book («… الألباني في صحيح الجامع»). */
    private const GRADER = '(?:\s+(?!في(?!\p{L}))[^\s.،,؛:()"«»“”]+){0,3}';

    /** A plural phrase whose subject is the hadiths just quoted. */
    private const GROUP = [
        '(?:و|ف)?(?:هي|هذه)\s+(?:الأحاديث|أحاديث|الروايات|روايات)\s+(?:كلها\s+)?(?:'.self::GRADE_PLURAL.')',
        '(?:و|ف)?(?:هذه\s+)?الأحاديث\s+(?:كلها\s+)?(?:'.self::GRADE_PLURAL.')',
        '(?:و|ف)?(?:كلها|جميعها)\s+(?:'.self::GRADE_PLURAL.')',
    ];

    /** «بأسانيد صحيحة»: one hadith's several chains, or several hadiths. */
    private const CHAINS = [
        '(?:و|ف|ب)?(?:أسانيد|أسانيدها|أسانيده)\s+(?:'.self::GRADE_PLURAL.')',
    ];

    /** A phrase grading the hadith just quoted, always with a hadith subject. */
    private const SINGLE = [
        '(?:و|ف)?(?:إسناده|إسنادها|سنده|سندها|بإسناد|بسند)\s+(?:'.self::GRADE.')'.self::NOT_A_BOOK.'(?:\s+(?:لغيره|جدا|على شرط\s+[^\s.،,؛:()]+(?:\s+[^\s.،,؛:()]+)?))?',
        '(?:و|ف)?(?:في|فى)\s+(?:إسناده|إسنادها|سنده|سندها)\s+(?:ضعف|مقال|لين|انقطاع|جهالة)',
        '(?:و|ف)?(?:صحح|حسن|ضعف)\s+(?:إسناده|إسنادها|سنده)'.self::GRADER,
        '(?:و|ف)?(?:صحح|حسن|ضعف)(?:ه|ها)'.self::GRADER,
        '(?:و)?(?:قال)(?:\s+(?:الشيخ\s+|الحافظ\s+|الإمام\s+|العلامة\s+)?[^\s:،.]+(?:\s+[^\s:،.]+)?)?\s*:\s*["«“]?\s*(?:هذا\s+)?(?:حديث\s+|إسناده\s+)?(?:'.self::GRADE.')'.self::NOT_A_BOOK,
        '(?:و|ف)?(?:الحديث|والحديث|هذا الحديث|وهذا الحديث)\s+(?:حديث\s+)?(?:'.self::GRADE.')'.self::NOT_A_BOOK.'(?:\s+(?:لغيره|جدا|الإسناد))?',
        '(?:و|ف)?(?:لكنه|ولكنه|هو|وهو|فهو|هذا|وهذا|فهذا)\s+حديث\s+(?:'.self::GRADE.')'.self::NOT_A_BOOK.'(?:\s+(?:لغيره|جدا|الإسناد))?',
        '^\s*حديث\s+(?:'.self::GRADE.')'.self::NOT_A_BOOK.'(?:\s+(?:لغيره|جدا|الإسناد))?',
    ];

    /** A second sentence that moves on to another narration is not about this hadith. */
    private const ANOTHER_NARRATION = '/وفي رواية|وفي لفظ|وفي الباب|حديث آخر|الحديث الآخر|وروى|وعن |قال تعالى|وقال تعالى/u';

    /** A following sentence that reports scholars disagreeing on the grading belongs in the context. */
    private const DISAGREEMENT = '/ضعفه|ضعّفه|صححه|صحّحه|حسنه|حسّنه|أعله|أعلّه|خالف|تعقب|تعقّب|لا يصح|نازع|انتقد|استنكر|وضعف|ضعيف/u';

    /**
     * Where talk turns to another hadith that isn't marked as a quote («أما حديث: "من حفظ القرآن…" فهو حديث ضعيف»,
     * «وحديث…», «وأما ما ورد…», «وقوله ﷺ:»): the window ends there, so its grading never lands on this hadith.
     */
    private const NEXT_HADITH = [
        '(?:و|ف)?أما\s+(?:حديث|ما ورد|ما روي|رواية|الحديث)',
        'وحديث',
        '(?:و|ف|ول|ل)?قوله\s*(?:ﷺ|صلى الله عليه وسلم|عليه الصلاة والسلام)',
    ];

    /** A plural phrase that introduces what follows («…أحاديث صحيحة منها:»), not what came before. */
    private const INTRODUCER_AFTER = '/^\s*(?:منها|ومنها|:|تبين|تدل|تبيّن|في ذلك|في هذا)/u';

    private const INTRODUCER_BEFORE = '/وردت|ورد|جاءت|جاء|ثبتت|ثبت|منها/u';

    /** Names a person (a narrator) when it sits right before the grade. */
    private const NARRATOR = '/(?<!\p{L})(?:بن|ابن|أبو|ابو|أبي|ابي|أبا|ابا|بنت|الراوي|راو|راوي|راويه)(?!\p{L})/u';

    /** A citation verb: names after it are compilers, not narrators. */
    private const CITATION = '/(?<!\p{L})(?:رواه|أخرجه|اخرجه|روى|أخرج|اخرج)(?!\p{L})/u';

    /** Words that turn a citation back into a chain of narrators («في إسناده فلان»). */
    private const CHAIN_TALK = '/(?<!\p{L})(?:إسناده|اسناده|إسنادها|اسنادها|سنده|سندها|فيه|الراوي|راو|عن)(?!\p{L})/u';

    /** @var array<string, string> compiled patterns, by kind */
    private static array $compiled = [];

    /**
     * @param  list<list<array<string, mixed>>>  $paragraphs
     * @return list<list<array<string, mixed>>>
     */
    public static function apply(array $paragraphs): array
    {
        foreach ($paragraphs as $p => $paragraph) {
            foreach ($paragraph as $i => $segment) {
                if ($segment['type'] !== 'hadith') {
                    continue;
                }

                $window = self::textUntilQuote(array_slice($paragraph, $i + 1));
                $spilled = false;
                if (self::isBlank($window) && isset($paragraphs[$p + 1])) {
                    $window .= ' '.self::textUntilQuote($paragraphs[$p + 1]);
                    $spilled = true;
                }

                $run = self::run($paragraph, $i);
                $found = self::find($window, allowGroup: ! $spilled && count($run) >= 2);
                if ($found === null) {
                    continue;
                }

                [$kind, $phrase, $context] = $found;

                if ($kind === 'single' || ($kind === 'chains' && count($run) < 2)) {
                    $paragraphs[$p][$i] = ['gradingInFatwa' => $phrase, 'gradingScope' => 'this', 'gradingContext' => $context] + $paragraphs[$p][$i];
                } else {
                    foreach ($run as $j) {
                        if (($paragraphs[$p][$j]['gradingInFatwa'] ?? null) === null) {
                            $paragraphs[$p][$j] = ['gradingInFatwa' => $phrase, 'gradingScope' => 'group', 'gradingContext' => $context] + $paragraphs[$p][$j];
                        }
                    }
                }
            }
        }

        return $paragraphs;
    }

    private static function isBlank(string $text): bool
    {
        return preg_replace('/[\s«»"“”(){}\[\].،,؛:]+/u', '', $text) === '';
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    private static function textUntilQuote(array $segments): string
    {
        $text = '';

        foreach ($segments as $segment) {
            if (in_array($segment['type'], ['hadith', 'quran'], true)) {
                break;
            }
            $text .= $segment['text'];
        }

        if (preg_match(self::pattern('next', self::NEXT_HADITH), $text, $m, PREG_OFFSET_CAPTURE)) {
            $text = substr($text, 0, $m[0][1]);
        }

        return $text;
    }

    /**
     * This hadith and those quoted together right before it: separated only by a short connector («، وقوله ﷺ:»), with
     * no sentence end and no verse in between.
     *
     * @param  list<array<string, mixed>>  $paragraph
     * @return list<int>
     */
    private static function run(array $paragraph, int $i): array
    {
        $run = [$i];
        $gap = '';

        for ($j = $i - 1; $j >= 0; $j--) {
            $type = $paragraph[$j]['type'];

            if ($type === 'hadith') {
                if (mb_strlen(trim($gap)) > self::MAX_GAP || preg_match('/[.؟!?]/u', $gap)) {
                    break;
                }
                $run[] = $j;
                $gap = '';

                continue;
            }

            if ($type === 'quran') {
                break;
            }

            $gap = $paragraph[$j]['text'].$gap;
        }

        return array_reverse($run);
    }

    /**
     * @return ?array{string, string, string} [kind, phrase, context]
     */
    private static function find(string $window, bool $allowGroup): ?array
    {
        $window = preg_replace('/^[\s\p{M}«»"“”(){}\[\]،,.]+/u', '', $window);
        $sentences = preg_split('/(?<=[.؟!?])\s+/u', trim(mb_substr($window, 0, self::MAX_CONTEXT)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $offset = 0;

        foreach (array_slice($sentences, 0, 2) as $k => $sentence) {
            if ($offset > self::REACH || self::isQuestion($sentence) || ($k === 1 && preg_match(self::ANOTHER_NARRATION, $sentence))) {
                return null;
            }

            $kinds = $k === 0 && $allowGroup ? ['group' => self::GROUP] : [];
            $kinds += ['chains' => self::CHAINS, 'single' => self::SINGLE];

            foreach ($kinds as $kind => $patterns) {
                preg_match_all(self::pattern($kind, $patterns), $sentence, $matches, PREG_OFFSET_CAPTURE);

                foreach ($matches[0] as [$phrase, $at]) {
                    if ($offset + mb_strlen(substr($sentence, 0, $at)) > self::REACH) {
                        break;
                    }

                    $before = substr($sentence, 0, $at);
                    $after = substr($sentence, $at + strlen($phrase));
                    $accepted = $kind === 'group'
                        ? self::isGroupPhrase($before, $after)
                        : ! self::isAboutNarrator($before) && (! self::isBareVerb($phrase) || self::hasHadithSubject($before));

                    if ($accepted) {
                        return [$kind, trim($phrase), self::context($sentences, $k, trim($phrase))];
                    }
                }
            }

            $offset += mb_strlen($sentence) + 1;
        }

        return null;
    }

    /**
     * @param  list<string>  $sentences
     */
    private static function context(array $sentences, int $k, string $phrase): string
    {
        $context = array_slice($sentences, 0, $k + 1);
        $next = $sentences[$k + 1] ?? null;

        if ($next !== null && preg_match('/[.؟!?]["»”)]*$/u', $next) && preg_match(self::DISAGREEMENT, $next) && ! preg_match(self::ANOTHER_NARRATION, $next)) {
            $context[] = $next;
        }

        $context = trim(implode(' ', $context));

        // The window stopped at the next quote: drop the clause that leads into it («…، ولقوله ﷺ:»).
        if (! preg_match('/[.؟!?]["»”)]*$/u', $context)) {
            $trimmed = preg_replace('/[\s،,؛]+$/u', '', preg_replace('/[\s،,؛]*[^،,؛.؟!?]*:\s*$/u', '', $context));
            // Never trim away the grading itself («…، بإسناد صحيح عن جابر قال:»).
            $context = str_contains($trimmed, $phrase) ? $trimmed : preg_replace('/[\s:]+$/u', '', $context);
        }

        return $context;
    }

    private static function isQuestion(string $sentence): bool
    {
        return (bool) preg_match('/[؟?]\s*["»”)]*\s*$|^\s*(?:س\s*:\s*)?(?:و|ف)?هل(?!\p{L})|(?<!\p{L})س\s*:/u', $sentence);
    }

    /**
     * Right after the run: nothing before the phrase but an optional citation, and the phrase doesn't introduce a list.
     */
    private static function isGroupPhrase(string $before, string $after): bool
    {
        if (preg_match(self::INTRODUCER_AFTER, $after) || preg_match(self::INTRODUCER_BEFORE, $before)) {
            return false;
        }

        $before = trim(preg_replace('/[\s«»"“”(){}\[\]،,]+/u', ' ', $before));

        return $before === '' || (mb_strlen($before) <= 80 && preg_match('/^(?:رواه|أخرجه|اخرجه)(?!\p{L})/u', $before));
    }

    /**
     * «صححه…/ضعفه…» with only a pronoun for the thing graded.
     */
    private static function isBareVerb(string $phrase): bool
    {
        return (bool) preg_match(self::pattern('bare-verb', ['^(?:و|ف)?(?:صحح|حسن|ضعف)(?:ه|ها)']), $phrase);
    }

    /**
     * The pronoun of «صححه» can only point at the hadith when nothing comes before it in the sentence (it follows the
     * quote), or the sentence names the hadith or cites it («رواه الترمذي وصححه»). «وعمران قد ضعفه» points elsewhere.
     */
    private static function hasHadithSubject(string $before): bool
    {
        $rest = preg_replace('/^[\s\p{P}\p{M}]*(?:(?:و|ف)?(?:قد|لكن|ولكن)\s*)?$/u', '', $before);

        return $rest === '' || (bool) preg_match(self::pattern('hadith-subject', ['(?:رواه|أخرجه|اخرجه|روى|أخرج|خرجه|الحديث|حديث|إسناده|سنده|والحديث)']), $before);
    }

    /**
     * Whether the grade follows a person's name («سعيد بن سنان ضعفه ابن معين»), so it grades a narrator. Names after a
     * plain citation («رواه أبو داود وصححه الألباني») are compilers and don't count.
     */
    private static function isAboutNarrator(string $before): bool
    {
        $light = trim(preg_replace('/\s+/u', ' ', preg_replace('/['.substr(self::MARKS, 1, -2).']/u', '', $before)));

        if (preg_match_all(self::CITATION, $light, $verbs, PREG_OFFSET_CAPTURE)) {
            $afterCitation = substr($light, end($verbs[0])[1]);
            if (! preg_match(self::CHAIN_TALK, $afterCitation)) {
                return false;
            }
        }

        $words = preg_split('/[\s،,؛:()"«»“”]+/u', $light, -1, PREG_SPLIT_NO_EMPTY);

        return (bool) preg_match(self::NARRATOR, implode(' ', array_slice($words, -6)));
    }

    /**
     * One regex per kind, tolerant of harakat and of alef/yeh spelling variants, matching whole words only.
     *
     * @param  list<string>  $patterns
     */
    private static function pattern(string $kind, array $patterns): string
    {
        return self::$compiled[$kind] ??= '/(?<!\p{L})(?:'.implode('|', array_map(self::fuzzy(...), $patterns)).')(?!\p{L})/u';
    }

    private static function fuzzy(string $pattern): string
    {
        $out = '';

        foreach (mb_str_split($pattern) as $char) {
            $out .= match (true) {
                in_array($char, ['ا', 'أ', 'إ', 'آ'], true) => '[اأإآ]'.self::MARKS,
                in_array($char, ['ي', 'ى'], true) => '[يى]'.self::MARKS,
                (bool) preg_match('/^[\x{0621}-\x{064A}]$/u', $char) => $char.self::MARKS,
                default => $char,
            };
        }

        return $out;
    }
}
