<?php

namespace Tests\Unit\Answers;

use App\Answers\GroundingCheck;
use App\Answers\SourceSentences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review fix round 4 (scratchpad/rv4): the lexical checks reject these on their own, with or without the claims
 * judge. Each ruling is bound to its own clause, its subject and the party it applies to, so a ruling can't move to
 * another thing in the same sentence («يجوز التدخين» from «يحرم التدخين، ويجوز شرب القهوة»), to another person
 * («على الابن» from «على الأب»), or rest on words only a reported view gives. Honest controls pass.
 */
class ReviewRoundFourTest extends TestCase
{
    private const SOURCES = [
        'n1' => 'تجب النفقة على الأهل، وتستحب النفقة على الجيران والأصدقاء والفقراء من المسلمين.',
        'n2' => 'يحرم التدخين لما فيه من الضرر على الصحة والمال، ويجوز شرب القهوة والشاي باعتدال لمن لا يضره ذلك.',
        'n2b' => 'يحرم التدخين لما فيه من الضرر على الصحة والمال ويجوز شرب القهوة والشاي باعتدال لمن لا يضره ذلك.',
        'n3' => 'ذهب بعض العلماء إلى أنه يجوز للحائض أن تقرأ القرآن من المصحف بحائل. والراجح عندنا أنه يجوز للحائض أن تقرأ القرآن من حفظها.',
        'n4' => 'يجب على المسلم تعلم العلم الذي تصح به عبادته، ويستحب له طلب الزيادة في الفقه والتفسير.',
        'g1' => 'يجب على الأب أن ينفق على ابنه الصغير الفقير حتى يبلغ ويقدر على الكسب.',
        'g1b' => 'تجب الكفارة على الزوج إذا جامع زوجته في نهار رمضان عامدا ذاكرا لصومه.',
        'g3' => 'يجب على ولي الصبي أن يخرج الزكاة من مال الصبي إذا بلغ النصاب وحال عليه الحول.',
        'g4' => 'فرض الله الحج على المستطيع من المسلمين مرة في العمر، ومن لم يستطع فلا حج عليه.',
        'g5' => 'حرم الله على الرجال لبس الذهب والحرير لورود النهي عن ذلك، وأباحه للنساء للتزين.',
        'c' => 'تجب الزكاة في الذهب المعد للتجارة إذا بلغ النصاب أما الذهب الملبوس فلا زكاة فيه على الصحيح.',
        'z' => 'فذهب بعض العلماء إلى أنها لا تجب في الحلي الذي تلبسه المرأة وتعيره، وقال آخرون: إنها تجب، وهذا هو الصواب، أي وجوب الزكاة فيه إذا بلغ النصاب وحال عليه الحول.',
        'w' => 'صلاة الوتر سنة مؤكدة عند جمهور العلماء، وقال أبو حنيفة: هو واجب، والراجح ما ذهب إليه الجمهور.',
    ];

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function rejected(): array
    {
        return [
            'N1 duty made recommended for «الأهل»' => ['تستحب النفقة على الأهل.', ['n1.S1']],
            'N2 ruling moved to the other subject of its sentence' => ['يجوز التدخين لما فيه من الضرر على الصحة والمال.', ['n2.S1']],
            'N2 same, clauses joined by «و» only' => ['يجوز التدخين لما فيه من الضرر على الصحة والمال.', ['n2b.S1']],
            'N2 moved ruling joined to an honest one' => ['يجوز التدخين وشرب القهوة والشاي باعتدال لمن لا يضره ذلك.', ['n2b.S1']],
            'N3 reported view cited beside a narrower own view' => ['يجوز للحائض أن تقرأ القرآن من المصحف بحائل.', ['n3.S1', 'n3.S2']],
            'N4 duty made recommended for «العلم»' => ['يستحب للمسلم تعلم العلم الذي تصح به عبادته.', ['n4.S1']],
            'G1 duty moved from father to son' => ['يجب على الابن أن ينفق على أبيه الصغير الفقير حتى يبلغ.', ['g1.S1']],
            'G1b expiation moved from husband to wife' => ['تجب الكفارة على الزوجة إذا جامعها زوجها في نهار رمضان عامدا.', ['g1b.S1']],
            'G3 duty moved from the guardian to the boy' => ['يجب على الصبي أن يخرج الزكاة من ماله إذا بلغ النصاب وحال عليه الحول.', ['g3.S1']],
            'G4 duty moved to who can\'t afford it' => ['فرض الله الحج على من لم يستطع من المسلمين مرة في العمر.', ['g4.S1']],
            'G5 prohibition moved from men to women' => ['حرم الله على النساء لبس الذهب والحرير لورود النهي عن ذلك.', ['g5.S1']],
            'ruling of the «أما» case moved to the other' => ['تجب الزكاة في الذهب الملبوس إذا بلغ النصاب.', ['c.S1']],
        ];
    }

    /**
     * @param  list<string>  $support
     */
    #[DataProvider('rejected')]
    public function test_rejected_lexically(string $body, array $support): void
    {
        $this->assertNull($this->check($body, $support));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function honest(): array
    {
        return [
            'duty to the family' => ['تجب النفقة على الأهل.', ['n1.S1']],
            'the permitted half of its sentence' => ['يجوز شرب القهوة والشاي باعتدال لمن لا يضره ذلك.', ['n2b.S1']],
            'own view only' => ['يجوز للحائض أن تقرأ القرآن من حفظها.', ['n3.S2']],
            'guardian kept' => ['يجب على ولي الصبي أن يخرج الزكاة من مال الصبي إذا بلغ النصاب وحال عليه الحول.', ['g3.S1']],
            'both parties kept' => ['حرم الله على الرجال لبس الذهب والحرير، وأباحه للنساء للتزين.', ['g5.S1']],
            'both cases kept' => ['لا زكاة في الذهب الملبوس، وتجب في الذهب المعد للتجارة إذا بلغ النصاب.', ['c.S1']],
            'subject named where the source has a pronoun' => ['ذهب بعض العلماء إلى أن الزكاة لا تجب في الحلي الذي تلبسه المرأة وتعيره.', ['z.S1']],
            'a reported view named as such, its subject by pronoun' => ['قال أبو حنيفة إن الوتر واجب.', ['w.S1']],
        ];
    }

    /**
     * @param  list<string>  $support
     */
    #[DataProvider('honest')]
    public function test_honest_passes(string $body, array $support): void
    {
        $this->assertNotNull($this->check($body, $support));
    }

    public function test_an_agree_headline_that_drops_a_condition_is_replaced(): void
    {
        $sentence = 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.';
        $draft = [
            'kind' => 'agree',
            'headline' => 'يجوز للمسافر أن يجمع بين الظهر والعصر',
            'agreed' => ['title' => 'جمع المسافر', 'heldBy' => null, 'body' => $sentence, 'citations' => ['h'], 'evidence' => [], 'support' => [['fatwaId' => 'h', 'quote' => $sentence]]],
            'views' => [], 'preferences' => [], 'caution' => null,
        ];

        $checked = (new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4))->check($draft, ['h' => $sentence], 'ar', ['h' => ['إسلام ويب']]);

        $this->assertNotNull($checked);
        $this->assertNotSame('يجوز للمسافر أن يجمع بين الظهر والعصر', $checked['headline']);
        $this->assertNotNull((new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4))->check([...$draft, 'headline' => $sentence], ['h' => $sentence], 'ar', ['h' => ['إسلام ويب']]));
    }

    /**
     * @param  list<string>  $support  «key.Sn»
     * @return array<string, mixed>|null
     */
    private function check(string $body, array $support): ?array
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

        $check = new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4);
        $check->prepare(self::SOURCES, array_fill_keys(array_keys(self::SOURCES), ['إسلام ويب']));

        return $check->item('agreed', $sentences->resolve(['title' => 'الحكم', 'heldBy' => null, 'body' => $body, 'citations' => $labels, 'evidence' => [], 'support' => $ids]));
    }
}
