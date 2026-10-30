<?php

namespace Tests\Unit\Answers;

use App\Answers\GroundingCheck;
use App\Answers\SourceSentences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review fix round 2 (scratchpad/review2/r2.php, r40.php): items citing sentence ids, checked the way the summary
 * checks them: reported views found up to three sentences back or by the fatwa's own contrary view after, names
 * attached only through a sentence carrying the same ruling on the same subject, and a cited fatwa's opposite ruling
 * elsewhere.
 */
class ReviewRoundTwoTest extends TestCase
{
    private const SOURCES = [
        'iw-1' => "الحمد لله، أما بعد: فإن الحلي المعد للاستعمال تجب فيه الزكاة إذا بلغ النصاب وحال عليه الحول، وهذا قول الحنفية.\nونصاب الذهب خمسة وثمانون جراما من الذهب الخالص تقريبا.\nويجب على المرأة أن تخرج ربع العشر من قيمة الحلي كل عام.\nوالله أعلم.",
        'bz-2' => "الحلي المعد للاستعمال لا تجب فيه الزكاة في أصح قولي العلماء لأنه كالثياب والمتاع.\nأما الحلي المعد للتجارة أو الادخار فتجب فيه الزكاة إذا بلغ النصاب وحال عليه الحول.\nويجوز للمرأة أن تلبس الذهب المحلق وغير المحلق.",
        'w-3' => "اختلف العلماء في الجمع بين الصلاتين للمسافر.\nفذهب بعض أهل العلم إلى أنه يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما ولو كان نازلا.\nوالصحيح أنه لا يجمع إلا إذا جد به السير، والله أعلم.",
        'h-4' => "لا يجب على الحائض قضاء الصلاة التي فاتتها أيام حيضها بإجماع أهل العلم.\nويجب عليها قضاء الصوم الذي أفطرته في رمضان بعد طهرها.\nولا يجوز لها أن تصوم أو تصلي حال الحيض.",
        'w-11' => 'ذكر بعض الفقهاء في هذه المسألة أقوالا متعددة. وقد بسطها أهل العلم في كتبهم المعروفة قديما وحديثا. وأشهرها: يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما ولو كان نازلا. والراجح عندنا خلاف ذلك.',
        'w-12' => 'ذكر بعض الفقهاء في هذه المسألة أقوالا متعددة. وقد بسطها أهل العلم في كتبهم المعروفة قديما وحديثا. وأشهرها: يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما ولو كان نازلا. والراجح عندنا أنه لا يجوز للمسافر الجمع إلا إذا جد به السير.',
        'w-13' => 'ذهب بعض أهل العلم إلى جواز الجمع للمسافر النازل. ودليلهم أنه يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما ولو كان نازلا. والراجح عندنا خلاف ذلك.',
        'z-9' => "يجب على المرأة أن تخرج ربع العشر من قيمة الحلي كل عام إذا بلغ النصاب.\nوالله أعلم.",
        'w-14' => 'ذهب بعض أهل العلم إلى منع الجمع للمسافر النازل. والصحيح أنه يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما ولو كان نازلا.',
    ];

    private const NAMES = ['iw-1' => ['إسلام ويب'], 'bz-2' => ['ابن باز'], 'w-3' => ['إسلام ويب'], 'h-4' => ['إسلام ويب'], 'w-11' => ['إسلام ويب'], 'w-12' => ['إسلام ويب'], 'w-13' => ['إسلام ويب'], 'z-9' => ['إسلام ويب'], 'w-14' => ['إسلام ويب']];

    private const ZAKAT = 'الحلي المعد للاستعمال تجب فيه الزكاة إذا بلغ النصاب وحال عليه الحول.';

    private const JOIN = 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما ولو كان نازلا.';

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function attacks(): array
    {
        $point = fn (string $body, array $citations, array $support, ?string $heldBy = null): array => ['title' => 'الحكم', 'heldBy' => $heldBy, 'body' => $body, 'citations' => $citations, 'evidence' => [], 'support' => $support];
        $preference = fn (string $text, array $citations, array $support): array => ['text' => $text, 'citations' => $citations, 'evidence' => [], 'support' => $support];

        return [
            'heldBy Ibn Baz on another fatwa\'s ruling plus his trade sentence' => ['view', $point(self::ZAKAT, ['F1', 'F2'], ['F1.S1', 'F2.S2'], 'ابن باز')],
            'preference Ibn Baz, same padding' => ['preference', $preference('ويرى ابن باز أن '.self::ZAKAT, ['F1', 'F2'], ['F1.S1', 'F2.S2'])],
            'object swap: trade sentence for jewellery in use' => ['view', $point(self::ZAKAT, ['F2'], ['F2.S2'], 'ابن باز')],
            'inverted via other sentences of the fatwa' => ['agreed', $point('يجب على الحائض قضاء الصلاة التي فاتتها بعد طهرها.', ['F4'], ['F4.S2', 'F4.S3'])],
            'trade and use mixed' => ['agreed', $point('تجب الزكاة في الحلي المعد للاستعمال والادخار إذا بلغ النصاب وحال عليه الحول.', ['F1', 'F2'], ['F1.S2', 'F2.S2', 'F2.S3', 'F1.S3'])],
            'every month for every year' => ['agreed', $point('يجب على المرأة أن تخرج ربع العشر من قيمة الحلي كل شهر إذا بلغ النصاب.', ['F8'], ['F8.S1'])],
            'reported view in its own sentence' => ['agreed', $point(self::JOIN, ['F3'], ['F3.S2'])],
            'marker two sentences back, «خلاف ذلك» after' => ['agreed', $point(self::JOIN, ['F5'], ['F5.S3'])],
            'marker two sentences back, as a view held by the source' => ['view', $point(self::JOIN, ['F5'], ['F5.S3'], 'إسلام ويب')],
            'marker two sentences back, contrary ruling after' => ['agreed', $point(self::JOIN, ['F6'], ['F6.S3'])],
            'marker one sentence back ending in «.»' => ['agreed', $point(self::JOIN, ['F7'], ['F7.S2'])],
            'same, as the source\'s preference' => ['preference', $preference('ويرى إسلام ويب أنه '.self::JOIN, ['F7'], ['F7.S2'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    #[DataProvider('attacks')]
    public function test_attack_is_rejected(string $section, array $item): void
    {
        $this->assertNull($this->check($section, $item));
    }

    public function test_the_fatwas_own_view_after_a_reported_one_still_counts(): void
    {
        $checked = $this->check('view', ['title' => 'الجمع للمسافر', 'heldBy' => 'إسلام ويب', 'body' => self::JOIN, 'citations' => ['F9'], 'evidence' => [], 'support' => ['F9.S2']]);

        $this->assertNotNull($checked);
        $this->assertSame('إسلام ويب', $checked['heldBy']);
        $this->assertNotNull($this->check('agreed', ['title' => 'الزكاة', 'heldBy' => null, 'body' => 'يجب على المرأة أن تخرج ربع العشر من قيمة الحلي كل عام إذا بلغ النصاب.', 'citations' => ['F8'], 'evidence' => [], 'support' => ['F8.S1']]));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function check(string $section, array $item): ?array
    {
        $sentences = new SourceSentences;

        foreach (['iw-1', 'bz-2', 'w-3', 'h-4', 'w-11', 'w-12', 'w-13', 'z-9', 'w-14'] as $id) {
            $sentences->addText($id, self::SOURCES[$id]);
        }

        $check = new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4);
        $check->prepare(self::SOURCES, self::NAMES);

        return $check->item($section, $sentences->resolve($item));
    }
}
