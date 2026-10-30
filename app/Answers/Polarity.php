<?php

namespace App\Answers;

use App\Support\Arabic;

/**
 * Reads the rulings a text states, with their strength and polarity, as one of: obligatory, recommended, permitted,
 * disliked, forbidden, or the negation of one (not-obligatory, not-recommended, not-disliked, not-forbidden), plus
 * invalid / valid. «لا يجوز» is forbidden, «لا بأس» permitted, «لا ينبغي» disliked, «ليس بواجب» and «لا زكاة فيه»
 * not-obligatory, «ففيه الزكاة» obligatory.
 *
 * The safeguard requires every ruling a summary states to be stated, with the same value, by its own support quotes,
 * so a summary can't turn «يحرم» into «يكره», «يجب» into «يستحب», or «لا يجوز» into «يجوز». English translations are
 * compared with their Arabic the same way (englishRulings()).
 */
final class Polarity
{
    /** Normalized Arabic ruling words and the value each states un-negated. */
    private const ARABIC = [
        'obligatory' => ['يجب', 'تجب', 'واجب', 'واجبه', 'واجبا', 'لازما', 'فرضا', 'يشترط', 'تشترط', 'يشترطون', 'اشترط', 'لابد', 'وجوب', 'يلزم', 'تلزم', 'يلزمه', 'يلزمها', 'يلزمهم', 'تلزمه', 'تلزمها', 'لزم', 'لزمت', 'لزمه', 'لزمها', 'لزمتك', 'لزمته', 'لازم', 'لازمه', 'فرض', 'فريضه', 'يتعين', 'متعين', 'يتحتم'],
        'recommended' => ['يستحب', 'تستحب', 'مستحب', 'مستحبه', 'مستحبا', 'مسنونا', 'مشروعا', 'مندوبا', 'استحباب', 'يسن', 'تسن', 'مسنون', 'مسنونه', 'ينبغي', 'يشرع', 'تشرع', 'مشروع', 'مشروعه', 'يندب', 'مندوب', 'الافضل', 'افضل', 'الاحوط', 'احوط', 'الاحسن'],
        'permitted' => ['يجوز', 'تجوز', 'يجز', 'تجز', 'جايز', 'جايزه', 'جايزا', 'مباحا', 'حلالا', 'اجازت', 'رخص', 'يرخص', 'ترخص', 'رخصه', 'مرخص', 'جواز', 'يباح', 'تباح', 'مباح', 'مباحه', 'اباحه', 'حلال', 'يحل', 'تحل', 'جاز', 'اجاز', 'اجازه', 'اجازها', 'اجازوا', 'يجيز', 'تجيز', 'يجيزون', 'يجيزه', 'يجيزها'],
        'disliked' => ['يكره', 'تكره', 'مكروه', 'مكروهه', 'مكروها', 'كراهه', 'كراهيه'],
        'forbidden' => ['يحرم', 'تحرم', 'حرم', 'حرمه', 'حرمها', 'حرام', 'حراما', 'محرم', 'محرمه', 'محرما', 'ممنوعا', 'محظورا', 'تحريم', 'يمنع', 'تمنع', 'ممنوع', 'ممنوعه', 'منع', 'يحظر', 'محظور', 'منعه', 'منعها',
            'ياثم', 'تاثم', 'ياثمون', 'ياثمان', 'تاثمان', 'اثم', 'اثما', 'اثمه', 'اثمها', 'اثمهم', 'اثمون', 'اثمين'],
        'harm' => ['حرج', 'باس'],
        'invalid' => ['يبطل', 'تبطل', 'باطل', 'باطله', 'باطلا', 'يفسد', 'فاسد'],
    ];

    /** What a negator turns each value into. */
    private const NEGATED = [
        'obligatory' => 'not-obligatory', 'recommended' => 'not-recommended', 'permitted' => 'forbidden',
        'disliked' => 'not-disliked', 'forbidden' => 'not-forbidden', 'harm' => 'permitted', 'invalid' => 'valid',
    ];

    /**
     * «ينبغي» negated is «لا ينبغي»: disliked, not merely "not recommended". «فيه حرج» un-negated: disliked. «يأثم»,
     * «إثم», «آثم» state a prohibition; negated («لا إثم عليه», «لا يأثم») they lift it, like «لا حرج».
     */
    private const SPECIAL = ['لابد' => ['obligatory', 'obligatory'], 'بد' => ['obligatory', 'obligatory'], 'ينبغي' => ['recommended', 'disliked'], 'حرج' => ['disliked', 'permitted'], 'باس' => ['disliked', 'permitted'],
        'ياثم' => ['forbidden', 'permitted'], 'تاثم' => ['forbidden', 'permitted'], 'ياثمون' => ['forbidden', 'permitted'],
        'ياثمان' => ['forbidden', 'permitted'], 'تاثمان' => ['forbidden', 'permitted'],
        'اثم' => ['forbidden', 'permitted'], 'اثما' => ['forbidden', 'permitted'], 'اثمه' => ['forbidden', 'permitted'],
        'اثمها' => ['forbidden', 'permitted'], 'اثمهم' => ['forbidden', 'permitted'], 'اثمون' => ['forbidden', 'permitted'],
        'اثمين' => ['forbidden', 'permitted']];

    private const NEGATORS = ['لا', 'لم', 'لن', 'ليس', 'ليست', 'غير', 'عدم', 'بغير', 'ولا', 'فلا', 'وليس', 'فليس', 'وليست', 'فليست', 'ولم', 'فلم', 'ولن', 'وغير'];

    /** Tokens before a ruling word in which a negator still applies to it («ليس للمرأة في ذلك حرج»). */
    private const NEGATION_WINDOW = 6;

    /** Words that open a new clause, past which a negator doesn't reach. */
    private const SCOPE_BREAKS = ['ان', 'انه', 'انها', 'لو', 'من', 'الذي', 'التي', 'الذين', 'اللاتي', 'اللذين', 'ما', 'اذا', 'لان', 'لانه', 'لانها', 'حيث', 'كي', 'لكي', 'حتي', 'بل', 'لكن', 'ولكن', 'اما', 'وانما', 'انما', 'يمكن', 'يمكنه', 'يمكنها'];

    /** «لم يثبت», «لا يقال», «ليس صحيحا»: deny the whole statement that follows in the clause. */
    private const DENIALS = ['يثبت', 'يقال', 'صحيحا', 'صحيح', 'بصحيح', 'يصح', 'دليل', 'يسلم', 'صوابا', 'بصواب'];

    /**
     * «من الخطأ القول بأن…», «القول بـ… خطأ», «وهذا القول غلط»: deny every ruling in their clause, wherever they sit.
     * Only with «من» before them or a «قول» in the clause, so «قتل الخطأ» stays a kind of killing.
     */
    private const MISTAKE_WORDS = ['خطا', 'الخطا', 'غلط', 'الغلط', 'خاطي', 'خاطيه', 'مردود', 'مردوده'];

    /** Words that make «سنة» mean "year", not "Sunnah". */
    private const YEAR_CONTEXT = ['بعد', 'قبل', 'مضت', 'مضي', 'مرور', 'حال', 'كل', 'لمده', 'مده', 'خلال', 'نصف', 'واحده', 'كامله', 'قمريه', 'هجريه', 'ميلاديه'];

    /** "It is not the case that…", "it is wrong to say that…", "nobody holds that…": deny the rest of the sentence. */
    private const ENGLISH_DENIAL = '/\\b(?:(?:it is |it s |its |it would be )?(?:not the case|not true|untrue|false|incorrect|not correct|wrong|mistaken|a mistake|an error|not right)(?: to (?:say|claim|hold|think|believe))? that|(?:nobody|no one|no scholar|none of the scholars) (?:holds?|held|says?|said|thinks?|claims?|believes?|maintains?|is of the view)(?: that)?|at no time)\\b/u';

    /** What an English denial turns each value into. */
    private const ENGLISH_DENIED = [
        'obligatory' => 'not-obligatory', 'recommended' => 'not-recommended', 'permitted' => 'forbidden', 'disliked' => 'not-disliked',
        'forbidden' => 'not-forbidden', 'not-obligatory' => 'obligatory', 'not-recommended' => 'recommended',
        'not-disliked' => 'disliked', 'not-forbidden' => 'forbidden',
    ];

    /** @var array<string, string>|null */
    private static ?array $lookup = null;

    /** Words with which a clause reports that a ruling is disputed («اختلف الفقهاء في وجوب…»), rather than stating one. */
    private const DISPUTE_WORDS = ['اختلف', 'اختلفوا', 'اختلفت', 'يختلف', 'خلاف', 'الخلاف', 'خلافا', 'اختلاف', 'الاختلاف', 'تنازع'];

    /** Rulings that can't both hold of the same thing (permitted and recommended can; permitted and forbidden can't). */
    private const OPPOSITES = [
        'obligatory' => ['not-obligatory', 'forbidden', 'disliked'],
        'not-obligatory' => ['obligatory'],
        'recommended' => ['forbidden', 'disliked', 'not-recommended'],
        'not-recommended' => ['recommended'],
        'permitted' => ['forbidden'],
        'disliked' => ['obligatory', 'recommended', 'not-disliked'],
        'not-disliked' => ['disliked'],
        'forbidden' => ['permitted', 'not-forbidden', 'obligatory', 'recommended'],
        'not-forbidden' => ['forbidden'],
        'invalid' => ['valid'],
        'valid' => ['invalid'],
    ];

    /**
     * Words that say who holds or reports a ruling («عند جمهور الفقهاء», «والراجح»), not what it is about: they are left
     * out of a ruling's scope. «ذهب» is kept: it is also gold. Words that are also real subjects («الأهل», «العلم»,
     * «مالك المال», «أبو الصبي») are left out only where they report (REPORTING_PHRASES).
     */
    private const REPORTING_WORDS = [
        'جمهور', 'الجمهور', 'العلماء', 'علماء', 'الفقهاء', 'فقهاء', 'الايمه', 'قول', 'قولي', 'اقوال', 'قال', 'قالوا',
        'يقول', 'ذهبوا', 'خالف', 'خالفهم', 'راجح', 'الراجح', 'ارجح', 'الارجح', 'صحيح', 'الصحيح', 'اصح', 'الاصح', 'صواب', 'الصواب',
        'اظهر', 'الاظهر', 'المختار', 'المعتمد', 'روي', 'نقل', 'حكي', 'ليس', 'ليست', 'عندنا', 'عنده', 'عندهم', 'حنيفه',
        'الشافعي', 'الحنفيه', 'المالكيه', 'الشافعيه', 'الحنابله', 'المذهب', 'مذهب',
    ];

    /**
     * Words that report only in a phrase: «أهل العلم» (not «النفقة على الأهل», «تعلم العلم»), and the imams' names next
     * to a word that reports them («عند مالك», «الإمام أحمد», «أبو حنيفة»), not «مالك المال», «أبو الصبي».
     */
    private const REPORTING_PHRASES = [
        'اهل' => ['after' => ['العلم', 'علم']],
        'العلم' => ['before' => ['اهل', 'واهل']],
        'علم' => ['before' => ['اهل', 'واهل']],
        'مالك' => ['before' => ['عند', 'الامام', 'قال', 'مذهب', 'وعند', 'حنيفه', 'والشافعي', 'الشافعي'], 'after' => ['واحمد', 'والشافعي', 'رحمه']],
        'ومالك' => ['before' => ['حنيفه', 'الشافعي', 'احمد', 'عند']],
        'احمد' => ['before' => ['عند', 'الامام', 'قال', 'مذهب', 'وعند', 'مالك', 'الشافعي'], 'after' => ['رحمه']],
        'واحمد' => ['before' => ['مالك', 'ومالك', 'الشافعي', 'والشافعي', 'حنيفه']],
        'ابو' => ['after' => ['حنيفه']],
        'ابي' => ['after' => ['حنيفه']],
    ];

    /** @var array<string, true>|null */
    private static ?array $unscoped = null;

    /** @var array<string, true> more stems left out of scopes (the sources' and muftis' names) */
    private array $ignored = [];

    /**
     * Leaves these words (the sources' and muftis' names) out of every ruling's scope from now on.
     *
     * @param  list<string>  $names
     */
    public function ignoring(array $names): self
    {
        $this->ignored = array_fill_keys(Lexicon::contentStems(implode(' ', $names)), true);

        return $this;
    }

    /** Where a clause ends, for rulings and their scope. */
    private const CLAUSE_BREAK = '/[.!?؟؛;:\n،,]+/u';

    /**
     * @return list<string> the ruling values the Arabic text states, in order
     */
    public function rulings(string $text): array
    {
        $found = [];

        foreach (preg_split(self::CLAUSE_BREAK, $text) ?: [] as $clause) {
            array_push($found, ...array_column($this->clauseRulings(Lexicon::tokens($clause)), 0));
        }

        return $found;
    }

    /**
     * The rulings one clause states, each with the position of its ruling word and the value that word states
     * un-negated.
     *
     * @param  list<string>  $tokens
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function clauseRulings(array $tokens): array
    {
        $found = [];
        $denied = $this->deniedAsMistake($tokens);

        foreach ($tokens as $i => $token) {
            if (in_array($token, self::DENIALS, true) && (in_array($tokens[$i - 1] ?? '', self::NEGATORS, true)
                || (in_array($tokens[$i - 1] ?? '', ['هناك', 'يوجد', 'ثمه'], true) && in_array($tokens[$i - 2] ?? '', [...self::NEGATORS, 'ما'], true)))) {
                $denied = true;

                continue;
            }

            $value = $this->arabicValue($tokens, $i);

            if ($value === null) {
                continue;
            }

            [$word, $base] = $value;
            $negated = $denied;

            // Look back for a negator, but not past a word that opens a new clause («من يجب عليه», «الذي يحرم»).
            for ($j = $i - 1; $j >= max(0, $i - self::NEGATION_WINDOW); $j--) {
                if (in_array($tokens[$j], self::SCOPE_BREAKS, true)) {
                    break;
                }

                $negated = $negated || (in_array($tokens[$j], self::NEGATORS, true) && ! in_array($tokens[$j + 1] ?? '', self::DENIALS, true)
                    && ! (in_array($tokens[$j], ['غير', 'بغير', 'وغير'], true) && $j !== $i - 1));
            }

            $found[] = [isset(self::SPECIAL[$word]) ? self::SPECIAL[$word][$negated ? 1 : 0] : ($negated ? self::NEGATED[$base] : $base), $i, $base];
        }

        return $found;
    }

    /**
     * Each ruling the prose states is anchored to what its support says it about. Support is whole sentences, which
     * can state one ruling for one thing and another for something else («يحرم التدخين، ويجوز شرب القهوة»), so each
     * ruling is bound to its own clause (segments()): its subject and the party it applies to («على الأب», «للرجال»).
     * The prose fails when:
     * - a support clause stating a different ruling shares more of the ruling's subject than every clause stating the
     *   same ruling, or shares a word of it that no clause stating the ruling has («يجوز التدخين», «يحرم لبس خاتم
     *   الفضة»);
     * - the ruling names a party and the clauses stating it give it to another party only («يجب على الابن» from «يجب
     *   على الأب», «حرم على النساء» from «حرم على الرجال»).
     * A clause that only says the ruling is disputed («اختلف الفقهاء في وجوب الزكاة في الحلي») states no different ruling.
     *
     * @param  list<string>  $quotes
     */
    public function anchored(string $prose, array $quotes): bool
    {
        $clauses = $this->clauseIndex($quotes, bound: true);

        foreach ($this->rulingScopes($prose) as ['value' => $value, 'scope' => $scope, 'party' => $party, 'head' => $head, 'conjuncts' => $conjuncts]) {
            $stating = array_values(array_filter($clauses, fn (array $support): bool => isset($support['values'][$value])));

            if ($stating === []) {
                continue; // a missing ruling (consistent() rejects that)
            }

            if ($party !== [] && $this->partyMoved($party, $scope, $stating)) {
                return false;
            }

            if ($scope === []) {
                continue;
            }

            $best = max(array_map(fn (array $support): int => $this->shared($scope, $support), $stating));
            $covered = array_merge(...array_map(fn (array $support): array => $support['stems'], $stating));
            $pronominal = in_array([], array_column($stating, 'stems'), true);

            // What the ruling is about at its core («التدخين» of «يجوز التدخين باعتدال لمن احتاج إليه») must be named
            // by a clause stating that ruling, not only by one with a different ruling, whatever qualifiers are copied.
            if ($head !== null && ! $pronominal && ! $this->rootCovered($head, $stating)) {
                foreach ($this->differing($clauses, $value) as $support) {
                    if (isset($support['stems'][$head])) {
                        return false;
                    }
                }
            }

            foreach ($this->differing($clauses, $value) as $support) {
                $shared = array_keys(array_intersect_key(array_flip($scope), $support['stems']));

                if (count($shared) >= 2 && (count($shared) > $best || array_diff_key(array_flip($shared), $covered) !== [])) {
                    return false;
                }

                // One word can be all a ruling is about («يجوز التدخين», «يجوز التدخين وشرب القهوة»): a thing the
                // ruling names on its own, which a clause with a different ruling names and no clause stating it does.
                // A clause stating it of a pronoun («وقال أبو حنيفة: هو واجب») may be about that word: not judged here.
                foreach ($pronominal ? [] : $conjuncts as $conjunct) {
                    $bestHere = max(array_map(fn (array $stated): int => $this->shared($conjunct, $stated), $stating));

                    if ($this->shared($conjunct, $support) === count($conjunct) && count($conjunct) > $bestHere) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Whether a clause stating the ruling names this word, or a word of its root («تقرأه» for «القراءة»).
     *
     * @param  list<array{stems: array<string, int>}>  $stating
     */
    private function rootCovered(string $word, array $stating): bool
    {
        $root = self::root($word);

        foreach ($stating as $support) {
            foreach (array_keys($support['stems']) as $stem) {
                if ($stem === $word || self::root((string) $stem) === $root) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the clauses stating a ruling give it to another party than the prose: none names the prose's party, and
     * the one closest to the prose's subject names a different one.
     *
     * @param  list<string>  $party
     * @param  list<string>  $scope
     * @param  list<array{stems: array<string, int>, party: list<string>}>  $stating
     */
    private function partyMoved(array $party, array $scope, array $stating): bool
    {
        $best = null;
        $bestShared = -1;

        foreach ($stating as $support) {
            if ($support['party'] !== [] && ! $this->partiesDiffer($party, $support['party'])) {
                return false;
            }

            if ($this->shared($scope, $support) > $bestShared) {
                [$best, $bestShared] = [$support, $this->shared($scope, $support)];
            }
        }

        return $best !== null && $best['party'] !== [];
    }

    /**
     * Two parties differ when the head of either («ولي» of «ولي الصبي»; any word of «المسلم المستطيع») is missing
     * from the other.
     *
     * @param  list<string>  $these
     * @param  list<string>  $those
     */
    private function partiesDiffer(array $these, array $those): bool
    {
        $bare = fn (array $party): array => array_map(fn (string $word): string => ltrim($word, '+'), $party);

        return array_intersect($this->partyHeads($these), $bare($those)) === [] || array_intersect($this->partyHeads($those), $bare($these)) === [];
    }

    /**
     * @param  list<string>  $party
     * @return list<string>
     */
    private function partyHeads(array $party): array
    {
        return str_starts_with($party[0], '+') ? [substr($party[0], 1)] : array_map(fn (string $word): string => ltrim($word, '+'), $party);
    }

    /**
     * Whether a whole fatwa gives an opposite ruling on what the prose rules on, in sentences the prose may not cite:
     * a clause stating an opposite ruling (OPPOSITES) shares more of the ruling's words than any clause stating it (at least
     * two), or shares at least three of them including one no clause stating it has («الحلي المعد للاستعمال لا تجب
     * فيه الزكاة» against a claim about it resting on «الحلي المعد للتجارة… فتجب فيه الزكاة»). Sentences reporting
     * others' views are left out.
     */
    public function contradictedBy(string $prose, string $fatwa): bool
    {
        // A view the fatwa reports from others («فذهب بعضهم إلى أنه يجوز…») is not a ruling it gives, and neither is a
        // question it quotes («وقد سئل الشيخ: … فهل يشترط أن يستأذن امرأته؟») or a view it puts in order to answer it
        // («فمن قال: يشترط…، فهو المطالب بالدليل»).
        $clauses = $this->clauseIndex(array_values(array_filter(
            preg_split('/(?<=[.!?؟؛\n])/u', $fatwa) ?: [],
            fn (string $sentence): bool => ! GroundingCheck::attributesToOthers($sentence) && ! self::notItsRuling($sentence),
        )));

        foreach ($this->rulingScopes($prose) as ['value' => $value, 'scope' => $scope]) {
            if ($scope === []) {
                continue;
            }

            $stating = array_filter($clauses, fn (array $support): bool => isset($support['values'][$value]));
            $best = $stating === [] ? 0 : max(array_map(fn (array $support): int => $this->shared($scope, $support), $stating));
            $covered = array_merge(...array_map(fn (array $support): array => $support['stems'], array_values($stating)) ?: [[]]);

            $opposed = array_filter($this->differing($clauses, $value), fn (array $clause): bool => array_intersect(array_keys($clause['values']), self::OPPOSITES[$value] ?? []) !== []);

            foreach ($opposed as $support) {
                $shared = array_keys(array_intersect_key(array_flip($scope), $support['stems']));

                if (count($shared) >= 2 && (count($shared) > $best || array_diff_key(array_flip($shared), $covered) !== [])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a sentence only asks («فهل يشترط…؟», «ما حكم…؟»: ends in a question mark and asks with «هل» or «ما حكم»)
     * or puts someone's saying to answer it («فمن قال: يشترط…»).
     */
    private static function notItsRuling(string $sentence): bool
    {
        $normalized = ' '.Arabic::normalize($sentence).' ';

        if (preg_match('/\s(و|ف)?من\s+قال\s/u', $normalized) === 1) {
            return true;
        }

        return preg_match('/[؟?]$/u', trim($sentence)) === 1 && preg_match('/\s(و|ف)?(هل|ما\s+(ال)?حكم)\s/u', $normalized) === 1;
    }

    /**
     * Whether some ruling in one list can't hold together with some ruling in the other (OPPOSITES).
     *
     * @param  list<string>  $these
     * @param  list<string>  $those
     */
    public function opposed(array $these, array $those): bool
    {
        foreach ($these as $value) {
            if (array_intersect(self::OPPOSITES[$value] ?? [], $those) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each ruling the text states, with its scope (the content words of its own clause, segments()), the party it
     * applies to, and the scope split at «و» into the things it is stated of («التدخين» | «وشرب القهوة»).
     *
     * @return list<array{value: string, scope: list<string>, party: list<string>, head: string|null, conjuncts: list<list<string>>}>
     */
    public function rulingScopes(string $text): array
    {
        $scopes = [];

        foreach (preg_split(self::CLAUSE_BREAK, $text) ?: [] as $clause) {
            $tokens = Lexicon::tokens($clause);
            $segments = $this->segments($tokens);
            $positions = array_column($segments, 'at');

            foreach ($segments as $segment) {
                $scopes[] = [
                    'value' => $segment['value'],
                    // Its own words only: a subject it shares with an earlier ruling covers the support, not the prose.
                    'scope' => array_values(array_unique($this->scopeStems($tokens, $segment['from'], $segment['to'], $positions))),
                    'party' => $segment['party'],
                    'head' => $segment['head'],
                    'conjuncts' => $this->conjuncts($tokens, $segment['from'], $segment['to'], $positions),
                ];
            }
        }

        return $scopes;
    }

    /**
     * Words that open the clause of a new ruling inside a sentence («…، أما الملبوس فلا زكاة فيه», «بينما…», «لا يجوز
     * مس المصحف لكن يجوز…», «يحرم التدخين ثم يجوز…», «بل يجوز…», «وإنما يجب…»).
     */
    private const CONTRAST_OPENERS = ['اما', 'واما', 'فاما', 'بينما', 'وبينما', 'بخلاف', 'وبخلاف', 'لكن', 'ولكن', 'لكنه', 'ولكنه', 'لكنها', 'ولكنها',
        'انما', 'وانما', 'ثم', 'بل'];

    /** A party named by a pronoun right after its ruling («لكن يجب عليه القضاء», «يجوز لهم لبسه»). */
    private const PRONOUN_PARTIES = ['عليه', 'عليها', 'عليهم', 'عليهما', 'عليهن', 'له', 'لها', 'لهم', 'لهما', 'لهن'];

    /** Nouns a «لـ» after a ruling gives as its purpose, not the party it applies to («يجوز للضرورة»). */
    private const PURPOSES = ['ضروره', 'حاجه', 'تجاره', 'استعمال', 'امتحان', 'زينه', 'عذر', 'مصلحه', 'علاج', 'تداوي', 'مرض', 'سفر', 'اللبس', 'لبس'];

    /**
     * Each ruling of a clause bound to its own span of the clause. A new ruling's span opens at «أما»/«بينما», or at the
     * «و»/«ف» that joins it («ويجوز», «ولا يجوز», «والملبوس لا زكاة فيه»), and the one before ends there; with no such
     * opener the spans meet at the rulings themselves, as two rulings of one verb phrase («يجوز بل يستحب»).
     *
     * @param  list<string>  $tokens
     * @return list<array{value: string, at: int, from: int, to: int, party: list<string>, topic: list<string>, head: string|null}>
     */
    private function segments(array $tokens): array
    {
        $found = $this->clauseRulings($tokens);
        $opens = [];

        foreach ($found as $k => [, $at]) {
            $opens[$k] = $k === 0 ? 0 : $this->opener($tokens, $found[$k - 1][1], $at);
        }

        $segments = [];
        $positions = array_column($found, 1);
        // A subject before the first ruling («الذهب الملبوس يحرم على الرجال ويباح للنساء») is also the subject of each
        // later ruling joined to it with no subject of its own.
        $fronted = $found === [] ? [] : $this->scopeStems($tokens, 0, $found[0][1], $positions);

        foreach ($found as $k => [$value, $at, $base]) {
            $from = $k === 0 ? 0 : ($opens[$k] ?? $found[$k - 1][1] + 1);
            $to = isset($found[$k + 1]) ? ($opens[$k + 1] ?? $found[$k + 1][1]) : count($tokens);
            $party = $this->party($tokens, $at, $base, $from, $to);
            $head = $this->head($tokens, $at, $from, $to, $party, $positions);
            // Only a joined ruling with no subject of its own, before or after it, shares the fronted one: «ويباح للنساء»
            // does, «ويجوز شرب القهوة», «ويجوز لهم لبس خاتم الفضة» and «ويحل الخل» don't.
            $shares = $k > 0 && isset($opens[$k]) && ! in_array($tokens[$opens[$k]], self::CONTRAST_OPENERS, true) && $this->scopeStems($tokens, $from, $at, $positions) === []
                && ! $this->namesOwnSubject($tokens, $at, $to, $party, $positions);
            $topic = $shares ? $fronted : [];
            $previous = $segments[$k - 1] ?? null;

            // A later ruling that names what it is about, or whom, only by a pronoun or not at all («وإنما يجب في حق
            // البالغ», «لكن يجب عليه القضاء») is about the previous ruling's subject, or for its party.
            if ($previous !== null && $head === null && $previous['head'] !== null) {
                [$head, $topic] = [$previous['head'], [...$topic, $previous['head']]];
            }

            // A pronoun party («لهم», «عليه») carries the previous ruling's party only, never its subject.
            if ($previous !== null && $party === [] && in_array($tokens[$at + 1] ?? '', self::PRONOUN_PARTIES, true)) {
                $party = $previous['party'];
                $topic = array_map(fn (string $word): string => Lexicon::stem(ltrim($word, '+!')), $party);
            }

            $segments[] = ['value' => $value, 'at' => $at, 'from' => $from, 'to' => $to, 'party' => $party, 'topic' => $topic, 'head' => $head];
        }

        return $segments;
    }

    /**
     * Whether a ruling word (after any negators) comes right after the word at $j.
     *
     * @param  list<string>  $tokens
     */
    private function rulingFollows(array $tokens, int $j): bool
    {
        for ($i = $j + 1; $i < count($tokens) && in_array($tokens[$i], self::NEGATORS, true); $i++) {
            // «بل ولا يجوز»: past the negator.
        }

        return isset($tokens[$i]) && $this->arabicValue($tokens, $i) !== null;
    }

    /**
     * Whether the ruling at $at names a subject or object of its own after it: a content word past its party and any
     * pronoun party, that isn't a prepositional qualifier («بعرفة», «بالمعروف»). «ويباح للنساء» names none.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $party
     * @param  list<int>  $rulingAt
     */
    private function namesOwnSubject(array $tokens, int $at, int $to, array $party, array $rulingAt): bool
    {
        $partyWords = array_flip(array_map(fn (string $word): string => ltrim($word, '+!'), $party));

        for ($i = $at + 1; $i < $to; $i++) {
            $token = $tokens[$i];
            $bare = (string) preg_replace('/^(وال|فال|بال|لل|ال|و(?=ال))/u', '', $token);

            if (in_array($token, ['الله', 'لله'], true) || in_array($token, self::PRONOUN_PARTIES, true) || isset($partyWords[$bare])
                || preg_match('/^(و|ف)?(بال|لل|كال|ب|ك|ل)\p{L}{3,}$/u', $token) === 1) {
                continue;
            }

            if ($this->scopeStems($tokens, $i, $i + 1, $rulingAt) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the clause of the ruling at $at opens, after the ruling at $previous, or null when nothing marks it.
     *
     * @param  list<string>  $tokens
     */
    private function opener(array $tokens, int $previous, int $at): ?int
    {
        for ($j = $at - 1; $j > $previous; $j--) {
            // «بل» opens a clause only before a ruling («بل يستحب», «بل ولا يجوز»); else it is emphasis inside its own
            // clause («يحرم الربا بل هو من أكبر الكبائر»).
            if (in_array($tokens[$j], self::CONTRAST_OPENERS, true) && ($tokens[$j] !== 'بل' || $this->rulingFollows($tokens, $j))) {
                return $j;
            }
        }

        $joined = fn (string $token): bool => preg_match('/^[وف]/u', $token) === 1 && ! isset(self::lookup()[$token]);

        if ($joined($tokens[$at])) {
            return $at; // «ويجوز», «وتستحب»
        }

        // Its joined negator («ولا يجوز», «فلا زكاة»), or else its subject joined right before it.
        $topic = null;

        for ($j = $at - 1; $j > $previous && $j >= $at - self::NEGATION_WINDOW; $j--) {
            if (in_array($tokens[$j], self::SCOPE_BREAKS, true) || isset(self::lookup()[$tokens[$j]])) {
                break;
            }

            if (in_array($tokens[$j], self::NEGATORS, true) && $joined($tokens[$j])) {
                return $j;
            }

            // «وخل العنب يجوز بيعه», «والملبوس لا زكاة فيه»: a subject joined by «و», with or without «ال».
            if ($topic === null && $j >= $at - 3 && preg_match('/^و\p{L}{2,}$/u', $tokens[$j]) === 1 && ! Lexicon::isStopword($tokens[$j]) && ! in_array($tokens[$j], self::NEGATORS, true)) {
                $topic = $j;
            }
        }

        return $topic;
    }

    /**
     * What the ruling at $at is about, at its core: the first word of its subject after it («يجوز التدخين باعتدال» →
     * «التدخين»), past the party it applies to; else the subject before it («الخمر يحرم بيعها» has «بيع» after it).
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $party
     * @param  list<int>  $rulingAt
     */
    private function head(array $tokens, int $at, int $from, int $to, array $party, array $rulingAt): ?string
    {
        $partyWords = array_flip(array_map(fn (string $word): string => ltrim($word, '+!'), $party));

        foreach ([...range($at + 1, max($at + 1, $to - 1)), ...array_reverse(range($from, max($from, $at - 1)))] as $i) {
            if ($i === $at || $i < $from || $i >= $to || in_array($tokens[$i], ['الله', 'لله'], true)) {
                continue;
            }

            $bare = (string) preg_replace('/^(وال|فال|بال|لل|ال|و(?=ال))/u', '', $tokens[$i]);

            if (isset($partyWords[$bare]) || in_array($tokens[$i], ['لمن', 'ولمن', 'حق'], true)) {
                continue;
            }

            $stems = $this->scopeStems($tokens, $i, $i + 1, $rulingAt);

            if ($stems !== []) {
                return $stems[0];
            }
        }

        return null;
    }

    /**
     * A rough root of a stem (its first three consonants, without the verb prefix), so «القراءة» meets «تقرأه».
     */
    private static function root(string $stem): string
    {
        $stem = mb_strlen($stem) > 3 ? (string) preg_replace('/^[يتنا]/u', '', $stem) : $stem;

        return mb_substr((string) preg_replace('/[اويءه]/u', '', $stem), 0, 3);
    }

    /**
     * Who the ruling at $at applies to, without the words' prefixes («الزوجة» stays apart from «الزوج»): «على» right
     * after the ruling («يجب على الأب», «لا حرج على المرأة») or before it («ليس على المرأة حرج»), after «الله»
     * («حرم الله على الرجال»), or, for a duty or a prohibition, after its object («تجب الكفارة على الزوج», «فرض الله الحج
     * على المستطيع»); «لـ» right after it («يجوز للمسافر», «وأباحه للنساء»), unless it gives a purpose («للضرورة»).
     * «في حق الولي» names it like «على الولي». «المسح على الجورب» is what is wiped, not who. A negated relative party keeps its negation («على من لم يستطع» →
     * «!يستطع»). The first word is marked «+» when it heads a construct («ولي الصبي»), so only it must match.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function party(array $tokens, int $at, string $base, int $from, int $to): array
    {
        $inSpan = fn (int $i): bool => $i >= $from && $i < $to && $i !== $at;
        $after = $tokens[$at + 1] ?? '';
        $allah = in_array($after, ['الله', 'لله'], true) ? 1 : 0;
        $on = [$at + 1 + $allah];

        // «ليس على المرأة حرج», «على من فعل ذلك إثم».
        if (in_array($base, ['harm', 'forbidden'], true) && preg_match('/^(و|ف)?(حرج|باس|اثم)/u', $tokens[$at]) === 1) {
            array_push($on, $at - 1, $at - 2);
        }

        if (in_array($base, ['obligatory', 'forbidden'], true)) {
            array_push($on, $at + 2 + $allah, $at + 3 + $allah);
        }

        foreach ($on as $i) {
            if ($inSpan($i) && in_array($tokens[$i], ['علي', 'وعلي'], true)) {
                return $this->partyWords($tokens, $i + 1, $to);
            }

            // «يجب في حق الولي», like «على الولي».
            if ($inSpan($i) && in_array($tokens[$i], ['في', 'وفي'], true) && ($tokens[$i + 1] ?? '') === 'حق') {
                return $this->partyWords($tokens, $i + 2, $to);
            }
        }

        $i = $at + 1 + $allah;

        if (! $inSpan($i)) {
            return [];
        }

        if (preg_match('/^(و|ف)?لل(\p{L}{3,})$/u', $tokens[$i], $match) === 1 && ! isset(self::lookup()[$tokens[$i]]) && ! in_array($match[2], self::PURPOSES, true)) {
            return $this->partyWords([...array_slice($tokens, 0, $i), 'ال'.$match[2], ...array_slice($tokens, $i + 1)], $i, $to);
        }

        if (in_array($tokens[$i], ['لمن', 'ولمن'], true)) {
            return $this->partyWords([...array_slice($tokens, 0, $i), 'من', ...array_slice($tokens, $i + 1)], $i, $to);
        }

        return [];
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function partyWords(array $tokens, int $from, int $to): array
    {
        $words = [];
        $negated = false;
        $relative = in_array($tokens[$from] ?? '', ['من', 'ما'], true);

        for ($i = $relative ? $from + 1 : $from; $i < $to && count($words) < 3; $i++) {
            $token = $tokens[$i];

            if (in_array($token, self::NEGATORS, true)) {
                $negated = true;

                continue;
            }

            $definite = preg_match('/^(و|ف|ب)?ال/u', $token) === 1;

            // A party is one noun phrase: its first word, then words with the article («المسلم المستطيع», «ولي
            // الصبي»), or one verb after a relative («من لم يستطع»).
            if (Lexicon::isStopword($token) || in_array($token, self::SCOPE_BREAKS, true) || mb_strlen($token) < 2 || $this->arabicValue($tokens, $i) !== null
                || ($words !== [] && ($relative || ! $definite))) {
                break;
            }

            $bare = (string) preg_replace('/^(وال|فال|بال|ال|و(?=ال))/u', '', $token);
            $words[] = ($negated ? '!' : '').$bare;
        }

        // A construct («ولي الصبي»): the party is its head.
        if (count($words) > 1 && preg_match('/^(و|ف|ب)?ال/u', $tokens[$relative ? $from + 1 : $from] ?? '') !== 1) {
            $words[0] = '+'.$words[0];
        }

        return $words;
    }

    /**
     * The scope of a span split where a word joined by «و» opens a new thing («يجوز التدخين وشرب القهوة»).
     *
     * @param  list<string>  $tokens
     * @param  list<int>  $rulingAt
     * @return list<list<string>>
     */
    private function conjuncts(array $tokens, int $from, int $to, array $rulingAt): array
    {
        $parts = [];
        $start = $from;

        for ($i = $from + 1; $i <= $to; $i++) {
            if ($i === $to || (preg_match('/^و\p{L}{3,}/u', $tokens[$i]) === 1 && ! Lexicon::isStopword($tokens[$i]) && ! in_array($tokens[$i], self::NEGATORS, true))) {
                $stems = array_values(array_unique($this->scopeStems($tokens, $start, $i, $rulingAt)));

                if ($stems !== []) {
                    $parts[] = $stems;
                }

                $start = $i;
            }
        }

        return $parts;
    }

    /**
     * How many of a ruling's scope words the text's best clause stating that ruling shares (0 when none states it).
     *
     * @param  list<string>  $scope
     */
    public function stated(string $value, array $scope, string $text): int
    {
        $runs = [];

        foreach (preg_split(self::CLAUSE_BREAK, $text) ?: [] as $clause) {
            if ($runs === [] || $this->rulings($clause) !== []) {
                $runs[] = $clause;
            } else {
                $runs[count($runs) - 1] .= ' '.$clause;
            }
        }

        $stating = array_filter($this->clauseIndex($runs), fn (array $clause): bool => isset($clause['values'][$value]));

        return $stating === [] ? 0 : max(array_map(fn (array $clause): int => max(1, $this->shared($scope, $clause)), $stating));
    }

    /**
     * The clauses of the texts with the rulings they state and their content words. Bound, each ruling is its own
     * entry with the words of its own clause (segments()) and the party it applies to; unbound, a clause is one entry
     * with all its rulings and words (for comparing a ruling with a whole fatwa, where a looser match is wanted). A
     * clause stating no ruling is one entry with no values.
     *
     * @param  list<string>  $texts
     * @return list<array{values: array<string, int>, stems: array<string, int>, party: list<string>, disputed: bool}>
     */
    private function clauseIndex(array $texts, bool $bound = false): array
    {
        $clauses = [];

        foreach ($texts as $text) {
            foreach (preg_split(self::CLAUSE_BREAK, $text) ?: [] as $clause) {
                $tokens = Lexicon::tokens($clause);
                $segments = $this->segments($tokens);
                $positions = array_column($segments, 'at');
                $disputed = $this->disputed($tokens);

                if ($segments === [] || ! $bound) {
                    $clauses[] = ['values' => array_flip(array_column($segments, 'value')), 'stems' => array_flip($this->scopeStems($tokens, 0, count($tokens), $positions)), 'party' => [], 'disputed' => $disputed];

                    continue;
                }

                foreach ($segments as $segment) {
                    $clauses[] = ['values' => [$segment['value'] => 0], 'stems' => array_flip([...$segment['topic'], ...$this->scopeStems($tokens, $segment['from'], $segment['to'], $positions)]), 'party' => $segment['party'], 'disputed' => $disputed];
                }
            }
        }

        return $clauses;
    }

    /**
     * Whether a clause says a ruling is disputed («اختلف الفقهاء في…»); «بلا خلاف», «لا خلاف» say the opposite.
     *
     * @param  list<string>  $tokens
     */
    private function disputed(array $tokens): bool
    {
        foreach ($tokens as $i => $token) {
            if (in_array((string) preg_replace('/^(و|ف)(?=..)/u', '', $token), self::DISPUTE_WORDS, true)
                && ! in_array($tokens[$i - 1] ?? '', ['لا', 'بلا', 'ولا', 'بدون', 'دون', 'غير', 'بغير', 'عدم'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clauses stating a ruling other than this one (a clause only saying the ruling is disputed names the question,
     * not a different answer to it).
     *
     * @param  list<array{values: array<string, int>, stems: array<string, int>, party: list<string>, disputed: bool}>  $clauses
     * @return list<array{values: array<string, int>, stems: array<string, int>, party: list<string>, disputed: bool}>
     */
    private function differing(array $clauses, string $value): array
    {
        return array_values(array_filter($clauses, fn (array $clause): bool => $clause['values'] !== [] && ! isset($clause['values'][$value]) && ! $clause['disputed']));
    }

    /**
     * @param  list<string>  $scope
     * @param  array{stems: array<string, int>}  $clause
     */
    private function shared(array $scope, array $clause): int
    {
        return count(array_intersect_key(array_flip($scope), $clause['stems']));
    }

    /**
     * The content words (stemmed) of a span of a clause, without ruling words and the words that only report who
     * holds a ruling.
     *
     * @param  list<string>  $tokens
     * @param  list<int>  $rulingAt
     * @return list<string>
     */
    private function scopeStems(array $tokens, int $from, int $to, array $rulingAt): array
    {
        $framing = self::$unscoped ??= array_flip([...Lexicon::FRAMING, ...array_map(Lexicon::stem(...), self::REPORTING_WORDS)]);
        $framing += $this->ignored;
        $stems = [];

        for ($i = $from; $i < $to; $i++) {
            $token = $tokens[$i];

            if (in_array($i, $rulingAt, true) || mb_strlen($token) < 3 || Lexicon::isStopword($token) || is_numeric($token) || in_array($token, self::NEGATORS, true)
                || in_array($token, self::CONTRAST_OPENERS, true) || $this->reports($tokens, $i)) {
                continue;
            }

            $stem = Lexicon::stem($token);

            if (! isset($framing[$stem])) {
                $stems[] = $stem;
            }
        }

        return $stems;
    }

    /**
     * Whether the word at $i only reports who holds a ruling in this phrase («أهل العلم», «عند مالك»).
     *
     * @param  list<string>  $tokens
     */
    private function reports(array $tokens, int $i): bool
    {
        $phrase = self::REPORTING_PHRASES[$tokens[$i]] ?? null;

        return $phrase !== null && (in_array($tokens[$i - 1] ?? '', $phrase['before'] ?? [], true) || in_array($tokens[$i + 1] ?? '', $phrase['after'] ?? [], true));
    }

    /**
     * Every ruling stated in the prose is stated, with the same value, by the support quotes themselves.
     *
     * @param  list<string>  $quotes
     */
    public function consistent(string $prose, array $quotes): bool
    {
        $supported = array_flip($this->rulings(implode(' . ', $quotes)));

        foreach ($this->rulings($prose) as $ruling) {
            if (! isset($supported[$ruling])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strong ruling statements in an English text, on the same scale. Only unambiguous wording counts ("may" alone
     * is too common to read as a ruling; "may not" is forbidden). "not" and "never" negate alike, and a sentence that
     * denies what follows ("it is not the case that…", "it is untrue that…") turns the rulings after it around.
     *
     * @return list<string>
     */
    public function englishRulings(string $text): array
    {
        $text = mb_strtolower((string) preg_replace("/n['’]t\\b/u", ' not', $text));
        $found = [];

        foreach (preg_split('/[.!?;:\n]+/u', $text) ?: [] as $sentence) {
            $sentence = ' '.(string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $sentence).' ';
            $sentence = (string) preg_replace('/\s+/u', ' ', $sentence);
            $parts = preg_split(self::ENGLISH_DENIAL, $sentence, 2) ?: [$sentence];

            array_push($found, ...$this->englishClauseRulings($parts[0]));

            if (isset($parts[1])) {
                array_push($found, ...array_map(fn (string $value): string => self::ENGLISH_DENIED[$value], $this->englishClauseRulings($parts[1])));
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function englishClauseRulings(string $text): array
    {
        // Negated wording first, and each match is consumed, so "not forbidden" isn't also read as "forbidden".
        $patterns = [
            'not-obligatory' => '/\b((not|never) (be )?(obligatory|required|compulsory|mandatory|due|wajib|binding)|does not have to|do not have to|exempt|no zakat|(not|never) obliged)\b/u',
            'not-recommended' => '/\b(not|never) (be )?(recommended|mustahabb|sunnah|encouraged)\b/u',
            'not-disliked' => '/\b(not|never) (be )?(disliked|makruh)\b/u',
            'not-forbidden' => '/\b(not|never) (be )?(forbidden|prohibited|haram|unlawful|sinful)\b/u',
            'forbidden' => '/\b(forbidden|forbids|forbidding|prohibited|prohibits|prohibiting|prohibition|impermissible|haram|unlawful|sinful|(not|never) (be )?(permitted|permissible|allowed|lawful)|(may|must) (not|never))\b/u',
            'obligatory' => '/\b(obligatory|required|compulsory|mandatory|wajib|must|obliged|obligated|obligation|is due|are due|binding)\b/u',
            'recommended' => '/\b(recommended|mustahabb|sunnah|sunna|encouraged)\b/u',
            'disliked' => '/\b(disliked|makruh|discouraged|reprehensible|should (not|never))\b/u',
            'permitted' => '/\b(permissible|permitted|permits|permitting|allowed|allows|lawful|halal|no harm|nothing wrong|no blame|no sin)\b/u',
        ];

        $found = [];

        foreach ($patterns as $value => $pattern) {
            $count = preg_match_all($pattern, $text);

            if ($count > 0) {
                array_push($found, ...array_fill(0, $count, $value));
                // Consume the matched wording so "not permitted" isn't also read as "permitted".
                $text = (string) preg_replace($pattern, ' ', $text);
            }
        }

        return $found;
    }

    /**
     * How many negations the text has, Arabic or English (informational; translations are compared by their rulings).
     */
    public function negations(string $text): int
    {
        $words = ['لا', 'لم', 'لن', 'ليس', 'ليست', 'فلا', 'وليس', 'فليس', 'ولم', 'فلم', 'ولن', 'غير', 'بغير', 'عدم', 'دون', 'بدون',
            'not', 'no', 'never', 'cannot', 'none', 'nothing', 'without', 'unless'];

        return count(array_filter(
            Lexicon::tokens((string) preg_replace("/n['’]t\\b/u", ' not', mb_strtolower($text))),
            fn (string $token): bool => in_array($token, $words, true),
        ));
    }

    /**
     * The ruling word at $i and its base value, or null.
     *
     * @param  list<string>  $tokens
     * @return array{0: string, 1: string}|null
     */
    private function arabicValue(array $tokens, int $i): ?array
    {
        $token = $tokens[$i];

        // «لا بد من…», «فلا بد»: a duty.
        if ($token === 'بد' && in_array($tokens[$i - 1] ?? '', ['لا', 'فلا', 'ولا'], true)) {
            return ['بد', 'obligatory'];
        }

        foreach ([$token, $this->withoutPrefix($token)] as $word) {
            if (isset(self::lookup()[$word])) {
                return [$word, self::lookup()[$word]];
            }
        }

        // «لا زكاة فيه» says zakat isn't due and «ففيه الزكاة» that it is; zakat named otherwise states nothing.
        if (preg_match('/^(ال)?زكاه$/u', $token)) {
            if (in_array($tokens[$i - 1] ?? '', self::NEGATORS, true)) {
                return ['زكاه', 'obligatory'];
            }

            if (preg_match('/^(و|ف)?(فيه|فيها|عليه|عليها|عليهم)$/u', $tokens[$i - 1] ?? '')) {
                return ['زكاه', 'obligatory'];
            }
        }

        // «الأولى» / «والأولى أن»: preferable, unless it is "the first" of a feminine noun («المسألة الأولى») or «خلاف الأولى».
        $bare = (string) preg_replace('/^(و|ف)(?=ال)/u', '', $token);

        if ($bare === 'الاولي' || ($token === 'اولي' && (in_array($tokens[$i + 1] ?? '', ['من', 'ان', 'بان', 'بذلك', 'به', 'بها'], true)
            || in_array($tokens[$i - 1] ?? '', ['هو', 'هي', 'وهو', 'وهي', 'فهو', 'فهي', 'كان', 'فكان', 'هذا', 'وهذا', 'فهذا'], true)))) {
            $previous = $tokens[$i - 1] ?? '';

            if (in_array($previous, ['خلاف', 'بخلاف', 'وخلاف', 'ترك'], true)) {
                return ['خلاف الاولي', 'disliked'];
            }

            if ($bare === 'الاولي' && (str_ends_with($previous, 'ه') || str_ends_with($previous, 'ات') || in_array($previous, ['العشر', 'الليالي', 'الايام', 'الثلث', 'الدنيا', 'الحياه'], true))) {
                return null;
            }

            // «أن يعلم الأولى أو يأخذ رأيها», «من إعلام الأولى», «مع عدم علم الأولى في حق…»: "the first (wife)" right after
            // a content word is no preference; «وهذا هو الأولى», «لكن الأولى», «تركه الأولى له أن…» still are.
            if ($bare === 'الاولي' && $previous !== '' && ! Lexicon::isStopword($previous)
                && ! in_array($tokens[$i + 1] ?? '', ['ان', 'بان', 'له', 'لها', 'لهما', 'لهم', 'لك', 'به', 'بها'], true)
                && ! in_array($previous, ['هو', 'هي', 'وهو', 'وهي', 'فهو', 'فهي', 'كان', 'فكان', 'وكان', 'لكان', 'يكون', 'فيكون', 'هذا', 'وهذا', 'فهذا', 'ذلك', 'وذلك', 'فذلك', 'لكن', 'ولكن', 'بل'], true)) {
                return null;
            }

            return ['الاولي', 'recommended'];
        }

        // «هو السنة» / «هذا هو السنة»: the Sunnah as a ruling.
        if (in_array($token, ['السنه'], true) && in_array($tokens[$i - 1] ?? '', ['هو', 'هي', 'وهو', 'وهي', 'فهو', 'فهي', 'هذا'], true)) {
            return ['سنه', 'recommended'];
        }

        // «سنة» is the Sunnah only when it isn't a year («بعد سنة», «سنة كاملة»).
        if (in_array($token, ['سنه', 'وسنه'], true)
            && ! in_array($tokens[$i - 1] ?? '', self::YEAR_CONTEXT, true)
            && ! in_array($tokens[$i + 1] ?? '', self::YEAR_CONTEXT, true)
            && ! is_numeric($tokens[$i - 1] ?? '')) {
            return ['سنه', 'recommended'];
        }

        return null;
    }

    /**
     * Whether the clause calls what it says a mistake («من الخطأ القول بأنه يجب», «القول بالوجوب خطأ»).
     *
     * @param  list<string>  $tokens
     */
    private function deniedAsMistake(array $tokens): bool
    {
        $saying = false;

        foreach ($tokens as $i => $token) {
            $bare = (string) preg_replace('/^(و|ف)(?=..)/u', '', $token);

            if (preg_match('/^(ال)?(قول|اقوال|قولهم|قوله|زعم|ادعاء|دعوي)$/u', $bare)) {
                $saying = true;
            }

            if (in_array($bare, self::MISTAKE_WORDS, true) && ($saying || in_array($tokens[$i - 1] ?? '', ['من', 'ومن', 'فمن'], true))) {
                return true;
            }
        }

        return false;
    }

    /** Verbs that are a noun with the article («الحرم»: the sanctuary). */
    private const VERBS_ONLY = ['حرم', 'حرمه', 'حرمها'];

    private function withoutPrefix(string $token): string
    {
        foreach (['وب', 'ب', 'و', 'ف', 'ل', 'فال', 'وال', 'بال', 'ال'] as $prefix) {
            $rest = mb_substr($token, mb_strlen($prefix));

            if (str_starts_with($token, $prefix) && isset(self::lookup()[$rest]) && ! (str_ends_with($prefix, 'ال') && in_array($rest, self::VERBS_ONLY, true))) {
                return mb_substr($token, mb_strlen($prefix));
            }
        }

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private static function lookup(): array
    {
        if (self::$lookup === null) {
            self::$lookup = [];

            foreach (self::ARABIC as $value => $words) {
                foreach ($words as $word) {
                    self::$lookup[$word] = $value;
                }
            }
        }

        return self::$lookup;
    }
}
