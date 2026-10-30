<?php

namespace App\Content;

use App\Models\Ayah;
use App\Models\Surah;
use App\Support\Arabic;
use Illuminate\Support\Facades\DB;

/**
 * Finds the ayah a fatwa quotes. A reference printed next to the quote («[البقرة: 255]», «المؤمنون (20)», «البقرة/275»)
 * wins; otherwise the quote is matched by trigram word similarity against `ayahs.search_text`, so harakat, spelling
 * differences and partial quotes still land on the containing ayah. Matching uses `ayahs.match_text`, a skeleton
 * without alef or hamza, so Uthmani spelling («أَيۡمَٰنِكُمۡ») meets standard spelling («أيمانكم»).
 */
class QuranQuoteMatcher
{
    /** Word similarity a quote must reach to count as the same ayah text. */
    private const MIN_SCORE = 0.8;

    /** Share of an ayah a quote must reproduce for exactAyah(). */
    private const WHOLE_AYAH = 0.8;

    /** Words used from each end of a long quote to find where it starts and ends. */
    private const EDGE_WORDS = 8;

    /** Names sources use that differ from the surah's standard name. */
    private const ALIASES = [
        'بني اسرائيل' => 17, 'الاسراء' => 17, 'براءه' => 9, 'التوبه' => 9, 'المومن' => 40, 'غافر' => 40, 'حم السجده' => 41, 'فصلت' => 41,
        'القتال' => 47, 'محمد' => 47, 'الدهر' => 76, 'الانسان' => 76, 'تبت' => 111, 'اللهب' => 111, 'المسد' => 111, 'الاخلاص' => 112,
    ];

    private bool $matchTextReady = false;

    /** @var array<string, array{int, int}>|null normalized name => [surah number, ayah count] */
    private ?array $surahs = null;

    /**
     * @return ?array{surah:int, ayah:int, ayahEnd:?int}
     */
    public function match(string $quote, ?string $reference): ?array
    {
        if ($reference !== null && ($fromReference = $this->fromReference($reference)) !== null) {
            return $fromReference;
        }

        return $this->fromText($quote);
    }

    /**
     * @return ?array{surah:int, ayah:int, ayahEnd:?int}
     */
    public function fromReference(string $reference): ?array
    {
        $reference = strtr($reference, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        if (! preg_match('/^([^\d]{0,40}?)(\d{1,3})(?:\s*[-–—،,]\s*(\d{1,3}))?/u', $reference, $m)) {
            return null;
        }

        $name = trim(preg_replace('/^(سوره|من سوره)\s+|\s+(الايه|ايه|الايات|رقم)$/u', '', Arabic::normalize($m[1])));
        $surah = $this->surahs()[$name] ?? $this->surahs()['ال'.$name] ?? null;

        if ($surah === null) {
            return null;
        }

        [$number, $ayahCount] = $surah;
        $ayah = (int) $m[2];
        $ayahEnd = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;

        if ($ayah < 1 || $ayah > $ayahCount) {
            return null;
        }

        if ($ayahEnd !== null && ($ayahEnd <= $ayah || $ayahEnd > $ayahCount)) {
            $ayahEnd = null;
        }

        return ['surah' => $number, 'ayah' => $ayah, 'ayahEnd' => $ayahEnd];
    }

    /**
     * @return ?array{surah:int, ayah:int, ayahEnd:?int}
     */
    public function fromText(string $quote): ?array
    {
        $this->ensureMatchText();

        // Sources separate the ayahs of a multi-ayah quote with «*» or «۝».
        $parts = array_values(array_filter(array_map(self::skeleton(...), preg_split('/[*۝]/u', $quote)), fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return null;
        }

        $whole = $this->locate(implode(' ', $parts));
        if ($whole !== null) {
            return ['surah' => $whole[0], 'ayah' => $whole[1], 'ayahEnd' => null];
        }

        $words = explode(' ', implode(' ', $parts));
        if (count($parts) > 1) {
            [$first, $last] = [$parts[0], $parts[count($parts) - 1]];
        } elseif (count($words) >= self::EDGE_WORDS + 2) {
            [$first, $last] = [implode(' ', array_slice($words, 0, self::EDGE_WORDS)), implode(' ', array_slice($words, -self::EDGE_WORDS))];
        } else {
            return null;
        }

        $start = $this->locate($first);
        if ($start === null) {
            return null;
        }

        $end = $this->locate($last);
        $endsLater = $end !== null && $end[0] === $start[0] && $end[1] > $start[1] && $end[1] - $start[1] <= 30;

        return ['surah' => $start[0], 'ayah' => $start[1], 'ayahEnd' => $endsLater ? $end[1] : null];
    }

    /**
     * The ayah a quote reproduces word for word (spelling aside), covering at least 80% of it, or null. Stricter than
     * fromText(): used to tell a verse a source mis-marked as hadith from a real hadith, so a hadith that merely shares
     * a few words with an ayah stays a hadith. Quotes under three words never count.
     *
     * @return ?array{surah:int, ayah:int, ayahEnd:?int}
     */
    public function exactAyah(string $quote): ?array
    {
        $this->ensureMatchText();
        $skeleton = self::skeleton($quote);

        if (count(explode(' ', $skeleton)) < 3) {
            return null;
        }

        $row = DB::selectOne(
            "select surah_number, number from ayahs where ' ' || match_text || ' ' like ? and char_length(match_text) <= ? order by surah_number, number limit 1",
            ['% '.$skeleton.' %', (int) floor(mb_strlen($skeleton) / self::WHOLE_AYAH)],
        );

        return $row === null ? null : ['surah' => (int) $row->surah_number, 'ayah' => (int) $row->number, 'ayahEnd' => null];
    }

    /**
     * The matching form of a text: normalized, without alef or hamza, where Uthmani and standard spelling differ most.
     */
    public static function skeleton(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(['ا', 'ء'], '', Arabic::normalize($text))));
    }

    /**
     * @return ?array{int, int} [surah, ayah]
     */
    private function locate(string $skeleton): ?array
    {
        return count(explode(' ', $skeleton)) >= 3 ? $this->best($skeleton) : $this->unique($skeleton);
    }

    /**
     * The ayah whose text contains the skeleton, if similar enough.
     *
     * @return ?array{int, int}
     */
    private function best(string $skeleton): ?array
    {
        $row = DB::selectOne(
            'select surah_number, number, word_similarity(?, match_text) as score from ayahs where ? <% match_text order by score desc, surah_number, number limit 1',
            [$skeleton, $skeleton],
        );

        return $row !== null && $row->score >= self::MIN_SCORE ? [(int) $row->surah_number, (int) $row->number] : null;
    }

    /**
     * A short phrase (one or two words) links only when it appears, whole, in exactly one ayah.
     *
     * @return ?array{int, int}
     */
    private function unique(string $skeleton): ?array
    {
        if (mb_strlen($skeleton) < 8) {
            return null;
        }

        $rows = DB::select("select surah_number, number from ayahs where ' ' || match_text || ' ' like ? limit 2", ['% '.$skeleton.' %']);

        return count($rows) === 1 ? [(int) $rows[0]->surah_number, (int) $rows[0]->number] : null;
    }

    /**
     * Fills `ayahs.match_text` for ayahs imported since the last run.
     */
    private function ensureMatchText(): void
    {
        if ($this->matchTextReady) {
            return;
        }

        Ayah::query()->whereNull('match_text')->select(['id', 'text'])->chunkById(500, function ($ayahs): void {
            DB::transaction(function () use ($ayahs): void {
                foreach ($ayahs as $ayah) {
                    Ayah::query()->whereKey($ayah->id)->update(['match_text' => self::skeleton($ayah->text)]);
                }
            });
        });

        $this->matchTextReady = true;
    }

    /**
     * @return array<string, array{int, int}>
     */
    private function surahs(): array
    {
        if ($this->surahs !== null) {
            return $this->surahs;
        }

        $this->surahs = [];
        $counts = [];

        foreach (Surah::query()->get(['number', 'name_ar', 'ayah_count']) as $surah) {
            $counts[$surah->number] = $surah->ayah_count;
            $this->surahs[Arabic::normalize($surah->name_ar)] = [$surah->number, $surah->ayah_count];
        }

        foreach (self::ALIASES as $alias => $number) {
            if (isset($counts[$number])) {
                $this->surahs[$alias] ??= [$number, $counts[$number]];
            }
        }

        return $this->surahs;
    }
}
