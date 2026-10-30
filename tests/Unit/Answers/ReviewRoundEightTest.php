<?php

namespace Tests\Unit\Answers;

use App\Answers\GroundingCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Re-review of the golden-rule pruning: conditions, restrictions and references phrased in ways no word list names
 * («ويشترط لذلك», «ويكره له ذلك بعد الزوال», «وقال به جمهور أهل العلم», «وهذا في الفريضة دون النافلة»). The lexical
 * checks never prune, so every one of these items is rejected whole. A prune only follows the claims judge, and the
 * prune is judged again for what it left out (PruneJudge, covered in SummaryWriterTest); here, what the judge-path
 * prune hands that check, and which citations it shows.
 */
class ReviewRoundEightTest extends TestCase
{
    private const SOURCES = [
        'a-1' => 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها. ويشترط لذلك إذن زوجها ورضاه.',
        'a-2' => 'يجوز للصائم أن يستعمل السواك في نهار رمضان. ويكره له ذلك بعد الزوال.',
        'a-3' => 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها في حال إذن زوجها ورضاه.',
        'a-4' => 'يجوز للمرأة أن تخرج من بيتها للعمل المباح بشرط إذن زوجها ورضاه.',
        'b-1' => 'لا يجوز للحائض أن تمس المصحف بيدها عند بعض أهل العلم. ويجوز لها أن تقرأ القرآن من حفظها، وعلى هذا جمهور أهل العلم.',
        'b-2' => 'لا يجوز للحائض أن تمس المصحف بيدها عند بعض أهل العلم. ويجوز لها أن تقرأ القرآن من حفظها، وقال به جمهور أهل العلم.',
        'b-3' => 'لا يجوز للحائض أن تمس المصحف بيدها عند بعض أهل العلم. ويجوز لها أن تقرأ القرآن من حفظها، فهذا قول جمهور أهل العلم.',
        'b-4' => 'لا يجوز للحائض أن تمس المصحف بيدها عند بعض أهل العلم. ويجوز لها أن تقرأ القرآن من حفظها، وعليه جمهور أهل العلم.',
        'b-5' => 'لا يجوز للحائض أن تمس المصحف بيدها عند بعض أهل العلم. ويجوز لها أن تقرأ القرآن من حفظها، والجمهور على ذلك من أهل العلم.',
        'c-1' => 'يجوز للمرأة أن تصوم الاثنين والخميس تطوعا في بيتها. ويجوز لها أن تخرج من بيتها لزيارة والديها. ويشترط لذلك إذن زوجها ورضاه.',
        'c-2' => 'يجب على المصلي أن يستقبل القبلة في صلاته. وهذا في الفريضة دون النافلة للمسافر الراكب على دابته.',
        'c-3' => 'يجب على المصلي أن يستقبل القبلة بوجهه وبدنه في صلاته في الحضر والسفر. وهذا في الفريضة دون النافلة للمسافر الراكب.',
        'x-2' => 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها. وذلك بإذن زوجها ورضاه.',
        'd-1' => 'يجوز المسح على الخفين للمقيم يوما وليلة عند جميع أهل العلم.',
        'y-5' => 'لا يجوز بيع الذهب بالذهب إلا مثلا بمثل يدا بيد عند جميع أهل العلم.',
        'x-4' => 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير عند جمهور الفقهاء.',
    ];

    private const GOLD = 'لا يجوز بيع الذهب بالذهب إلا مثلا بمثل يدا بيد عند جميع أهل العلم';

    /**
     * @return array<string, array{0: string, 1: string, 2: list<array{0: string, 1: string}>}>
     */
    public static function failingSentenceCases(): array
    {
        $cases = [
            'A1 «ويشترط لذلك» condition' => ['خروج المرأة لزيارة أهلها', 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها. ويشترط لذلك إذن زوجها ورضاه ثلاث مرات.', [['a-1', 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها'], ['a-1', 'ويشترط لذلك إذن زوجها ورضاه']]],
            'A2 «ويكره له ذلك بعد الزوال» restriction' => ['السواك للصائم', 'يجوز للصائم أن يستعمل السواك في نهار رمضان. ويكره له ذلك بعد الزوال مرتين.', [['a-2', 'يجوز للصائم أن يستعمل السواك في نهار رمضان'], ['a-2', 'ويكره له ذلك بعد الزوال']]],
            'A3 «في حال» with another fatwa\'s failing sentence' => ['خروج المرأة لزيارة أهلها', 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها في حال إذن زوجها ورضاه. ويجوز لها أن تخرج للعمل المباح سبع مرات.', [['a-3', 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها في حال إذن زوجها ورضاه'], ['a-4', 'يجوز للمرأة أن تخرج من بيتها للعمل المباح بشرط إذن زوجها ورضاه']]],
            'A4 «بشرط» inside the failing sentence' => ['خروج المرأة', 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها في حال إذن زوجها ورضاه. ويجوز لها أن تخرج للعمل المباح سبع مرات بشرط إذن زوجها.', [['a-3', 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها في حال إذن زوجها ورضاه'], ['a-4', 'يجوز للمرأة أن تخرج من بيتها للعمل المباح بشرط إذن زوجها ورضاه']]],
            'C1 a condition that would re-attach to another ruling' => ['ما يجوز للمرأة', 'يجوز للمرأة أن تصوم الاثنين والخميس تطوعا في بيتها. ويجوز لها أن تخرج من بيتها لزيارة والديها ثلاث مرات. ويشترط لذلك إذن زوجها ورضاه.', [['c-1', 'يجوز للمرأة أن تصوم الاثنين والخميس تطوعا في بيتها'], ['c-1', 'ويجوز لها أن تخرج من بيتها لزيارة والديها'], ['c-1', 'ويشترط لذلك إذن زوجها ورضاه']]],
            'C2 «وهذا في الفريضة دون النافلة» restriction' => ['استقبال القبلة', 'يجب على المصلي أن يستقبل القبلة في صلاته. وهذا في الفريضة دون النافلة للمسافر الراكب على دابته مسافة ثلاثين ميلا.', [['c-2', 'يجب على المصلي أن يستقبل القبلة في صلاته'], ['c-2', 'وهذا في الفريضة دون النافلة للمسافر الراكب على دابته']]],
            'C3 the same after a long lead' => ['استقبال القبلة', 'يجب على المصلي أن يستقبل القبلة بوجهه وبدنه في صلاته في الحضر والسفر. وهذا في الفريضة دون النافلة للمسافر الراكب مسافة ثلاثين ميلا.', [['c-3', 'يجب على المصلي أن يستقبل القبلة بوجهه وبدنه في صلاته في الحضر والسفر'], ['c-3', 'وهذا في الفريضة دون النافلة للمسافر الراكب']]],
            'D1 a gold sentence beside a stock phrase' => ['المسح على الخفين', 'يجوز المسح على الخفين للمقيم يوما وليلة عند جميع أهل العلم. ولا يجوز بيع الذهب بالذهب مثلا بمثل سبع مرات.', [['d-1', 'يجوز المسح على الخفين للمقيم يوما وليلة عند جميع أهل العلم'], ['y-5', self::GOLD]]],
            'D2 a title naming the removed topic' => ['الجمع للمسافر وبيع الذهب بالذهب', 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير عند جمهور الفقهاء. ولا يجوز بيع الذهب بالذهب مثلا بمثل سبع مرات.', [['x-4', 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير عند جمهور الفقهاء'], ['y-5', self::GOLD]]],
        ];

        foreach (['b-1' => 'وعلى هذا جمهور أهل العلم', 'b-2' => 'وقال به جمهور أهل العلم', 'b-3' => 'فهذا قول جمهور أهل العلم', 'b-4' => 'وعليه جمهور أهل العلم', 'b-5' => 'والجمهور على ذلك من أهل العلم'] as $id => $tail) {
            $cases["B {$id} «{$tail}»"] = ['مس المصحف وقراءة القرآن للحائض', "لا يجوز للحائض أن تمس المصحف بيدها. ويجوز لها أن تقرأ القرآن من حفظها مرتين. {$tail}.", [[$id, 'لا يجوز للحائض أن تمس المصحف بيدها عند بعض أهل العلم'], [$id, 'ويجوز لها أن تقرأ القرآن من حفظها، '.$tail]]];
        }

        return $cases;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $support
     */
    #[DataProvider('failingSentenceCases')]
    public function test_an_item_with_a_failing_sentence_is_rejected_whole_never_pruned(string $title, string $body, array $support): void
    {
        $check = $this->checker();

        $this->assertNull($check->item('agreed', $this->point($title, $body, $support)));
        $this->assertNotSame([], $check->rejections());
    }

    public function test_a_judge_prune_of_a_condition_sentence_hands_the_removed_sentence_to_the_omission_check(): void
    {
        $check = $this->checker();
        $passed = $check->item('agreed', $this->point('خروج المرأة لزيارة أهلها', 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها. وذلك بإذن زوجها ورضاه.', [['x-2', 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها'], ['x-2', 'وذلك بإذن زوجها ورضاه']]));

        // Lexically the bare permission still reads as supported: only the omission check (PruneJudge) can tell
        // that the condition went with the removed sentence, so the removed sentence travels with the prune.
        $pruned = $check->prune('agreed', $passed, [2]);

        $this->assertSame(['وذلك بإذن زوجها ورضاه.'], $pruned['_removed']);
    }

    public function test_a_judge_prune_never_keeps_a_citation_through_a_stock_phrase_or_the_title(): void
    {
        $check = $this->checker();

        $d1 = $check->item('agreed', $this->point('المسح على الخفين', 'يجوز المسح على الخفين للمقيم يوما وليلة عند جميع أهل العلم. ولا يجوز بيع الذهب بالذهب إلا مثلا بمثل يدا بيد.', [['d-1', 'يجوز المسح على الخفين للمقيم يوما وليلة عند جميع أهل العلم'], ['y-5', self::GOLD]]));
        $this->assertSame(['d-1'], $check->prune('agreed', $d1, [2])['citations']);

        $d2 = $check->item('agreed', $this->point('الجمع للمسافر وبيع الذهب بالذهب', 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير عند جمهور الفقهاء. ولا يجوز بيع الذهب بالذهب إلا مثلا بمثل يدا بيد.', [['x-4', 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير عند جمهور الفقهاء'], ['y-5', self::GOLD]]));
        $pruned = $check->prune('agreed', $d2, [2]);
        $this->assertSame(['x-4'], $pruned['citations']);
        // The title named the removed topic: it gives way to the neutral label.
        $this->assertSame('ما اتفقت عليه المصادر', GroundingCheck::labelled($pruned, null)['title']);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $support
     * @return array<string, mixed>
     */
    private function point(string $title, string $body, array $support): array
    {
        $support = array_map(fn (array $quote): array => ['fatwaId' => $quote[0], 'quote' => $quote[1]], $support);

        return ['title' => $title, 'heldBy' => null, 'body' => $body, 'citations' => array_values(array_unique(array_column($support, 'fatwaId'))), 'evidence' => [], 'support' => $support];
    }

    private function checker(): GroundingCheck
    {
        $check = new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4);
        $check->prepare(self::SOURCES, array_map(fn (): array => ['إسلام ويب'], self::SOURCES));

        return $check;
    }
}
