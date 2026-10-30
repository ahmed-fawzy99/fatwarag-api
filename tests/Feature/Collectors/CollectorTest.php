<?php

namespace Tests\Feature\Collectors;

use App\Collectors\BinbazCollector;
use App\Collectors\IslamqaCollector;
use App\Collectors\IslamwebCollector;
use App\Models\CollectionTask;
use App\Models\CollectorState;
use App\Models\Fatwa;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CollectorTest extends TestCase
{
    use RefreshDatabase;

    private string $raw;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->raw = sys_get_temp_dir().'/collector-'.uniqid();
        config(['fatwarag.raw_path' => $this->raw, 'fatwarag.collectors.delay_ms' => ['islamqa' => 0, 'binbaz' => 0, 'islamweb' => 0], 'fatwarag.collectors.binbaz_miss_limit' => 3, 'fatwarag.telegram.token' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->raw);
        parent::tearDown();
    }

    private function source(string $key): Source
    {
        return Source::query()->create(['key' => $key, 'kind' => 'fatwa', 'tier' => 1, 'name_ar' => $key, 'name_en' => $key, 'base_url' => "https://{$key}.test"]);
    }

    private function fatwa(Source $source, string $id): void
    {
        Fatwa::query()->create([
            'public_id' => "{$source->key}-{$id}", 'source_id' => $source->id, 'source_fatwa_id' => $id, 'url' => "https://example.test/{$id}",
            'title' => 'عنوان', 'question' => [], 'answer' => [], 'search_text' => 'عنوان',
        ]);
    }

    private function islamqaPage(int $id): string
    {
        return '<html><head><link rel="canonical" href="https://islamqa.info/ar/answers/'.$id.'"/></head><body><article id="single-post-content">'
            .'<h1 data-sut="question-title">سؤال جديد</h1><section><h2 data-sut="question-number">السؤال '.$id.'</h2><div><p>ما الحكم؟</p></div></section>'
            .'<section><h2>الجواب</h2><div data-sut="answer-text"><p>الحكم كذا.</p></div></section></article></body></html>';
    }

    private function sitemap(string $lang, array $ids): string
    {
        return '<urlset>'.implode('', array_map(fn (int $id): string => "<url><loc>https://islamqa.info/{$lang}/answers/{$id}</loc></url>", $ids)).'</urlset>';
    }

    public function test_refresh_fetches_only_unknown_ids(): void
    {
        $islamqa = $this->source('islamqa');
        $this->fatwa($islamqa, '100');
        Http::fake([
            'islamqa.info/sitemaps/ar/answers/1/*' => Http::response($this->sitemap('ar', [100, 200])),
            'islamqa.info/sitemaps/ar/answers/2/*' => Http::response($this->sitemap('ar', [])),
            'islamqa.info/sitemaps/en/answers/1/*' => Http::response($this->sitemap('en', [])),
            'islamqa.info/ar/answers/200' => Http::response($this->islamqaPage(200)),
        ]);

        $state = app(IslamqaCollector::class)->refresh();

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://islamqa.info/ar/answers/200' && $request->header('User-Agent') === ['FatwaRAG/0.1']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/answers/100'));
        $this->assertSame('done', $state->status);
        $this->assertSame(1, $state->items_collected);
        $this->assertFileExists($this->raw.'/islamqa/pages/ar/00/200.html.gz');
        $this->assertTrue(Fatwa::query()->where('source_fatwa_id', '200')->exists(), 'the refresh imports what it collected');
    }

    public function test_refresh_stops_and_records_reason_on_403(): void
    {
        $this->source('islamqa');
        Http::fake([
            'islamqa.info/sitemaps/ar/answers/1/*' => Http::response($this->sitemap('ar', [200])),
            'islamqa.info/sitemaps/*' => Http::response($this->sitemap('en', [])),
            'islamqa.info/ar/answers/200' => Http::response('Forbidden', 403),
        ]);

        $state = app(IslamqaCollector::class)->refresh();

        $this->assertSame('stopped', $state->status);
        $this->assertStringContainsString('403', $state->stop_reason);
        $this->assertDatabaseHas('alerts', ['kind' => 'collector', 'fingerprint' => 'collector:islamqa']);
        $this->assertSame('pending', CollectionTask::query()->sole()->status, 'the page waits for the next run');
    }

    public function test_refresh_resumes_from_cursor(): void
    {
        $binbaz = $this->source('binbaz');
        $this->fatwa($binbaz, '10');
        CollectorState::query()->create(['source_id' => $binbaz->id, 'status' => 'stopped', 'stop_reason' => 'HTTP 403', 'cursor' => ['next_id' => 50]]);
        Http::fake([
            'binbaz.org.sa/fatwas/50/x' => Http::response('', 302, ['Location' => 'https://binbaz.org.sa/fatwas/50/slug']),
            'binbaz.org.sa/fatwas/50/slug' => Http::response('<html><body><article class="fatwa"><h1 class="article-title article-title--primary">عنوان</h1><div itemprop="articleBody"><p>جواب.</p></div></article></body></html>'),
            'binbaz.org.sa/fatwas/*' => Http::response('', 404),
        ]);

        $state = app(BinbazCollector::class)->refresh();

        $probes = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->filter(fn (string $url): bool => str_ends_with($url, '/x'))->values();
        $this->assertSame('https://binbaz.org.sa/fatwas/50/x', $probes->first(), 'probing starts at the cursor, not after the highest known id');
        $this->assertCount(4, $probes, 'one hit, then three misses in a row');
        $this->assertSame('done', $state->status);
        $this->assertSame(['next_id' => 51], $state->cursor);
        $this->assertFileExists($this->raw.'/binbaz/pages/ar/50/50.html.gz');
    }

    public function test_task_left_running_by_a_crash_is_resumed(): void
    {
        $islamqa = $this->source('islamqa');
        CollectorState::query()->create(['source_id' => $islamqa->id, 'status' => 'running', 'last_started_at' => now()->subHours(2)]);
        CollectorState::query()->toBase()->update(['updated_at' => now()->subHours(2)]);
        CollectionTask::query()->create(['source_id' => $islamqa->id, 'kind' => 'ar', 'external_id' => '200', 'url' => 'https://islamqa.info/ar/answers/200', 'status' => 'running', 'attempts' => 1]);

        $this->assertSame('interrupted', CollectorState::query()->sole()->status, 'a silent «running» collector shows as interrupted');

        Http::fake([
            'islamqa.info/sitemaps/*' => Http::response($this->sitemap('ar', [])),
            'islamqa.info/ar/answers/200' => Http::response($this->islamqaPage(200)),
        ]);

        $state = app(IslamqaCollector::class)->refresh();

        $this->assertSame('done', $state->status);
        $this->assertSame('done', CollectionTask::query()->sole()->status);
        $this->assertTrue(Fatwa::query()->where('source_fatwa_id', '200')->exists());
    }

    public function test_islamweb_refresh_follows_one_level_of_cross_references(): void
    {
        $islamweb = $this->source('islamweb');
        $this->fatwa($islamweb, '300');
        $page = fn (string $answer): string => '<html><body><div class="item-fatwa"><div class="top-item"><h2>عنوان</h2></div></div>'
            .'<div class="quest-fatwa"><div itemprop="text"><p>سؤال</p></div></div><div class="quest-fatwa" itemprop="acceptedAnswer"><div itemprop="text">'.$answer.'</div></div><div class="linkedtab"><a href="/ar/fatwa/999/x">ذات صلة</a></div></body></html>';
        Http::fake([
            'www.islamweb.net/ar/fatawa/?pageno=1' => Http::response('<a href="/ar/fatwa/400/x">جديد</a><a href="/ar/fatwa/300/y">قديم</a>'),
            'www.islamweb.net/ar/fatawa/?pageno=2' => Http::response('<a href="/ar/fatwa/300/y">قديم</a>'),
            'www.islamweb.net/ar/fatwa/400/' => Http::response($page('<p>وانظر الفتوى رقم: <a href="/ar/fatwa/500/z">500</a> و<a href="/ar/fatwa/300/y">300</a>.</p>')),
            'www.islamweb.net/ar/fatwa/500/' => Http::response($page('<p>انظر <a href="/ar/fatwa/600/z">600</a>.</p>')),
        ]);

        $state = app(IslamwebCollector::class)->refresh();

        $this->assertSame('done', $state->status);
        $this->assertSame(['ar:400', 'xref:500'], CollectionTask::query()->orderBy('id')->get()->map(fn (CollectionTask $t): string => "{$t->kind}:{$t->external_id}")->all());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/600/') || str_contains($request->url(), '/999/'));
        $this->assertTrue(Fatwa::query()->where('source_fatwa_id', '500')->value('is_cross_reference'));
    }

    public function test_refresh_waits_while_the_first_harvest_runs(): void
    {
        $this->source('islamqa');
        File::ensureDirectoryExists($this->raw.'/islamqa');
        File::put($this->raw.'/islamqa/progress.json', json_encode(['state' => 'running', 'updated_at' => now()->toIso8601String()]));
        Http::fake();

        app(IslamqaCollector::class)->refresh();

        Http::assertNothingSent();
    }

    public function test_command_refreshes_a_source(): void
    {
        $this->source('islamqa');
        Http::fake(['islamqa.info/sitemaps/*' => Http::response('<urlset></urlset>')]);

        $this->artisan('fatwas:refresh', ['source' => 'islamqa'])->assertSuccessful();
        $this->artisan('fatwas:refresh', ['source' => 'fawzan'])->assertSuccessful();
        $this->artisan('fatwas:refresh', ['source' => 'nope'])->assertFailed();
    }
}
