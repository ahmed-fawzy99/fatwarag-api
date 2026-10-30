<?php

namespace Tests\Feature\Importers;

use App\Importers\IslamwebImporter;
use App\Models\Fatwa;
use App\Models\FatwaLink;
use App\Models\Source;
use App\Models\SourceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IslamwebImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $raw;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        $this->raw = sys_get_temp_dir().'/islamweb-import-'.uniqid();
        config(['fatwarag.raw_path' => $this->raw]);
        Source::query()->create(['key' => 'islamweb', 'kind' => 'fatwa', 'tier' => 1, 'name_ar' => 'إسلام ويب', 'name_en' => 'Islamweb', 'base_url' => 'https://www.islamweb.net']);

        File::ensureDirectoryExists($this->raw.'/islamweb/queue');
        File::put($this->raw.'/islamweb/queue/tree.json', json_encode([
            '0' => ['kids' => [['id' => 850, 'name' => 'الدعوة ووسائلها', 'tree_count' => 2211]], 'listed' => 65, 'path' => []],
            '850' => ['kids' => [['id' => 852, 'name' => 'وسائل الدعوة', 'tree_count' => 300]], 'listed' => 40, 'path' => ['الدعوة ووسائلها'], 'parent' => 0],
            '852' => ['kids' => [], 'listed' => 120, 'path' => ['الدعوة ووسائلها', 'وسائل الدعوة'], 'parent' => 850],
        ], JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->raw);
        parent::tearDown();
    }

    /**
     * A page shaped like islamweb's fatwa page, reduced to the parts the importer reads.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function page(int $id, array $overrides = []): void
    {
        $o = $overrides + [
            'title' => 'وسائل الخطاب الدعوي والتكنولوجيا الحديثة',
            'date' => 'الخميس 22 رمضان 1425 هـ - 4-11-2004 م',
            'question' => '<p>كيف نستفيد من التكنولوجيا في الدعوة؟</p>',
            'answer' => '<p><P><FONT color=red>الحمد لله، والصلاة والسلام على رسول الله، أما بعد: <BR></FONT><P><FONT color=black>فلا بد من الاستفادة، قال تعالى: <FONT color=blue>ادْعُ إِلَى سَبِيلِ رَبِّكَ بِالْحِكْمَةِ وَالْمَوْعِظَةِ الْحَسَنَةِ</FONT> {النحل: 125}. وقال صلى الله عليه وسلم: <span style="color:green">بلغوا عني ولو آية</span> رواه البخاري. وانظر الفتاوى ذات الأرقام التالية: <a href="/ar/fatwa/7113/توجيه-وسائل-2">7113</a>، 9584.</FONT></P><FONT color=black>والله أعلم.</FONT></p>',
            'crumbs' => [[850, 'الدعوة ووسائلها'], [852, 'وسائل الدعوة']],
            'answered' => true,
        ];

        $crumbs = '<li><a itemprop="item" href="/ar/fatwa/"><span itemprop="name"> الرئيسية </span></a></li>';
        foreach ($o['crumbs'] as [$category, $name]) {
            $crumbs .= '<li itemprop="itemListElement"><a itemprop="item" href="/ar/fatawa/'.$category.'/slug"><span itemprop="name">'.$name.'</span></a></li>';
        }

        $html = $o['answered']
            ? '<html><body><div itemprop="mainEntity"><div class="item-fatwa"><div class="top-item"><h2>'.$o['title'].'</h2></div>'
                .'<div class="footer-item"><span><samp><svg></svg><a>'.$id.'</a></samp><samp><svg></svg><a>17375</a></samp>'
                .($o['date'] ? '<samp><svg></svg><a>'.$o['date'].'</a></samp>' : '').'</span></div></div>'
                .'<div class="mainitem quest-fatwa"><h3>السؤال</h3><div itemprop="text">'.$o['question'].'</div></div>'
                .'<div class="mainitem quest-fatwa" itemprop="acceptedAnswer"><h3>الإجابــة</h3><div itemprop="text">'.$o['answer'].'</div></div>'
                .'<div class="clear"></div><div class="mainitem linkedtab"><a href="/ar/fatwa/99999/related">فتوى ذات صلة</a></div></div>'
                .'<ol itemscope itemtype="http://schema.org/BreadcrumbList">'.$crumbs.'</ol></body></html>'
            : '<html><head><title>إسلام ويب - سعادة تمتد</title></head><body><div class="home">إسلام ويب - سعادة تمتد</div></body></html>';

        File::ensureDirectoryExists(sprintf('%s/islamweb/pages/ar/%d', $this->raw, intdiv($id, 1000)));
        File::put(sprintf('%s/islamweb/pages/ar/%d/%d.html.gz', $this->raw, intdiv($id, 1000), $id), gzencode($html));
    }

    public function test_fatwa_fields_and_number(): void
    {
        $this->page(55404);

        $result = app(IslamwebImporter::class)->import();

        $this->assertSame(1, $result->created);
        $fatwa = Fatwa::query()->sole();
        $this->assertSame('islamweb-55404', $fatwa->public_id);
        $this->assertSame('55404', $fatwa->source_fatwa_id);
        $this->assertSame('https://www.islamweb.net/ar/fatwa/55404/', $fatwa->url);
        $this->assertSame('وسائل الخطاب الدعوي والتكنولوجيا الحديثة', $fatwa->title);
        $this->assertSame('كيف نستفيد من التكنولوجيا في الدعوة؟', $fatwa->question[0][0]['text']);
        $this->assertSame([22, 9, 1425], [$fatwa->hijri_day, $fatwa->hijri_month, $fatwa->hijri_year]);
        $this->assertSame('2004-11-04', $fatwa->date_gregorian->toDateString());
        $this->assertSame('islamweb', $fatwa->mufti->key);
        $this->assertFalse($fatwa->is_cross_reference);

        $types = collect($fatwa->answer)->flatten(1)->pluck('type')->unique()->values()->all();
        $this->assertEqualsCanonicalizing(['text', 'hadith', 'fatwaRef'], $types);
        $this->assertStringNotContainsString('فتوى ذات صلة', json_encode($fatwa->answer, JSON_UNESCAPED_UNICODE));
    }

    public function test_in_text_fatwa_numbers_become_fatwa_ref(): void
    {
        $this->page(55404);

        app(IslamwebImporter::class)->import();

        $refs = collect(Fatwa::query()->sole()->answer)->flatten(1)->where('type', 'fatwaRef')->pluck('fatwaId')->all();
        $this->assertSame(['islamweb-7113', 'islamweb-9584'], array_values($refs));
        $this->assertEqualsCanonicalizing(['7113', '9584'], FatwaLink::query()->pluck('target_source_fatwa_id')->all());
    }

    public function test_redirect_only_answer_is_skipped(): void
    {
        $this->page(55221, ['answer' => '<p>الحمد لله والصلاة والسلام على رسول الله وعلى آله وصحبه، أما بعد: فقد سبق الجواب عن هذا السؤال في الفتوى رقم: <a href="/ar/fatwa/54948/x">54948</a>. والله أعلم.</p>']);

        $result = app(IslamwebImporter::class)->import();

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->skipped);
        $this->assertDatabaseCount('fatwas', 0);
    }

    public function test_missing_page_is_skipped(): void
    {
        $this->page(1637, ['answered' => false]);
        $this->page(55404);

        $result = app(IslamwebImporter::class)->import();

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->failed);
    }

    public function test_absent_date_is_null(): void
    {
        $this->page(55404, ['date' => null]);

        app(IslamwebImporter::class)->import();

        $fatwa = Fatwa::query()->sole();
        $this->assertNull($fatwa->hijri_year);
        $this->assertNull($fatwa->date_gregorian);
    }

    public function test_cross_referenced_fatwas_are_marked(): void
    {
        $this->page(7113, ['answer' => '<p>جواب طويل بما فيه الكفاية عن وسائل الدعوة الحديثة.</p>']);
        $this->page(55404);
        File::put($this->raw.'/islamweb/manifest.jsonl', implode("\n", [
            json_encode(['kind' => 'ar', 'id' => '55404', 'note' => 'ok', 'via' => 'select:852']),
            json_encode(['kind' => 'ar', 'id' => '7113', 'note' => 'ok', 'via' => 'xref']),
        ])."\n");

        app(IslamwebImporter::class)->import();

        $this->assertTrue(Fatwa::query()->where('source_fatwa_id', '7113')->value('is_cross_reference'));
        $this->assertFalse(Fatwa::query()->where('source_fatwa_id', '55404')->value('is_cross_reference'));
        $this->assertSame(Fatwa::query()->where('source_fatwa_id', '7113')->value('id'), FatwaLink::query()->where('target_source_fatwa_id', '7113')->value('target_fatwa_id'));
    }

    public function test_tree_and_breadcrumb_categories(): void
    {
        $this->page(55404);

        app(IslamwebImporter::class)->import();

        $leaf = SourceCategory::query()->where('external_id', '852')->sole();
        $this->assertSame('الدعوة ووسائلها / وسائل الدعوة', $leaf->path);
        $this->assertSame(120, $leaf->fatwa_count);
        $this->assertSame('850', $leaf->parent->external_id);
        $this->assertSame(['852'], Fatwa::query()->sole()->categories->pluck('external_id')->all());
    }

    public function test_reparsed_verse_releases_its_wrong_hadith_key(): void
    {
        $verse = 'وَاعْبُدْ رَبَّكَ حَتَّى يَأْتِيَكَ الْيَقِينُ';
        $this->page(30404, ['answer' => '<p>قال تعالى: <font color="008000">'.$verse.'</font> {الحجر: 99}.</p>']);
        app(IslamwebImporter::class)->import();
        $this->assertDatabaseHas('hadith_quotes', ['fatwa_count' => 1]);

        $this->page(30404, ['answer' => '<p>قال تعالى: <font color="008000"><font color="0000ff">'.$verse.'</font></font> {الحجر: 99}.</p>']);
        touch(sprintf('%s/islamweb/pages/ar/30/30404.html.gz', $this->raw), time() + 10);
        app(IslamwebImporter::class)->import();

        $this->assertDatabaseMissing('hadith_quotes', ['fatwa_count' => 1]);
        $this->assertNotContains('hadith', collect(Fatwa::query()->sole()->answer)->flatten(1)->pluck('type')->all());
    }

    public function test_reimport_is_idempotent(): void
    {
        $this->page(55404);

        app(IslamwebImporter::class)->import();
        $again = app(IslamwebImporter::class)->import();
        app(IslamwebImporter::class)->import(force: true);

        $this->assertSame(0, $again->written());
        $this->assertDatabaseCount('fatwas', 1);
        $this->assertDatabaseHas('hadith_quotes', ['fatwa_count' => 1]);
    }
}
