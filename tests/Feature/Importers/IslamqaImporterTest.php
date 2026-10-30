<?php

namespace Tests\Feature\Importers;

use App\Importers\IslamqaImporter;
use App\Models\Fatwa;
use App\Models\FatwaLink;
use App\Models\FatwaTranslation;
use App\Models\Source;
use App\Models\SourceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IslamqaImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $raw;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        $this->raw = sys_get_temp_dir().'/islamqa-import-'.uniqid();
        config(['fatwarag.raw_path' => $this->raw]);
        Source::query()->create(['key' => 'islamqa', 'kind' => 'fatwa', 'tier' => 1, 'name_ar' => 'الإسلام سؤال وجواب', 'name_en' => 'IslamQA', 'base_url' => 'https://islamqa.info']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->raw);
        parent::tearDown();
    }

    /**
     * A page shaped like islamqa's server HTML, reduced to the parts the importer reads.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function page(string $lang, int $id, array $overrides = []): void
    {
        $o = $overrides + [
            'title' => 'ما حكم زكاة الفطر نقدا؟',
            'question' => '<p>هل يجوز إخراج زكاة الفطر نقدا؟</p>',
            'summary' => '<p>لا يجزئ إخراجها نقدا.</p>',
            'summaryLabel' => 'ملخص الجواب',
            'answer' => '<div id="toc_container"><p>محتويات</p><ul><li><a href="#a">أولا</a></li></ul></div><p>قال النبي صلى الله عليه وسلم: <span class="hadith">«من أداها قبل الصلاة فهي زكاة مقبولة»</span> رواه أبو داود.</p><p>وانظر: إجابة رقم (<a href="/ar/answers/145112" class="LinkFatwa">145112</a>).</p>',
            'date' => '24/رمضان/1431 الموافق 03/سبتمبر/2010',
            'published' => '2010-09-02T21:00:00.000Z',
            'source' => 'موقع الإسلام سؤال وجواب',
            'crumbs' => [[['topics/55', 'الفقه وأصوله'], ['topics/99', 'الزكاة'], ['topics/103', 'زكاة الفطر']]],
        ];

        $crumbs = '';
        foreach ($o['crumbs'] as $path) {
            $items = '<li><a href="/'.$lang.'"><meta itemProp="name" content="الرئيسية"/></a></li><li><a itemProp="item" href="/'.$lang.'/categories/topics"><span itemProp="name">الموضوعية</span></a></li>';
            foreach ($path as [$href, $name]) {
                $items .= '<li><a itemProp="item" href="/'.$lang.'/categories/'.$href.'"><span itemProp="name">'.$name.'</span></a></li>';
            }
            $crumbs .= '<ol itemScope="" data-gtm="breadcrumb" aria-label="breadcrumb">'.$items.'<li><a itemProp="item" href="#"><span itemProp="name">'.$o['title'].'</span></a></li></ol>';
        }

        $jsonLd = $o['published'] ? '<script type="application/ld+json">'.json_encode(['@type' => 'QAPage', 'mainEntity' => ['datePublished' => $o['published'], 'inLanguage' => $lang]]).'</script>' : '';
        $date = $o['date'] ? '<section><time data-sut="publication-date"><svg></svg>'.$o['date'].'</time></section>' : '';
        $summary = $o['summary'] ? '<section><h2 class="x">'.$o['summaryLabel'].'</h2><div class="post-body">'.$o['summary'].'</div><a href="#related_answers">ذات صلة</a></section>' : '';

        $html = '<html><head><link rel="canonical" href="https://islamqa.info/'.$lang.'/answers/'.$id.'"/>'.$jsonLd.'</head><body>'.$crumbs
            .'<article id="single-post-content"><div><header><h1 data-sut="question-title" class="x">'.$o['title'].'</h1></header><div>'.$date.'</div></div>'
            .'<section><h2 data-sut="question-number">السؤال<!-- --> <!-- -->'.$id.'</h2><div class="q">'.$o['question'].'</div></section>'
            .$summary
            .'<section><h2>الجواب<hr/></h2><p class="intro">الحمد لله.</p><div data-sut="answer-text">'.$o['answer'].'</div><dialog id="footnote-modal"><h2>المراجع</h2></dialog></section>'
            .'<section><a data-gtm="link-topic" href="/'.$lang.'/categories/topics/103"><div>زكاة الفطر</div></a></section>'
            .'<div><section><h2>المصدر: </h2><p data-sut="fatwa-source">'.$o['source'].'</p></section></div></article></body></html>';

        File::ensureDirectoryExists(sprintf('%s/islamqa/pages/%s/%02d', $this->raw, $lang, $id % 100));
        File::put(sprintf('%s/islamqa/pages/%s/%02d/%d.html.gz', $this->raw, $lang, $id % 100, $id), gzencode($html));
    }

    public function test_answer_fields_are_parsed(): void
    {
        $this->page('ar', 22888);

        $result = app(IslamqaImporter::class)->import();

        $this->assertSame(1, $result->created);
        $fatwa = Fatwa::query()->where('source_fatwa_id', '22888')->sole();
        $this->assertSame('islamqa-22888', $fatwa->public_id);
        $this->assertSame('https://islamqa.info/ar/answers/22888', $fatwa->url);
        $this->assertSame('ما حكم زكاة الفطر نقدا؟', $fatwa->title);
        $this->assertSame('هل يجوز إخراج زكاة الفطر نقدا؟', $fatwa->question[0][0]['text']);
        $this->assertSame('لا يجزئ إخراجها نقدا.', $fatwa->source_summary[0][0]['text']);
        $this->assertSame('الحمد لله.', $fatwa->answer[0][0]['text']);
        $this->assertContains('hadith', array_column($fatwa->answer[1], 'type'));
        $this->assertStringNotContainsString('محتويات', json_encode($fatwa->answer, JSON_UNESCAPED_UNICODE));
        $this->assertSame('2010-09-03', $fatwa->date_gregorian->toDateString());
        $this->assertSame([24, 9, 1431], [$fatwa->hijri_day, $fatwa->hijri_month, $fatwa->hijri_year]);
        $this->assertSame('islamqa', $fatwa->mufti->key);
        $this->assertNull($fatwa->origin);
        $this->assertFalse($fatwa->is_cross_reference);
    }

    public function test_english_page_becomes_source_translation(): void
    {
        $this->page('ar', 22888);
        $this->page('en', 22888, ['title' => 'What Is the Rate of Zakat al-Fitr?', 'question' => '<p>What is the rate?</p>', 'summaryLabel' => 'Summary of answer', 'summary' => '<p>One sa`.</p>', 'answer' => '<p>Answer: One sa` of food.</p>']);

        app(IslamqaImporter::class)->import();

        $translation = FatwaTranslation::query()->sole();
        $this->assertSame('source', $translation->kind);
        $this->assertSame('en', $translation->locale);
        $this->assertSame('What Is the Rate of Zakat al-Fitr?', $translation->title);
        $this->assertSame('What is the rate?', $translation->question[0][0]['text']);
        $this->assertSame('One sa`.', $translation->source_summary[0][0]['text']);
        $this->assertSame('https://islamqa.info/en/answers/22888', $translation->url);
    }

    public function test_english_page_arriving_later_is_picked_up(): void
    {
        $this->page('ar', 22888);
        app(IslamqaImporter::class)->import();
        $this->page('en', 22888, ['title' => 'What Is the Rate of Zakat al-Fitr?']);
        touch(sprintf('%s/islamqa/pages/en/88/22888.html.gz', $this->raw), time() + 10);

        $result = app(IslamqaImporter::class)->import();

        $this->assertSame(1, $result->updated);
        $this->assertDatabaseCount('fatwa_translations', 1);
    }

    public function test_breadcrumbs_create_categories(): void
    {
        $this->page('ar', 22888, ['crumbs' => [
            [['topics/55', 'الفقه وأصوله'], ['topics/99', 'الزكاة'], ['topics/103', 'زكاة الفطر']],
            [['topics/55', 'الفقه وأصوله'], ['topics/60', 'الصيام']],
        ]]);

        app(IslamqaImporter::class)->import();

        $leaf = SourceCategory::query()->where('external_id', 'topics/103')->sole();
        $this->assertSame('زكاة الفطر', $leaf->name);
        $this->assertSame('الفقه وأصوله / الزكاة / زكاة الفطر', $leaf->path);
        $this->assertSame('topics/99', $leaf->parent->external_id);
        $this->assertNull(SourceCategory::query()->where('external_id', 'topics/55')->value('parent_id'));
        $this->assertEqualsCanonicalizing(['topics/103', 'topics/60'], Fatwa::query()->sole()->categories->pluck('external_id')->all());
    }

    public function test_linkfatwa_creates_fatwa_ref_and_link_row(): void
    {
        $this->page('ar', 22888);

        app(IslamqaImporter::class)->import();

        $fatwa = Fatwa::query()->where('source_fatwa_id', '22888')->sole();
        $ref = collect($fatwa->answer)->flatten(1)->firstWhere('type', 'fatwaRef');
        $this->assertSame('islamqa-145112', $ref['fatwaId']);
        $link = FatwaLink::query()->sole();
        $this->assertSame('145112', $link->target_source_fatwa_id);
        $this->assertNull($link->target_fatwa_id);
        $this->assertStringContainsString('إجابة رقم', $link->anchor_text);
    }

    public function test_link_resolves_when_target_is_imported(): void
    {
        $this->page('ar', 22888);
        $this->page('ar', 145112, ['answer' => '<p>نعم.</p>']);

        app(IslamqaImporter::class)->import();

        $this->assertSame(Fatwa::query()->where('source_fatwa_id', '145112')->value('id'), FatwaLink::query()->sole()->target_fatwa_id);
    }

    public function test_ibn_baz_source_maps_to_his_mufti_card(): void
    {
        $this->page('ar', 22888, ['source' => 'سماحة الشيخ ابن باز رحمه الله . مجلة البحوث الإسلامية العدد 17']);
        $this->page('ar', 22889, ['source' => 'اللجنة الدائمة في فتاوى إسلامية 1-223']);

        app(IslamqaImporter::class)->import();

        $baz = Fatwa::query()->where('source_fatwa_id', '22888')->sole();
        $this->assertSame('binbaz', $baz->mufti->key);
        $this->assertSame('سماحة الشيخ ابن باز رحمه الله . مجلة البحوث الإسلامية العدد 17', $baz->origin);
        $this->assertSame('lajnah', Fatwa::query()->where('source_fatwa_id', '22889')->sole()->mufti->key);
    }

    public function test_reimport_is_idempotent(): void
    {
        $this->page('ar', 22888);

        app(IslamqaImporter::class)->import();
        $again = app(IslamqaImporter::class)->import();
        app(IslamqaImporter::class)->import(force: true);

        $this->assertSame(0, $again->written());
        $this->assertDatabaseCount('fatwas', 1);
        $this->assertDatabaseCount('fatwa_links', 1);
        $this->assertDatabaseHas('hadith_quotes', ['fatwa_count' => 1]);
    }

    public function test_missing_page_is_skipped(): void
    {
        File::ensureDirectoryExists($this->raw.'/islamqa/pages/ar/01');
        File::put($this->raw.'/islamqa/pages/ar/01/1.html.gz', gzencode('<html><body><main><h1>الصفحة غير موجودة</h1></main></body></html>'));
        File::put($this->raw.'/islamqa/pages/ar/01/101.html.gz', 'truncated');
        $this->page('ar', 22888);

        $result = app(IslamqaImporter::class)->import();

        $this->assertSame(1, $result->created);
        $this->assertSame(2, $result->skipped);
        $this->assertSame(0, $result->failed);
    }

    public function test_absent_date_is_null(): void
    {
        $this->page('ar', 22888, ['date' => null, 'published' => null]);

        app(IslamqaImporter::class)->import();

        $fatwa = Fatwa::query()->sole();
        $this->assertNull($fatwa->date_gregorian);
        $this->assertNull($fatwa->hijri_year);
        $this->assertNull($fatwa->hijri_day);
    }

    public function test_limit_stops_after_that_many_fatwas(): void
    {
        $this->page('ar', 22888);
        $this->page('ar', 22889);

        $result = app(IslamqaImporter::class)->import(limit: 1);

        $this->assertSame(1, $result->created);
        $this->assertDatabaseCount('fatwas', 1);
    }
}
