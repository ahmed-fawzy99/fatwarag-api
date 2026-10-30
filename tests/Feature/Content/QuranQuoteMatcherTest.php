<?php

namespace Tests\Feature\Content;

use App\Content\QuranQuoteMatcher;
use App\Models\Ayah;
use App\Models\Surah;
use App\Support\Arabic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuranQuoteMatcherTest extends TestCase
{
    use RefreshDatabase;

    private const KURSI = 'ٱللَّهُ لَآ إِلَٰهَ إِلَّا هُوَ ٱلۡحَيُّ ٱلۡقَيُّومُۚ لَا تَأۡخُذُهُۥ سِنَةٞ وَلَا نَوۡمٞۚ لَّهُۥ مَا فِي ٱلسَّمَٰوَٰتِ وَمَا فِي ٱلۡأَرۡضِ';

    protected function setUp(): void
    {
        parent::setUp();

        Surah::query()->create(['number' => 2, 'name_ar' => 'البقرة', 'name_en' => 'Al-Baqarah', 'ayah_count' => 286]);
        Surah::query()->create(['number' => 7, 'name_ar' => 'الأعراف', 'name_en' => "Al-A'raf", 'ayah_count' => 206]);
        Surah::query()->create(['number' => 4, 'name_ar' => 'النساء', 'name_en' => "An-Nisa'", 'ayah_count' => 176]);
        Surah::query()->create(['number' => 5, 'name_ar' => 'المائدة', 'name_en' => "Al-Ma'idah", 'ayah_count' => 120]);
        Surah::query()->create(['number' => 92, 'name_ar' => 'الليل', 'name_en' => 'Al-Layl', 'ayah_count' => 21]);
        $this->ayah(2, 255, self::KURSI);
        $this->ayah(2, 256, 'لَآ إِكۡرَاهَ فِي ٱلدِّينِۖ قَد تَّبَيَّنَ ٱلرُّشۡدُ مِنَ ٱلۡغَيِّۚ');
        $this->ayah(5, 89, 'لَا يُؤَاخِذُكُمُ ٱللَّهُ بِٱللَّغۡوِ فِيٓ أَيۡمَٰنِكُمۡ وَلَٰكِن يُؤَاخِذُكُم بِمَا عَقَّدتُّمُ ٱلۡأَيۡمَٰنَۖ ذَٰلِكَ كَفَّٰرَةُ أَيۡمَٰنِكُمۡ إِذَا حَلَفۡتُمۡۚ');
        $this->ayah(4, 19, 'وَعَاشِرُوهُنَّ بِٱلۡمَعۡرُوفِۚ فَإِن كَرِهۡتُمُوهُنَّ فَعَسَىٰٓ أَن تَكۡرَهُواْ شَيۡـٔٗا');
        $this->ayah(92, 5, 'فَأَمَّا مَنۡ أَعۡطَىٰ وَٱتَّقَىٰ');
        $this->ayah(92, 6, 'وَصَدَّقَ بِٱلۡحُسۡنَىٰ');
        $this->ayah(7, 204, 'وَإِذَا قُرِئَ ٱلۡقُرۡءَانُ فَٱسۡتَمِعُواْ لَهُۥ وَأَنصِتُواْ لَعَلَّكُمۡ تُرۡحَمُونَ');
    }

    private function ayah(int $surah, int $number, string $text): void
    {
        Ayah::query()->create(['surah_number' => $surah, 'number' => $number, 'text' => $text, 'search_text' => Arabic::normalize($text)]);
    }

    public function test_partial_ayah_quote_links_to_containing_ayah(): void
    {
        $firstSixWords = implode(' ', array_slice(explode(' ', self::KURSI), 0, 6));

        $this->assertSame(['surah' => 2, 'ayah' => 255, 'ayahEnd' => null], app(QuranQuoteMatcher::class)->match($firstSixWords, null));
    }

    public function test_quote_without_harakat_still_matches(): void
    {
        $this->assertSame(['surah' => 2, 'ayah' => 256, 'ayahEnd' => null], app(QuranQuoteMatcher::class)->match('لا إكراه في الدين قد تبين الرشد من الغي', null));
    }

    public function test_standard_spelling_matches_uthmani_text(): void
    {
        $this->assertSame(['surah' => 5, 'ayah' => 89, 'ayahEnd' => null], app(QuranQuoteMatcher::class)->match('ذلك كفارة أيمانكم', null));
    }

    public function test_short_phrase_links_only_when_unique(): void
    {
        $matcher = app(QuranQuoteMatcher::class);

        $this->assertSame(['surah' => 4, 'ayah' => 19, 'ayahEnd' => null], $matcher->match('وَعَاشِرُوهُنَّ بِالْمَعْرُوفِ', null));
        $this->assertNull($matcher->match('في الدين', null));
    }

    public function test_star_separated_ayahs_give_a_range(): void
    {
        $this->assertSame(['surah' => 92, 'ayah' => 5, 'ayahEnd' => 6], app(QuranQuoteMatcher::class)->match('فَأَمَّا مَنْ أَعْطَى وَاتَّقَى * وَصَدَّقَ بِالْحُسْنَى', null));
    }

    public function test_exact_ayah_needs_the_words_of_an_ayah(): void
    {
        $matcher = app(QuranQuoteMatcher::class);

        $this->assertSame(['surah' => 7, 'ayah' => 204, 'ayahEnd' => null], $matcher->exactAyah('وإذا قرئ القرآن فاستمعوا له وأنصتوا لعلكم ترحمون'));
        $this->assertNull($matcher->exactAyah('الله لا إله إلا هو الحي القيوم'), 'a few words shared with a long ayah are not that ayah');
        $this->assertNull($matcher->exactAyah('وإذا قرئ الحديث فاستمعوا له وأنصتوا'));
        $this->assertNull($matcher->exactAyah('لعلكم ترحمون'));
    }

    public function test_reference_text_wins_over_similarity(): void
    {
        $match = app(QuranQuoteMatcher::class)->match(self::KURSI, '[الأعراف: 204]');

        $this->assertSame(['surah' => 7, 'ayah' => 204, 'ayahEnd' => null], $match);
    }

    public function test_reference_formats(): void
    {
        $matcher = app(QuranQuoteMatcher::class);

        $this->assertSame(['surah' => 2, 'ayah' => 275, 'ayahEnd' => 277], $matcher->match('نص', '[البقرة: 275-277].'));
        $this->assertSame(['surah' => 2, 'ayah' => 275, 'ayahEnd' => null], $matcher->match('نص', ' البقرة/275 ، وكذلك'));
        $this->assertSame(['surah' => 7, 'ayah' => 204, 'ayahEnd' => null], $matcher->match('نص', ' [الأعراف: ٢٠٤] .'));
        $this->assertSame(['surah' => 7, 'ayah' => 204, 'ayahEnd' => null], $matcher->match('نص', 'سورة الأعراف (204)'));
        $this->assertSame(['surah' => 2, 'ayah' => 16, 'ayahEnd' => 17], $matcher->match('نص', ' [البقرة: ١٦، ١٧] .'));
    }

    public function test_reference_beyond_surah_length_is_ignored(): void
    {
        $this->assertNull(app(QuranQuoteMatcher::class)->match('نص لا يشبه شيئا', '[البقرة: 999]'));
    }

    public function test_unmatched_quote_returns_null(): void
    {
        $this->assertNull(app(QuranQuoteMatcher::class)->match('هذا كلام عادي لا علاقة له بأي آية من الآيات المذكورة', null));
    }

    public function test_quote_spanning_two_ayahs_gets_an_end(): void
    {
        $quote = 'وَلَا نَوۡمٞۚ لَّهُۥ مَا فِي ٱلسَّمَٰوَٰتِ وَمَا فِي ٱلۡأَرۡضِ لَآ إِكۡرَاهَ فِي ٱلدِّينِۖ قَد تَّبَيَّنَ ٱلرُّشۡدُ مِنَ ٱلۡغَيِّۚ';

        $this->assertSame(['surah' => 2, 'ayah' => 255, 'ayahEnd' => 256], app(QuranQuoteMatcher::class)->match($quote, null));
    }
}
