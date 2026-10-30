<?php

namespace Tests\Unit\Answers;

use App\Answers\GroundingCheck;
use App\Answers\SourceSentences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review fix round 5 (scratchpad/rv5): rejected by the lexical checks alone. «لكن», «ولكن», «وإنما», «ثم», «بل» open
 * a new ruling's clause; a subject before its verb after «و» («وخل العنب يجوز») binds to that verb; «في حق X» names
 * the party; a ruling's core subject named only by a clause with a different ruling fails whatever qualifiers are
 * copied. A subject fronted before two rulings applies to both, with the party deciding which ruling.
 */
class ReviewRoundFiveTest extends TestCase
{
    private const SOURCES = [
        'a1' => 'لا يجوز للحائض مس المصحف بيدها مباشرة لكن يجوز لها القراءة من حفظها عند الحاجة.',
        'a2' => 'لا يجوز للحائض مس المصحف بيدها مباشرة ولكن يجوز لها القراءة من حفظها عند الحاجة.',
        'a3' => 'يحرم التدخين ثم يجوز شرب القهوة باعتدال لمن احتاج إليها.',
        'a4' => 'يحرم بيع الخمر بل يجوز بيع خل العنب لمن أراد التجارة فيه.',
        'b1' => 'الذهب الملبوس يحرم على الرجال ويباح للنساء بإجماع أهل العلم.',
        'b2' => 'الخمر يحرم بيعها وشراؤها وخل العنب يجوز بيعه وشراؤه للتجارة والأكل.',
        'b4' => 'خاتم الذهب يحرم على الرجال وخاتم الفضة يجوز لهم لبسه بلا خلاف بين أهل العلم.',
        'c1' => 'يجب في حق الولي إخراج زكاة مال الصبي إذا بلغ النصاب وحال عليه الحول.',
        'c2' => 'لا يجب الصوم في حق الصبي الذي لم يبلغ وإنما يجب في حق البالغ العاقل القادر.',
        'n2' => 'يحرم التدخين لما فيه من الضرر على البدن والمال، ويجوز شرب القهوة باعتدال لمن احتاج إليها.',
        'h3' => 'يجوز للمسافر أن يفطر في رمضان لكن يجب عليه القضاء بعد رمضان قبل أن يدخل رمضان التالي.',
    ];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejected(): array
    {
        return [
            'C1 «لكن»: forbidden made permitted' => ['يجوز للحائض مس المصحف بيدها مباشرة.', 'a1'],
            'C1 «ولكن»: forbidden made permitted' => ['يجوز للحائض مس المصحف بيدها مباشرة.', 'a2'],
            'C1 «ثم»: smoking permitted' => ['يجوز التدخين لمن احتاج إليه.', 'a3'],
            'C1 «بل»: selling wine permitted' => ['يجوز بيع الخمر لمن أراد التجارة فيه.', 'a4'],
            'C1b gold permitted to men' => ['يباح الذهب الملبوس للرجال.', 'b1'],
            'C1b vinegar sale forbidden' => ['يحرم بيع خل العنب وشراؤه.', 'b2'],
            'C1b gold ring permitted to men' => ['يجوز للرجال لبس خاتم الذهب.', 'b4'],
            'C2 duty moved from the guardian to the boy' => ['يجب على الصبي إخراج زكاة ماله إذا بلغ النصاب وحال عليه الحول.', 'c1'],
            'C2 «في حق» moved' => ['يجب في حق الصبي إخراج زكاة مال الولي إذا بلغ النصاب وحال عليه الحول.', 'c1'],
            'C2 «في حق» with «وإنما»' => ['يجب الصوم على الصبي الذي لم يبلغ.', 'c2'],
            'C3 bare' => ['يجوز التدخين.', 'n2'],
            'C3 one copied qualifier' => ['يجوز التدخين باعتدال.', 'n2'],
            'C3 another copied qualifier' => ['يجوز التدخين لمن احتاج إليه.', 'n2'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_rejected_lexically(string $body, string $source): void
    {
        $this->assertNull($this->check($body, $source));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function honest(): array
    {
        return [
            'the «لكن» half' => ['يجوز للحائض القراءة من حفظها عند الحاجة.', 'a1'],
            'fronted subject, women' => ['يباح الذهب الملبوس للنساء.', 'b1'],
            'fronted subject, men' => ['يحرم الذهب الملبوس على الرجال.', 'b1'],
            'subject after «و»' => ['يجوز بيع خل العنب وشراؤه للتجارة.', 'b2'],
            'subject before its verb' => ['يحرم بيع الخمر وشراؤها.', 'b2'],
            'party by pronoun after «و»' => ['يجوز للرجال لبس خاتم الفضة بلا خلاف بين أهل العلم.', 'b4'],
            '«في حق» kept' => ['يجب في حق الولي إخراج زكاة مال الصبي إذا بلغ النصاب وحال عليه الحول.', 'c1'],
            '«على» for «في حق»' => ['يجب على الولي إخراج زكاة مال الصبي إذا بلغ النصاب وحال عليه الحول.', 'c1'],
            '«وإنما» clause with its subject elided' => ['يجب الصوم في حق البالغ العاقل القادر.', 'c2'],
            '«لكن» clause with its party by pronoun' => ['يجب على المسافر الذي أفطر في رمضان القضاء بعد رمضان.', 'h3'],
        ];
    }

    #[DataProvider('honest')]
    public function test_honest_passes(string $body, string $source): void
    {
        $this->assertNotNull($this->check($body, $source));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function check(string $body, string $source): ?array
    {
        $sentences = new SourceSentences;

        foreach (self::SOURCES as $id => $text) {
            $sentences->addText($id, $text);
        }

        $label = $sentences->label($source);
        $check = new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4);
        $check->prepare(self::SOURCES, array_fill_keys(array_keys(self::SOURCES), ['إسلام ويب']));

        return $check->item('agreed', $sentences->resolve(['title' => 'الحكم', 'heldBy' => null, 'body' => $body, 'citations' => [$label], 'evidence' => [], 'support' => ["{$label}.S1"]]));
    }
}
