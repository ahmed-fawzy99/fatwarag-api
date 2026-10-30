<?php

namespace Tests\Feature\Importers;

use App\Importers\FawzanImporter;
use App\Models\Fatwa;
use App\Models\HadithQuote;
use App\Models\Source;
use App\Support\Arabic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FawzanImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $raw;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        $this->raw = sys_get_temp_dir().'/fawzan-import-'.uniqid();
        config(['fatwarag.raw_path' => $this->raw]);
        Source::query()->create(['key' => 'fawzan', 'kind' => 'fatwa', 'tier' => 3, 'name_ar' => 'مجموع فتاوى الفوزان', 'name_en' => 'Fawzan', 'base_url' => 'https://shamela.ws/book/8612']);

        $this->page(243, 1, 259, [
            '<span class="c4">[الجهر بالبسملة في الصلاة الجهرية]</span>',
            '<span class="c5">سؤال:</span> ما حكم ذكر البسملة في الصلاة جهرًا؟',
            '<span class="c5">الجواب:</span> لا بأس بذلك أحيانًا، لقوله صلى الله عليه وسلم: <span class="c2">«صلوا كما رأيتموني أصلي<span class="c5"> -يعني:</span> في صلاتكم»</span> .',
            'قراءة سورة الفاتحة في الصلاة',
            '<span class="c5">سؤال:</span> هل يجب على المأموم أن يقرأ الفاتحة؟',
        ]);
        $this->page(244, 1, 260, [
            '<span class="c5">الجواب:</span> اختلف العلماء في ذلك<span class="c5"> لقوله تعالى:</span> <span class="c3">{وَإِذَا قُرِئَ الْقُرْآنُ فَاسْتَمِعُوا لَهُ}</span> <span class="c4">[الأعراف: ٢٠٤]</span> ، كما',
        ]);
        $this->page(245, 1, 261, [
            'جاء ذلك في حديث جبريل.',
            '***',
        ]);
        File::put($this->raw.'/fawzan/pages/book/8612.html.gz', gzencode('<ul><li><a href="https://shamela.ws/book/8612/2">كتاب العقيدة</a></li><li><a href="https://shamela.ws/book/8612/220">كتاب الصلاة</a></li></ul>'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->raw);
        parent::tearDown();
    }

    /**
     * @param  list<string>  $paragraphs
     */
    private function page(int $id, int $part, int $pageNum, array $paragraphs): void
    {
        $nass = '';
        foreach ($paragraphs as $i => $html) {
            $n = $i + 1;
            $nass .= "<p><span class=\"anchor\" id=\"np{$id}_{$n}\"></span>{$html}<a href=\"#np{$id}_{$n}\" class=\"btn_tag btn btn-sm\"><span class=\"text-gray fa fa-copy\"></span></a></p>";
        }

        File::ensureDirectoryExists($this->raw.'/fawzan/pages/page');
        File::ensureDirectoryExists($this->raw.'/fawzan/pages/book');
        File::put("{$this->raw}/fawzan/pages/page/{$id}.json.gz", gzencode(json_encode([
            'nass' => $nass, 'part' => (string) $part, 'pageNum' => $pageNum, 'title' => 'عنوان', 'pageId' => $id, 'prevId' => $id - 1, 'nextId' => $id + 1,
        ], JSON_UNESCAPED_UNICODE)));
    }

    public function test_book_is_split_into_fatwas_by_markers(): void
    {
        $result = app(FawzanImporter::class)->import();

        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->failed);

        $first = Fatwa::query()->where('source_fatwa_id', '243-1')->sole();
        $this->assertSame('الجهر بالبسملة في الصلاة الجهرية', $first->title);
        $this->assertEquals([[['type' => 'text', 'text' => 'ما حكم ذكر البسملة في الصلاة جهرًا؟']]], $first->question);

        $second = Fatwa::query()->where('source_fatwa_id', '243-4')->sole();
        $this->assertSame('fawzan-243-4', $second->public_id);
        $this->assertSame('قراءة سورة الفاتحة في الصلاة', $second->title);
        $answer = Fatwa::query()->whereKey($second->id)->value('search_text');
        $this->assertStringContainsString(Arabic::normalize('كما جاء ذلك في حديث جبريل'), $answer);
        $this->assertCount(1, $second->answer);
        $this->assertStringStartsWith('اختلف العلماء', $second->answer[0][0]['text']);
    }

    public function test_quotes_become_linked_segments(): void
    {
        app(FawzanImporter::class)->import();

        $first = Fatwa::query()->where('source_fatwa_id', '243-1')->sole();
        $hadith = collect($first->answer[0])->firstWhere('type', 'hadith');
        $quote = HadithQuote::query()->where('quote_key', $hadith['quoteKey'])->sole();
        $this->assertSame('صلوا كما رأيتموني أصلي في صلاتكم', $quote->quote);
        $this->assertSame(1, $quote->fatwa_count);
    }

    public function test_fatwa_cites_printed_volume_and_page(): void
    {
        app(FawzanImporter::class)->import();

        $fatwa = Fatwa::query()->where('source_fatwa_id', '243-4')->sole();
        $this->assertSame('ج1 ص259', $fatwa->origin);
        $this->assertSame('https://shamela.ws/book/8612/243#p4', $fatwa->url);
        $this->assertSame('fawzan', $fatwa->mufti->key);
        $this->assertSame(['كتاب الصلاة'], $fatwa->categories->pluck('name')->all());
    }

    public function test_reimport_is_idempotent(): void
    {
        app(FawzanImporter::class)->import();
        $second = app(FawzanImporter::class)->import();
        app(FawzanImporter::class)->import(force: true);

        $this->assertSame(0, $second->created);
        $this->assertDatabaseCount('fatwas', 2);
        $this->assertSame(1, HadithQuote::query()->value('fatwa_count'));
    }

    public function test_parser_change_reimports_without_force(): void
    {
        app(FawzanImporter::class)->import();
        Fatwa::query()->update(['parser_version' => FawzanImporter::PARSER_VERSION - 1]);

        $result = app(FawzanImporter::class)->import();

        $this->assertSame(2, $result->updated);
        $this->assertSame([FawzanImporter::PARSER_VERSION], Fatwa::query()->distinct()->pluck('parser_version')->all());
    }

    public function test_import_waits_for_a_running_import_of_the_same_source(): void
    {
        Cache::put('import-lock:fawzan', 'another-host|boot|123|live', 600);

        $this->artisan('fatwas:import', ['source' => 'fawzan'])->expectsOutputToContain('skipped')->assertSuccessful();
        $this->assertDatabaseCount('fatwas', 0);
    }

    public function test_lock_left_by_a_crashed_import_is_taken_over(): void
    {
        Cache::put('import-lock:fawzan', gethostname().'|'.trim((string) @file_get_contents('/proc/sys/kernel/random/boot_id')).'|999999999|dead', 600);

        $this->artisan('fatwas:import', ['source' => 'fawzan'])->expectsOutputToContain('Peak memory')->assertSuccessful();

        $this->assertDatabaseCount('fatwas', 2);
        $this->assertNull(Cache::get('import-lock:fawzan'), 'the lock is released when the import ends');
    }

    public function test_missing_page_is_skipped(): void
    {
        File::put($this->raw.'/fawzan/pages/page/246.json.gz', 'not gzip');
        File::put($this->raw.'/fawzan/pages/page/247.json.gz', gzencode('{"error":"not found"}'));

        $result = app(FawzanImporter::class)->import();

        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->failed);
        $this->assertGreaterThanOrEqual(2, $result->skipped);
    }

    public function test_absent_date_is_null(): void
    {
        app(FawzanImporter::class)->import();

        $fatwa = Fatwa::query()->where('source_fatwa_id', '243-1')->sole();
        $this->assertNull($fatwa->hijri_year);
        $this->assertNull($fatwa->hijri_month);
        $this->assertNull($fatwa->hijri_day);
        $this->assertNull($fatwa->date_gregorian);
    }

    public function test_command_imports_the_source(): void
    {
        $this->artisan('fatwas:import', ['source' => 'fawzan'])->assertSuccessful();

        $this->assertDatabaseCount('fatwas', 2);
    }

    public function test_command_rejects_unknown_source(): void
    {
        $this->artisan('fatwas:import', ['source' => 'nope'])->assertFailed();
    }
}
