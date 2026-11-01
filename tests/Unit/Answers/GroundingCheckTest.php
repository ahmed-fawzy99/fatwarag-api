<?php

namespace Tests\Unit\Answers;

use App\Answers\GroundingCheck;
use App\Answers\SourceSentences;
use App\Support\Arabic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GroundingCheckTest extends TestCase
{
    private const SOURCES = [
        'w-7' => 'يحرم على الرجل لبس خاتم الذهب لورود النهي الصريح عن ذلك في الأحاديث الصحيحة، ويجوز له لبس خاتم الفضة.',
        'w-8' => 'يجب على المرأة ستر شعرها أمام الرجال الأجانب عنها بإجماع أهل العلم في كل الأحوال.',
        'w-9' => 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير عند جمهور الفقهاء.',
        'w-10' => 'الحمد لله. اختلف العلماء في هذه المسألة. والراجح أن المرأة الحائض يجوز لها أن تقرأ القرآن من حفظها عند الحاجة إلى ذلك في التعليم أو خوف النسيان. والله أعلم.',
        'web-3' => 'السؤال: هل يجوز للحائض قراءة القرآن من المصحف؟ الجواب: الحمد لله، لا يجوز للحائض أن تمس المصحف بيدها مباشرة عند جمهور أهل العلم، ويجوز لها أن تقرأ القرآن من حفظها دون مس المصحف. والله أعلم.',
        'baz-7' => 'لا حرج في إعطاء القريب الفقير من الزكاة. الصواب: أن الحكم ليس مناطًا بالإرث، ولكن مناطًا بالفقر والحاجة، فإذا كان القريب محتاجًا، فيعطى من الزكاة.',
        'baz-5' => 'ونصاب الذهب يقدر بعشرين مثقالا وفيه ربع العشر إذا حال عليه الحول عند جميع أهل العلم.',
        'q-6' => 'السؤال: أنا امرأة سمعت أن لبس الذهب محرم على النساء مطلقا ولا يجوز بحال فهل هذا صحيح؟ الجواب: الحمد لله، لبس الذهب المحلق وغيره جائز للنساء بإجماع أهل العلم، والأحاديث الواردة في المنع منسوخة.',
        'islamweb-1' => 'الحمد لله والصلاة والسلام على رسول الله، أما بعد: فإن نصاب الذهب خمسة وثمانون جرامًا (85 جرامًا) من الذهب الخالص، فإذا بلغه الحلي المعد للاستعمال ففيه الزكاة على الراجح عندنا، ومقدار الزكاة ربع العشر. والله تعالى أعلم.',
        'w-11' => 'الأولى للمصلي أن يصلي السنة الراتبة في بيته اقتداء بالنبي صلى الله عليه وسلم، ولا إثم عليه إن صلاها في المسجد مع الجماعة.',
        'w-12' => 'اختلف العلماء في قراءة الحائض للقرآن، فذهب بعضهم إلى أنه يجوز للحائض أن تقرأ القرآن من حفظها لطول مدة الحيض، والصحيح أنه لا يجوز لها أن تقرأ القرآن حتى تطهر لعموم الأدلة الواردة في ذلك.',
        'w-13' => 'وقد ذكر بعض الفقهاء أقوالا، منها: يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير. والراجح عندنا المنع.',
        'w-14' => 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير عند جمع من أهل العلم، والراجح المنع.',
        'w-15' => 'اختلف الفقهاء في وجوب الزكاة في الذهب المعد للزينة، والجمهور على أنه لا زكاة في حلي المرأة المعد للزينة.',
        'w-16' => 'ولكن من صلى في بيته بلا عذر، صحت صلاته مع الإثم على الصحيح، ومن أصر على التخلف، فهو على خطر عظيم.',
        'baz-16' => 'ومن صلى في بيته بلا عذر، صحت صلاته مع الإثم على الصحيح من أقوال العلماء.',
        'binbaz-2' => 'نصاب الذهب عشرون مثقالًا، ومقداره بالجرام اثنان وتسعون جرامًا (92 جرامًا)، والحلي المستعمل لا زكاة فيه عند جمع من أهل العلم لأنه معد للاستعمال كالثياب والمتاع.',
    ];

    private const NAMES = [
        'w-7' => ['إسلام ويب'], 'w-8' => ['إسلام ويب'], 'w-9' => ['إسلام ويب'], 'w-10' => ['إسلام ويب'],
        'w-11' => ['إسلام ويب'], 'w-12' => ['إسلام ويب'], 'w-13' => ['إسلام ويب'], 'w-14' => ['إسلام ويب'], 'w-15' => ['إسلام ويب'],
        'web-3' => ['إسلام ويب'], 'w-16' => ['إسلام ويب'], 'baz-16' => ['ابن باز', 'باز'],
        'baz-5' => ['ابن باز', 'باز'],
        'baz-7' => ['ابن باز', 'باز'],
        'q-6' => ['ابن باز', 'باز'],
        'islamweb-1' => ['إسلام ويب', 'Islamweb', 'مركز الفتوى', 'اسلام ويب'],
        'binbaz-2' => ['موقع الشيخ ابن باز', 'Sheikh Ibn Baz', 'ابن باز', 'باز'],
    ];

    private const WEB_ZAKAT = 'فإذا بلغه الحلي المعد للاستعمال ففيه الزكاة على الراجح عندنا';

    private const BAZ_NO_ZAKAT = 'والحلي المستعمل لا زكاة فيه عند جمع من أهل العلم لأنه معد للاستعمال';

    /**
     * Support is now whole cited sentences, which can give one ruling for one thing and another for something else in
     * the same sentence. A claim borrowing the other part's ruling is rejected.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function rulingsBorrowedWithinASentence(): array
    {
        return [
            'negation flip' => ['web-3', 'مس الحائض للمصحف', 'يجوز للحائض أن تمس المصحف بيدها مباشرة عند جمهور أهل العلم.'],
            'negation insert' => ['web-3', 'قراءة الحائض', 'لا يجوز للحائض أن تقرأ القرآن من حفظها دون مس المصحف.'],
            'forbidden → no harm' => ['w-7', 'خاتم الذهب', 'لا بأس على الرجل في لبس خاتم الذهب مع ورود النهي عن ذلك في الأحاديث الصحيحة.'],
            'gold ring ruling moved to silver' => ['w-7', 'خاتم الفضة', 'يحرم على الرجل لبس خاتم الفضة لورود النهي الصريح عن ذلك في الأحاديث.'],
        ];
    }

    #[DataProvider('rulingsBorrowedWithinASentence')]
    public function test_a_ruling_borrowed_from_another_part_of_a_cited_sentence_is_rejected(string $fatwaId, string $title, string $body): void
    {
        $this->assertNull($this->checkIds($fatwaId, $title, $body));
    }

    public function test_each_ruling_of_a_cited_sentence_stays_with_its_own_part(): void
    {
        $this->assertNotNull($this->checkIds('w-7', 'خاتم الذهب والفضة', 'يحرم على الرجل لبس خاتم الذهب لورود النهي الصريح عن ذلك، ويجوز له لبس خاتم الفضة.'));
        $this->assertNotNull($this->checkIds('web-3', 'مس الحائض للمصحف', 'لا يجوز للحائض أن تمس المصحف بيدها مباشرة عند جمهور أهل العلم.'));
        // Saying the ruling is disputed («اختلف الفقهاء في وجوب…») gives no different ruling.
        $this->assertNotNull($this->checkIds('w-15', 'زكاة الحلي', 'لا زكاة في الذهب المعد للزينة عند الجمهور.'));
    }

    /**
     * Review fix round 1: a point whose only ruling support is a view its fatwa reports from others is dropped, unless
     * the point itself says whose view it is.
     */
    public function test_a_point_resting_only_on_a_reported_view_is_dropped(): void
    {
        $reported = fn (string $body, string $quote): array => $this->draft(agreed: $this->point('الجمع للمسافر', $body, ['w-13'], [['fatwaId' => 'w-13', 'quote' => $quote]]), kind: 'agree');
        $sentence = 'وقد ذكر بعض الفقهاء أقوالا، منها: يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير';

        $this->assertNull($this->check($reported('يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.', $sentence)));
        $this->assertNotNull($this->check($reported('ذكر بعض الفقهاء أنه يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.', $sentence)));

        // The lead-in in the sentence before («…أقوالا منها:», a list in the next paragraph) counts too.
        $sources = [...self::SOURCES, 'w-16' => "وذكر بعض الفقهاء في المسألة أقوالا منها:\nيجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير."];
        $point = $this->point('الجمع للمسافر', 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.', ['w-16'], [[
            'fatwaId' => 'w-16', 'quote' => 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير', 'before' => ['وذكر بعض الفقهاء في المسألة أقوالا منها:'],
        ]]);
        $this->assertNull((new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4))->check($this->draft(agreed: $point, kind: 'agree'), $sources, 'ar', [...self::NAMES, 'w-16' => ['إسلام ويب']]));
    }

    /**
     * Review fix round 1: who holds a view, and whose preference it is, must be the source or mufti of the fatwa whose
     * own cited sentence carries it; a filler sentence from another fatwa doesn't attach the ruling to that mufti.
     */
    public function test_attribution_must_name_the_fatwa_that_carries_the_ruling(): void
    {
        $filler = ['fatwaId' => 'binbaz-2', 'quote' => 'نصاب الذهب عشرون مثقالًا، ومقداره بالجرام اثنان وتسعون جرامًا (92 جرامًا)'];
        $point = $this->point('الحلي المعد للاستعمال', 'في الحلي المعد للاستعمال الزكاة.', ['islamweb-1', 'binbaz-2'], [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT], $filler]);

        $this->assertNull($this->check($this->draft(agreed: [...$point, 'heldBy' => 'رجّحه الشيخ ابن باز'], kind: 'agree'))['agreed']['heldBy']);
        $this->assertNull($this->check($this->draft(agreed: [...$point, 'heldBy' => 'رجّحه موقع إسلام ويب والشيخ ابن باز'], kind: 'agree'))['agreed']['heldBy']);
        $this->assertSame('رجّحه موقع إسلام ويب', $this->check($this->draft(agreed: [...$point, 'heldBy' => 'رجّحه موقع إسلام ويب'], kind: 'agree'))['agreed']['heldBy']);

        $draft = $this->draft(agreed: $point, kind: 'agree');
        $draft['preferences'] = [
            ['text' => 'ويرى الشيخ ابن باز أن في الحلي المعد للاستعمال الزكاة.', 'citations' => ['islamweb-1', 'binbaz-2'], 'support' => [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT], $filler]],
            ['text' => 'ويرى موقع إسلام ويب أن في الحلي المعد للاستعمال الزكاة.', 'citations' => ['islamweb-1', 'binbaz-2'], 'support' => [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT], $filler]],
        ];

        $this->assertSame(['ويرى موقع إسلام ويب أن في الحلي المعد للاستعمال الزكاة.'], array_column($this->check($draft)['preferences'], 'text'));
    }

    public function test_invented_ruling_on_a_boilerplate_quote_is_dropped(): void
    {
        $draft = $this->draft(agreed: $this->point(
            'حكم لبس الذهب',
            'يحرم على المرأة لبس الذهب مطلقا، وزكاته خمسة وثمانون جراما وربع العشر.',
            ['islamweb-1'],
            [['fatwaId' => 'islamweb-1', 'quote' => 'والله تعالى أعلم']],
        ), kind: 'agree');
        $draft['headline'] = 'يجب على كل مسلم إخراج زكاة الحلي فورا';

        $this->assertNull($this->check($draft));
    }

    public function test_short_or_formula_only_quotes_do_not_count(): void
    {
        $draft = $this->draft(agreed: $this->point('النصاب', 'نصاب الذهب معروف.', ['islamweb-1'], [
            ['fatwaId' => 'islamweb-1', 'quote' => 'الحمد لله والصلاة والسلام على رسول الله، أما بعد'],
            ['fatwaId' => 'islamweb-1', 'quote' => 'فإن نصاب الذهب'],
        ]), kind: 'agree');

        $this->assertNull($this->check($draft));
    }

    public function test_point_whose_wording_is_not_in_its_sources_is_dropped(): void
    {
        $draft = $this->draft(views: [
            $this->point('القول الأول: في الحلي الزكاة', 'في الحلي المعد للاستعمال الزكاة إذا بلغ النصاب.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]]),
            $this->point('القول الثاني: لا زكاة فيه', 'الحلي المستعمل لا زكاة فيه عند جمع من أهل العلم لأنه معد للاستعمال كالثياب.', ['binbaz-2'], [['fatwaId' => 'binbaz-2', 'quote' => self::BAZ_NO_ZAKAT]]),
            $this->point('القول الثالث', 'تجب الكفارة على من سافر في رمضان بالطائرة دون عذر شرعي معتبر.', ['binbaz-2'], [['fatwaId' => 'binbaz-2', 'quote' => self::BAZ_NO_ZAKAT]]),
        ]);

        $checked = $this->check($draft);

        $this->assertSame(['القول الأول: في الحلي الزكاة', 'القول الثاني: لا زكاة فيه'], array_column($checked['views'], 'title'));
    }

    public function test_invented_ruling_in_the_fatwas_vocabulary_is_dropped(): void
    {
        $draft = $this->draft(agreed: $this->point(
            'حكم الحلي',
            'يحرم على المرأة لبس الذهب الخالص، ويجب عليها بيع الحلي والتصدق بثمنه.',
            ['islamweb-1'],
            [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]],
        ), kind: 'agree');

        $this->assertNull($this->check($draft));
    }

    public function test_ungrounded_title_is_replaced_by_a_neutral_label(): void
    {
        $draft = $this->draft(views: [
            $this->point('القول الأول: يحرم على المرأة لبس الذهب مطلقا', 'في الحلي المعد للاستعمال الزكاة.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]]),
            $this->point('القول الثاني: الحلي المستعمل لا زكاة فيه', 'الحلي المستعمل لا زكاة فيه عند جمع من أهل العلم لأنه معد للاستعمال كالثياب.', ['binbaz-2'], [['fatwaId' => 'binbaz-2', 'quote' => self::BAZ_NO_ZAKAT]]),
        ]);

        $checked = $this->check($draft);

        $this->assertSame(['القول الأول', 'القول الثاني: الحلي المستعمل لا زكاة فيه'], array_column($checked['views'], 'title'));
        $this->assertArrayNotHasKey('_relabel', $checked['views'][0]);
    }

    public function test_flipped_ruling_is_dropped(): void
    {
        $support = [['fatwaId' => 'web-3', 'quote' => 'لا يجوز للحائض أن تمس المصحف بيدها مباشرة عند جمهور أهل العلم']];

        $flipped = $this->draft(agreed: $this->point('مس الحائض للمصحف', 'يجوز للحائض أن تمس المصحف بيدها مباشرة عند جمهور أهل العلم.', ['web-3'], $support), kind: 'agree');
        $flipped['headline'] = 'يجوز للحائض مس المصحف';
        $this->assertNull($this->check($flipped));

        $honest = $this->draft(agreed: $this->point('مس الحائض للمصحف', 'لا يجوز للحائض أن تمس المصحف بيدها مباشرة عند جمهور أهل العلم.', ['web-3'], $support), kind: 'agree');
        $this->assertNotNull($this->check($honest));
    }

    public function test_inserted_negation_is_dropped(): void
    {
        $draft = $this->draft(agreed: $this->point('قراءة الحائض', 'لا يجوز للحائض أن تقرأ القرآن من حفظها دون مس المصحف.', ['web-3'], [
            ['fatwaId' => 'web-3', 'quote' => 'ويجوز لها أن تقرأ القرآن من حفظها دون مس المصحف'],
        ]), kind: 'agree');

        $this->assertNull($this->check($draft));
    }

    public function test_antonyms_count_as_the_same_ruling(): void
    {
        $support = [['fatwaId' => 'web-3', 'quote' => 'لا يجوز للحائض أن تمس المصحف بيدها مباشرة عند جمهور أهل العلم']];

        $antonym = $this->draft(agreed: $this->point('مس الحائض للمصحف', 'يحرم على الحائض أن تمس المصحف بيدها مباشرة عند جمهور أهل العلم.', ['web-3'], $support), kind: 'agree');
        $this->assertNotNull($this->check($antonym));

        $opposite = $this->draft(agreed: $this->point('مس الحائض للمصحف', 'لا حرج على الحائض أن تمس المصحف بيدها مباشرة عند جمهور أهل العلم.', ['web-3'], $support), kind: 'agree');
        $this->assertNull($this->check($opposite));
    }

    public function test_ruling_must_be_stated_by_the_quote_itself(): void
    {
        $support = [['fatwaId' => 'baz-7', 'quote' => 'أن الحكم ليس مناطًا بالإرث، ولكن مناطًا بالفقر والحاجة، فإذا كان القريب محتاجًا، فيعطى من الزكاة']];

        // The fatwa says «لا حرج» elsewhere, but the quote states no ruling: the point needs a ruling-bearing quote.
        $permitted = $this->draft(agreed: $this->point('زكاة القريب', 'يجوز إعطاء القريب المحتاج من الزكاة، لأن الحكم مناطه الفقر والحاجة لا الإرث.', ['baz-7'], $support), kind: 'agree');
        $this->assertNull($this->check($permitted));

        $longQuote = $this->draft(agreed: $this->point('زكاة القريب', 'لا حرج في إعطاء القريب الفقير المحتاج من الزكاة، لأن الحكم مناطه الفقر والحاجة لا الإرث.', ['baz-7'], [
            ['fatwaId' => 'baz-7', 'quote' => 'لا حرج في إعطاء القريب الفقير من الزكاة. الصواب: أن الحكم ليس مناطًا بالإرث، ولكن مناطًا بالفقر والحاجة'],
        ]), kind: 'agree');
        $this->assertNotNull($this->check($longQuote));
    }

    public function test_questioners_words_are_not_support(): void
    {
        $draft = $this->draft(agreed: $this->point('لبس الذهب للنساء', 'لبس الذهب محرم على النساء مطلقا ولا يجوز بحال.', ['q-6'], [
            ['fatwaId' => 'q-6', 'quote' => 'سمعت أن لبس الذهب محرم على النساء مطلقا ولا يجوز بحال فهل هذا صحيح'],
        ]), kind: 'agree');

        $this->assertNull($this->check($draft));
    }

    public function test_numbers_with_attached_prefixes_are_checked(): void
    {
        $support = [['fatwaId' => 'baz-5', 'quote' => 'ونصاب الذهب يقدر بعشرين مثقالا وفيه ربع العشر إذا حال عليه الحول']];

        $thirty = $this->draft(agreed: $this->point('نصاب الذهب', 'نصاب الذهب يقدر بثلاثين مثقالا وفيه ربع العشر إذا حال عليه الحول.', ['baz-5'], $support), kind: 'agree');
        $this->assertNull($this->check($thirty));

        $seventyDays = $this->draft(agreed: $this->point('نصاب الذهب', 'نصاب الذهب يقدر بعشرين مثقالا وفيه ربع العشر إذا حال عليه الحول كاملا لسبعين يوما.', ['baz-5'], $support), kind: 'agree');
        $this->assertNull($this->check($seventyDays));

        $honest = $this->draft(agreed: $this->point('نصاب الذهب', 'نصاب الذهب يقدر بعشرين مثقالا وفيه ربع العشر إذا حال عليه الحول.', ['baz-5'], $support), kind: 'agree');
        $this->assertNotNull($this->check($honest));
    }

    public function test_preference_words_need_attribution_next_to_them(): void
    {
        $support = [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]];

        $outsideList = $this->draft(agreed: $this->point('زكاة الحلي', 'والأظهر أن في الحلي المعد للاستعمال الزكاة إذا بلغ النصاب.', ['islamweb-1'], $support), kind: 'agree');
        $this->assertNull($this->check($outsideList));

        $nameElsewhere = $this->draft(agreed: $this->point('زكاة الحلي', 'ذكر إسلام ويب أن في الحلي المعد للاستعمال الزكاة والصحيح عندنا أنه لا زكاة فيه.', ['islamweb-1'], $support), kind: 'agree');
        $this->assertNull($this->check($nameElsewhere));

        $farName = $this->draft(agreed: $this->point('زكاة الحلي', 'ذكر موقع إسلام ويب في فتواه المطولة عن الحلي المعد للاستعمال أن المعتمد ففيه الزكاة.', ['islamweb-1'], $support), kind: 'agree');
        $this->assertNull($this->check($farName));

        $attributed = $this->draft(agreed: $this->point('زكاة الحلي', 'ويرى موقع إسلام ويب أن الراجح أن في الحلي المعد للاستعمال الزكاة.', ['islamweb-1'], $support), kind: 'agree');
        $this->assertNotNull($this->check($attributed));

        $verbBeforeName = $this->draft(agreed: $this->point('زكاة الحلي', 'ويرى موقع إسلام ويب أن في الحلي المعد للاستعمال الزكاة، وأن هذا القول في الحلي هو الراجح.', ['islamweb-1'], $support), kind: 'agree');
        $this->assertNotNull($this->check($verbBeforeName));

        $quotedSource = $this->draft(agreed: $this->point('زكاة الحلي', 'ويقول موقع إسلام ويب: «في الحلي المعد للاستعمال الزكاة على الراجح عندنا».', ['islamweb-1'], $support), kind: 'agree');
        $this->assertNotNull($this->check($quotedSource));

        $quotedUnnamed = $this->draft(agreed: $this->point('زكاة الحلي', 'في الحلي المعد للاستعمال الزكاة «على الراجح عندنا».', ['islamweb-1'], $support), kind: 'agree');
        $this->assertNull($this->check($quotedUnnamed));

        $afterWithInda = $this->draft(agreed: $this->point('زكاة الحلي', 'في الحلي المعد للاستعمال الزكاة على الراجح عند إسلام ويب.', ['islamweb-1'], $support), kind: 'agree');
        $this->assertNotNull($this->check($afterWithInda));
    }

    /**
     * Review round 3 (adv2.php): rulings with a different strength, a different form or a wider negation.
     */
    public function test_ruling_strength_and_form_changes_are_dropped(): void
    {
        $gold = [['fatwaId' => 'w-7', 'quote' => 'يحرم على الرجل لبس خاتم الذهب لورود النهي الصريح عن ذلك في الأحاديث الصحيحة']];
        $hair = [['fatwaId' => 'w-8', 'quote' => 'يجب على المرأة ستر شعرها أمام الرجال الأجانب عنها بإجماع أهل العلم']];
        $travel = [['fatwaId' => 'w-9', 'quote' => 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير']];

        $drafts = [
            'yahrum → yukrah' => ['w-7', 'يكره على الرجل لبس خاتم الذهب لورود النهي الصريح عن ذلك في الأحاديث الصحيحة.', $gold],
            'yajib → yustahab' => ['w-8', 'يستحب للمرأة ستر شعرها أمام الرجال الأجانب عنها بإجماع أهل العلم.', $hair],
            'yajuz → la yanbaghi' => ['w-9', 'لا ينبغي للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.', $travel],
            'yajuz → mamnu' => ['w-9', 'ممنوع للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.', $travel],
            'yahrum → la ba\'s' => ['w-7', 'لا بأس على الرجل في لبس خاتم الذهب مع ورود النهي عن ذلك في الأحاديث الصحيحة.', $gold],
            'yajib → sunna' => ['w-8', 'ستر المرأة شعرها أمام الرجال الأجانب عنها سنة بإجماع أهل العلم.', $hair],
            'negator six words away' => ['w-9', 'ليس صحيحا ما يقال عند بعضهم من أنه يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.', $travel],
            'la yuqal' => ['w-9', 'لا يقال إنه يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.', $travel],
            'lam yathbut + noun' => ['w-9', 'لم يثبت للمسافر جواز أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.', $travel],
            'truncated quote without its ruling' => ['web-3', 'يجوز للحائض أن تمس المصحف بيدها مباشرة عند جمهور أهل العلم.', [['fatwaId' => 'web-3', 'quote' => 'أن تمس المصحف بيدها مباشرة عند جمهور أهل العلم']]],
        ];

        foreach ($drafts as $name => [$fatwaId, $body, $support]) {
            $this->assertNull($this->check($this->draft(agreed: $this->point('الحكم', $body, [$fatwaId], $support), kind: 'agree')), $name);
        }

        $honest = $this->draft(agreed: $this->point('خاتم الذهب', 'يحرم على الرجل لبس خاتم الذهب لورود النهي الصريح عن ذلك في الأحاديث الصحيحة.', ['w-7'], $gold), kind: 'agree');
        $this->assertNotNull($this->check($honest));
    }

    /**
     * Review round 3 (rv_bypass.php X1–X3): sin words, "it is a mistake to say", and «الأولى / الأفضل / الأحوط» are
     * rulings too, so a summary can't use them to flip or weaken what its quote states.
     */
    public function test_sin_mistake_and_preference_wording_are_checked_rulings(): void
    {
        $gold = [['fatwaId' => 'w-7', 'quote' => 'يحرم على الرجل لبس خاتم الذهب لورود النهي الصريح عن ذلك في الأحاديث الصحيحة']];
        $hair = [['fatwaId' => 'w-8', 'quote' => 'يجب على المرأة ستر شعرها أمام الرجال الأجانب عنها بإجماع أهل العلم']];
        $travel = [['fatwaId' => 'w-9', 'quote' => 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير']];

        $attacks = [
            'X1 yajuz → ya\'thamu' => ['w-9', 'يأثم المسافر إذا جمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.', $travel],
            'X1 yajuz → ithm' => ['w-9', 'على المسافر إثم إذا جمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.', $travel],
            'X1 yajuz → athim' => ['w-9', 'المسافر آثم إذا جمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.', $travel],
            'X2 min al-khata\' al-qawl' => ['w-8', 'ومن الخطأ القول بأنه يجب على المرأة ستر شعرها أمام الرجال الأجانب عنها بإجماع أهل العلم.', $hair],
            'X2 la yasihh al-qawl' => ['w-8', 'لا يصح القول بأنه يجب على المرأة ستر شعرها أمام الرجال الأجانب عنها بإجماع أهل العلم.', $hair],
            'X2 al-qawl bi… khata\'' => ['w-8', 'القول بأنه يجب على المرأة ستر شعرها أمام الرجال الأجانب عنها بإجماع أهل العلم خطأ.', $hair],
            'X3 yahrum → al-awla' => ['w-7', 'الأولى للرجل ترك لبس خاتم الذهب لورود النهي الصريح عن ذلك في الأحاديث الصحيحة.', $gold],
            'X3 yahrum → al-afdal' => ['w-7', 'الأفضل للرجل ترك لبس خاتم الذهب لورود النهي الصريح عن ذلك في الأحاديث الصحيحة.', $gold],
            'X3 yajib → al-ahwat' => ['w-8', 'الأحوط للمرأة ستر شعرها أمام الرجال الأجانب عنها بإجماع أهل العلم.', $hair],
        ];

        foreach ($attacks as $name => [$fatwaId, $body, $support]) {
            $check = new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4);
            $this->assertNull($check->check($this->draft(agreed: $this->point('الحكم', $body, [$fatwaId], $support), kind: 'agree'), self::SOURCES, 'ar', self::NAMES), $name);
            $this->assertStringContainsString('ruling', $check->rejections()[0]['reason'] ?? '', $name);
        }

        $honest = [
            'yahrum → ya\'thamu' => ['w-7', 'يأثم الرجل بلبس خاتم الذهب لورود النهي الصريح عن ذلك في الأحاديث الصحيحة.', $gold],
            'la ithm → la ithm' => ['w-11', 'لا إثم على المصلي إن صلى السنة الراتبة في المسجد مع الجماعة.', [['fatwaId' => 'w-11', 'quote' => 'اقتداء بالنبي صلى الله عليه وسلم، ولا إثم عليه إن صلاها في المسجد مع الجماعة']]],
            'al-awla → al-awla' => ['w-11', 'الأولى للمصلي أن يصلي السنة الراتبة في بيته اقتداء بالنبي صلى الله عليه وسلم.', [['fatwaId' => 'w-11', 'quote' => 'الأولى للمصلي أن يصلي السنة الراتبة في بيته اقتداء بالنبي صلى الله عليه وسلم']]],
        ];

        foreach ($honest as $name => [$fatwaId, $body, $support]) {
            $this->assertNotNull($this->check($this->draft(agreed: $this->point('الحكم', $body, [$fatwaId], $support), kind: 'agree')), $name);
        }
    }

    /**
     * Review round 3: a fatwa that reports a view («فذهب بعضهم إلى أنه يجوز…») and then rejects it doesn't hold it, so
     * `heldBy` can't credit that view to its source.
     */
    public function test_held_by_is_dropped_for_a_view_the_fatwa_reports_from_others(): void
    {
        $reported = $this->point('القول الأول', 'ذهب بعض العلماء إلى أنه يجوز للحائض أن تقرأ القرآن من حفظها لطول مدة الحيض.', ['w-12'], [
            ['fatwaId' => 'w-12', 'quote' => 'يجوز للحائض أن تقرأ القرآن من حفظها لطول مدة الحيض'],
        ]);
        $own = $this->point('القول الثاني', 'لا يجوز للحائض أن تقرأ القرآن حتى تطهر لعموم الأدلة الواردة في ذلك.', ['w-12'], [
            ['fatwaId' => 'w-12', 'quote' => 'والصحيح أنه لا يجوز لها أن تقرأ القرآن حتى تطهر لعموم الأدلة الواردة في ذلك'],
        ]);

        $checked = $this->check($this->draft(views: [[...$reported, 'heldBy' => 'إسلام ويب'], [...$own, 'heldBy' => 'إسلام ويب']]));

        $this->assertNull($checked['views'][0]['heldBy']);
        $this->assertSame('إسلام ويب', $checked['views'][1]['heldBy']);

        $quoted = $this->check($this->draft(views: [
            [...$this->point('القول الأول', 'ذهب بعضهم إلى أنه يجوز للحائض أن تقرأ القرآن من حفظها لطول مدة الحيض.', ['w-12'], [
                ['fatwaId' => 'w-12', 'quote' => 'فذهب بعضهم إلى أنه يجوز للحائض أن تقرأ القرآن من حفظها لطول مدة الحيض'],
            ]), 'heldBy' => 'إسلام ويب'],
            [...$own, 'heldBy' => 'إسلام ويب'],
        ]));

        $this->assertNull($quoted['views'][0]['heldBy']);
    }

    /**
     * Review round 4 (rv5.php N1, N2): the report can sit in an earlier comma clause of the sentence («ذكر بعض الفقهاء
     * أقوالًا، منها: …») or after the quote («… عند جمع من أهل العلم»).
     */
    public function test_held_by_is_dropped_when_the_sentence_reports_the_view(): void
    {
        $travel = 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير';
        $own = [...$this->point('القول الثاني', 'لا يجوز للحائض أن تقرأ القرآن حتى تطهر لعموم الأدلة الواردة في ذلك.', ['w-12'], [
            ['fatwaId' => 'w-12', 'quote' => 'والصحيح أنه لا يجوز لها أن تقرأ القرآن حتى تطهر لعموم الأدلة الواردة في ذلك'],
        ]), 'heldBy' => 'إسلام ويب'];

        foreach (['N1 earlier clause' => 'w-13', 'N2 «عند جمع من أهل العلم»' => 'w-14'] as $name => $fatwaId) {
            $reported = [...$this->point('الجواز', 'ذكر بعض الفقهاء أنه يجوز للمسافر أن يجمع بين الظهر والعصر إذا جد به السير.', [$fatwaId], [['fatwaId' => $fatwaId, 'quote' => $travel]]), 'heldBy' => 'إسلام ويب'];
            $checked = $this->check($this->draft(views: [$reported, $own]));

            $this->assertNull($checked['views'][0]['heldBy'], $name);
            $this->assertSame('إسلام ويب', $checked['views'][1]['heldBy'], $name);
        }

        $sources = [...self::SOURCES, 'baz-9' => 'الحلي المستعمل لا زكاة فيه لدى جمهور أهل العلم لأنه معد للاستعمال كالثياب والمتاع، والله أعلم.'];
        $point = [...$this->point('الحلي', 'الحلي المستعمل لا زكاة فيه لدى جمهور أهل العلم لأنه معد للاستعمال كالثياب.', ['baz-9'], [
            ['fatwaId' => 'baz-9', 'quote' => 'الحلي المستعمل لا زكاة فيه لدى جمهور أهل العلم لأنه معد للاستعمال كالثياب'],
        ]), 'heldBy' => 'ابن باز'];
        $checked = (new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4))->check($this->draft(agreed: $point, kind: 'agree'), $sources, 'ar', [...self::NAMES, 'baz-9' => ['ابن باز', 'باز']]);
        // Review fix round 3: a majority view the fatwa adopts (no later turn against it) is its own.
        $this->assertSame('ابن باز', $checked['agreed']['heldBy']);
    }

    /**
     * Review round 4 (rv5.php P1): a preference can't credit its source with a view the fatwa reports from others.
     */
    public function test_preference_crediting_a_reported_view_is_dropped(): void
    {
        $views = [
            $this->point('القول الأول', 'ذهب بعض العلماء إلى أنه يجوز للحائض أن تقرأ القرآن من حفظها لطول مدة الحيض.', ['w-12'], [['fatwaId' => 'w-12', 'quote' => 'يجوز للحائض أن تقرأ القرآن من حفظها لطول مدة الحيض']]),
            $this->point('القول الثاني', 'لا يجوز للحائض أن تقرأ القرآن حتى تطهر لعموم الأدلة الواردة في ذلك.', ['w-12'], [
                ['fatwaId' => 'w-12', 'quote' => 'والصحيح أنه لا يجوز لها أن تقرأ القرآن حتى تطهر لعموم الأدلة الواردة في ذلك'],
            ]),
        ];
        $draft = $this->draft(views: $views);
        $draft['preferences'] = [
            ['text' => 'ويرى إسلام ويب أنه يجوز للحائض أن تقرأ القرآن من حفظها لطول مدة الحيض.', 'citations' => ['w-12'], 'support' => [
                ['fatwaId' => 'w-12', 'quote' => 'يجوز للحائض أن تقرأ القرآن من حفظها لطول مدة الحيض'],
            ]],
            ['text' => 'ويرى إسلام ويب أن الصحيح أنه لا يجوز للحائض أن تقرأ القرآن حتى تطهر.', 'citations' => ['w-12'], 'support' => [
                ['fatwaId' => 'w-12', 'quote' => 'والصحيح أنه لا يجوز لها أن تقرأ القرآن حتى تطهر لعموم الأدلة الواردة في ذلك'],
            ]],
        ];

        $this->assertSame(['ويرى إسلام ويب أن الصحيح أنه لا يجوز للحائض أن تقرأ القرآن حتى تطهر.'], array_column($this->check($draft)['preferences'], 'text'));
    }

    public function test_judge_context_is_the_quote_within_its_own_clause(): void
    {
        $source = self::SOURCES['w-12'];

        $this->assertSame(
            'والصحيح أنه لا يجوز لها أن تقرأ القرآن حتى تطهر لعموم الأدلة الواردة في ذلك.',
            GroundingCheck::clauseAround($source, 'لا يجوز لها أن تقرأ القرآن حتى تطهر'),
        );

        $long = 'وهذا '.str_repeat('كلام طويل جدا ', 30).'ثم يجب عليه أن يعيد الصلاة في وقتها '.str_repeat('وهكذا دواليك ', 30).'.';
        $context = GroundingCheck::clauseAround($long, 'يجب عليه أن يعيد الصلاة في وقتها');
        $this->assertStringContainsString('يجب عليه أن يعيد الصلاة في وقتها', $context);
        $this->assertLessThanOrEqual(GroundingCheck::JUDGE_CONTEXT_WORDS + 2, count(explode(' ', $context)));

        $this->assertSame('نص غير موجود في الفتوى', GroundingCheck::clauseAround($source, 'نص غير موجود في الفتوى'));
    }

    public function test_first_person_preference_is_site_voice(): void
    {
        $travel = [['fatwaId' => 'w-9', 'quote' => 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير']];

        $yazharLi = $this->draft(agreed: $this->point('الجمع', 'والذي يظهر لي أنه يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.', ['w-9'], $travel), kind: 'agree');
        $this->assertNull($this->check($yazharLi));

        $newStatement = $this->draft(agreed: $this->point('الجمع', 'قال إسلام ويب يجوز للمسافر الجمع بين الظهر والعصر والأظهر أنه يجمع في وقت إحداهما إذا جد به السير.', ['w-9'], $travel), kind: 'agree');
        $this->assertNull($this->check($newStatement));
    }

    public function test_differ_headline_is_a_neutral_template(): void
    {
        $draft = $this->draft(views: [
            $this->point('في الحلي الزكاة', 'في الحلي المعد للاستعمال الزكاة إذا بلغ النصاب.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]]),
            $this->point('لا زكاة', 'الحلي المستعمل لا زكاة فيه عند جمع من أهل العلم لأنه معد للاستعمال كالثياب.', ['binbaz-2'], [['fatwaId' => 'binbaz-2', 'quote' => self::BAZ_NO_ZAKAT]]),
        ]);
        $draft['headline'] = 'الحلي المستعمل لا زكاة فيه';

        $this->assertSame('اختلفت المصادر في هذه المسألة على قولين', $this->check($draft)['headline']);
    }

    public function test_quote_fragment_is_widened_to_its_sentence_for_the_support_share(): void
    {
        $fragment = [['fatwaId' => 'w-10', 'quote' => 'يجوز لها أن تقرأ القرآن من حفظها عند الحاجة']];
        $body = 'الحائض تقرأ للتعليم وخوف النسيان والمراجعة، ويجوز لها ذلك.';
        $check = new GroundingCheck;
        $this->assertLessThan(0.4, $check->coverage($body, ['يجوز لها أن تقرأ القرآن من حفظها عند الحاجة'], ['w-10'], array_map(Arabic::normalize(...), self::SOURCES))['support']);

        $checked = $this->check($this->draft(agreed: $this->point('قراءة الحائض', $body, ['w-10'], $fragment), kind: 'agree'));

        $this->assertNotNull($checked);
        $this->assertSame('يجوز لها أن تقرأ القرآن من حفظها عند الحاجة', $checked['agreed']['support'][0]['quote']);
    }

    public function test_rejections_say_why(): void
    {
        $check = new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4);
        $check->check($this->draft(agreed: $this->point('الحكم', 'يكره على الرجل لبس خاتم الذهب لورود النهي الصريح عن ذلك في الأحاديث الصحيحة.', ['w-7'], [
            ['fatwaId' => 'w-7', 'quote' => 'يحرم على الرجل لبس خاتم الذهب لورود النهي الصريح عن ذلك في الأحاديث الصحيحة'],
        ]), kind: 'agree'), self::SOURCES, 'ar', self::NAMES);

        $this->assertStringContainsString('ruling', $check->rejections()[0]['reason']);
    }

    public function test_point_without_verbatim_support_is_dropped(): void
    {
        $draft = $this->draft(views: [
            $this->point('القول الأول', 'في الحلي المعد للاستعمال الزكاة.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]]),
            $this->point('القول الثاني', 'لا زكاة في الحلي.', ['binbaz-2'], [['fatwaId' => 'binbaz-2', 'quote' => 'الحلي لا زكاة فيه أبدا بإجماع العلماء من غير خلاف بينهم']]),
            $this->point('القول الثالث', 'الحلي المستعمل لا زكاة فيه عند جمع من أهل العلم.', ['binbaz-2'], [['fatwaId' => 'binbaz-2', 'quote' => self::BAZ_NO_ZAKAT]]),
        ]);

        $checked = $this->check($draft);

        $this->assertSame(['القول الأول', 'القول الثالث'], array_column($checked['views'], 'title'));
    }

    public function test_number_not_in_support_drops_point(): void
    {
        $draft = $this->draft(views: [
            $this->point('القول الأول', 'نصاب الذهب 85 جرامًا من الذهب الخالص.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => 'فإن نصاب الذهب خمسة وثمانون جرامًا (85 جرامًا) من الذهب الخالص']]),
            $this->point('القول الثاني', 'نصاب الذهب 90 جرامًا.', ['binbaz-2'], [['fatwaId' => 'binbaz-2', 'quote' => 'نصاب الذهب عشرون مثقالًا، ومقداره بالجرام اثنان وتسعون جرامًا (92 جرامًا)']]),
            $this->point('القول الثالث', 'نصاب الذهب ثلاثون مثقالًا.', ['binbaz-2'], [['fatwaId' => 'binbaz-2', 'quote' => 'نصاب الذهب عشرون مثقالًا، ومقداره بالجرام اثنان وتسعون جرامًا (92 جرامًا)']]),
            $this->point('القول الرابع', 'نصاب الذهب ٩٢ جرامًا، وهي عشرون مثقالًا.', ['binbaz-2'], [['fatwaId' => 'binbaz-2', 'quote' => 'نصاب الذهب عشرون مثقالًا، ومقداره بالجرام اثنان وتسعون جرامًا (92 جرامًا)']]),
        ]);

        $checked = $this->check($draft);

        $this->assertSame(['القول الأول', 'القول الرابع'], array_column($checked['views'], 'title'));
    }

    public function test_numbers_match_whole_numbers_only(): void
    {
        $draft = $this->draft(agreed: $this->point('النصاب', 'نصاب الذهب 185 جرامًا من الذهب الخالص.', ['islamweb-1'], [
            ['fatwaId' => 'islamweb-1', 'quote' => 'فإن نصاب الذهب خمسة وثمانون جرامًا (85 جرامًا) من الذهب الخالص'],
        ]), kind: 'agree');

        $this->assertNull($this->check($draft));
    }

    public function test_number_words_and_fractions_must_be_in_support(): void
    {
        $quote = 'فإن نصاب الذهب خمسة وثمانون جرامًا (85 جرامًا) من الذهب الخالص';

        $words = $this->draft(agreed: $this->point('النصاب', 'نصاب الذهب خمسة وثمانون جرامًا من الذهب الخالص.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => $quote]]), kind: 'agree');
        $this->assertNotNull($this->check($words));

        $fraction = $this->draft(agreed: $this->point('النصاب', 'نصاب الذهب 85 جرامًا، وفيه نصف العشر.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => $quote]]), kind: 'agree');
        $this->assertNull($this->check($fraction));
    }

    public function test_number_needs_its_qualifier_in_the_support(): void
    {
        $draft = $this->draft(agreed: $this->point('النصاب', 'نصاب الذهب 85 مثقالًا من الذهب الخالص.', ['islamweb-1'], [
            ['fatwaId' => 'islamweb-1', 'quote' => 'فإن نصاب الذهب خمسة وثمانون جرامًا (85 جرامًا) من الذهب الخالص'],
        ]), kind: 'agree');

        $this->assertNull($this->check($draft));
    }

    public function test_quotes_match_ignoring_harakat_punctuation_and_markers(): void
    {
        $draft = $this->draft(agreed: $this->point(
            'نصاب الذهب',
            'للذهب نصاب من الذهب الخالص.',
            ['islamweb-1'],
            [['fatwaId' => 'islamweb-1', 'quote' => '[E1 آية] فَإِنَّ نِصَابَ الذَّهَبِ - خمسة وثمانون جراما (85 جراما) من الذهب الخالص']],
        ), kind: 'agree');

        $this->assertSame('نصاب الذهب', $this->check($draft)['agreed']['title']);
    }

    public function test_all_points_dropped_returns_failed(): void
    {
        $draft = $this->draft(agreed: $this->point('رأي', 'كلام من عند الكاتب.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => 'هذا الكلام ليس في الفتوى أصلا ولا يمت لها بصلة البتة']]), kind: 'agree');

        $this->assertNull($this->check($draft));
    }

    public function test_citations_are_exactly_the_supported_retrieved_fatwas(): void
    {
        $draft = $this->draft(agreed: $this->point('الحلي المعد للاستعمال', 'في الحلي المعد للاستعمال الزكاة.', ['islamweb-1', 'binbaz-2', 'islamqa-999'], [
            ['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT],
            ['fatwaId' => 'islamqa-999', 'quote' => self::WEB_ZAKAT],
        ]), kind: 'agree');

        $this->assertSame(['islamweb-1'], $this->check($draft)['agreed']['citations']);
    }

    public function test_preference_must_name_its_source_and_not_speak_for_the_site(): void
    {
        $draft = $this->draft(agreed: $this->point('الحلي المعد للاستعمال', 'في الحلي المعد للاستعمال الزكاة.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]]), kind: 'agree');
        $support = [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]];
        $draft['preferences'] = [
            ['text' => 'والراجح وجوب الزكاة في الحلي المعد للاستعمال.', 'citations' => ['islamweb-1'], 'support' => $support],
            ['text' => 'ويرى بعض العلماء وجوب الزكاة في الحلي المعد للاستعمال.', 'citations' => ['islamweb-1'], 'support' => $support],
            ['text' => 'ويرى الشيخ ابن باز وجوب الزكاة في الحلي المعد للاستعمال.', 'citations' => ['islamweb-1'], 'support' => $support],
            ['text' => 'ويرى موقع إسلام ويب أن الراجح وجوب الزكاة في الحلي المعد للاستعمال.', 'citations' => ['islamweb-1'], 'support' => $support],
        ];

        $checked = $this->check($draft);

        $this->assertSame(['ويرى موقع إسلام ويب أن الراجح وجوب الزكاة في الحلي المعد للاستعمال.'], array_column($checked['preferences'], 'text'));
    }

    public function test_unattributed_preference_in_a_point_drops_it(): void
    {
        $draft = $this->draft(views: [
            $this->point('القول الأول', 'في الحلي المعد للاستعمال الزكاة، وهو الصحيح.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]]),
            $this->point('القول الثاني', 'الحلي المستعمل لا زكاة فيه عند جمع من أهل العلم.', ['binbaz-2'], [['fatwaId' => 'binbaz-2', 'quote' => self::BAZ_NO_ZAKAT]]),
        ]);

        // The other side, which says whose view it is, is shown alone, pointing to the fatwas for the other view.
        $checked = $this->check($draft);
        $this->assertSame(['الحلي المستعمل لا زكاة فيه عند جمع من أهل العلم.'], array_column($checked['views'], 'body'));
        $this->assertSame(GroundingCheck::NOTE_OTHER_VIEWS, $checked['note']);
    }

    public function test_a_disagreement_with_one_side_left_shows_it_only_when_it_says_whose_it_is(): void
    {
        $failing = $this->point('القول الأول', 'في الحلي المعد للاستعمال الزكاة، وهو الصحيح.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]]);
        $support = [['fatwaId' => 'binbaz-2', 'quote' => 'نصاب الذهب عشرون مثقالًا، ومقداره بالجرام اثنان وتسعون جرامًا']];
        $attributed = $this->point('القول الثاني', 'يرى الشيخ ابن باز أن نصاب الذهب عشرون مثقالًا.', ['binbaz-2'], $support);

        $checked = $this->check($this->draft(views: [$failing, $attributed]));

        $this->assertSame('differ', $checked['kind']);
        $this->assertSame(GroundingCheck::SOURCES_SAY_HEADLINE, $checked['headline']);
        $this->assertSame(['يرى الشيخ ابن باز أن نصاب الذهب عشرون مثقالًا.'], array_column($checked['views'], 'body'));
        $this->assertSame(GroundingCheck::NOTE_OTHER_VIEWS, $checked['note']);

        // A lone side that doesn't say whose it is isn't shown as the answer: with nothing else, the summary fails.
        $this->assertNull($this->check($this->draft(views: [$failing, $this->point('القول الثاني', 'نصاب الذهب عشرون مثقالًا.', ['binbaz-2'], $support)])));
    }

    public function test_an_agreement_whose_agreed_point_fails_shows_its_preferences(): void
    {
        $draft = $this->draft(agreed: $this->point('الحلي', 'في الحلي المعد للاستعمال الزكاة، وهو الصحيح.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]]), kind: 'agree');
        $draft['preferences'] = [['text' => 'ويرى موقع إسلام ويب أن الراجح وجوب الزكاة في الحلي المعد للاستعمال.', 'citations' => ['islamweb-1'], 'support' => [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]]]];

        $checked = $this->check($draft);

        $this->assertNull($checked['agreed']);
        $this->assertSame(GroundingCheck::SOURCES_SAY_HEADLINE, $checked['headline']);
        $this->assertCount(1, $checked['preferences']);
        $this->assertSame(GroundingCheck::NOTE_DETAILS, $checked['note']); // a line points to the fatwas for the details
    }

    public function test_a_dropped_item_adds_the_details_note_and_a_whole_summary_none(): void
    {
        $whole = $this->checkIds('w-9', 'الجمع للمسافر', 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير عند جمهور الفقهاء.');

        $this->assertNull($whole['note']);
        // A preference that fails is dropped: the details note points to the fatwas.
        $this->assertSame(GroundingCheck::NOTE_DETAILS, $this->check([...$this->draft(agreed: $whole['agreed'], kind: 'agree'), 'preferences' => [['text' => 'والراجح وجوب الزكاة.', 'citations' => ['w-9'], 'support' => []]]])['note']);
    }

    public function test_the_summary_fails_when_nothing_survives(): void
    {
        $failing = $this->point('القول الأول', 'في الحلي المعد للاستعمال الزكاة، وهو الصحيح.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]]);

        $this->assertNull($this->check($this->draft(views: [$failing, $failing])));
        $this->assertNull($this->check($this->draft(agreed: $failing, kind: 'agree')));
    }

    public function test_held_by_must_name_a_cited_source(): void
    {
        $point = $this->point('الحلي المعد للاستعمال', 'في الحلي المعد للاستعمال الزكاة.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]]);

        $named = $this->check($this->draft(agreed: [...$point, 'heldBy' => 'رجّحه موقع إسلام ويب'], kind: 'agree'));
        $this->assertSame('رجّحه موقع إسلام ويب', $named['agreed']['heldBy']);

        $bare = $this->check($this->draft(agreed: [...$point, 'heldBy' => 'جمهور الفقهاء'], kind: 'agree'));
        $this->assertNull($bare['agreed']['heldBy']);

        $uncited = $this->check($this->draft(agreed: [...$point, 'heldBy' => 'الشيخ ابن باز'], kind: 'agree'));
        $this->assertNull($uncited['agreed']['heldBy']);
    }

    public function test_differing_views_with_one_view_left_fail(): void
    {
        $draft = $this->draft(views: [
            $this->point('القول الأول', 'في الحلي المعد للاستعمال الزكاة.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]]),
            $this->point('القول الثاني', 'لا زكاة فيه.', ['binbaz-2'], [['fatwaId' => 'binbaz-2', 'quote' => 'نص غير موجود في الفتوى إطلاقا ولا في غيرها من الفتاوى']]),
        ]);

        $this->assertNull($this->check($draft));
    }

    public function test_ungrounded_headline_is_rebuilt_from_the_points(): void
    {
        $draft = $this->draft(agreed: $this->point('الحلي المعد للاستعمال', 'في الحلي المعد للاستعمال الزكاة.', ['islamweb-1'], [['fatwaId' => 'islamweb-1', 'quote' => self::WEB_ZAKAT]]), kind: 'agree');

        $draft['headline'] = 'يجب على كل مسلم إخراج زكاة الحلي فورا';
        $this->assertSame('الحلي المعد للاستعمال', $this->check($draft)['headline']);

        $draft['headline'] = 'في الحلي المعد للاستعمال الزكاة 70 جراما';
        $this->assertSame('الحلي المعد للاستعمال', $this->check($draft)['headline']);

        $draft['headline'] = 'تذكر الفتوى أن في الحلي المعد للاستعمال الزكاة';
        $this->assertSame('تذكر الفتوى أن في الحلي المعد للاستعمال الزكاة', $this->check($draft)['headline']);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    /**
     * An agreed point citing its fatwa's first sentence by id, resolved the way the summary resolves it.
     *
     * @return array<string, mixed>|null
     */
    /**
     * «على الصحيح» copied word for word from one source's own quote is that source's word: «عند <source>» is added
     * after it and the point passes. Not copied, or copied from two sources' quotes, it is still the site's voice.
     */
    public function test_a_preference_word_copied_from_one_sources_quote_is_attributed_to_it(): void
    {
        $copied = $this->draft(agreed: $this->point('صلاة من تخلف عن الجماعة', 'ومن صلى في بيته بلا عذر صحت صلاته مع الإثم على الصحيح.', ['w-16'], [['fatwaId' => 'w-16', 'quote' => 'ولكن من صلى في بيته بلا عذر، صحت صلاته مع الإثم على الصحيح']]), kind: 'agree');
        $checked = $this->check($copied);
        $this->assertNotNull($checked);
        $this->assertSame('ومن صلى في بيته بلا عذر صحت صلاته مع الإثم على الصحيح عند إسلام ويب.', $checked['agreed']['body']);

        // The draft's own «عند إسلام ويب» later in the sentence isn't shown a second time.
        $namedLater = $this->draft(agreed: $this->point('صلاة من تخلف عن الجماعة', 'ومن صلى في بيته بلا عذر صحت صلاته مع الإثم على الصحيح، ومن أصر على التخلف فهو على خطر عظيم عند إسلام ويب.', ['w-16'], [['fatwaId' => 'w-16', 'quote' => 'ولكن من صلى في بيته بلا عذر، صحت صلاته مع الإثم على الصحيح، ومن أصر على التخلف، فهو على خطر عظيم']]), kind: 'agree');
        $this->assertSame('ومن صلى في بيته بلا عذر صحت صلاته مع الإثم على الصحيح عند إسلام ويب، ومن أصر على التخلف فهو على خطر عظيم.', $this->check($namedLater)['agreed']['body'] ?? null);

        $notCopied = $this->draft(agreed: $this->point('صلاة من تخلف عن الجماعة', 'ومن صلى في بيته بلا عذر فالصحيح أن صلاته صحت مع الإثم.', ['w-16'], [['fatwaId' => 'w-16', 'quote' => 'ولكن من صلى في بيته بلا عذر، صحت صلاته مع الإثم على الصحيح']]), kind: 'agree');
        $this->assertNull($this->check($notCopied));

        $twoSources = $this->draft(agreed: $this->point('صلاة من تخلف عن الجماعة', 'ومن صلى في بيته بلا عذر صحت صلاته مع الإثم على الصحيح.', ['w-16', 'baz-16'], [
            ['fatwaId' => 'w-16', 'quote' => 'ولكن من صلى في بيته بلا عذر، صحت صلاته مع الإثم على الصحيح'],
            ['fatwaId' => 'baz-16', 'quote' => 'ومن صلى في بيته بلا عذر، صحت صلاته مع الإثم على الصحيح من أقوال العلماء'],
        ]), kind: 'agree');
        $this->assertNull($this->check($twoSources));
    }

    private function checkIds(string $fatwaId, string $title, string $body): ?array
    {
        $sentences = new SourceSentences;

        foreach (self::SOURCES as $id => $text) {
            $sentences->addText($id, $text);
        }

        $label = (string) $sentences->label($fatwaId);
        $point = $sentences->resolve(['title' => $title, 'heldBy' => null, 'body' => $body, 'citations' => [$label], 'evidence' => [], 'support' => ["{$label}.S1"]]);

        return $this->check($this->draft(agreed: $point, kind: 'agree'));
    }

    private function check(array $draft, string $locale = 'ar'): ?array
    {
        return (new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4))->check($draft, self::SOURCES, $locale, self::NAMES);
    }

    /**
     * @param  array<string, mixed>|null  $agreed
     * @param  list<array<string, mixed>>  $views
     * @return array<string, mixed>
     */
    private function draft(?array $agreed = null, array $views = [], string $kind = 'differ'): array
    {
        return ['kind' => $kind, 'headline' => 'خلاصة', 'headlineEn' => 'Summary', 'agreed' => $agreed, 'views' => $views, 'preferences' => [], 'caution' => null];
    }

    /**
     * @param  list<string>  $citations
     * @param  list<array{fatwaId: string, quote: string}>  $support
     * @return array<string, mixed>
     */
    private function point(string $title, string $body, array $citations, array $support): array
    {
        return ['title' => $title, 'heldBy' => null, 'body' => $body, 'citations' => $citations, 'evidence' => [], 'support' => $support];
    }
}
