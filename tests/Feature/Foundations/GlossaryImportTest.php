<?php

namespace Tests\Feature\Foundations;

use App\Enums\GradeLevel;
use App\Models\GlossaryTerm;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GlossaryImportTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir().'/glossary-'.uniqid().'.json';
        $text = fn (string $t) => ['paragraphs' => [$t], 'sourceName' => 'src', 'sourceUrl' => 'https://example.test'];
        File::put($this->file, json_encode(['intro' => ['ar' => $text('مقدمة'), 'en' => $text('intro')], 'terms' => [
            ['term' => 'sahih', 'labelAr' => 'صحيح', 'labelEn' => 'Sahih', 'level' => 'sahih',
                'ar' => ['meaning' => $text('معنى'), 'acting' => $text('حكم')], 'en' => ['meaning' => $text('meaning'), 'acting' => $text('ruling')]],
            ['term' => 'mursal', 'labelAr' => 'مرسل', 'labelEn' => 'Mursal', 'level' => 'daif',
                'ar' => ['meaning' => $text('معنى 2'), 'acting' => null], 'en' => null],
        ]], JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        File::delete($this->file);
        parent::tearDown();
    }

    public function test_terms_keep_group_and_both_sections(): void
    {
        $this->artisan('glossary:import', ['--file' => $this->file])->assertSuccessful();

        $sahih = GlossaryTerm::query()->where('key', 'sahih')->first();
        $this->assertSame(GradeLevel::Sahih, $sahih->level);
        $this->assertSame('صحيح', $sahih->label_ar);
        $this->assertSame('معنى', $sahih->entry_ar['meaning']['paragraphs'][0]);
        $this->assertSame('حكم', $sahih->entry_ar['acting']['paragraphs'][0]);
        $this->assertSame('ruling', $sahih->entry_en['acting']['paragraphs'][0]);

        $mursal = GlossaryTerm::query()->where('key', 'mursal')->first();
        $this->assertSame(GradeLevel::Daif, $mursal->level);
        $this->assertNull($mursal->entry_ar['acting']);
        $this->assertNull($mursal->entry_en);
        $this->assertSame(['sahih', 'mursal'], GlossaryTerm::query()->orderBy('position')->pluck('key')->all());
        $this->assertSame('intro', Setting::get('glossary_intro')['en']['paragraphs'][0]);
    }

    public function test_import_is_rerunnable(): void
    {
        $this->artisan('glossary:import', ['--file' => $this->file])->assertSuccessful();
        $this->artisan('glossary:import', ['--file' => $this->file])->assertSuccessful();

        $this->assertSame(2, GlossaryTerm::query()->count());
    }

    public function test_missing_file_fails_without_crashing(): void
    {
        $this->artisan('glossary:import', ['--file' => '/nonexistent/glossary.json'])->assertFailed();
    }
}
