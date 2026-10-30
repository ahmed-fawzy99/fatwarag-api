<?php

namespace Tests\Unit\Answers;

use App\Answers\GroundingCheck;
use PHPUnit\Framework\TestCase;

/**
 * Review of the golden-rule pruning («العهدة على المصدر»): removing a failing sentence must never make the rest say
 * what the fatwa doesn't (a condition, an exception or a back-reference cut away). The lexical checks never prune, so
 * each of these items is rejected whole; a prune the claims judge asks for shows only the citations its kept text
 * rests on.
 */
class ReviewRoundSevenTest extends TestCase
{
    private const SOURCES = [
        'x-1' => 'لا يجوز للحائض أن تمس المصحف بيدها عند بعض أهل العلم. ويجوز لها أن تقرأ القرآن من حفظها، وهذا قول جمهور أهل العلم.',
        'x-2' => 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها. وذلك بإذن زوجها ورضاه.',
        'x-3' => 'يجوز للحائض أن تقرأ القرآن من حفظها عند الحاجة. ويستثنى من ذلك الجنب فلا يجوز له أن يقرأ القرآن.',
        'x-4' => 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير عند جمهور الفقهاء.',
        'y-5' => 'لا يجوز بيع الذهب بالذهب إلا مثلا بمثل يدا بيد عند جميع أهل العلم.',
    ];

    private const NAMES = ['x-1' => ['إسلام ويب'], 'x-2' => ['إسلام ويب'], 'x-3' => ['إسلام ويب'], 'x-4' => ['إسلام ويب'], 'y-5' => ['ابن باز']];

    private const TRAVEL = 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير عند جمهور الفقهاء';

    private const GOLD = 'لا يجوز بيع الذهب بالذهب إلا مثلا بمثل يدا بيد عند جميع أهل العلم';

    public function test_a_condition_in_a_sentence_of_its_own_is_never_pruned_away(): void
    {
        $this->assertNull($this->agreed('خروج المرأة لزيارة أهلها', 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها. وذلك بإذن زوجها ورضاه ثلاث مرات.', [
            ['fatwaId' => 'x-2', 'quote' => 'يجوز للمرأة أن تخرج من بيتها لزيارة أهلها ووالديها'],
            ['fatwaId' => 'x-2', 'quote' => 'وذلك بإذن زوجها ورضاه'],
        ]));
    }

    public function test_a_back_reference_never_loses_the_sentence_it_points_to(): void
    {
        // «وهذا قول جمهور أهل العلم» would point at the first sentence, which the fatwa gives as some scholars' view.
        $this->assertNull($this->agreed('مس المصحف وقراءة القرآن للحائض', 'لا يجوز للحائض أن تمس المصحف بيدها. ويجوز لها أن تقرأ القرآن من حفظها مرتين. وهذا قول جمهور أهل العلم.', [
            ['fatwaId' => 'x-1', 'quote' => 'لا يجوز للحائض أن تمس المصحف بيدها عند بعض أهل العلم'],
            ['fatwaId' => 'x-1', 'quote' => 'ويجوز لها أن تقرأ القرآن من حفظها، وهذا قول جمهور أهل العلم'],
        ]));
    }

    public function test_a_clause_after_a_semicolon_stays_with_its_sentence(): void
    {
        $this->assertSame(['يجوز للمسافر أن يجمع؛ إذا جد به السير.'], GroundingCheck::sentences('يجوز للمسافر أن يجمع؛ إذا جد به السير.'));
        $this->assertNull($this->agreed('الجمع للمسافر', 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما عند جمهور الفقهاء؛ إذا جد به السير مسافة ثلاثين ميلا.', [
            ['fatwaId' => 'x-4', 'quote' => self::TRAVEL],
        ]));
    }

    public function test_an_exception_sentence_is_never_pruned_away(): void
    {
        $this->assertNull($this->agreed('قراءة الحائض', 'يجوز للحائض أن تقرأ القرآن من حفظها عند الحاجة. ويستثنى من ذلك الجنب فلا يجوز له أن يقرأ القرآن خمس مرات.', [
            ['fatwaId' => 'x-3', 'quote' => 'يجوز للحائض أن تقرأ القرآن من حفظها عند الحاجة'],
            ['fatwaId' => 'x-3', 'quote' => 'ويستثنى من ذلك الجنب فلا يجوز له أن يقرأ القرآن'],
        ]));
    }

    public function test_a_point_pruned_by_the_judge_shows_only_the_citations_its_kept_text_rests_on(): void
    {
        $check = $this->checker();
        $passed = $check->item('agreed', $this->point('الجمع للمسافر', self::TRAVEL.'. لا يجوز بيع الذهب بالذهب إلا مثلا بمثل يدا بيد.', [
            ['fatwaId' => 'x-4', 'quote' => self::TRAVEL],
            ['fatwaId' => 'y-5', 'quote' => self::GOLD],
        ]));

        $pruned = $check->prune('agreed', $passed, [2]);

        $this->assertSame(self::TRAVEL.'.', $pruned['body']);
        $this->assertSame(['x-4'], $pruned['citations']);
        $this->assertSame(['لا يجوز بيع الذهب بالذهب إلا مثلا بمثل يدا بيد.'], $pruned['_removed']);
        // The checks still read the whole support.
        $this->assertSame(['x-4', 'y-5'], array_column($pruned['support'], 'fatwaId'));
    }

    public function test_a_judge_prune_that_would_leave_nothing_meaningful_drops_the_point(): void
    {
        $check = $this->checker();
        $passed = $check->item('agreed', $this->point('الجمع للمسافر', self::TRAVEL.'. لا يجوز بيع الذهب بالذهب إلا مثلا بمثل يدا بيد.', [
            ['fatwaId' => 'x-4', 'quote' => self::TRAVEL],
            ['fatwaId' => 'y-5', 'quote' => self::GOLD],
        ]));

        // The lead sentence carries the point's ruling and the title states none; and the rest is under half the text.
        $this->assertNull($check->prune('agreed', $passed, [1]));
        $this->assertNull($check->prune('agreed', $passed, [1, 2]));
    }

    public function test_the_lexical_checks_never_prune(): void
    {
        // One sentence fails (a number its support lacks): the whole item fails, even though the rest would pass.
        $this->assertNull($this->agreed('الجمع للمسافر', self::TRAVEL.'. ويصلي ثلاثين ركعة.', [['fatwaId' => 'x-4', 'quote' => self::TRAVEL]]));
        $this->assertNotNull($this->agreed('الجمع للمسافر', self::TRAVEL.'.', [['fatwaId' => 'x-4', 'quote' => self::TRAVEL]]));
    }

    /**
     * @param  list<array{fatwaId: string, quote: string}>  $support
     * @return array<string, mixed>|null the agreed point as shown, or null when the summary fails
     */
    private function agreed(string $title, string $body, array $support): ?array
    {
        $draft = ['kind' => 'agree', 'headline' => 'خلاصة', 'agreed' => $this->point($title, $body, $support), 'views' => [], 'preferences' => [], 'caution' => null];

        return (new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4))->check($draft, self::SOURCES, 'ar', self::NAMES)['agreed'] ?? null;
    }

    /**
     * @param  list<array{fatwaId: string, quote: string}>  $support
     * @return array<string, mixed>
     */
    private function point(string $title, string $body, array $support): array
    {
        return ['title' => $title, 'heldBy' => null, 'body' => $body, 'citations' => array_values(array_unique(array_column($support, 'fatwaId'))), 'evidence' => [], 'support' => $support];
    }

    private function checker(): GroundingCheck
    {
        $check = new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4);
        $check->prepare(self::SOURCES, self::NAMES);

        return $check;
    }
}
