<?php

namespace App\Content;

use App\Support\Arabic;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Turns a source's HTML into rich paragraphs: arrays of segments shaped like `web/src/types/content.ts`.
 *
 * Quotes the source marks (by CSS selector) become `quran`, `hadith` or `fatwaRef` segments. Hadith segments carry an
 * internal `quoteKey` (see HadithQuoteRegistry); the API swaps it for `ref`. Brackets around a quote («», {}, ﴿﴾)
 * stay in the neighbouring text. Anything that can't be linked stays plain text, so no source text is ever lost.
 *
 * @phpstan-type Segment array<string, mixed>
 * @phpstan-type Paragraph list<Segment>
 */
class RichTextBuilder
{
    private const BLOCKS = ['p', 'div', 'li', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'section', 'article', 'table', 'tr', 'td', 'th', 'header', 'footer', 'pre', 'dl', 'dt', 'dd', 'hr'];

    private const SKIP = ['script', 'style', 'noscript', 'template', 'svg', 'button', 'form', 'iframe', 'img', 'audio', 'video'];

    private const OPEN_BRACKETS = '«{﴿("“[';

    private const CLOSE_BRACKETS = '»}﴾)"”]';

    /** Prophetic or companion context before a span, as in the Dorar harvester. */
    private const PROPHET = '/صلى الله عليه وسلم|صلى الله عليه و سلم|ﷺ|النبي|رسول الله|الرسول|حديث|عليه الصلاة والسلام|رضي الله عن/u';

    /** «الفتوى رقم: 54948», «الفتاوى ذات الأرقام التالية: 7113، 9584 ، 21186»: the keyword, then a list of numbers. */
    private const IN_TEXT_REF = '/(?:الفتو[ىي]|الفتاوى|الفتويين|فتوى|فتاوى)[^0-9.؟!\n]{0,40}?((?:\d{2,7})(?:\s*(?:[,،]|و|-)\s*\d{2,7})*)/u';

    /** A fatwa number that belongs to another body or site («فتوى اللجنة الدائمة رقم 1234», «… من فتاوى ابن باز»). */
    private const OTHER_SITE = 'اللجنة|ابن باز|بن باز|ابن عثيمين|العثيمين|الإسلام سؤال|سؤال وجواب|دار الإفتاء|دار الافتاء|الأزهر|مجمع|المجمع|موقع|نور على الدرب|مجموع|الشيخ';

    /** The same, said right after the number («رقم 1234 من فتاوى اللجنة»), or a volume/page («12/345»). */
    private const OTHER_SITE_AFTER = '/^\s*\/\s*\d|^\s*[)\]]?\s*(?:(?:من|في|عن)\s+)?(?:فتاوى|موقع|اللجنة|للجنة|ابن باز|بن باز|ابن عثيمين|العثيمين|سؤال وجواب|دار الإفتاء)/u';

    /** A citation right after a span, as in the Dorar harvester. */
    private const CITATION = '/رواه|روى|أخرجه|اخرجه|متفق عليه|صححه|حسنه|ضعفه/u';

    /** @var list<array<string, mixed>> */
    private array $paragraphs = [];

    /** @var list<array<string, mixed>> */
    private array $current = [];

    private bool $lastWasBreak = false;

    /** @var array<string, string> quoteKey => quote text */
    private array $quotes = [];

    /** @var array<string, string> */
    private array $markers = [];

    /** @var array{fatwaRefPrefix?: string, fatwaRefPattern?: string, inTextFatwaRefs?: bool, braceQuran?: bool, hadithMayBeQuran?: bool, hadithFilter?: string, remove?: list<string>, quoteStrip?: list<string>} */
    private array $options = [];

    public function __construct(private QuranQuoteMatcher $quran) {}

    /**
     * Options: `fatwaRefPrefix` is the source key for `fatwaId` («islamqa» gives «islamqa-278624»). `hadithFilter` is
     * «context» (default: a hadith span counts only with prophetic context before it or a citation after it; islamqa,
     * binbaz), «guillemets» (only when it opens with «»; al-Fawzan) or «none» (every marked span of 2+ words; islamweb).
     * `remove` lists selectors dropped entirely; `quoteStrip` lists selectors inside a hadith left out of its quote key.
     * `fatwaRefPattern` is the regex whose first group is the fatwa id in a link's href (default: its last number), and
     * `inTextFatwaRefs` also links fatwa numbers cited in plain text («الفتوى رقم: 54948»); `braceQuran` links Quran quotes
     * marked only by braces; `hadithMayBeQuran` types a hadith-marked span as Quran when it reproduces an ayah.
     *
     * @param  array<string, string>  $markers  CSS selector => quran|hadith|fatwaRef
     * @param  array{fatwaRefPrefix?: string, fatwaRefPattern?: string, inTextFatwaRefs?: bool, braceQuran?: bool, hadithMayBeQuran?: bool, hadithFilter?: 'context'|'guillemets'|'none', remove?: list<string>, quoteStrip?: list<string>}  $options
     * @return list<list<array<string, mixed>>>
     */
    public function fromHtml(string $html, array $markers, array $options = []): array
    {
        $this->paragraphs = [];
        $this->current = [];
        $this->lastWasBreak = false;
        $this->quotes = [];
        $this->markers = $markers;
        $this->options = $options;

        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', LIBXML_NOERROR | LIBXML_COMPACT, 'UTF-8');

        foreach ($document->body->childNodes as $child) {
            $this->walk($child);
        }
        $this->flush();

        if ($options['braceQuran'] ?? false) {
            $this->paragraphs = array_map($this->linkBraceQuotes(...), $this->paragraphs);
        }

        if ($options['inTextFatwaRefs'] ?? false) {
            $this->paragraphs = array_map($this->linkFatwaNumbers(...), $this->paragraphs);
        }

        return $this->paragraphs = HadithGradingExtractor::apply($this->paragraphs);
    }

    /**
     * Links Quran quotes a source only puts in braces («قال تعالى: {وجعلوا الملائكة…}»), when they match an ayah.
     *
     * @param  list<array<string, mixed>>  $paragraph
     * @return list<array<string, mixed>>
     */
    private function linkBraceQuotes(array $paragraph): array
    {
        $linked = [];

        foreach ($paragraph as $segment) {
            if ($segment['type'] !== 'text' || ! preg_match_all('/\{([^{}]{6,2000})\}/u', $segment['text'], $quotes, PREG_OFFSET_CAPTURE)) {
                $linked[] = $segment;

                continue;
            }

            $text = $segment['text'];
            $cursor = 0;
            foreach ($quotes[1] as [$quote, $offset]) {
                $inner = trim($quote);
                $match = $this->quran->match($inner, mb_substr(substr($text, $offset + strlen($quote) + 1), 0, 60));

                if ($match === null || $inner === '') {
                    continue;
                }

                $linked[] = ['type' => 'text', 'text' => substr($text, $cursor, $offset - $cursor)];
                $linked[] = ['type' => 'quran', 'text' => $inner, ...$match];
                $cursor = $offset + strlen($quote);
            }

            $linked[] = ['type' => 'text', 'text' => substr($text, $cursor)];
        }

        return array_values(array_filter($linked, fn (array $s): bool => $s['type'] !== 'text' || $s['text'] !== ''));
    }

    /**
     * Turns fatwa numbers cited in plain text («انظر الفتوى رقم: 54948، 12345») into fatwaRef segments.
     *
     * @param  list<array<string, mixed>>  $paragraph
     * @return list<array<string, mixed>>
     */
    private function linkFatwaNumbers(array $paragraph): array
    {
        $prefix = $this->options['fatwaRefPrefix'] ?? null;
        $linked = [];

        foreach ($paragraph as $segment) {
            $lists = [];
            if ($segment['type'] === 'text') {
                // A list that continues after a linked number: «…<a>9584</a> ، 21186».
                $previous = $linked === [] ? null : $linked[array_key_last($linked)]['type'];
                if ($previous === 'fatwaRef' && preg_match('/^(?:\s*(?:[,،]|و|-)\s*\d{2,7})+/u', $segment['text'], $m)) {
                    $lists[] = [$m[0], 0];
                }
                if (preg_match_all(self::IN_TEXT_REF, $segment['text'], $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                    foreach ($found as $match) {
                        $after = mb_strcut($segment['text'], $match[1][1] + strlen($match[1][0]), 120);
                        if (! preg_match('/'.self::OTHER_SITE.'/u', $match[0][0]) && ! preg_match(self::OTHER_SITE_AFTER, $after)) {
                            $lists[] = $match[1];
                        }
                    }
                }
            }

            if ($lists === []) {
                $linked[] = $segment;

                continue;
            }

            $text = $segment['text'];
            $cursor = 0;
            foreach ($lists as [$list, $listOffset]) {
                preg_match_all('/\d{2,7}/', $list, $numbers, PREG_OFFSET_CAPTURE);
                foreach ($numbers[0] as [$number, $offset]) {
                    $at = $listOffset + $offset;
                    if ($at < $cursor || (int) $number === 0) {
                        continue;
                    }
                    if ($at > $cursor) {
                        $linked[] = ['type' => 'text', 'text' => substr($text, $cursor, $at - $cursor)];
                    }
                    $linked[] = ['type' => 'fatwaRef', 'text' => $number, 'fatwaId' => $prefix ? "{$prefix}-{$number}" : $number];
                    $cursor = $at + strlen($number);
                }
            }

            if ($cursor < strlen($text)) {
                $linked[] = ['type' => 'text', 'text' => substr($text, $cursor)];
            }
        }

        return $linked;
    }

    /**
     * Hadith quotes found by the last fromHtml() call, keyed by quote key.
     *
     * @return array<string, string>
     */
    public function collectedQuotes(): array
    {
        return $this->quotes;
    }

    /**
     * Removes a leading «السؤال:», «الجواب:», «س:», «ج:», «Question:» or «Answer:» from the first paragraph.
     *
     * @param  list<list<array<string, mixed>>>  $paragraphs
     * @return list<list<array<string, mixed>>>
     */
    public static function stripLabel(array $paragraphs): array
    {
        if (! isset($paragraphs[0][0]) || $paragraphs[0][0]['type'] !== 'text') {
            return $paragraphs;
        }

        $stripped = preg_replace('/^\s*(?:السؤال|سؤال|الجواب|جواب|س|ج|Question|Answer|Q|A)\s*[:：]\s*/u', '', $paragraphs[0][0]['text'], 1);

        if ($stripped === '') {
            array_shift($paragraphs[0]);
        } else {
            $paragraphs[0][0]['text'] = $stripped;
        }

        if ($paragraphs[0] === []) {
            array_shift($paragraphs);
        }

        return array_values($paragraphs);
    }

    /**
     * @param  list<list<array<string, mixed>>>|null  $paragraphs
     */
    public static function plainText(?array $paragraphs): string
    {
        return implode("\n\n", array_map(
            fn (array $paragraph): string => implode('', array_column($paragraph, 'text')),
            $paragraphs ?? [],
        ));
    }

    /**
     * Wraps plain text (paragraphs separated by blank lines) as text-only rich paragraphs.
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function fromPlainText(string $text): array
    {
        $paragraphs = [];

        foreach (preg_split('/\n\s*\n/u', $text) as $paragraph) {
            $paragraph = self::clean($paragraph);
            if ($paragraph !== '') {
                $paragraphs[] = [['type' => 'text', 'text' => $paragraph]];
            }
        }

        return $paragraphs;
    }

    private function walk(Node $node): void
    {
        if ($node instanceof Text) {
            $this->appendText($node->data);

            return;
        }

        if (! $node instanceof Element) {
            return;
        }

        $tag = $node->localName;

        if (in_array($tag, self::SKIP, true) || $this->matchesAny($node, $this->options['remove'] ?? [])) {
            return;
        }

        if ($tag === 'br') {
            if ($this->lastWasBreak) {
                $this->flush();
            } else {
                $this->appendText(' ');
                $this->lastWasBreak = true;
            }

            return;
        }

        foreach ($this->markers as $selector => $type) {
            if ($node->matches($selector) && ! ($type === 'hadith' && $this->containsQuran($node))) {
                $this->appendMarked($node, $type);

                return;
            }
        }

        $isBlock = in_array($tag, self::BLOCKS, true);
        if ($isBlock) {
            $this->flush();
        }

        foreach ($node->childNodes as $child) {
            $this->walk($child);
        }

        if ($isBlock) {
            $this->flush();
        }
    }

    /**
     * Whether a hadith-marked element wraps a Quran-marked one (islamweb nests blue inside green): its children are
     * read instead, so the verse is not taken for a hadith.
     */
    private function containsQuran(Element $element): bool
    {
        foreach ($this->markers as $selector => $type) {
            if ($type === 'quran' && $element->querySelector($selector) !== null) {
                return true;
            }
        }

        return false;
    }

    private function appendMarked(Element $element, string $type): void
    {
        $text = self::clean($this->textOf($element, $this->options['remove'] ?? []));
        [$open, $inner, $close] = $this->splitBrackets($text);

        if ($inner === '') {
            $this->appendText($text);

            return;
        }

        if ($type === 'hadith' && ($this->options['hadithMayBeQuran'] ?? false) && ($ayah = $this->quran->exactAyah($inner)) !== null) {
            $type = 'quran';
            $reference = $this->quran->fromReference($this->followingText($element, 60));
            $ayah = $reference !== null && $reference['surah'] === $ayah['surah'] ? $reference : $ayah;
        }

        $segment = match ($type) {
            'quran' => isset($ayah) ? ['type' => 'quran', 'text' => $inner, ...$ayah] : $this->quranSegment($element, $inner),
            'hadith' => $this->hadithSegment($element, $text, $inner),
            'fatwaRef' => $this->fatwaRefSegment($element, $text),
            default => null,
        };

        if ($segment === null) {
            $this->appendText($text);

            return;
        }

        if ($type === 'fatwaRef') {
            $this->current[] = $segment;
        } else {
            $this->appendText($open);
            $this->current[] = $segment;
            $this->appendText($close);
        }

        $this->lastWasBreak = false;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function quranSegment(Element $element, string $inner): ?array
    {
        $match = $this->quran->match($inner, $this->followingText($element, 60));

        return $match === null ? null : ['type' => 'quran', 'text' => $inner, ...$match];
    }

    /**
     * @return ?array<string, mixed>
     */
    private function hadithSegment(Element $element, string $text, string $inner): ?array
    {
        if (! $this->isHadith($element, $text)) {
            return null;
        }

        $strip = [...($this->options['remove'] ?? []), ...($this->options['quoteStrip'] ?? [])];
        [, $quote] = $this->splitBrackets(self::clean($this->textOf($element, $strip)));

        if (Arabic::normalize($quote) === '') {
            return null;
        }

        $key = Arabic::quoteKey($quote);
        $this->quotes[$key] = $quote;

        return ['type' => 'hadith', 'text' => $inner, 'quoteKey' => $key, 'gradingInFatwa' => null, 'gradingScope' => null, 'gradingContext' => null];
    }

    /**
     * Mirrors the Dorar harvester's filter so both agree on which spans are hadith.
     */
    private function isHadith(Element $element, string $text): bool
    {
        $words = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));

        return match ($this->options['hadithFilter'] ?? 'context') {
            'none' => $words >= 2,
            'guillemets' => str_starts_with($text, '«') && $words >= 2,
            default => $this->hasHadithContext($element, $text, $words),
        };
    }

    private function hasHadithContext(Element $element, string $text, int $words): bool
    {
        if ($words < 2) {
            return false;
        }

        $after = $this->followingText($element, 400);

        if ($words <= 6 && preg_match('/^\s*\(\s*\d/u', $after)) {
            return false;
        }

        if ($words >= 4 && preg_match(self::PROPHET, self::light($text))) {
            return true;
        }

        $before = mb_substr(self::light($this->precedingText()), -120);

        return preg_match(self::PROPHET, $before) || preg_match(self::CITATION, mb_substr(self::light($after), 0, 80));
    }

    /**
     * @return ?array<string, mixed>
     */
    private function fatwaRefSegment(Element $element, string $text): ?array
    {
        $href = (string) $element->getAttribute('href');

        if (! preg_match($this->options['fatwaRefPattern'] ?? '/(\d+)\D*$/', $href, $m) && ! preg_match('/(\d+)/', strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']), $m)) {
            return null;
        }

        if ((int) $m[1] === 0) {
            return null;
        }

        $prefix = $this->options['fatwaRefPrefix'] ?? null;

        return ['type' => 'fatwaRef', 'text' => $text, 'fatwaId' => $prefix ? "{$prefix}-{$m[1]}" : $m[1]];
    }

    private function appendText(string $text): void
    {
        if ($text === '') {
            return;
        }

        if (trim($text) !== '') {
            $this->lastWasBreak = false;
        }

        $last = array_key_last($this->current);
        if ($last !== null && $this->current[$last]['type'] === 'text') {
            $this->current[$last]['text'] .= $text;
        } else {
            $this->current[] = ['type' => 'text', 'text' => $text];
        }
    }

    private function flush(): void
    {
        $segments = [];

        foreach ($this->current as $segment) {
            if ($segment['type'] === 'text') {
                $segment['text'] = preg_replace('/\s+/u', ' ', self::stripInvisible($segment['text']));
            }
            $segments[] = $segment;
        }

        $this->current = [];
        $this->lastWasBreak = false;

        if ($segments === []) {
            return;
        }

        $segments[0]['text'] = ltrim($segments[0]['text']);
        $last = array_key_last($segments);
        $segments[$last]['text'] = rtrim($segments[$last]['text']);
        $segments = array_values(array_filter($segments, fn (array $s): bool => $s['type'] !== 'text' || $s['text'] !== ''));

        if ($segments !== [] && trim(self::plainText([$segments])) !== '') {
            $this->paragraphs[] = $segments;
        }
    }

    /**
     * Text already emitted (previous paragraphs and the current one), newest last.
     */
    private function precedingText(): string
    {
        $tail = array_slice($this->paragraphs, -2);
        $tail[] = $this->current;

        return implode(' ', array_map(fn (array $p): string => implode('', array_column($p, 'text')), $tail));
    }

    /**
     * Text that follows the element in document order, up to roughly $limit characters.
     */
    private function followingText(Element $element, int $limit): string
    {
        $text = '';
        $node = $element;

        while ($node !== null && ! ($node instanceof Element && $node->localName === 'body')) {
            for ($sibling = $node->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
                $text .= $sibling->textContent;
                if (mb_strlen($text) >= $limit) {
                    return self::clean(mb_substr($text, 0, $limit));
                }
            }
            $node = $node->parentNode;
        }

        return self::clean($text);
    }

    /**
     * @param  list<string>  $without  selectors whose text is left out
     */
    private function textOf(Element $element, array $without): string
    {
        if ($without === []) {
            return $element->textContent;
        }

        $text = '';
        foreach ($element->childNodes as $child) {
            if ($child instanceof Text) {
                $text .= $child->data;
            } elseif ($child instanceof Element && ! $this->matchesAny($child, $without)) {
                $text .= $this->textOf($child, $without);
            } elseif ($child instanceof Element) {
                $text .= ' ';
            }
        }

        return $text;
    }

    /**
     * @param  list<string>  $selectors
     */
    private function matchesAny(Element $element, array $selectors): bool
    {
        foreach ($selectors as $selector) {
            if ($element->matches($selector)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{string, string, string} [opening brackets, inner text, closing brackets]
     */
    private function splitBrackets(string $text): array
    {
        $open = '';
        $close = '';
        $chars = mb_str_split($text);

        while ($chars !== [] && (mb_strpos(self::OPEN_BRACKETS, $chars[0]) !== false || trim($chars[0]) === '')) {
            $open .= array_shift($chars);
        }
        while ($chars !== [] && (mb_strpos(self::CLOSE_BRACKETS, end($chars)) !== false || trim(end($chars)) === '')) {
            $close = array_pop($chars).$close;
        }

        return [$open, implode('', $chars), $close];
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', self::stripInvisible($text)));
    }

    private static function stripInvisible(string $text): string
    {
        return preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', str_replace("\u{00A0}", ' ', $text));
    }

    /**
     * Without harakat or tatweel, letters kept as written (the harvester's `light`).
     */
    private static function light(string $text): string
    {
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text);

        return trim(preg_replace('/\s+/u', ' ', self::stripInvisible($text)));
    }
}
