<?php

namespace Tests\Unit\Content;

use App\Content\QuranQuoteMatcher;
use App\Content\RichTextBuilder;
use App\Support\Arabic;
use Mockery;
use PHPUnit\Framework\TestCase;

class RichTextBuilderTest extends TestCase
{
    private const MARKERS = ['span.aaya' => 'quran', 'span.hadith' => 'hadith', 'a.LinkFatwa' => 'fatwaRef'];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function builder(?array $quranMatch = null): RichTextBuilder
    {
        $matcher = Mockery::mock(QuranQuoteMatcher::class);
        $matcher->shouldReceive('match')->andReturn($quranMatch);

        return new RichTextBuilder($matcher);
    }

    public function test_text_and_marked_quotes_become_segments(): void
    {
        $builder = $this->builder(['surah' => 1, 'ayah' => 2, 'ayahEnd' => null]);

        $paragraphs = $builder->fromHtml('<p>قال تعالى: <span class="aaya">{الحمد لله}</span> وقال ﷺ: <span class="hadith">«إنما الأعمال بالنيات»</span></p>', self::MARKERS);

        $this->assertCount(1, $paragraphs);
        $this->assertSame(['text', 'quran', 'text', 'hadith'], array_column($this->withoutBrackets($paragraphs[0]), 'type'));

        $quran = collect($paragraphs[0])->firstWhere('type', 'quran');
        $this->assertSame(['type' => 'quran', 'text' => 'الحمد لله', 'surah' => 1, 'ayah' => 2, 'ayahEnd' => null], $quran);

        $hadith = collect($paragraphs[0])->firstWhere('type', 'hadith');
        $this->assertSame('إنما الأعمال بالنيات', $hadith['text']);
        $this->assertSame(Arabic::quoteKey('إنما الأعمال بالنيات'), $hadith['quoteKey']);
        $this->assertArrayNotHasKey('ref', $hadith);
        $this->assertSame([$hadith['quoteKey'] => 'إنما الأعمال بالنيات'], $builder->collectedQuotes());
    }

    public function test_brackets_stay_outside_quote_segments(): void
    {
        $paragraphs = $this->builder(['surah' => 1, 'ayah' => 2, 'ayahEnd' => null])
            ->fromHtml('<p>قال تعالى: <span class="aaya">{الحمد لله}</span>.</p>', self::MARKERS);

        $this->assertSame('قال تعالى: {الحمد لله}.', RichTextBuilder::plainText($paragraphs));
    }

    public function test_unmatched_quran_quote_stays_text(): void
    {
        $paragraphs = $this->builder(null)->fromHtml('<p>قال: <span class="aaya">نص غير معروف</span></p>', self::MARKERS);

        $this->assertSame([[['type' => 'text', 'text' => 'قال: نص غير معروف']]], $paragraphs);
    }

    public function test_book_title_in_hadith_span_stays_text(): void
    {
        $builder = $this->builder();
        $paragraphs = $builder->fromHtml('<p>انظر: <span class="hadith">مجموع الفتاوى</span> (22/ 243).</p>', self::MARKERS);

        $this->assertSame(['text'], array_column($paragraphs[0], 'type'));
        $this->assertSame([], $builder->collectedQuotes());
    }

    public function test_hadith_followed_by_citation_word_is_accepted(): void
    {
        $paragraphs = $this->builder()->fromHtml('<p>وفيه: <span class="hadith">من صام رمضان إيمانا واحتسابا</span> رواه البخاري.</p>', self::MARKERS);

        $this->assertContains('hadith', array_column($paragraphs[0], 'type'));
    }

    public function test_fatwa_link_becomes_fatwa_ref(): void
    {
        $paragraphs = $this->builder()->fromHtml('<p>انظر: إجابة رقم (<a href="/ar/answers/278624" class="LinkFatwa">278624</a>).</p>', self::MARKERS, ['fatwaRefPrefix' => 'islamqa']);

        $ref = collect($paragraphs[0])->firstWhere('type', 'fatwaRef');
        $this->assertSame(['type' => 'fatwaRef', 'text' => '278624', 'fatwaId' => 'islamqa-278624'], $ref);
    }

    public function test_brace_quotes_become_quran_segments_when_they_match(): void
    {
        $paragraphs = $this->builder(['surah' => 43, 'ayah' => 19, 'ayahEnd' => null])
            ->fromHtml('<p>بقوله: {وجعلوا الملائكة الذين هم عباد الرحمن إناثا}، وهم مع هذا</p>', [], ['braceQuran' => true]);

        $this->assertSame(['text', 'quran', 'text'], array_column($paragraphs[0], 'type'));
        $this->assertSame('وجعلوا الملائكة الذين هم عباد الرحمن إناثا', $paragraphs[0][1]['text']);
        $this->assertSame('بقوله: {وجعلوا الملائكة الذين هم عباد الرحمن إناثا}، وهم مع هذا', RichTextBuilder::plainText($paragraphs));
    }

    public function test_in_text_fatwa_numbers_become_fatwa_refs(): void
    {
        $paragraphs = $this->builder()->fromHtml(
            '<p>انظر الفتاوى ذات الأرقام التالية: 7113، <a href="/ar/fatwa/9584/x-2">9584</a> ، 21186. وعددهم 30 رجلا.</p>',
            ['a[href*="/ar/fatwa/"]' => 'fatwaRef'],
            ['fatwaRefPrefix' => 'islamweb', 'fatwaRefPattern' => '#/fatwa/(\d+)#', 'inTextFatwaRefs' => true],
        );

        $refs = array_values(array_filter($paragraphs[0], fn (array $s): bool => $s['type'] === 'fatwaRef'));
        $this->assertSame(['islamweb-7113', 'islamweb-9584', 'islamweb-21186'], array_column($refs, 'fatwaId'));
        $this->assertSame('انظر الفتاوى ذات الأرقام التالية: 7113، 9584 ، 21186. وعددهم 30 رجلا.', RichTextBuilder::plainText($paragraphs));
    }

    public function test_verse_nested_inside_hadith_colour_stays_a_verse(): void
    {
        $builder = $this->builder(['surah' => 19, 'ayah' => 65, 'ayahEnd' => null]);
        $paragraphs = $builder->fromHtml(
            '<p>قال تعالى: <font color="008000"><font color="0000ff">وَاعْبُدْ رَبَّكَ حَتَّى يَأْتِيَكَ الْيَقِينُ</font></font> وقال: <font color="green">بلغوا عني ولو آية</font></p>',
            ['font[color="0000ff" i]' => 'quran', 'font[color="008000" i], font[color="green" i]' => 'hadith'],
            ['hadithFilter' => 'none'],
        );

        $this->assertSame(['text', 'quran', 'text', 'hadith'], array_column($paragraphs[0], 'type'));
        $this->assertCount(1, $builder->collectedQuotes());
    }

    public function test_zero_and_other_sites_fatwa_numbers_are_not_linked(): void
    {
        $paragraphs = $this->builder()->fromHtml(
            '<p>انظر الفتوى رقم: 12345، وفتوى اللجنة الدائمة رقم 6789، والفتوى رقم 4321 من فتاوى ابن باز، و<a href="/ar/fatwa/0/x">0</a>.</p>',
            ['a[href*="/ar/fatwa/"]' => 'fatwaRef'],
            ['fatwaRefPrefix' => 'islamweb', 'fatwaRefPattern' => '#/fatwa/(\d+)#', 'inTextFatwaRefs' => true],
        );

        $refs = array_values(array_filter($paragraphs[0], fn (array $s): bool => $s['type'] === 'fatwaRef'));
        $this->assertSame(['islamweb-12345'], array_column($refs, 'fatwaId'));
    }

    public function test_single_word_in_guillemets_is_not_a_hadith(): void
    {
        $paragraphs = $this->builder()->fromHtml('<p>قوله: <span class="c2">«الأوابين»</span> وقوله ﷺ: <span class="c2">«صلاة الأوابين»</span></p>', ['span.c2' => 'hadith'], ['hadithFilter' => 'guillemets']);

        $this->assertSame(['text', 'hadith', 'text'], array_column($paragraphs[0], 'type'));
    }

    public function test_block_elements_and_double_breaks_split_paragraphs(): void
    {
        $paragraphs = $this->builder()->fromHtml('<div>أولا<br>تتمة</div><p>  </p><p>ثانيا<br><br>ثالثا</p><ul><li>رابعا</li></ul>', self::MARKERS);

        $this->assertSame([
            [['type' => 'text', 'text' => 'أولا تتمة']],
            [['type' => 'text', 'text' => 'ثانيا']],
            [['type' => 'text', 'text' => 'ثالثا']],
            [['type' => 'text', 'text' => 'رابعا']],
        ], $paragraphs);
    }

    public function test_label_is_stripped_once(): void
    {
        $paragraphs = RichTextBuilder::stripLabel([[['type' => 'text', 'text' => 'السؤال: ما حكم…']], [['type' => 'text', 'text' => 'الجواب: كذا']]]);

        $this->assertSame('ما حكم…', $paragraphs[0][0]['text']);
        $this->assertSame('الجواب: كذا', $paragraphs[1][0]['text']);
    }

    public function test_label_only_paragraph_is_removed(): void
    {
        $paragraphs = RichTextBuilder::stripLabel([[['type' => 'text', 'text' => 'ج:']], [['type' => 'text', 'text' => 'نعم']]]);

        $this->assertSame([[['type' => 'text', 'text' => 'نعم']]], $paragraphs);
    }

    public function test_plain_text_joins_paragraphs(): void
    {
        $text = RichTextBuilder::plainText([
            [['type' => 'text', 'text' => 'أ '], ['type' => 'quran', 'text' => 'ب', 'surah' => 1, 'ayah' => 1, 'ayahEnd' => null]],
            [['type' => 'text', 'text' => 'ج']],
        ]);

        $this->assertSame("أ ب\n\nج", $text);
    }

    /**
     * Drops the bracket-only text segments that sit between a quote and its neighbours.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @return array<int, array<string, mixed>>
     */
    private function withoutBrackets(array $segments): array
    {
        return array_values(array_filter($segments, fn (array $s): bool => $s['type'] !== 'text' || trim($s['text'], ' {}«»') !== ''));
    }
}
