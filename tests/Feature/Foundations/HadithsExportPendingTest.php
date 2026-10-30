<?php

namespace Tests\Feature\Foundations;

use App\Models\HadithQuote;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HadithsExportPendingTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/export-pending-'.uniqid();
        config(['fatwarag.raw_path' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function quote(string $text, string $status, int $count): HadithQuote
    {
        return HadithQuote::create(['quote_key' => md5($text), 'quote' => $text, 'status' => $status, 'fatwa_count' => $count]);
    }

    public function test_it_writes_pending_quotes_by_fatwa_count(): void
    {
        $this->quote('إنما الأعمال بالنيات', 'pending', 3);
        $this->quote('لا يحل سلف وبيع', 'pending', 12);
        $this->quote('الحديث المعروف', 'found', 50);
        $this->quote('حديث غير موجود', 'not_found', 40);

        $this->artisan('hadiths:export-pending')->assertSuccessful();

        $path = $this->root.'/dorar/queue/extra_quotes.json';
        $this->assertFileExists($path);
        $this->assertSame([
            ['quote' => 'لا يحل سلف وبيع', 'fatwa_count' => 12],
            ['quote' => 'إنما الأعمال بالنيات', 'fatwa_count' => 3],
        ], json_decode(File::get($path), true));
        $this->assertSame([], glob($this->root.'/dorar/queue/*.tmp'));
    }

    public function test_it_replaces_the_previous_file_and_writes_an_empty_list_when_nothing_is_pending(): void
    {
        File::ensureDirectoryExists($this->root.'/dorar/queue');
        File::put($this->root.'/dorar/queue/extra_quotes.json', '[{"quote":"قديم","fatwa_count":1}]');

        $this->artisan('hadiths:export-pending')->assertSuccessful();

        $this->assertSame([], json_decode(File::get($this->root.'/dorar/queue/extra_quotes.json'), true));
    }

    public function test_it_is_scheduled_every_thirty_minutes_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'hadiths:export-pending'));

        $this->assertNotNull($event);
        $this->assertSame('*/30 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
