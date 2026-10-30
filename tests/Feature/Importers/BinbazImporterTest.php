<?php

namespace Tests\Feature\Importers;

use App\Importers\BinbazImporter;
use App\Models\Fatwa;
use App\Models\Source;
use App\Models\SourceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BinbazImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $raw;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        $this->raw = sys_get_temp_dir().'/binbaz-import-'.uniqid();
        config(['fatwarag.raw_path' => $this->raw]);
        Source::query()->create(['key' => 'binbaz', 'kind' => 'fatwa', 'tier' => 2, 'name_ar' => 'موقع ابن باز', 'name_en' => 'Ibn Baz', 'base_url' => 'https://binbaz.org.sa']);

        File::ensureDirectoryExists($this->raw.'/binbaz/pages/category');
        File::put($this->raw.'/binbaz/pages/category/trees.html.gz', gzencode(
            '<div id="fiqhi"><div class="tree"><ul><li><a href="https://binbaz.org.sa/categories/fiqhi/8"> العبادات </a><ul>'
            .'<li><a href="https://binbaz.org.sa/categories/fiqhi/71"> الزكاة </a><ul><li><a href="https://binbaz.org.sa/categories/fiqhi/72"> زكاة النقدين </a></li></ul></li>'
            .'</ul></li></ul></div></div><div id="objective"><div class="tree"><ul><li><a href="https://binbaz.org.sa/categories/objective/199"> التفسير </a></li></ul></div></div>'
        ));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->raw);
        parent::tearDown();
    }

    /**
     * A page shaped like binbaz.org.sa's fatwa page, reduced to the parts the importer reads.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function page(int $id, array $overrides = []): void
    {
        $o = $overrides + [
            'kind' => 1,
            'kindName' => 'مجموع الفتاوى',
            'title' => 'زكاة الحلي',
            'question' => '<p><strong>السؤال:</strong></p><p>هل في الحلي زكاة؟</p>',
            'audio' => null,
            'answer' => '<p><strong>الجواب: </strong></p><p>نعم، قال ﷺ: <span class="hadith">ما بلغ أن تؤدى زكاته فزكي فليس بكنز</span><sup data-footnote-id="a1"><a href="#footnote-1">[1]</a></sup> والحمد لله <span class="arabisque"></span>.</p>',
            'footnotes' => ['نشرت في جريدة المسلمون بتاريخ 14/11/1415 هـ. (مجموع فتاوى ومقالات الشيخ ابن باز 212/14).'],
            'categories' => ['fiqhi/72' => 'زكاة النقدين'],
        ];

        $audio = $o['audio'] ? '<div class="row audio"><div class="jp-jplayer" data-files="[{&quot;link&quot;:&quot;'.str_replace('/', '\/', $o['audio']).'&quot;}]"></div><noscript><audio src="'.$o['audio'].'"></audio></noscript></div>' : '';
        $footnotes = $o['footnotes'] ? '<section class="footnotes"><ol>'.implode('', array_map(fn (string $f): string => "<li><cite>{$f}</cite></li>", $o['footnotes'])).'</ol></section>' : '';
        $categories = implode('', array_map(fn (string $href, string $name): string => '<a href="https://binbaz.org.sa/categories/'.$href."\"\n   class=\"categories__item\">".$name.'</a>', array_keys($o['categories']), $o['categories']));

        $html = '<html><head><link rel="canonical" href="https://binbaz.org.sa/fatwas/'.$id.'/slug"/></head><body>'
            .'<nav><a href="https://binbaz.org.sa/fatwas/kind/1">مجموع الفتاوى</a><a href="https://binbaz.org.sa/fatwas/kind/2">نور على الدرب</a></nav>'
            .'<div itemprop="breadcrumb"><ol class="breadcrumb"><li><a href="/"><i class="fa fa-home"></i></a></li><li><a href="https://binbaz.org.sa/fatwas/kind/'.$o['kind'].'">'.$o['kindName'].'</a></li><li class="active">'.$o['title'].'</li></ol></div>'
            .'<article class="fatwa"><h1 class="article-title article-title--primary"> '.$o['title'].' </h1>'
            .'<h2 class="article-title article-title__question" itemprop="alternativeHeadline"><i class="fa fa-question-circle"></i>'.$o['question'].'</h2>'
            .$audio
            .'<div itemprop="articleBody" class="article-content">'.$o['answer'].$footnotes.'</div></article>'
            .'<div class="categories">'.$categories.'</div>'
            .'<aside><article class="fatwa"><h1>فتوى ذات صلة</h1></article><div class="categories"><a href="https://binbaz.org.sa/categories/objective/199" class="categories__item">التفسير</a></div></aside></body></html>';

        File::ensureDirectoryExists(sprintf('%s/binbaz/pages/ar/%02d', $this->raw, $id % 100));
        File::put(sprintf('%s/binbaz/pages/ar/%02d/%d.html.gz', $this->raw, $id % 100, $id), gzencode($html));
    }

    public function test_kind1_origin_volume_page_from_footnote(): void
    {
        $this->page(1047);

        $result = app(BinbazImporter::class)->import();

        $this->assertSame(1, $result->created);
        $fatwa = Fatwa::query()->sole();
        $this->assertSame('binbaz-1047', $fatwa->public_id);
        $this->assertSame('https://binbaz.org.sa/fatwas/1047/slug', $fatwa->url);
        $this->assertSame('مجموع فتاوى ومقالات ابن باز 14/212', $fatwa->origin);
        $this->assertSame('binbaz', $fatwa->mufti->key);
        $this->assertSame('زكاة الحلي', $fatwa->title);
    }

    public function test_kind2_origin_from_audio_path(): void
    {
        $this->page(7900, ['kind' => 2, 'kindName' => 'نور على الدرب', 'footnotes' => [], 'audio' => 'https://files.zadapps.info/binbaz.org.sa/fatawa/nour_3la_aldarb/nour_446/44606.mp3']);

        app(BinbazImporter::class)->import();

        $this->assertSame('نور على الدرب، الحلقة 446', Fatwa::query()->sole()->origin);
    }

    public function test_other_kinds_cite_footnote_or_kind_name(): void
    {
        $this->page(31768, ['kind' => 3, 'kindName' => 'فتاوى الدروس', 'footnotes' => ['<a href="https://binbaz.org.sa/audios/2990/x">فتاوى اللقاء المفتوح</a>']]);
        $this->page(1000, ['kind' => 4, 'kindName' => 'فتاوى الجامع الكبير', 'footnotes' => []]);

        app(BinbazImporter::class)->import();

        $this->assertSame('فتاوى اللقاء المفتوح', Fatwa::query()->where('source_fatwa_id', '31768')->value('origin'));
        $this->assertSame('فتاوى الجامع الكبير', Fatwa::query()->where('source_fatwa_id', '1000')->value('origin'));
    }

    public function test_takhrij_footnote_is_not_an_origin(): void
    {
        $this->page(1000, ['kind' => 4, 'kindName' => 'فتاوى الجامع الكبير', 'footnotes' => ['رواه البخاري (1395) ومسلم (19).']]);

        app(BinbazImporter::class)->import();

        $this->assertSame('فتاوى الجامع الكبير', Fatwa::query()->sole()->origin);
    }

    public function test_footnotes_and_arabisque_glyphs_removed_from_answer(): void
    {
        $this->page(1047);

        app(BinbazImporter::class)->import();

        $answer = json_encode(Fatwa::query()->sole()->answer, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('مجموع فتاوى', $answer);
        $this->assertStringNotContainsString('[1]', $answer);
        $this->assertStringNotContainsString('arabisque', $answer);
        $this->assertStringContainsString('والحمد لله', $answer);
        $this->assertStringContainsString('"hadith"', $answer);
    }

    public function test_labels_stripped(): void
    {
        $this->page(1047, ['question' => '<div>س:&nbsp;هل في الحلي زكاة؟</div>', 'answer' => '<div><strong>ج:</strong>&nbsp;نعم تجب.</div>']);
        $this->page(1048);

        app(BinbazImporter::class)->import();

        $short = Fatwa::query()->where('source_fatwa_id', '1047')->sole();
        $this->assertSame('هل في الحلي زكاة؟', $short->question[0][0]['text']);
        $this->assertSame('نعم تجب.', $short->answer[0][0]['text']);
        $long = Fatwa::query()->where('source_fatwa_id', '1048')->sole();
        $this->assertSame('هل في الحلي زكاة؟', $long->question[0][0]['text']);
        $this->assertStringStartsWith('نعم', $long->answer[0][0]['text']);
    }

    public function test_categories_come_from_the_trees(): void
    {
        $this->page(1047);

        app(BinbazImporter::class)->import();

        $leaf = SourceCategory::query()->where('external_id', 'fiqhi/72')->sole();
        $this->assertSame('العبادات / الزكاة / زكاة النقدين', $leaf->path);
        $this->assertSame('fiqhi/71', $leaf->parent->external_id);
        $this->assertDatabaseHas('source_categories', ['external_id' => 'objective/199', 'name' => 'التفسير']);
        $this->assertSame(['fiqhi/72'], Fatwa::query()->sole()->categories->pluck('external_id')->all());
    }

    public function test_hijri_date_comes_from_the_footnote_when_present(): void
    {
        $this->page(1047);

        app(BinbazImporter::class)->import();

        $fatwa = Fatwa::query()->sole();
        $this->assertSame([14, 11, 1415], [$fatwa->hijri_day, $fatwa->hijri_month, $fatwa->hijri_year]);
    }

    public function test_reimport_is_idempotent(): void
    {
        $this->page(1047);

        app(BinbazImporter::class)->import();
        $again = app(BinbazImporter::class)->import();
        app(BinbazImporter::class)->import(force: true);

        $this->assertSame(0, $again->written());
        $this->assertDatabaseCount('fatwas', 1);
        $this->assertDatabaseHas('hadith_quotes', ['fatwa_count' => 1]);
    }

    public function test_missing_page_is_skipped(): void
    {
        File::ensureDirectoryExists($this->raw.'/binbaz/pages/ar/05');
        File::put($this->raw.'/binbaz/pages/ar/05/5.html.gz', gzencode('<html><body><h1>404</h1></body></html>'));
        File::put($this->raw.'/binbaz/pages/ar/05/105.html.gz', 'broken');
        $this->page(1047);

        $result = app(BinbazImporter::class)->import();

        $this->assertSame(1, $result->created);
        $this->assertSame(2, $result->skipped);
        $this->assertSame(0, $result->failed);
    }

    public function test_absent_date_is_null(): void
    {
        $this->page(1047, ['footnotes' => []]);

        app(BinbazImporter::class)->import();

        $fatwa = Fatwa::query()->sole();
        $this->assertNull($fatwa->hijri_year);
        $this->assertNull($fatwa->date_gregorian);
        $this->assertSame('مجموع الفتاوى', $fatwa->origin);
    }
}
