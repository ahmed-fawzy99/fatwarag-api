<?php

namespace App\Importers;

use App\Content\HijriDate;
use App\Content\RichTextBuilder;
use App\Models\Fatwa;
use App\Models\Source;
use App\Models\SourceCategory;
use Carbon\CarbonImmutable;
use Dom\Element;
use Dom\HTMLDocument;
use Throwable;

/**
 * islamqa.info answers: one Arabic page per fatwa (pages/ar/<id%100>/<id>.html.gz) plus, when the site publishes it,
 * its own English translation (pages/en/…), stored as a `source` translation. Soft 404s (no question title) are skipped.
 */
class IslamqaImporter extends SourceImporter
{
    public const PARSER_VERSION = 7;

    private const MARKERS = ['span.aaya' => 'quran', 'span.hadith' => 'hadith', 'a.LinkFatwa' => 'fatwaRef'];

    private const ENGLISH_MARKERS = ['a[href*="/answers/"]' => 'fatwaRef'];

    private const OPTIONS = ['fatwaRefPrefix' => 'islamqa', 'remove' => ['div#toc_container', 'dialog']];

    /** The site naming itself as the source: not worth showing as an origin. */
    private const OWN_NAMES = ['موقع الإسلام سؤال وجواب', 'الإسلام سؤال وجواب'];

    /** @var array<string, int> external id => source_categories id */
    private array $categoryIds = [];

    public function sourceKey(): string
    {
        return 'islamqa';
    }

    public function import(?int $limit = null, bool $force = false): ImportResult
    {
        $result = new ImportResult;
        $source = $this->source();
        $this->categoryIds = [];

        foreach (glob($this->rawDir('pages/ar/*/*.html.gz')) ?: [] as $file) {
            $this->tick();

            if ($limit !== null && $result->written() >= $limit) {
                break;
            }

            $id = basename($file, '.html.gz');
            $english = $this->rawDir(sprintf('pages/en/%02d/%s.html.gz', (int) $id % 100, $id));
            $mtime = max((int) filemtime($file), is_file($english) ? (int) filemtime($english) : 0);

            if (! $force && $this->isUnchanged($id, $mtime)) {
                $result->skipped++;

                continue;
            }

            try {
                $fatwa = $this->importPage($source, $id, $file, $english, $mtime);
                $fatwa === null ? $result->skipped++ : $this->count($result, $fatwa);
            } catch (Throwable $exception) {
                $this->fail($result, $id, $exception);
            }
        }

        return $this->finish($result);
    }

    private function importPage(Source $source, string $id, string $file, string $englishFile, int $mtime): ?Fatwa
    {
        $html = $this->readGz($file);
        $page = $html === null ? null : $this->parse($html, self::MARKERS);

        if ($page === null) {
            return null;
        }

        $quotes = $page['quotes'];
        $origin = $page['source'] !== null && ! in_array($page['source'], self::OWN_NAMES, true) ? $page['source'] : null;
        $mufti = match (true) {
            $page['source'] !== null && (bool) preg_match('/ابن باز|بن باز/u', $page['source']) => 'binbaz',
            $page['source'] !== null && str_contains($page['source'], 'اللجنة الدائمة') => 'lajnah',
            default => 'islamqa',
        };

        $englishHtml = is_file($englishFile) ? $this->readGz($englishFile) : null;
        $english = $englishHtml === null ? null : $this->parse($englishHtml, self::ENGLISH_MARKERS);

        return $this->writer->save($source, [
            'source_fatwa_id' => $id,
            'url' => "https://islamqa.info/ar/answers/{$id}",
            'mufti_id' => Muftis::id($mufti),
            'title' => $page['title'],
            'question' => $page['question'],
            'answer' => $page['answer'],
            'source_summary' => $page['summary'],
            'origin' => $origin,
            'hijri' => $page['hijri'],
            'date_gregorian' => $page['published'],
            'collected_at' => $mtime,
            'parser_version' => static::PARSER_VERSION,
        ], $quotes, $this->categories($source, $html), $english === null ? null : [
            'locale' => 'en',
            'title' => $english['title'],
            'question' => $english['question'],
            'answer' => $english['answer'],
            'source_summary' => $english['summary'],
            'url' => "https://islamqa.info/en/answers/{$id}",
        ]);
    }

    /**
     * @param  array<string, string>  $markers
     * @return ?array{title: string, question: list<mixed>, answer: list<mixed>, summary: ?list<mixed>, hijri: ?array{day: int, month: int, year: int}, published: ?string, source: ?string, quotes: array<string, string>}
     */
    private function parse(string $html, array $markers): ?array
    {
        $start = strpos($html, '<article id="single-post-content"');
        $end = $start === false ? false : strpos($html, '</article>', $start);

        if ($start === false || $end === false) {
            return null;
        }

        $article = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.substr($html, $start, $end - $start + 10).'</body></html>', LIBXML_NOERROR, 'UTF-8');
        $title = $this->text($article->querySelector('h1[data-sut="question-title"]'));
        $answerNode = $article->querySelector('div[data-sut="answer-text"]');

        if ($title === null || $title === '' || $answerNode === null) {
            return null;
        }

        $questionNode = $article->querySelector('h2[data-sut="question-number"]')?->nextElementSibling;
        $question = RichTextBuilder::stripLabel($this->builder->fromHtml($questionNode?->innerHTML ?? '', $markers, self::OPTIONS));
        $quotes = $this->builder->collectedQuotes();

        $summary = null;
        foreach ($article->querySelectorAll('h2') as $heading) {
            if (in_array($this->text($heading), ['ملخص الجواب', 'Summary of answer'], true) && $heading->nextElementSibling !== null) {
                $summary = $this->builder->fromHtml($heading->nextElementSibling->innerHTML, $markers, self::OPTIONS) ?: null;
                $quotes += $this->builder->collectedQuotes();
            }
        }

        $intro = '';
        for ($node = $answerNode->previousElementSibling; $node !== null && $node->localName === 'p'; $node = $node->previousElementSibling) {
            $intro = $node->outerHTML.$intro;
        }
        $answer = RichTextBuilder::stripLabel($this->builder->fromHtml($intro.'<div>'.$answerNode->innerHTML.'</div>', $markers, self::OPTIONS));
        $quotes += $this->builder->collectedQuotes();

        $date = $this->text($article->querySelector('time[data-sut="publication-date"]'));

        return [
            'title' => $title,
            'question' => $question,
            'answer' => $answer,
            'summary' => $summary,
            'hijri' => $date !== null ? HijriDate::parse($date) : null,
            'published' => $this->publishedDate($html),
            'source' => $this->text($article->querySelector('p[data-sut="fatwa-source"]')) ?: null,
            'quotes' => $quotes,
        ];
    }

    private function text(?Element $element): ?string
    {
        return $element === null ? null : trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $element->textContent)));
    }

    /**
     * The JSON-LD publication date, as the calendar date in Mecca (the site stamps midnight Riyadh time in UTC).
     */
    private function publishedDate(string $html): ?string
    {
        if (! preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $json) {
            $published = json_decode($json, true)['mainEntity']['datePublished'] ?? null;

            if (is_string($published)) {
                try {
                    return CarbonImmutable::parse($published)->setTimezone('Asia/Riyadh')->toDateString();
                } catch (Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Every topic breadcrumb path becomes a chain of categories; the fatwa belongs to each path's last one.
     *
     * @return list<int>
     */
    private function categories(Source $source, string $html): array
    {
        preg_match_all('#<ol[^>]*aria-label="breadcrumb"[^>]*>(.*?)</ol>#s', $html, $lists);
        $leaves = [];

        foreach ($lists[1] as $list) {
            preg_match_all('#href="/(?:ar|en)/categories/(topics/\d+)"[^>]*>\s*<span itemProp="name">([^<]*)</span>#', $list, $crumbs, PREG_SET_ORDER);

            $parentId = null;
            $names = [];
            foreach ($crumbs as [, $externalId, $name]) {
                $name = trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $names[] = $name;
                $parentId = $this->categoryIds[$externalId] ??= SourceCategory::query()->updateOrCreate(
                    ['source_id' => $source->id, 'external_id' => $externalId],
                    ['name' => $name, 'path' => implode(' / ', $names), 'parent_id' => $parentId],
                )->id;
            }

            if ($parentId !== null) {
                $leaves[$parentId] = true;
            }
        }

        return array_keys($leaves);
    }
}
