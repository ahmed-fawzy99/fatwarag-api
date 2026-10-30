<?php

namespace App\Importers\Foundations;

use App\Models\Hadith;
use App\Models\HadithExplanation;
use App\Models\HadithGrading;
use App\Models\HadithQuote;
use App\Services\Alerts;
use App\Support\Arabic;
use App\Support\GradeClassifier;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Resolves hadith quotes (hadith_quotes) against the Dorar pages the harvester saved under data/raw/dorar.
 *
 * The harvester looks a quote up by its first ~10 words and files the search page under sha1 of the normalized query,
 * so the same query is rebuilt here (lookupKey/searchId, ported from tools/harvest/dorar/harvest.py) to find the page.
 * Results of that page that match the quote (trigram similarity) become ONE hadith with every grading found; the quote
 * becomes `found`, or `not_found` when nothing matches. A quote whose lookup the harvester hasn't finished stays pending.
 * Safe to re-run: only pending quotes are touched, and a hadith already stored (same Dorar hash) is reused.
 */
class DorarImporter
{
    public const SIMILARITY = 0.5;

    /** A quote shorter than this only matches a result it is similar to; as a part of a longer text it would be too ambiguous. */
    private const CONTAINMENT_MIN_WORDS = 4;

    /** A result this similar to the quote itself is kept even when it is a spelling variant that doesn't contain it. */
    private const STRONG_SIMILARITY = 0.7;

    private const QUERY_WORDS = 10;

    /** Arabic name of the grading scholar => English, the same spelling the prototype uses. */
    private const SCHOLARS_EN = [
        'الألباني' => 'Al-Albani', 'شعيب الأرناؤوط' => "Shu'ayb al-Arna'ut", 'ابن باز' => 'Ibn Baz', 'البخاري' => 'Al-Bukhari',
        'ابن حجر العسقلاني' => 'Ibn Hajar al-Asqalani', 'ابن عثيمين' => 'Ibn Uthaymin', 'البيهقي' => 'Al-Bayhaqi',
        'الدارقطني' => 'Al-Daraqutni', 'الإمام أحمد' => 'Imam Ahmad', 'مسلم' => 'Muslim', 'ابن الملقن' => 'Ibn al-Mulaqqin',
        'ابن حبان' => 'Ibn Hibban', 'أبو داود' => 'Abu Dawud', 'ابن حزم' => 'Ibn Hazm', 'الشوكاني' => 'Al-Shawkani',
        'أحمد شاكر' => 'Ahmad Shakir', 'ابن خزيمة' => 'Ibn Khuzaymah', 'ابن الجوزي' => 'Ibn al-Jawzi', 'المنذري' => 'Al-Mundhiri',
        'البغوي' => 'Al-Baghawi', 'الترمذي' => 'Al-Tirmidhi', 'ابن العربي' => 'Ibn al-Arabi', 'الذهبي' => 'Al-Dhahabi',
        'ابن كثير' => 'Ibn Kathir', 'العيني' => 'Al-Ayni', 'ابن دقيق العيد' => 'Ibn Daqiq al-Id', 'النووي' => 'Al-Nawawi',
        'ابن تيمية' => 'Ibn Taymiyyah', 'ابن القيم' => 'Ibn al-Qayyim', 'ابن مفلح' => 'Ibn Muflih', 'الطبراني' => 'Al-Tabarani',
        'أبو حاتم الرازي' => 'Abu Hatim al-Razi', 'الزيلعي' => "Al-Zayla'i", 'الحاكم' => 'Al-Hakim', 'الصنعاني' => "Al-San'ani",
        'الهيثمي' => 'Al-Haythami', 'الإمام الشافعي' => "Al-Shafi'i", 'ابن عدي' => 'Ibn Adi', 'ابن رجب' => 'Ibn Rajab',
        'السيوطي' => 'Al-Suyuti', 'العراقي' => 'Al-Iraqi', 'أبو زرعة الرازي' => "Abu Zur'ah al-Razi", 'المزي' => 'Al-Mizzi',
        'العقيلي' => 'Al-Uqayli', 'ابن عساكر' => 'Ibn Asakir', 'الطحاوي' => 'Al-Tahawi', 'النسائي' => "Al-Nasa'i",
        'البزار' => 'Al-Bazzar', 'البوصيري' => 'Al-Busiri',
    ];

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? base_path('../data/raw/dorar'), '/');
    }

    // ------------------------------------------------------------------ the harvester's query, ported exactly

    /**
     * The query the harvester sends to Dorar for a quote: its first segment (before an ellipsis), punctuation and
     * Latin letters/digits removed, first ten words. Null when fewer than two words are left (never looked up).
     */
    public static function query(string $quote): ?string
    {
        $text = self::light($quote);
        $segments = array_values(array_filter(preg_split('/\.\.\.+|…/u', $text), fn ($s) => trim($s) !== ''));

        if ($segments === []) {
            return null;
        }

        $segment = $segments[0];

        foreach ($segments as $candidate) {
            if (count(self::words($candidate)) >= 3) {
                $segment = $candidate;
                break;
            }
        }

        $segment = preg_replace('/[^\p{L}\p{N}_\s]/u', ' ', $segment);
        $segment = preg_replace('/[A-Za-z0-9_٠-٩]/u', ' ', $segment);
        $words = self::words($segment);

        return count($words) < 2 ? null : implode(' ', array_slice($words, 0, self::QUERY_WORDS));
    }

    /** The harvester's dedup key for a query: harakat and letter variants removed. */
    public static function lookupKey(string $quote): ?string
    {
        $query = self::query($quote);

        if ($query === null) {
            return null;
        }

        $text = strtr(self::light($query), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** The id the harvester saved the quote's search page under. */
    public static function searchId(string $quote): ?string
    {
        $key = self::lookupKey($quote);

        return $key === null ? null : substr(sha1($key), 0, 16);
    }

    public static function searchUrl(string $quote): ?string
    {
        $query = self::query($quote);

        return $query === null ? null : 'https://dorar.net/hadith/search?q='.rawurlencode($query);
    }

    private static function light(string $text): string
    {
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E8}\x{06EA}-\x{06ED}\x{0640}]/u', '', $text);
        $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    // ------------------------------------------------------------------ parsing Dorar pages

    /**
     * The result blocks of a search page, in page order.
     *
     * @return list<array{text: string, phrase: ?string, narrator: ?string, scholar: ?string, book: ?string, reference: ?string, takhrij: ?string, hash: ?string, sharh_id: ?string, tab: string}>
     */
    public static function parseSearch(string $html): array
    {
        preg_match_all('/<div class="border-bottom py-4/', $html, $starts, PREG_OFFSET_CAPTURE);
        $offsets = array_column($starts[0], 1);
        $specialist = strpos($html, 'id="specialist"');
        $results = [];

        foreach ($offsets as $i => $start) {
            $isSpecialist = $specialist !== false && $start > $specialist;
            $end = $offsets[$i + 1] ?? min(strlen($html), $start + 20000);

            if (! $isSpecialist && $specialist !== false && $end > $specialist) {
                $end = $specialist;
            }

            $block = substr($html, $start, $end - $start);
            $text = self::cleanText(preg_match('/<h5[^>]*>(.*?)<\/h5>/su', $block, $m) ? $m[1] : '');
            $text = preg_replace('/^\d+\s*-\s*/u', '', $text);

            if ($text === '') {
                continue;
            }

            $sharhId = null;

            if (preg_match_all('/xplain="(\d+)"[^>]*>(.*?)<\/a>/su', $block, $xplain, PREG_SET_ORDER)) {
                foreach ($xplain as $link) {
                    if (trim(strip_tags($link[2]), "| \t\n\r") === 'شرح الحديث') {
                        $sharhId = $link[1];
                        break;
                    }
                }
            }

            $results[] = [
                'text' => $text,
                'phrase' => self::field($block, 'خلاصة حكم المحدث'),
                'narrator' => self::field($block, 'الراوي'),
                'scholar' => self::field($block, '\|\s*المحدث'),
                'book' => self::field($block, '\|\s*المصدر'),
                'reference' => self::field($block, 'الصفحة أو الرقم'),
                'takhrij' => self::field($block, 'التخريج'),
                'hash' => preg_match('/href="(?:https?:\/\/dorar\.net)?\/h\/([A-Za-z0-9]+)/', $block, $h) ? $h[1] : null,
                'sharh_id' => $sharhId,
                'tab' => $isSpecialist ? 'specialist' : 'general',
            ];
        }

        return $results;
    }

    /**
     * The commentary on a sharh page, one paragraph per line; null when the page has none.
     */
    public static function parseSharh(string $html): ?string
    {
        if (! preg_match('/<div id="sharh-text-content">(.*?)(?:<div class="visible-xs"|<\/div>)/su', $html, $m)) {
            return null;
        }

        $paragraphs = array_map(fn ($p) => self::cleanText($p), preg_split('/<br\s*\/?>/i', $m[1]));
        $text = implode("\n", array_filter($paragraphs, fn ($p) => $p !== ''));

        return $text === '' ? null : $text;
    }

    private static function field(string $block, string $label): ?string
    {
        if (! preg_match('/'.$label.'\s*:\s*(?:<a[^>]*>\s*)?<span[^>]*>(.*?)<\/span>/su', $block, $m)) {
            return null;
        }

        $value = self::cleanText($m[1]);

        return in_array($value, ['', '-'], true) ? null : $value;
    }

    private static function cleanText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    // ------------------------------------------------------------------ matching

    /**
     * Trigram sets the way pg_trgm builds them: each word padded with two spaces before and one after.
     *
     * @return array<string, true>
     */
    private static function trigrams(string $normalized): array
    {
        $set = [];

        foreach (self::words($normalized) as $word) {
            $chars = mb_str_split('  '.$word.' ');

            for ($i = 0, $n = count($chars) - 2; $i < $n; $i++) {
                $set[$chars[$i].$chars[$i + 1].$chars[$i + 2]] = true;
            }
        }

        return $set;
    }

    /**
     * Trigram similarity (pg_trgm's: shared / all trigrams) of two normalized texts.
     */
    public static function similarity(string $a, string $b): float
    {
        $a = self::trigrams($a);
        $b = self::trigrams($b);
        $union = count($a + $b);

        return $union === 0 ? 0.0 : count(array_intersect_key($a, $b)) / $union;
    }

    /**
     * Whether the quote's words appear, in order and as whole words, inside the result's text (the quote is a part of a
     * longer narration). Needs at least four words; a shorter quote would match unrelated hadiths.
     */
    public static function contains(string $quote, string $resultText): bool
    {
        $quote = Arabic::normalize($quote);
        $result = Arabic::normalize($resultText);

        return count(self::words($quote)) >= self::CONTAINMENT_MIN_WORDS && str_contains(" {$result} ", " {$quote} ");
    }

    /**
     * A result matches a quote at trigram similarity >= 0.5, or when it contains the whole quote as a run of words.
     */
    public static function matches(string $quote, string $resultText): bool
    {
        $normalizedQuote = Arabic::normalize($quote);
        $result = Arabic::normalize($resultText);

        if ($normalizedQuote === '' || $result === '') {
            return false;
        }

        return self::similarity($normalizedQuote, $result) >= self::SIMILARITY || self::contains($quote, $resultText);
    }

    // ------------------------------------------------------------------ import

    /**
     * @return array{found: int, not_found: int, pending: int, failed: int}
     */
    public function import(): array
    {
        $counts = ['found' => 0, 'not_found' => 0, 'pending' => 0, 'failed' => 0];
        $done = $this->doneLookups();

        HadithQuote::query()->where('status', 'pending')->orderBy('id')->chunkById(200, function ($quotes) use (&$counts, $done) {
            foreach ($quotes as $quote) {
                try {
                    $status = $this->resolve($quote, $done);
                } catch (Throwable $exception) {
                    $status = 'failed';
                    $this->alert($quote, $exception);
                }

                $counts[$status]++;
            }
        });

        return $counts;
    }

    /**
     * @param  array<string, string>  $done  the harvester's finished lookups, key => search id
     * @return 'found'|'not_found'|'pending'|'failed'
     */
    private function resolve(HadithQuote $quote, array $done): string
    {
        $key = self::lookupKey($quote->quote);

        if ($key === null) {
            // Too short for Dorar to search: nothing to look up, ever.
            $quote->update(['status' => 'not_found']);

            return 'not_found';
        }

        if (! isset($done[$key])) {
            return 'pending';
        }

        $html = $this->read('search', $done[$key]);

        if ($html === null) {
            return 'pending';
        }

        $candidates = array_values(array_filter(self::parseSearch($html), fn ($r) => self::matches($quote->quote, $r['text'])));
        // The main text is the first general-tab result that contains the quote, else the first similar one.
        $containing = array_values(array_filter($candidates, fn ($r) => self::contains($quote->quote, $r['text'])));
        $main = collect($containing)->firstWhere('tab', 'general') ?? $containing[0] ?? collect($candidates)->firstWhere('tab', 'general') ?? $candidates[0] ?? null;

        // Only results that are this same hadith are kept: they contain the quote, or are close to the main text or the quote.
        $matches = $main === null ? [] : array_values(array_filter($candidates, fn ($r) => self::contains($quote->quote, $r['text'])
            || self::similarity(Arabic::normalize($main['text']), Arabic::normalize($r['text'])) >= self::SIMILARITY
            || self::similarity(Arabic::normalize($quote->quote), Arabic::normalize($r['text'])) >= self::STRONG_SIMILARITY));

        // A grading needs its wording and a scholar; with none to show, the safe state is "not found".
        if (! array_filter($matches, fn ($r) => $r['phrase'] && $r['scholar'])) {
            $quote->update(['status' => 'not_found']);

            return 'not_found';
        }

        DB::transaction(function () use ($quote, $main, $matches) {
            $hadith = $this->saveHadith($quote, $main, $matches);
            $quote->update(['status' => 'found', 'hadith_id' => $hadith->id]);
        });

        return 'found';
    }

    /**
     * @param  array<string, mixed>  $main  the first matching general-tab result, whose text is the hadith's
     * @param  non-empty-list<array<string, mixed>>  $matches
     */
    private function saveHadith(HadithQuote $quote, array $main, array $matches): Hadith
    {
        // Reuse a stored hadith only when its main narration is this one, never because of some shared hash.
        $hadith = ($main['hash'] ? Hadith::query()->whereHas('gradings', fn ($q) => $q->where('dorar_hash', $main['hash']))->first() : null)
            ?? Hadith::query()->create([
                'text' => $main['text'],
                'search_text' => Arabic::normalize($main['text']),
                'narrator' => $main['narrator'] ?? collect($matches)->pluck('narrator')->filter()->first(),
                'lookup_url' => self::searchUrl($quote->quote),
            ]);

        foreach ($matches as $result) {
            if (! $result['phrase'] || ! $result['scholar']) {
                continue; // a result with no grading or no scholar adds nothing to show
            }

            $values = [
                'text' => $result['text'],
                'narrator' => $result['narrator'],
                'scholar' => $result['scholar'],
                'scholar_en' => self::SCHOLARS_EN[$result['scholar']] ?? null,
                'book' => $result['book'],
                'reference' => $result['reference'],
                'phrase' => $result['phrase'],
                'level' => GradeClassifier::level($result['phrase']),
                'takhrij' => $result['takhrij'],
                'tab' => $result['tab'],
            ];

            if ($result['hash']) {
                $hadith->gradings()->updateOrCreate(['dorar_hash' => $result['hash']], $values);
            } else {
                $hadith->gradings()->create($values);
            }
        }

        $hadith->gradings()->get()
            ->sortBy([fn (HadithGrading $a, HadithGrading $b) => $a->level->strength() <=> $b->level->strength(), fn ($a, $b) => $a->id <=> $b->id])
            ->values()
            ->each(fn (HadithGrading $grading, int $position) => $grading->position === $position || $grading->update(['position' => $position]));

        if ($hadith->hadith_explanation_id === null) {
            $this->attachExplanation($hadith, array_values(array_unique(array_filter(array_column([$main, ...$matches], 'sharh_id')))));
        }

        return $hadith;
    }

    /**
     * Attaches the first exact «شرح الحديث» whose page was harvested. «شرح حديث مشابه» (a similar hadith's) never has an id here.
     *
     * @param  list<string>  $sharhIds
     */
    private function attachExplanation(Hadith $hadith, array $sharhIds): void
    {
        foreach ($sharhIds as $sharhId) {
            $html = $this->read('sharh', $sharhId);
            $text = $html === null ? null : self::parseSharh($html);

            if ($text !== null) {
                $explanation = HadithExplanation::query()->updateOrCreate(['dorar_sharh_id' => $sharhId], [
                    'text' => $text,
                    'source_url' => 'https://dorar.net/hadith/sharh/'.$sharhId,
                ]);
                $hadith->update(['hadith_explanation_id' => $explanation->id]);

                return;
            }
        }
    }

    // ------------------------------------------------------------------ the raw store

    /**
     * Lookups the harvester has finished (search page and its sharh pages stored): normalized query => search id.
     *
     * @return array<string, string>
     */
    private function doneLookups(): array
    {
        $path = $this->root.'/queue/done.tsv';
        $done = [];

        foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $columns = explode("\t", $line);

            if (isset($columns[1]) && $columns[0] !== '') {
                $done[$columns[0]] = $columns[1];
            }
        }

        return $done;
    }

    private function read(string $kind, string $id): ?string
    {
        $shard = $kind === 'search' ? substr($id, 0, 2) : (ctype_digit($id) ? sprintf('%02d', (int) $id % 100) : substr($id, 0, 2));
        $path = "{$this->root}/pages/{$kind}/{$shard}/{$id}.html.gz";

        if (! is_file($path)) {
            return null;
        }

        $html = @gzdecode((string) file_get_contents($path));

        return $html === false ? throw new \RuntimeException("Unreadable Dorar page {$kind}/{$id}") : $html;
    }

    private function alert(HadithQuote $quote, Throwable $exception): void
    {
        try {
            app(Alerts::class)->raise(
                'hadiths_import',
                'hadiths-import-failed',
                'A hadith quote could not be resolved from the Dorar store.',
                ['quote_id' => $quote->id, 'error' => $exception->getMessage()],
                'Check data/raw/dorar for a corrupt page; the quote stays pending and is retried.',
            );
        } catch (Throwable) {
            // Reporting must never stop the import.
        }
    }
}
