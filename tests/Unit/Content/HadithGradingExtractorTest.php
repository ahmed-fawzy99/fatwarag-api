<?php

namespace Tests\Unit\Content;

use App\Content\QuranQuoteMatcher;
use App\Content\RichTextBuilder;
use App\Support\Arabic;
use Mockery;
use PHPUnit\Framework\TestCase;

class HadithGradingExtractorTest extends TestCase
{
    private const MARKERS = ['span.aaya' => 'quran', 'span.hadith' => 'hadith'];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function hadiths(string $html): array
    {
        $matcher = Mockery::mock(QuranQuoteMatcher::class);
        $matcher->shouldReceive('match')->andReturn(['surah' => 1, 'ayah' => 1, 'ayahEnd' => null]);
        $paragraphs = (new RichTextBuilder($matcher))->fromHtml($html, self::MARKERS);

        return array_values(array_filter(array_merge(...$paragraphs), fn (array $s): bool => $s['type'] === 'hadith'));
    }

    /**
     * Asserts no hadith in the fixture got a grading.
     */
    private function assertUngraded(string $html): void
    {
        $hadiths = $this->hadiths($html);

        $this->assertNotEmpty($hadiths);
        foreach ($hadiths as $hadith) {
            $this->assertNull($hadith['gradingInFatwa'], 'wrongly graded: '.$hadith['text']);
            $this->assertNull($hadith['gradingScope']);
            $this->assertNull($hadith['gradingContext']);
        }
    }

    public function test_single_graded_hadith(): void
    {
        [$hadith] = $this->hadiths('<p>قال ﷺ: <span class="hadith">«من صام رمضان إيمانا واحتسابا»</span> رواه أبو داود وصحَّحه الألباني في صحيح أبي داود. ثم ذكر العلماء مسائل.</p>');

        $this->assertSame('وصحَّحه الألباني', $hadith['gradingInFatwa']);
        $this->assertSame('this', $hadith['gradingScope']);
        $this->assertSame('رواه أبو داود وصحَّحه الألباني في صحيح أبي داود.', $hadith['gradingContext']);
    }

    public function test_grading_in_the_next_sentence_is_kept(): void
    {
        [$hadith] = $this->hadiths('<p>قال النبي ﷺ: <span class="hadith">لا نكاح إلا بولي</span> رواه أحمد. وإسناده صحيح على شرط مسلم.</p>');

        $this->assertSame('وإسناده صحيح على شرط مسلم', $hadith['gradingInFatwa']);
        $this->assertSame('رواه أحمد. وإسناده صحيح على شرط مسلم.', $hadith['gradingContext']);
    }

    public function test_group_grading_applies_to_each_hadith(): void
    {
        $hadiths = $this->hadiths('<p>ورد عن النبي ﷺ: <span class="hadith">«صلاة الضحى صلاة الأوابين»</span>، وقوله ﷺ: <span class="hadith">«من صلى الضحى بنى الله له بيتا»</span>، وهي أحاديث ضعيفة لا يحتج بها.</p>');

        $this->assertCount(2, $hadiths);
        foreach ($hadiths as $hadith) {
            $this->assertSame('وهي أحاديث ضعيفة', $hadith['gradingInFatwa']);
            $this->assertSame('group', $hadith['gradingScope']);
            $this->assertSame('وهي أحاديث ضعيفة لا يحتج بها.', $hadith['gradingContext']);
        }
    }

    public function test_hadith_followed_by_an_unrelated_sentence_stays_ungraded(): void
    {
        [$hadith] = $this->hadiths('<p>قال ﷺ: <span class="hadith">«إنما الأعمال بالنيات»</span> رواه البخاري. والصحيح أن المسألة فيها خلاف. وإسناده صحيح.</p>');

        $this->assertNull($hadith['gradingInFatwa']);
        $this->assertNull($hadith['gradingScope']);
        $this->assertNull($hadith['gradingContext']);
    }

    public function test_book_title_is_not_a_grading(): void
    {
        [$hadith] = $this->hadiths('<p>قال ﷺ: <span class="hadith">«إنما الأعمال بالنيات»</span> والحديث صحيح البخاري ومسلم.</p>');

        $this->assertNull($hadith['gradingInFatwa']);
    }

    public function test_disagreeing_scholar_is_kept_in_the_context(): void
    {
        [$same, $next] = $this->hadiths(
            '<p>قال ﷺ: <span class="hadith">«الدعاء هو العبادة»</span> رواه الترمذي وحسَّنه، وضعَّفه آخرون.</p>'
            .'<p>وقال ﷺ: <span class="hadith">«من سلك طريقا يلتمس فيه علما»</span> رواه الحاكم وصححه. وتعقبه الذهبي فقال: فيه ضعف.</p>'
        );

        $this->assertSame('وحسَّنه', $same['gradingInFatwa']);
        $this->assertSame('رواه الترمذي وحسَّنه، وضعَّفه آخرون.', $same['gradingContext']);
        $this->assertSame('وصححه', $next['gradingInFatwa']);
        $this->assertSame('رواه الحاكم وصححه. وتعقبه الذهبي فقال: فيه ضعف.', $next['gradingContext']);
    }

    public function test_grading_after_a_verse_is_not_taken(): void
    {
        [$hadith] = $this->hadiths('<p>قال ﷺ: <span class="hadith">«إنما الأعمال بالنيات»</span> وقال تعالى: <span class="aaya">وما أمروا إلا ليعبدوا الله</span> وإسناده صحيح.</p>');

        $this->assertNull($hadith['gradingInFatwa']);
    }

    public function test_group_phrase_in_the_next_paragraph_about_something_else_is_ignored(): void
    {
        // binbaz-16827: «كلها باطلة» was about idols.
        $this->assertUngraded('<p>قال الرسول ﷺ لهم قولوا: <span class="hadith">الله أعلى وأجل</span>، قال الرسول ﷺ: <span class="hadith">الله مولانا ولا مولى لكم</span>.</p><p>وكانت حول الكعبة أصنام كلها باطلة.</p>');
    }

    public function test_introducer_is_never_applied_backwards(): void
    {
        // islamweb-33828: «وردت في ذلك أحاديث صحيحة منها:» introduces the next hadith.
        $hadiths = $this->hadiths('<p>روى أحمد: <span class="hadith">أن رجلا سأل رسول الله صلى الله عليه وسلم هل بقي علي من بر أبوي</span> الحديث رواه أبو داود وابن ماجه، وفي إسناده ضعف، ولكن يعمل به. لما رواه مسلم عن أبي هريرة رضي الله عنه: <span class="hadith">إذا مات ابن آدم انقطع عمله إلا من ثلاث</span> وكذلك الصدقة عن الميت، وقد وردت في ذلك أحاديث صحيحة منها: <span class="hadith">قول سعد: يا رسول الله إن أمي افتلتت نفسها</span></p>');

        $this->assertSame('وفي إسناده ضعف', $hadiths[0]['gradingInFatwa']);
        $this->assertSame('this', $hadiths[0]['gradingScope']);
        $this->assertNull($hadiths[1]['gradingInFatwa']);
        $this->assertNull($hadiths[2]['gradingInFatwa']);
    }

    public function test_plural_phrase_inside_a_later_sentence_is_ignored(): void
    {
        // islamweb-30222: «كما جاءت أحاديث صحيحة تبين ذلك».
        $this->assertUngraded('<p>قال ﷺ: <span class="hadith">إذا التقى المسلمان بسيفيهما</span>، وقوله ﷺ: <span class="hadith">سباب المسلم فسوق وقتاله كفر</span> فسماهم مؤمنين مع الاقتتال، كما جاءت أحاديث صحيحة تبين ذلك.</p>');
    }

    public function test_question_is_not_a_grading(): void
    {
        // binbaz-22707: «س: هل هذا ثابت؟».
        $this->assertUngraded('<p>وفي الحديث عن النبي ﷺ: <span class="hadith">لا يتخون أهله</span> س: هل هذا ثابت عن الثلاثة من الصحابة؟ الشيخ: ما أدري.</p>');
        $this->assertUngraded('<p>قال ﷺ: <span class="hadith">لا يتخون أهله</span> هل هذا حديث صحيح؟ نعم.</p>');
    }

    public function test_bare_pronoun_about_something_else_is_ignored(): void
    {
        // binbaz-13303 «بل هو باطل» (a story), binbaz-14220 «هذا لا أصل له» (a practice).
        $this->assertUngraded('<p>وفي اللفظ الآخر عن النبي ﷺ: <span class="hadith">فإن تسليمكم يبلغني أين كنتم</span></p><p>أما أنه مد يده لأحد من الناس؛ فهذا شيء لا أصل له، بل هو باطل، نعم.</p>');
        $this->assertUngraded('<p>لأن النبي ﷺ قال: <span class="hadith">إنه قد أتاهم ما يشغلهم</span> فإذا صنع الجيران طعاما لا بأس، أما الوليمة ثلاثة أيام، هذا لا أصل له، هذا من البدع، نعم.</p>');
    }

    public function test_grade_about_a_narrator_is_ignored(): void
    {
        // binbaz-19985 and islamqa-337787.
        $this->assertUngraded('<p>أن رسول الله ﷺ قال: <span class="hadith">إقامة حد من حدود الله خير من مطر أربعين ليلة</span>، قال في الزوائد: في إسناده سعيد بن سنان ضعفه ابن معين وغيره. وقال الدارقطني يضع الحديث.</p>');
        $this->assertUngraded('<p>قال رسول الله ﷺ: <span class="hadith">يوم من إمام عادل خير من عبادة ستين سنة</span> رواه الطبراني في الأوسط، وفيه زريق بن السخت ولم أعرفه. وفي إسناده جرير بن يزيد البجلي وهو ضعيف.</p>');
        $this->assertUngraded('<p>أن رسول الله صلى الله عليه وسلم قال: <span class="hadith">مَنْ صَامَ رَمَضَانَ ثُمَّ أَتْبَعَهُ سِتًّا مِنْ شَوَّالٍ</span>.</p><p>فهذا الحديث من حديث سَعْد بْنِ سَعِيدِ بْنِ قَيْسٍ، وقد ضعّفه بعض أهل العلم، حيث حكموا بضعف حفظه.</p>');
    }

    public function test_more_grading_forms_are_recognised(): void
    {
        $forms = [
            'رواه أحمد وفي سنده ضعف.' => 'وفي سنده ضعف',
            'رواه البيهقي، لكنه حديث ضعيف.' => 'لكنه حديث ضعيف',
            'رواه أبو داود. حديث صحيح.' => 'حديث صحيح',
            'رواه الترمذي وقال الترمذي: حديث حسن صحيح.' => 'وقال الترمذي: حديث حسن صحيح',
        ];

        foreach ($forms as $after => $phrase) {
            [$hadith] = $this->hadiths('<p>قال النبي ﷺ: <span class="hadith">«الدين النصيحة»</span> '.$after.'</p>');
            $this->assertSame($phrase, $hadith['gradingInFatwa'], $after);
        }
    }

    public function test_context_starts_at_a_word(): void
    {
        [$hadith] = $this->hadiths('<p>قال ﷺ: <span class="hadith">كان النبي صلى الله عليه وسلم يدعو</span>ُ. وهو حديث ضعيف كما قال الألباني.</p>');

        $this->assertSame('وهو حديث ضعيف كما قال الألباني.', $hadith['gradingContext']);
    }

    public function test_context_ends_at_a_sentence_boundary(): void
    {
        $long = str_repeat('وهذا كلام طويل في شرح معنى الحديث ', 12);
        [$hadith] = $this->hadiths('<p>قال ﷺ: <span class="hadith">«الدين النصيحة»</span> رواه مسلم وصححه جماعة من أهل العلم '.$long.'وانتهى.</p>');

        $this->assertSame('وصححه جماعة من أهل', $hadith['gradingInFatwa']);
        $this->assertStringEndsWith('وانتهى.', $hadith['gradingContext']);
        $this->assertStringStartsWith('رواه مسلم', $hadith['gradingContext']);
    }

    public function test_grading_of_the_next_unmarked_hadith_does_not_leak_back(): void
    {
        // binbaz-14881: «فهو حديث ضعيف» is about «من حفظ القرآن ثم نسيه», not the marked hadith before it.
        $this->assertUngraded('<p>والله يوفقه، وقد قال ﷺ: <span class="hadith">إنما أنا بشر أنسى كما تنسون، فإذا نسيت فذكروني</span></p><p>أما حديث: "من حفظ القرآن، ثم نسيه؛ لقي الله وهو أجذم" فهو حديث ضعيف.</p>');
        // binbaz-13557: «وأما حديث أنه تعرض عليه الأعمال… فهو حديث ضعيف».
        $this->assertUngraded('<p>ولهذا قال ﷺ: <span class="hadith">صلوا علي؛ فإن صلاتكم تبلغني حيث كنتم</span></p><p>وأما حديث أنه تعرض عليه الأعمال، فما وجد فيها من خير حمد الله؛ فهو حديث ضعيف لا يصح عن النبي ﷺ.</p>');
        $this->assertUngraded('<p>قال ﷺ: <span class="hadith">الدين النصيحة</span> وحديث: "من غشنا فليس منا" إسناده صحيح.</p>');
        $this->assertUngraded('<p>قال ﷺ: <span class="hadith">الدين النصيحة</span> وقوله ﷺ: من غشنا فليس منا، وهو حديث صحيح.</p>');
    }

    public function test_context_drops_the_lead_in_to_the_next_quote(): void
    {
        // binbaz-3912 and 12419: the context used to end «…، ولقوله عليه الصلاة والسلام:».
        $hadiths = $this->hadiths('<p>لقول النبي ﷺ: <span class="hadith">العهد الذي بيننا وبينهم الصلاة</span> رواه الإمام أحمد وأهل السنن بإسناد صحيح، عن بريدة بن الحصيب، ولقوله عليه الصلاة والسلام: <span class="hadith">بين الرجل وبين الشرك والكفر ترك الصلاة</span> أخرجه مسلم.</p>');

        $this->assertSame('بإسناد صحيح', $hadiths[0]['gradingInFatwa']);
        $this->assertSame('رواه الإمام أحمد وأهل السنن بإسناد صحيح، عن بريدة بن الحصيب', $hadiths[0]['gradingContext']);
    }

    public function test_lead_in_holding_the_grading_is_kept(): void
    {
        // binbaz-3210: the grading sits in the clause that leads into the next quote.
        [$hadith] = $this->hadiths('<p>قال ﷺ: <span class="hadith">نهى أن يجصص القبر</span> رواه الإمام مسلم في صحيحه، بإسناد صحيح عن جابر قال: <span class="hadith">لا تجلسوا على القبور</span></p>');

        $this->assertSame('بإسناد صحيح', $hadith['gradingInFatwa']);
        $this->assertSame('رواه الإمام مسلم في صحيحه، بإسناد صحيح عن جابر قال', $hadith['gradingContext']);
    }

    public function test_istankara_counts_as_disagreement(): void
    {
        // islamweb-13930: «واستنكره النسائي».
        [$hadith] = $this->hadiths('<p>عن صفوان رضي الله عنه: <span class="hadith">قال يهودي لصاحبه: اذهب بنا إلى هذا النبي</span> رواه الترمذي وقال: حسن صحيح. واستنكره النسائي.</p>');

        $this->assertSame('رواه الترمذي وقال: حسن صحيح. واستنكره النسائي.', $hadith['gradingContext']);
    }

    public function test_pronoun_verb_needs_the_hadith_as_its_subject(): void
    {
        // islamqa-519289: «وعمران قد ضعفه عدد من أئمة الحديث» grades a narrator.
        $this->assertUngraded('<p>قال ﷺ: <span class="hadith">أول ما يرفع من الناس الخشوع</span> رواه الطبراني. وعمران قد ضعفه عدد من أئمة الحديث.</p>');
        // binbaz-4178: «وجودها» is not «جوّدها».
        $this->assertUngraded('<p>قال ﷺ: <span class="hadith">ثم ليطلقها طاهرا أو حاملا</span> وإنما الذي ينهى عن تطليقها حال وجودها بالصفة التي ننبه عليها.</p>');

        [$hadith] = $this->hadiths('<p>قال ﷺ: <span class="hadith">الدين النصيحة</span> وقد صححه الألباني.</p>');
        $this->assertSame('صححه الألباني', $hadith['gradingInFatwa']);
    }

    public function test_context_ending_in_a_mark_stays_valid_utf8(): void
    {
        // binbaz-21351: trimming «،» byte by byte broke «ضعيفٌ».
        [$hadith] = $this->hadiths('<p>قال ﷺ: <span class="hadith">لا وصية لوارث</span> فهو حديثٌ ضعيفٌ احتجَّ به بعضُ الفقهاء، وهو حديثٌ ضعيفٌ، ولقوله ﷺ: <span class="hadith">الدين النصيحة</span></p>');

        $this->assertTrue(mb_check_encoding($hadith['gradingContext'], 'UTF-8'));
        $this->assertStringEndsWith('ضعيفٌ', $hadith['gradingContext']);
    }

    public function test_verse_in_hadith_colour_is_typed_as_quran(): void
    {
        // islamweb-16387: a green span that is word for word an ayah.
        $matcher = Mockery::mock(QuranQuoteMatcher::class);
        $matcher->shouldReceive('exactAyah')->andReturnUsing(fn (string $quote): ?array => str_contains(Arabic::normalize($quote), 'الكافرون') ? ['surah' => 5, 'ayah' => 44, 'ayahEnd' => null] : null);
        $matcher->shouldReceive('fromReference')->andReturn(['surah' => 5, 'ayah' => 44, 'ayahEnd' => null]);
        $builder = new RichTextBuilder($matcher);

        $paragraphs = $builder->fromHtml(
            '<p>قوله تعالى(<font color="green">وَمَنْ لَمْ يَحْكُمْ بِمَا أَنْزَلَ اللَّهُ فَأُولَئِكَ هُمُ الْكَافِرُونَ</font>)[ المائدة:44]، فهو ثابت في أصوله. وقال: <font color="green">بلغوا عني ولو آية</font></p>',
            ['font[color="green" i]' => 'hadith'],
            ['hadithFilter' => 'none', 'hadithMayBeQuran' => true],
        );

        $types = array_column($paragraphs[0], 'type');
        $this->assertSame(['text', 'quran', 'text', 'hadith'], $types);
        $this->assertSame(5, $paragraphs[0][1]['surah']);
        $this->assertNull($paragraphs[0][3]['gradingInFatwa']);
        $this->assertCount(1, $builder->collectedQuotes());
    }
}
