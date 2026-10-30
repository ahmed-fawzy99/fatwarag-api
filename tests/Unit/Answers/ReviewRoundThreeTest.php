<?php

namespace Tests\Unit\Answers;

use App\Answers\GroundingCheck;
use App\Answers\SourceSentences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review fix round 3 (scratchpad/review3) and the claims-judge decision (scratchpad/judgeonly): reported views at any
 * distance in a paragraph or contradicted anywhere later, majority views the fatwa adopts, and conditions, cases and
 * comparatives a ruling must keep now that no judge reads the items. Honest controls with exceptions and two cases
 * pass.
 */
class ReviewRoundThreeTest extends TestCase
{
    private const SOURCES = [
        'b4a' => "ذهب بعض أهل العلم إلى جواز الجمع للمسافر النازل. وهذه المسألة من المسائل المهمة. وهي مما يكثر السؤال عنه في هذا الزمان. ويحتاج إليها كثير من الناس في أسفارهم. وعليه فإنه يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما ولو كان نازلا.\nوالراجح عندنا خلاف ذلك، والله أعلم.",
        'b4b' => 'ذهب بعض أهل العلم إلى جواز الجمع للمسافر النازل. وهذه المسألة من المسائل المهمة. وهي مما يكثر السؤال عنه في هذا الزمان. ويحتاج إليها كثير من الناس في أسفارهم. وعليه فإنه يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما ولو كان نازلا. وهذه المسألة مهمة جدا. ويحتاج إليها الناس كثيرا. والراجح عندنا خلاف ذلك.',
        'sw' => "الذهب المعد للتجارة تجب فيه الزكاة إذا بلغ النصاب وحال عليه الحول.\nأما الذهب الملبوس فلا زكاة فيه على الصحيح.",
        'cA' => 'يجوز للمرأة الحائض أن تقرأ القرآن من حفظها من غير أن تمس المصحف. ولا يجوز للمرأة الحائض أن تمس المصحف بيدها مباشرة.',
        'cB' => 'إذا نوى المسافر الإقامة أربعة أيام فأكثر وجب عليه الإتمام، وإذا نوى الإقامة أقل من أربعة أيام فلا يجب عليه الإتمام.',
        'cD' => 'يجوز للمرأة أن تلبس الذهب المحلق، ولا يجوز للمرأة أن تلبس الذهب إذا كان على هيئة الصلبان.',
        'q' => 'يجوز للمسافر أن يقصر الصلاة الرباعية في سفره. ولا يجوز للمسافر إذا أقام في البلد أكثر من أربعة أيام أن يقصر الصلاة الرباعية عند الجمهور.',
        'j1' => 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.',
        'j2' => 'يجوز للمرأة الحائض أن تقرأ القرآن من حفظها عند خوف النسيان.',
        'j6' => 'يجوز للمرأة أن تخرج من بيتها لحاجتها بإذن زوجها.',
        'j8' => 'تصح صلاة المأموم خلف الإمام إذا كان الإمام يقرأ الفاتحة قراءة صحيحة.',
        'j4' => 'إذا نوى المسافر الإقامة أربعة أيام فأكثر وجب عليه الإتمام.',
        'j5' => 'يستحب للمسافر أن يصلي السنن الرواتب إلا سنة الفجر والوتر فإنه يحافظ عليهما.',
    ];

    private const JOIN = 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما ولو كان نازلا.';

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3?: string|null}>
     */
    public static function rejected(): array
    {
        $worn = 'الذهب الملبوس تجب فيه الزكاة إذا بلغ النصاب وحال عليه الحول.';

        return [
            'report four sentences back, own view in the next paragraph' => ['agreed', self::JOIN, ['b4a.S5']],
            'same, as a view held by the source' => ['view', self::JOIN, ['b4a.S5'], 'إسلام ويب'],
            'report four back, own view three sentences after' => ['agreed', self::JOIN, ['b4b.S5']],
            'worn gold from the trade-gold sentence' => ['agreed', $worn, ['sw.S1']],
            'same, held by Ibn Baz' => ['view', $worn, ['sw.S1'], 'ابن باز'],
            'condition dropped («إذا جد به السير»)' => ['agreed', 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما.', ['j1.S1']],
            'specific case generalised («عند خوف النسيان»)' => ['agreed', 'يجوز للمرأة الحائض أن تقرأ القرآن من حفظها.', ['j2.S1']],
            'condition dropped («بإذن زوجها»)' => ['agreed', 'يجوز للمرأة أن تخرج من بيتها لحاجتها.', ['j6.S1']],
            'case flipped («أقل من» for «فأكثر»)' => ['agreed', 'إذا نوى المسافر الإقامة أقل من أربعة أيام وجب عليه الإتمام.', ['j4.S1']],
            'condition negated («لا يقرأ»)' => ['agreed', 'تصح صلاة المأموم خلف الإمام إذا كان الإمام لا يقرأ الفاتحة قراءة صحيحة.', ['j8.S1']],
            'exception made the rule' => ['agreed', 'يستحب للمسافر أن يصلي سنة الفجر والوتر فقط دون غيرهما.', ['j5.S1']],
        ];
    }

    /**
     * @param  list<string>  $support
     */
    #[DataProvider('rejected')]
    public function test_rejected(string $section, string $body, array $support, ?string $heldBy = null): void
    {
        $this->assertNull($this->check($section, $body, $support, $heldBy));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function honest(): array
    {
        return [
            'ruling with its exception elsewhere' => ['يجوز للمرأة الحائض أن تقرأ القرآن من حفظها من غير أن تمس المصحف.', ['cA.S1', 'cA.S2']],
            'one of two cases' => ['إذا نوى المسافر الإقامة أقل من أربعة أيام فلا يجب عليه الإتمام.', ['cB.S1']],
            'the other case' => ['إذا نوى المسافر الإقامة أربعة أيام فأكثر وجب عليه الإتمام.', ['cB.S1']],
            'permitted beside a forbidden exception in its sentence' => ['يجوز للمرأة أن تلبس الذهب المحلق.', ['cD.S1']],
            'majority view the fatwa adopts («عند الجمهور»)' => ['يجوز للمسافر أن يقصر الصلاة الرباعية في سفره، ولا يجوز له القصر إذا أقام أكثر من أربعة أيام.', ['q.S1', 'q.S2']],
            'condition kept' => ['يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.', ['j1.S1']],
        ];
    }

    /**
     * @param  list<string>  $support
     */
    #[DataProvider('honest')]
    public function test_honest_passes(string $body, array $support): void
    {
        $this->assertNotNull($this->check('agreed', $body, $support));
    }

    /**
     * @param  list<string>  $support  «key.Sn»
     * @return array<string, mixed>|null
     */
    private function check(string $section, string $body, array $support, ?string $heldBy = null): ?array
    {
        $sentences = new SourceSentences;

        foreach (self::SOURCES as $id => $text) {
            $sentences->addText($id, $text);
        }

        $ids = array_map(function (string $ref) use ($sentences): string {
            [$key, $number] = explode('.', $ref);

            return $sentences->label($key).'.'.$number;
        }, $support);
        $labels = array_values(array_unique(array_map(fn (string $id): string => explode('.', $id)[0], $ids)));
        $names = array_fill_keys(array_keys(self::SOURCES), ['إسلام ويب']);
        $names['sw'] = ['ابن باز'];

        $check = new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4);
        $check->prepare(self::SOURCES, $names);

        return $check->item($section, $sentences->resolve(['title' => 'الحكم', 'heldBy' => $heldBy, 'body' => $body, 'citations' => $labels, 'evidence' => [], 'support' => $ids]));
    }
}
