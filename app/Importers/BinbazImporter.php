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
 * binbaz.org.sa fatwas (pages/ar/<id%100>/<id>.html.gz). The mufti is always Ibn Baz. Footnotes (takhrij and the
 * printed source) and the decorative «arabisque» glyphs are left out of the answer; the origin is read from the
 * «مجموع فتاوى ومقالات» footnote (kind 1), the «نور على الدرب» episode in the audio path (kind 2), another footnote,
 * or the kind's name. Categories come from the site's two category trees (pages/category/trees.html.gz).
 */
class BinbazImporter extends SourceImporter
{
    public const PARSER_VERSION = 7;

    private const MARKERS = ['span.aaya' => 'quran', 'span.hadith' => 'hadith'];

    private const OPTIONS = ['remove' => ['section.footnotes', 'sup', 'span.arabisque']];

    /** «مجموع فتاوى ومقالات الشيخ ابن باز (14/ 212)», sometimes written page/volume. */
    private const MAJMU = '/مجموع\s+فتاوى(?:\s+ومقالات)?[^0-9(]*\(?\s*(\d+)\s*\/\s*(\d+)/u';

    /** A footnote that names where the fatwa was published (others are takhrij or remarks). */
    private const PUBLICATION = '/نشر|صدر|مجلة|مجموع|كتاب|برنامج|محاضرة|درس|لقاء|فتاوى/u';

    /** The «مجموع فتاوى ومقالات ابن باز» runs to 30 volumes. */
    private const MAJMU_VOLUMES = 30;

    /** @var array<string, int> external id => source_categories id */
    private array $categoryIds = [];

    public function sourceKey(): string
    {
        return 'binbaz';
    }

    public function import(?int $limit = null, bool $force = false): ImportResult
    {
        $result = new ImportResult;
        $source = $this->source();
        $this->categoryIds = $this->importTrees($source);

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
        $start = $html === null ? false : strpos($html, '<article class="fatwa"');
        $end = $start === false ? false : strpos($html, '</article>', $start);

        if ($start === false || $end === false) {
            return null;
        }

        $article = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.substr($html, $start, $end - $start + 10).'</body></html>', LIBXML_NOERROR, 'UTF-8');
        $title = $this->text($article->querySelector('h1.article-title--primary'));
        $body = $article->querySelector('div[itemprop="articleBody"]');

        if ($title === '' || $body === null) {
            return null;
        }

        $question = RichTextBuilder::stripLabel($this->builder->fromHtml($article->querySelector('h2[itemprop="alternativeHeadline"]')?->innerHTML ?? '', self::MARKERS, self::OPTIONS));
        $quotes = $this->builder->collectedQuotes();
        $answer = RichTextBuilder::stripLabel($this->builder->fromHtml($body->innerHTML, self::MARKERS, self::OPTIONS));
        $quotes += $this->builder->collectedQuotes();

        if ($answer === []) {
            return null;
        }

        $footnotes = array_map(fn (Element $cite): string => $this->text($cite), iterator_to_array($body->querySelectorAll('section.footnotes cite')));
        $kind = preg_match('#<ol class="breadcrumb">.*?/fatwas/kind/(\d)">([^<]*)<#su', $html, $m) ? [(int) $m[1], trim($m[2])] : [null, null];

        return $this->writer->save($source, [
            'source_fatwa_id' => $id,
            'url' => preg_match('#<link rel="canonical" href="([^"]+)"#', $html, $c) ? html_entity_decode($c[1]) : "https://binbaz.org.sa/fatwas/{$id}/x",
            'mufti_id' => Muftis::id('binbaz'),
            'title' => $title,
            'question' => $question,
            'answer' => $answer,
            'origin' => $this->origin($footnotes, $kind, $html),
            'hijri' => $this->hijri($footnotes),
            'collected_at' => $mtime,
            'parser_version' => static::PARSER_VERSION,
        ], $quotes, $this->categories($source, substr($html, $end)));
    }

    /**
     * @param  list<string>  $footnotes
     * @param  array{?int, ?string}  $kind
     */
    private function origin(array $footnotes, array $kind, string $html): ?string
    {
        foreach (array_reverse($footnotes) as $footnote) {
            if (preg_match(self::MAJMU, $footnote, $m)) {
                [$volume, $page] = [(int) $m[1], (int) $m[2]];
                if ($volume > self::MAJMU_VOLUMES && $page <= self::MAJMU_VOLUMES) {
                    [$volume, $page] = [$page, $volume];
                }

                return "مجموع فتاوى ومقالات ابن باز {$volume}/{$page}";
            }
        }

        if (preg_match('#nour_3la_aldarb\\\\?/nour_(\d+)#', $html, $m)) {
            return "نور على الدرب، الحلقة {$m[1]}";
        }

        $last = end($footnotes);
        if ($last !== false && mb_strlen($last) <= 200 && preg_match(self::PUBLICATION, $last)) {
            return $last;
        }

        return $kind[1] ?: null;
    }

    /**
     * A Hijri date some footnotes give («بتاريخ 14/11/1415 هـ»).
     *
     * @param  list<string>  $footnotes
     * @return ?array{day: int, month: int, year: int}
     */
    private function hijri(array $footnotes): ?array
    {
        foreach ($footnotes as $footnote) {
            if (preg_match('/(\d{1,2})\s*\/\s*(\d{1,2})\s*\/\s*(1[34]\d\d)\s*هـ?/u', $footnote, $m)) {
                return HijriDate::parse("{$m[1]}/{$m[2]}/{$m[3]}");
            }
        }

        return null;
    }

    private function text(?Element $element): string
    {
        return $element === null ? '' : trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $element->textContent)));
    }

    /**
     * The fatwa's own category tags: the first «categories» block after the article (related fatwas further down
     * carry their own).
     *
     * @return list<int>
     */
    private function categories(Source $source, string $afterArticle): array
    {
        $block = preg_match('#<div class="categories">(.*?)</div>#su', $afterArticle, $m) ? $m[1] : '';
        preg_match_all('#<a\s+href="https://binbaz\.org\.sa/categories/((?:fiqhi|objective)/\d+)"\s+class="categories__item">([^<]*)</a>#u', $block, $links, PREG_SET_ORDER);
        $ids = [];

        foreach ($links as [, $externalId, $name]) {
            $name = trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $ids[] = $this->categoryIds[$externalId] ??= SourceCategory::query()->firstOrCreate(
                ['source_id' => $source->id, 'external_id' => $externalId],
                ['name' => $name, 'path' => $name],
            )->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Both category trees (fiqhi, objective) from the listing sidebar, saved with their parents and paths.
     *
     * @return array<string, int>
     */
    private function importTrees(Source $source): array
    {
        $html = $this->readGz($this->rawDir('pages/category/trees.html.gz'));
        $ids = [];

        if ($html === null) {
            return $ids;
        }

        foreach (['fiqhi', 'objective'] as $tree) {
            $start = strpos($html, "id=\"{$tree}\"");
            if ($start === false) {
                continue;
            }

            preg_match_all('#<ul>|</ul>|<a href="https://binbaz\.org\.sa/categories/'.$tree.'/(\d+)">\s*(.*?)\s*</a>#su', substr($html, $start), $tokens, PREG_SET_ORDER);
            $depth = 0;
            $stack = [];

            foreach ($tokens as $token) {
                if ($token[0] === '<ul>') {
                    $depth++;
                } elseif ($token[0] === '</ul>') {
                    if (--$depth === 0) {
                        break;
                    }
                } else {
                    $name = trim(html_entity_decode($token[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $stack = [...array_slice($stack, 0, max(0, $depth - 1)), ['id' => "{$tree}/{$token[1]}", 'name' => $name]];
                    $parent = $stack[count($stack) - 2] ?? null;

                    $ids["{$tree}/{$token[1]}"] = SourceCategory::query()->updateOrCreate(
                        ['source_id' => $source->id, 'external_id' => "{$tree}/{$token[1]}"],
                        ['name' => $name, 'path' => implode(' / ', array_column($stack, 'name')), 'parent_id' => $parent !== null ? $ids[$parent['id']] ?? null : null],
                    )->id;
                }
            }
        }

        return $ids;
    }
}
