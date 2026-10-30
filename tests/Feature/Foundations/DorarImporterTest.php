<?php

namespace Tests\Feature\Foundations;

use App\Enums\GradeLevel;
use App\Importers\Foundations\DorarImporter;
use App\Models\Hadith;
use App\Models\HadithQuote;
use App\Support\Arabic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DorarImporterTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTE = 'إِنَّمَا الْأَعْمَالُ بِالنِّيَّاتِ وَإِنَّمَا لِكُلِّ امْرِئٍ مَا نَوَى';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->root = sys_get_temp_dir().'/dorar-'.uniqid();
        File::ensureDirectoryExists($this->root.'/queue');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** One result block as Dorar renders it. */
    private function block(string $text, string $phrase, string $scholar, string $hash, ?array $xplain = null, string $narrator = 'عمر بن الخطاب'): string
    {
        $link = $xplain ? '<a href="" role="button" xplain="'.$xplain[0].'" class="xplain px-2 mr-2 text-info" data-toggle="modal">'."\n\t\t\t| {$xplain[1]}\n\t\t</a>" : '';

        return <<<HTML
<div class="border-bottom py-4  ">
	<article class="overflow-hidden pt-7"><h5 class="h5-responsive">
		 1  -  <span class="search-keys">{$text}</span></h5></article>
	<div class="d-block mb-2">
        <strong class="px-2">
            خلاصة حكم المحدث :
            <span class="">{$phrase}</span>
        </strong><br>
		<strong class="px-2">الراوي :<span class="primary-text-color">{$narrator}</span></strong>
		<strong class="px-2">| المحدث :<a data-toggle="modal" card-link="/hadith/mhd/1"><span class="primary-text-color">{$scholar}</span></a></strong>
		<strong class="px-2">| المصدر :<a data-toggle="modal" card-link="/hadith/book-card/2"><span class="primary-text-color">غاية المرام</span></a></strong><br>
		<strong class="px-2">الصفحة أو الرقم :<span class="primary-text-color">14</span></strong>
		<strong class="px-2">التخريج :<span class="primary-text-color">أخرجه البخاري (1)</span></strong>
		<strong class="px-2"><a class="mr-2 text-info" target="_blank" href="/h/{$hash}?sims=1">أحاديث مشابهة</a></strong>
		{$link}
	</div>
</div>
HTML;
    }

    private function page(array $general, array $specialist = []): string
    {
        return '<div class="tab-content"><div class="tab-pane show active" id="home">'.implode("\n", $general)
            .'</div><div class="tab-pane" id="specialist">'.implode("\n", $specialist).'</div></div>';
    }

    private function sharhPage(string $text): string
    {
        return '<div id="sharh-text-content">'.$text.'<div class="visible-xs" style="height: 60px;"></div></div>';
    }

    private function store(string $quote, ?string $searchHtml, array $sharh = []): void
    {
        $id = DorarImporter::searchId($quote);
        $this->writeGz('pages/search/'.substr($id, 0, 2)."/{$id}.html.gz", $searchHtml ?? '<html>no results</html>');
        File::append($this->root.'/queue/done.tsv', DorarImporter::lookupKey($quote)."\t{$id}\tok\n");

        foreach ($sharh as $sharhId => $html) {
            $this->writeGz('pages/sharh/'.sprintf('%02d', $sharhId % 100)."/{$sharhId}.html.gz", $html);
        }
    }

    private function writeGz(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname("{$this->root}/{$path}"));
        File::put("{$this->root}/{$path}", gzencode($contents));
    }

    private function quote(string $text = self::QUOTE): HadithQuote
    {
        return HadithQuote::query()->create(['quote_key' => Arabic::quoteKey($text), 'quote' => $text, 'status' => 'pending', 'fatwa_count' => 1]);
    }

    public function test_search_results_matching_quote_become_one_hadith_with_all_gradings(): void
    {
        $quote = $this->quote();
        $this->store(self::QUOTE, $this->page(
            [
                $this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', 'إسناده ضعيف', 'الألباني', 'AAAA1111'),
                $this->block('الدين النصيحة قلنا لمن', 'صحيح', 'مسلم', 'CCCC3333'),
            ],
            [$this->block('إنَّما الأعمالُ بالنِّيَّاتِ وإنَّما لكلِّ امرئٍ ما نَوى', 'صحيح', 'ابن حجر العسقلاني', 'BBBB2222')],
        ));

        (new DorarImporter($this->root))->import();

        $quote->refresh();
        $this->assertSame('found', $quote->status);
        $this->assertSame(1, Hadith::query()->count());

        $hadith = $quote->hadith;
        $this->assertSame('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', $hadith->text, 'the main text is the first matching general-tab result');
        $this->assertSame('عمر بن الخطاب', $hadith->narrator);
        $this->assertStringContainsString('dorar.net/hadith/search?q=', $hadith->lookup_url);

        $grades = $hadith->gradings;
        $this->assertCount(2, $grades, 'the unrelated result is left out');
        $this->assertSame([GradeLevel::Sahih, GradeLevel::Daif], $grades->pluck('level')->all(), 'strongest first');
        $this->assertSame(['BBBB2222', 'AAAA1111'], $grades->pluck('dorar_hash')->all());
        $this->assertSame('ابن حجر العسقلاني', $grades[0]->scholar);
        $this->assertSame('Ibn Hajar al-Asqalani', $grades[0]->scholar_en);
        $this->assertSame('specialist', $grades[0]->tab);
        $this->assertSame('general', $grades[1]->tab);
        $this->assertSame('أخرجه البخاري (1)', $grades[1]->takhrij);
        $this->assertSame('14', $grades[1]->reference);
    }

    public function test_only_exact_sharh_is_attached(): void
    {
        $this->quote();
        $this->store(self::QUOTE, $this->page([
            $this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', 'صحيح', 'الألباني', 'AAAA1111', ['111', 'شرح حديث مشابه']),
            $this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', 'صحيح', 'مسلم', 'BBBB2222', ['222', 'شرح الحديث']),
        ]), [111 => $this->sharhPage('شرح مشابه'), 222 => $this->sharhPage("فقرة أولى.<br>\nفقرة ثانية.")]);

        (new DorarImporter($this->root))->import();

        $explanation = Hadith::query()->first()->explanation;
        $this->assertSame('222', $explanation->dorar_sharh_id);
        $this->assertSame("فقرة أولى.\nفقرة ثانية.", $explanation->text);
        $this->assertSame('https://dorar.net/hadith/sharh/222', $explanation->source_url);
    }

    public function test_similar_sharh_alone_is_ignored(): void
    {
        $this->quote();
        $this->store(self::QUOTE, $this->page([
            $this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', 'صحيح', 'الألباني', 'AAAA1111', ['111', 'شرح حديث مشابه']),
        ]), [111 => $this->sharhPage('شرح مشابه')]);

        (new DorarImporter($this->root))->import();

        $this->assertNull(Hadith::query()->first()->explanation);
    }

    public function test_quote_marked_not_found_when_no_result_matches(): void
    {
        $quote = $this->quote();
        $this->store(self::QUOTE, $this->page([$this->block('الدين النصيحة قلنا لمن', 'صحيح', 'مسلم', 'CCCC3333')]));

        (new DorarImporter($this->root))->import();

        $this->assertSame('not_found', $quote->refresh()->status);
        $this->assertNull($quote->hadith_id);
        $this->assertSame(0, Hadith::query()->count());
    }

    public function test_search_with_no_result_blocks_is_not_found(): void
    {
        $quote = $this->quote();
        $this->store(self::QUOTE, null);

        (new DorarImporter($this->root))->import();

        $this->assertSame('not_found', $quote->refresh()->status);
    }

    public function test_quote_not_yet_harvested_stays_pending(): void
    {
        $quote = $this->quote();

        (new DorarImporter($this->root))->import();

        $this->assertSame('pending', $quote->refresh()->status);
    }

    public function test_quote_with_different_harakat_resolves_the_same(): void
    {
        $bare = preg_replace('/[\x{064B}-\x{065F}]/u', '', self::QUOTE);
        $this->quote($bare);
        $this->store(self::QUOTE, $this->page([$this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', 'صحيح', 'الألباني', 'AAAA1111')]));

        $this->assertSame(DorarImporter::searchId(self::QUOTE), DorarImporter::searchId($bare));
        (new DorarImporter($this->root))->import();

        $this->assertSame('found', HadithQuote::query()->first()->status);
    }

    public function test_partial_quote_matches_the_full_hadith(): void
    {
        $partial = 'إنما الأعمال بالنيات وإنما لكل';
        $quote = $this->quote($partial);
        $this->store($partial, $this->page([$this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى فمن كانت هجرته إلى الله ورسوله فهجرته إلى الله ورسوله ومن كانت هجرته لدنيا يصيبها أو امرأة ينكحها فهجرته إلى ما هاجر إليه', 'صحيح', 'الألباني', 'AAAA1111')]));

        (new DorarImporter($this->root))->import();

        $this->assertSame('found', $quote->refresh()->status);
    }

    public function test_two_quotes_of_the_same_hadith_share_one_hadith(): void
    {
        $first = $this->quote();
        $second = $this->quote('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى فمن كانت هجرته');
        $page = $this->page([$this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', 'صحيح', 'الألباني', 'AAAA1111')]);
        $this->store(self::QUOTE, $page);
        $this->store('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى فمن كانت هجرته', $page);

        (new DorarImporter($this->root))->import();
        (new DorarImporter($this->root))->import();

        $this->assertSame(1, Hadith::query()->count());
        $this->assertSame($first->refresh()->hadith_id, $second->refresh()->hadith_id);
        $this->assertSame(1, Hadith::query()->first()->gradings()->count());
    }

    public function test_corrupt_page_is_skipped_and_the_rest_still_import(): void
    {
        $bad = $this->quote('حديث تالف النص هنا للاختبار');
        $good = $this->quote();
        $id = DorarImporter::searchId('حديث تالف النص هنا للاختبار');
        File::ensureDirectoryExists("{$this->root}/pages/search/".substr($id, 0, 2));
        File::put("{$this->root}/pages/search/".substr($id, 0, 2)."/{$id}.html.gz", 'not gzip');
        File::append($this->root.'/queue/done.tsv', DorarImporter::lookupKey('حديث تالف النص هنا للاختبار')."\t{$id}\tok\n");
        $this->store(self::QUOTE, $this->page([$this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', 'صحيح', 'الألباني', 'AAAA1111')]));

        (new DorarImporter($this->root))->import();

        $this->assertSame('pending', $bad->refresh()->status);
        $this->assertSame('found', $good->refresh()->status);
    }

    public function test_different_hadith_sharing_common_words_is_not_attached(): void
    {
        $quote = $this->quote();
        $this->store(self::QUOTE, $this->page([
            $this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', 'صحيح', 'الألباني', 'AAAA1111'),
            $this->block('لكل امرئ ما نوى وإنما الأعمال بالنيات والخواتيم وإنما العبرة بالأخير والصلح جائز بين المسلمين إلا صلحا حرم حلالا أو أحل حراما والمسلمون على شروطهم', 'ضعيف', 'الذهبي', 'DDDD4444', ['444', 'شرح الحديث']),
            $this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى فمن كانت هجرته إلى الله ورسوله فهجرته إلى الله ورسوله', 'صحيح', 'مسلم', 'EEEE5555'),
        ]), [444 => $this->sharhPage('شرح حديث آخر')]);

        (new DorarImporter($this->root))->import();

        $hadith = $quote->refresh()->hadith;
        $this->assertSame(['AAAA1111', 'EEEE5555'], $hadith->gradings->pluck('dorar_hash')->sort()->values()->all());
        $this->assertNull($hadith->explanation, 'the other hadith\'s sharh is not attached');
    }

    public function test_main_text_prefers_a_result_that_contains_the_quote(): void
    {
        $partial = 'إنما الأعمال بالنيات وإنما لكل امرئ';
        $quote = $this->quote($partial);
        $this->store($partial, $this->page([
            $this->block('الأعمال بالنيات وإنما لكل امرئ ما نوى', 'صحيح', 'الألباني', 'AAAA1111'),
            $this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', 'صحيح', 'مسلم', 'BBBB2222'),
        ]));

        (new DorarImporter($this->root))->import();

        $this->assertSame('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', $quote->refresh()->hadith->text);
    }

    public function test_short_quote_inside_a_longer_text_is_not_found(): void
    {
        $short = 'الأعمال بالنيات';
        $quote = $this->quote($short);
        $this->store($short, $this->page([$this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى فمن كانت هجرته إلى الله ورسوله', 'صحيح', 'مسلم', 'EEEE5555')]));

        (new DorarImporter($this->root))->import();

        $this->assertSame('not_found', $quote->refresh()->status);
    }

    public function test_a_hadith_is_reused_only_when_its_main_result_is_the_same(): void
    {
        $first = $this->quote();
        $this->store(self::QUOTE, $this->page([$this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', 'صحيح', 'الألباني', 'AAAA1111')]));
        $other = 'إنما الأعمال بالنيات وإنما لكل امرئ ما نوى فمن كانت هجرته إلى الله ورسوله فهجرته إلى الله ورسوله';
        $second = $this->quote($other);
        $this->store($other, $this->page([
            $this->block($other, 'صحيح', 'مسلم', 'EEEE5555'),
            $this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', 'صحيح', 'الألباني', 'AAAA1111'),
        ]));

        (new DorarImporter($this->root))->import();

        $this->assertNotSame($first->refresh()->hadith_id, $second->refresh()->hadith_id);
    }

    public function test_matches_without_a_grading_are_not_found(): void
    {
        $quote = $this->quote();
        $this->store(self::QUOTE, $this->page([$this->block('إنما الأعمال بالنيات وإنما لكل امرئ ما نوى', '', 'الألباني', 'AAAA1111')]));

        (new DorarImporter($this->root))->import();

        $this->assertSame('not_found', $quote->refresh()->status);
        $this->assertSame(0, Hadith::query()->count());
    }
}
