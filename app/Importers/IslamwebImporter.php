<?php

namespace App\Importers;

use App\Content\HijriDate;
use App\Content\RichTextBuilder;
use App\Models\Fatwa;
use App\Models\Source;
use App\Models\SourceCategory;
use Dom\Element;
use Dom\HTMLDocument;
use Throwable;

/**
 * islamweb.net fatwas (pages/ar/<id/1000>/<id>.html.gz), a selection of about 40,000 plus the fatwas they cite.
 * Quotes are colour-coded: blue is Quran (with «{النحل: 125}» after it), green is hadith; newer pages only put the
 * Quran in «{…}», which is matched against the ayahs. Fatwa numbers cited in the
 * text become links. Generic shells (no accepted answer) and redirect-only answers («سبق الجواب عنه في الفتوى رقم…»)
 * are skipped. Fatwas collected only because a selected one cites them are marked as cross-references.
 */
class IslamwebImporter extends SourceImporter
{
    public const PARSER_VERSION = 7;

    private const MARKERS = [
        'font[color="blue" i], font[color="#0000ff" i], font[color="0000ff" i], span[style*="color:blue" i], span[style*="color: blue" i], span[style*="#0000ff" i]' => 'quran',
        'font[color="green" i], font[color="#008000" i], font[color="008000" i], span[style*="color:green" i], span[style*="color: green" i], span[style*="#008000" i]' => 'hadith',
        'a[href*="/ar/fatwa/"]' => 'fatwaRef',
    ];

    private const OPTIONS = ['fatwaRefPrefix' => 'islamweb', 'fatwaRefPattern' => '#/fatwa/(\d+)#', 'inTextFatwaRefs' => true, 'braceQuran' => true, 'hadithMayBeQuran' => true, 'hadithFilter' => 'none'];

    /** The harvester's redirect-only rule: what is left after the opening and closing formulas. */
    private const OPENING = '/^\s*الحمد لله.{0,100}?أما بعد\s*:?/su';

    private const CLOSING = '/والله\s*(تعالى\s*)?أعلم\s*\.?\s*$/u';

    private const CITES_FATWA = '/(الفتو[ىي]|الفتاوى|الفتويين|فتوى)[^0-9]{0,40}\d{2,6}/u';

    /** @var array<string, int> */
    private array $categoryIds = [];

    /** @var array<string, bool> fatwa ids collected only as cross-references */
    private array $crossReferences = [];

    public function sourceKey(): string
    {
        return 'islamweb';
    }

    public function import(?int $limit = null, bool $force = false): ImportResult
    {
        $result = new ImportResult;
        $source = $this->source();
        $this->categoryIds = $this->importTree($source);
        $this->crossReferences = $this->crossReferenceIds();

        foreach (glob($this->rawDir('pages/ar/*/*.html.gz')) ?: [] as $file) {
            $this->tick();

            if ($limit !== null && $result->written() >= $limit) {
                break;
            }

            $id = basename($file, '.html.gz');
            $mtime = (int) filemtime($file);

            if (! $force && $this->isUnchanged($id, $mtime)) {
                $result->skipped++;

                continue;
            }

            try {
                $fatwa = $this->importPage($source, $id, $file, $mtime);
                $fatwa === null ? $result->skipped++ : $this->count($result, $fatwa);
            } catch (Throwable $exception) {
                $this->fail($result, $id, $exception);
            }
        }

        return $this->finish($result);
    }

    private function importPage(Source $source, string $id, string $file, int $mtime): ?Fatwa
    {
        $html = $this->readGz($file);
        $start = $html === null ? false : strpos($html, '<div class="item-fatwa"');

        if ($start === false || ! str_contains($html, 'itemprop="acceptedAnswer"')) {
            return null;
        }

        $end = strpos($html, 'linkedtab', $start) ?: strpos($html, 'BreadcrumbList', $start) ?: strlen($html);
        $page = HTMLDocument::createFromString('<!DOCTYPE html><html><body><div>'.substr($html, $start, $end - $start).'</div></body></html>', LIBXML_NOERROR, 'UTF-8');

        $title = $this->text($page->querySelector('.item-fatwa .top-item h2'));
        $answerNode = $page->querySelector('div.quest-fatwa[itemprop="acceptedAnswer"] div[itemprop="text"]');

        if ($title === '' || $answerNode === null) {
            return null;
        }

        $answer = RichTextBuilder::stripLabel($this->builder->fromHtml($answerNode->innerHTML, self::MARKERS, self::OPTIONS));
        $quotes = $this->builder->collectedQuotes();

        if ($answer === [] || $this->isRedirectOnly(RichTextBuilder::plainText($answer))) {
            return null;
        }

        $question = RichTextBuilder::stripLabel($this->builder->fromHtml(
            $page->querySelector('div.quest-fatwa:not([itemprop]) div[itemprop="text"]')?->innerHTML ?? '', self::MARKERS, self::OPTIONS,
        ));
        $quotes += $this->builder->collectedQuotes();

        $footer = array_map(fn (Element $a): string => $this->text($a), iterator_to_array($page->querySelectorAll('.item-fatwa .footer-item samp a')));
        $date = collect($footer)->first(fn (string $text): bool => str_contains($text, 'هـ') || (bool) preg_match('/\d{1,2}-\d{1,2}-\d{4}/', $text));

        return $this->writer->save($source, [
            'source_fatwa_id' => $id,
            'url' => "https://www.islamweb.net/ar/fatwa/{$id}/",
            'mufti_id' => Muftis::id('islamweb'),
            'title' => $title,
            'question' => $question,
            'answer' => $answer,
            'hijri' => $date !== null ? HijriDate::parse($date) : null,
            'date_gregorian' => $date !== null && preg_match('/(\d{1,2})-(\d{1,2})-(\d{4})\s*م?/u', $date, $g) && checkdate((int) $g[2], (int) $g[1], (int) $g[3])
                ? sprintf('%04d-%02d-%02d', $g[3], $g[2], $g[1]) : null,
            'is_cross_reference' => isset($this->crossReferences[$id]),
            'collected_at' => $mtime,
            'parser_version' => static::PARSER_VERSION,
        ], $quotes, $this->categories($html));
    }

    /**
     * An answer that only points to another fatwa: under 220 characters once the formulas are removed, citing a
     * fatwa number, with almost nothing else.
     */
    private function isRedirectOnly(string $answer): bool
    {
        $core = trim(preg_replace(self::CLOSING, '', preg_replace(self::OPENING, '', preg_replace('/\s+/u', ' ', $answer))));

        if (mb_strlen($core) >= 220 || ! preg_match(self::CITES_FATWA, $core)) {
            return false;
        }

        $rest = array_filter(preg_split('/[.،؛:\n]+(?!\s*\d)/u', $core), fn (string $part): bool => trim($part) !== '' && ! preg_match(self::CITES_FATWA, $part));

        return mb_strlen(preg_replace('/[\d\s.,،؛:\-()]+/u', '', implode(' ', $rest))) < 25;
    }

    private function text(?Element $element): string
    {
        return $element === null ? '' : trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $element->textContent)));
    }

    /**
     * The breadcrumb's last category (one path per fatwa).
     *
     * @return list<int>
     */
    private function categories(string $html): array
    {
        $block = preg_match('#BreadcrumbList.*?</ol>#su', $html, $m) ? $m[0] : '';
        preg_match_all('#href="/ar/fatawa/(\d+)/#', $block, $ids);
        $leaf = end($ids[1]);

        return $leaf !== false && isset($this->categoryIds[$leaf]) ? [$this->categoryIds[$leaf]] : [];
    }

    /**
     * Every category of islamweb's tree (queue/tree.json, read by the harvester), with its own listed fatwa count.
     *
     * @return array<string, int>
     */
    private function importTree(Source $source): array
    {
        $tree = json_decode(@file_get_contents($this->rawDir('queue/tree.json')) ?: '[]', true) ?: [];
        $names = [];
        foreach ($tree as $node) {
            foreach ($node['kids'] ?? [] as $kid) {
                $names[(string) $kid['id']] = $kid['name'];
            }
        }

        $ids = [];
        $pending = array_map('strval', array_column($tree['0']['kids'] ?? [], 'id'));

        while ($pending !== []) {
            $externalId = array_shift($pending);
            $node = $tree[$externalId] ?? null;
            $path = $node['path'] ?? [$names[$externalId] ?? $externalId];
            $parent = (string) ($node['parent'] ?? '0');

            $ids[$externalId] = SourceCategory::query()->updateOrCreate(
                ['source_id' => $source->id, 'external_id' => $externalId],
                ['name' => $names[$externalId] ?? end($path), 'path' => implode(' / ', $path), 'parent_id' => $ids[$parent] ?? null, 'fatwa_count' => $node['listed'] ?? null],
            )->id;

            array_push($pending, ...array_map('strval', array_column($node['kids'] ?? [], 'id')));
        }

        return $ids;
    }

    /**
     * Ids fetched only because a selected fatwa cites them: queue/xref_ids.txt, or `via: xref` in the manifest,
     * minus anything also selected in its own right.
     *
     * @return array<string, bool>
     */
    private function crossReferenceIds(): array
    {
        $crossReferences = [];
        $selected = [];

        foreach ($this->lines('queue/xref_ids.txt') as $id) {
            $crossReferences[$id] = true;
        }
        foreach ($this->lines('queue/selected_ids.txt') as $id) {
            $selected[$id] = true;
        }

        $manifest = @fopen($this->rawDir('manifest.jsonl'), 'r');
        while ($manifest && ($line = fgets($manifest)) !== false) {
            if (! str_contains($line, '"kind": "ar"') && ! str_contains($line, '"kind":"ar"')) {
                continue;
            }

            $entry = json_decode($line, true);
            $via = (string) ($entry['via'] ?? '');
            if ($via === 'xref') {
                $crossReferences[(string) $entry['id']] = true;
            } elseif ($via !== '' && $via !== 'test') {
                $selected[(string) $entry['id']] = true;
            }
        }
        if ($manifest) {
            fclose($manifest);
        }

        return array_diff_key($crossReferences, $selected);
    }

    /**
     * @return list<string>
     */
    private function lines(string $path): array
    {
        $file = $this->rawDir($path);

        return is_file($file) ? array_values(array_filter(array_map('trim', file($file) ?: []))) : [];
    }
}
