<?php

namespace Tests\Feature\Answers;

use App\Answers\SummaryTranslator;
use Tests\TestCase;

class SummaryTranslatorTest extends TestCase
{
    public function test_translation_must_keep_numbers(): void
    {
        $translator = app(SummaryTranslator::class);

        $this->assertTrue($translator->faithful('نصاب الذهب 85 جرامًا من الذهب الخالص.', 'The nisab of gold is 85 grams of pure gold.'));
        $this->assertTrue($translator->faithful('نصاب الذهب عشرون مثقالًا.', 'The nisab of gold is twenty mithqals.'));
        $this->assertFalse($translator->faithful('نصاب الذهب 85 جرامًا.', 'The nisab of gold is 87 grams.'));
        $this->assertFalse($translator->faithful('نصاب الذهب 85 جرامًا.', 'The nisab of gold is 85 grams, about 2.5 percent.'));
    }

    /**
     * Review round 3 (adv3.php, adv4.php): flipped or weakened rulings in translation.
     */
    public function test_translation_must_keep_rulings(): void
    {
        $translator = app(SummaryTranslator::class);

        $flips = [
            'must → may' => ['يجب على المرأة ستر شعرها أمام الرجال الأجانب.', 'A woman may cover her hair in front of unrelated men.'],
            'forbidden → permitted' => ['يحرم على الرجل لبس خاتم الذهب.', 'A man is permitted to wear a gold ring.'],
            'not permitted → not forbidden' => ['لا يجوز للحائض أن تمس المصحف.', 'It is not forbidden for a menstruating woman to touch the mushaf.'],
            'one of three rulings flipped' => ['لا يجوز للحائض مس المصحف، ولا يجوز لها دخول المسجد، ولا يجب عليها قضاء الصلاة.', 'A menstruating woman may not touch the mushaf, may not enter the mosque, and must make up the prayer.'],
            'one of three sentences flipped' => ['لا يجوز للحائض مس المصحف. لا يجوز لها دخول المسجد. لا يجب عليها قضاء الصلاة.', 'A menstruating woman may not touch the mushaf. She may not enter the mosque. She must make up the prayer.'],
            'zakat flipped, number kept' => ['في الحلي 85 جراما الزكاة.', 'Jewellery of 85 grams is exempt from zakat.'],
            'site voice' => ['يجوز للمسافر الجمع.', 'In our view the traveller may combine prayers.'],
            'E1 never forbidden' => ['يحرم على الرجل لبس خاتم الذهب.', 'Wearing a gold ring is never forbidden for a man.'],
            'E1 never required' => ['يجب على المرأة ستر شعرها.', 'A woman is never required to cover her hair.'],
            'E2 not the case' => ['يجب على المرأة ستر شعرها.', 'It is not the case that a woman is required to cover her hair.'],
            'E2 untrue' => ['يحرم على الرجل لبس خاتم الذهب.', 'It is untrue that wearing a gold ring is forbidden for a man.'],
        ];

        foreach ($flips as $name => [$arabic, $english]) {
            $this->assertFalse($translator->faithful($arabic, $english), $name);
        }

        $honest = [
            'yahrum → is not permitted' => ['يحرم على الرجل لبس خاتم الذهب.', 'It is not permitted for a man to wear a gold ring.'],
            'la yajuz → impermissible' => ['لا يجوز للحائض أن تمس المصحف.', 'It is impermissible for a menstruating woman to touch the mushaf.'],
            'la yajuz → may not' => ['لا يجوز للحائض أن تمس المصحف.', 'A menstruating woman may not touch the mushaf.'],
            'la zakat → no zakat' => ['الحلي المستعمل لا زكاة فيه.', 'There is no zakat on jewellery that is worn.'],
            'yajib → obligatory' => ['تجب الزكاة في الحلي إذا بلغ النصاب.', 'Zakat is obligatory on jewellery once it reaches the nisab.'],
            'la yanbaghi → should not' => ['لا ينبغي الجمع إلا لمن جد به السير.', 'One should not combine prayers unless pressed by the journey.'],
            'la yajuz → never permitted' => ['لا يجوز للحائض أن تمس المصحف.', 'A menstruating woman is never permitted to touch the mushaf.'],
            'la yasihh al-qawl → not correct to say' => ['لا يصح القول بأنه يجب على المرأة قضاء الصلاة.', 'It is not correct to say that a woman must make up the prayer.'],
            'ya\'thamu → sinful' => ['يأثم من ترك الصلاة عمدا.', 'Whoever leaves the prayer deliberately is sinful.'],
            'yujiz → permits (calibration)' => ['ويجيز لها القراءة عند خوف النسيان.', 'He permits her to recite when there is fear of forgetting.'],
        ];

        foreach ($honest as $name => [$arabic, $english]) {
            $this->assertTrue($translator->faithful($arabic, $english), $name);
        }
    }
}
