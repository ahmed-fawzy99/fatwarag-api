<?php

namespace App\Importers;

use App\Content\RichTextBuilder;
use App\Models\Fatwa;
use App\Models\Source;
use App\Models\SourceCategory;
use Throwable;

/**
 * al-Fawzan's collected fatwas (Shamela book 8612). The book is one stream of paragraphs across 695 pages: pages are
 * joined where a sentence runs over, then split into fatwas at a heading paragraph, a «سؤال:» after «***», «***», or
 * a section title («كتاب الصلاة»).
 * Follow-up question/answer pairs stay inside their heading's fatwa. Each fatwa cites its printed volume and page.
 */
class FawzanImporter extends SourceImporter
{
    public const PARSER_VERSION = 7;

    public const BOOK = 8612;

    private const MARKERS = ['span.c3' => 'quran', 'span.c2' => 'hadith'];

    private const OPTIONS = ['hadithFilter' => 'guillemets', 'quoteStrip' => ['span.c5'], 'remove' => ['a.btn_tag', 'span.anchor']];

    /** A paragraph ending like this closes its sentence; anything else runs over to the next page. */
    private const SENTENCE_END = '/[.؟?!:»\])*]$/u';

    public function sourceKey(): string
    {
        return 'fawzan';
    }

    public function import(?int $limit = null, bool $force = false): ImportResult
    {
        $result = new ImportResult;
        $source = $this->source();
        [$paragraphs, $pages] = $this->readBook($result);
        $sections = $this->sections($source);

        foreach ($this->split($paragraphs) as $fatwa) {
            $this->tick();

            if ($limit !== null && $result->written() >= $limit) {
                break;
            }

            $id = "{$fatwa['start']['page']}-{$fatwa['start']['n']}";
            $mtime = max(array_map(fn (array $p): int => $pages[$p['page']]['mtime'], [...$fatwa['question'], ...$fatwa['answer']]));

            if (! $force && $this->isUnchanged($id, $mtime)) {
                $result->skipped++;

                continue;
            }

            try {
                $this->count($result, $this->save($source, $fatwa, $id, $pages, $sections, $mtime));
            } catch (Throwable $exception) {
                $this->fail($result, $id, $exception);
            }
        }

        return $this->finish($result);
    }

    /**
     * @param  array{start: array<string, mixed>, title: ?string, question: list<array<string, mixed>>, answer: list<array<string, mixed>>}  $fatwa
     * @param  array<int, array{part: string, pageNum: int, mtime: int}>  $pages
     * @param  array<int, int>  $sections  first page id => source_categories id
     */
    private function save(Source $source, array $fatwa, string $id, array $pages, array $sections, int $mtime): Fatwa
    {
        $question = RichTextBuilder::stripLabel($this->builder->fromHtml($this->html($fatwa['question']), self::MARKERS, self::OPTIONS));
        $quotes = $this->builder->collectedQuotes();
        $answer = RichTextBuilder::stripLabel($this->builder->fromHtml($this->html($fatwa['answer']), self::MARKERS, self::OPTIONS));
        $quotes += $this->builder->collectedQuotes();

        $startPage = $fatwa['start']['page'];
        $section = null;
        foreach ($sections as $firstPage => $categoryId) {
            if ($firstPage <= $startPage) {
                $section = $categoryId;
            }
        }

        return $this->writer->save($source, [
            'source_fatwa_id' => $id,
            'url' => 'https://shamela.ws/book/'.self::BOOK."/{$startPage}#p{$fatwa['start']['n']}",
            'mufti_id' => Muftis::id('fawzan'),
            'title' => $fatwa['title'] ?? $this->titleFrom($question),
            'question' => $question,
            'answer' => $answer,
            'origin' => "ج{$pages[$startPage]['part']} ص{$pages[$startPage]['pageNum']}",
            'collected_at' => $mtime,
            'parser_version' => static::PARSER_VERSION,
        ], $quotes, $section !== null ? [$section] : []);
    }

    /**
     * Every paragraph of the book in page order, with sentences that run over a page break joined back together.
     *
     * @return array{list<array{page: int, n: int, html: string, text: string}>, array<int, array{part: string, pageNum: int, mtime: int}>}
     */
    private function readBook(ImportResult $result): array
    {
        $files = glob($this->rawDir('pages/page/*.json.gz')) ?: [];
        usort($files, fn (string $a, string $b): int => (int) basename($a) <=> (int) basename($b));

        $paragraphs = [];
        $pages = [];

        foreach ($files as $file) {
            $page = json_decode($this->readGz($file) ?? '', true);

            if (! is_array($page) || ! is_string($page['nass'] ?? null) || ! isset($page['pageId'], $page['part'], $page['pageNum'])) {
                $result->skipped++;

                continue;
            }

            $pageId = (int) $page['pageId'];
            $pages[$pageId] = ['part' => (string) $page['part'], 'pageNum' => (int) $page['pageNum'], 'mtime' => (int) filemtime($file)];
            $onPage = $this->paragraphs($page['nass'], $pageId);

            $last = array_key_last($paragraphs);
            if ($last !== null && $onPage !== [] && $paragraphs[$last]['page'] === $pageId - 1
                && ! preg_match(self::SENTENCE_END, $paragraphs[$last]['text']) && $this->continues($onPage[0])) {
                $next = array_shift($onPage);
                $paragraphs[$last]['html'] .= ' '.$next['html'];
                $paragraphs[$last]['text'] .= ' '.$next['text'];
            }

            array_push($paragraphs, ...$onPage);
        }

        return [$paragraphs, $pages];
    }

    /**
     * @return list<array{page: int, n: int, html: string, text: string}>
     */
    private function paragraphs(string $nass, int $pageId): array
    {
        preg_match_all('/<p>(.*?)<\/p>/su', $nass, $matches);
        $paragraphs = [];

        foreach ($matches[1] as $i => $html) {
            $n = preg_match('/id="np\d+_(\d+)"/', $html, $m) ? (int) $m[1] : $i + 1;
            $html = preg_replace('/<a [^>]*class="btn_tag[^"]*"[^>]*>.*?<\/a>|<span [^>]*class="anchor"[^>]*><\/span>/su', '', $html);
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

            if ($text !== '') {
                $paragraphs[] = ['page' => $pageId, 'n' => $n, 'html' => $html, 'text' => $text];
            }
        }

        return $paragraphs;
    }

    /**
     * Groups the paragraph stream into fatwas: heading → «سؤال:» → «الجواب:» → answer, closed by «***» or the next heading.
     *
     * @param  list<array{page: int, n: int, html: string, text: string}>  $paragraphs
     * @return list<array{start: array<string, mixed>, title: ?string, question: list<array<string, mixed>>, answer: list<array<string, mixed>>}>
     */
    private function split(array $paragraphs): array
    {
        $fatwas = [];
        $current = null;
        $close = function () use (&$current, &$fatwas): void {
            if ($current !== null && $current['question'] !== [] && $current['answer'] !== []) {
                $fatwas[] = $current;
            }
            $current = null;
        };

        foreach ($paragraphs as $i => $paragraph) {
            if ($this->isSeparator($paragraph)) {
                $close();
            } elseif ($this->isHeading($paragraph, $paragraphs[$i + 1] ?? null)) {
                $close();
                $current = ['start' => $paragraph, 'title' => trim($paragraph['text'], ' []'), 'question' => [], 'answer' => []];
            } elseif ($this->isQuestion($paragraph)) {
                $current ??= ['start' => $paragraph, 'title' => null, 'question' => [], 'answer' => []];
                $current[$current['answer'] === [] ? 'question' : 'answer'][] = $paragraph;
            } elseif ($current !== null && ($this->isAnswer($paragraph) || $current['answer'] !== [])) {
                if ($current['question'] !== []) {
                    $current['answer'][] = $paragraph;
                }
            } elseif ($current !== null && $current['question'] !== []) {
                $current['question'][] = $paragraph;
            }
        }
        $close();

        return $fatwas;
    }

    /**
     * @param  array{text: string}  $paragraph
     * @param  ?array{text: string}  $next
     */
    private function isHeading(array $paragraph, ?array $next): bool
    {
        return $next !== null && $this->isQuestion($next) && ! $this->isQuestion($paragraph) && ! $this->isAnswer($paragraph)
            && mb_strlen($paragraph['text']) < 90
            && (! preg_match('/[.؟?!:،,؛»)]$/u', $paragraph['text']) || preg_match('/^\[.*\]$/u', $paragraph['text']));
    }

    /**
     * @param  array{text: string}  $paragraph
     */
    private function isQuestion(array $paragraph): bool
    {
        return (bool) preg_match('/^(?:السؤال|سؤال)\s*:/u', $paragraph['text']);
    }

    /**
     * @param  array{text: string}  $paragraph
     */
    private function isAnswer(array $paragraph): bool
    {
        return (bool) preg_match('/^(?:الجواب|جواب)\s*:/u', $paragraph['text']);
    }

    /**
     * @param  array{text: string}  $paragraph
     */
    private function isSeparator(array $paragraph): bool
    {
        return (bool) preg_match('/^[*\s]+$|^كتاب [^.؟?!:،,]{2,40}$/u', $paragraph['text']);
    }

    /**
     * Whether a page's first paragraph can continue the previous page's last sentence.
     *
     * @param  array{text: string}  $paragraph
     */
    private function continues(array $paragraph): bool
    {
        return ! $this->isQuestion($paragraph) && ! $this->isAnswer($paragraph) && ! $this->isSeparator($paragraph)
            && ! preg_match('/^\[.*\]$/u', $paragraph['text']);
    }

    /**
     * @param  list<array{html: string}>  $paragraphs
     */
    private function html(array $paragraphs): string
    {
        return implode('', array_map(fn (array $p): string => "<p>{$p['html']}</p>", $paragraphs));
    }

    /**
     * A title for a fatwa with no heading: the question's first sentence.
     *
     * @param  list<list<array<string, mixed>>>  $question
     */
    private function titleFrom(array $question): string
    {
        $text = RichTextBuilder::plainText(array_slice($question, 0, 1));
        $sentence = preg_split('/(?<=[.؟?!])\s/u', $text)[0];

        return mb_strlen($sentence) > 120 ? rtrim(mb_substr($sentence, 0, 117)).'…' : $sentence;
    }

    /**
     * The book's 20 sections («كتاب الصلاة»…) as categories, keyed by their first page id.
     *
     * @return array<int, int>
     */
    private function sections(Source $source): array
    {
        $html = $this->readGz($this->rawDir('pages/book/'.self::BOOK.'.html.gz')) ?? '';
        preg_match_all('#<a[^>]+href="[^"]*/book/'.self::BOOK.'/(\d+)"[^>]*>\s*(كتاب[^<]*)</a>#u', $html, $matches, PREG_SET_ORDER);

        $sections = [];
        foreach ($matches as [, $page, $name]) {
            $name = trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $sections[(int) $page] = SourceCategory::query()->updateOrCreate(
                ['source_id' => $source->id, 'external_id' => $page],
                ['name' => $name, 'path' => $name],
            )->id;
        }
        ksort($sections);

        return $sections;
    }
}
