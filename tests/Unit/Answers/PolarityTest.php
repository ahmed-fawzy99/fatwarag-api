<?php

namespace Tests\Unit\Answers;

use App\Answers\Polarity;
use PHPUnit\Framework\TestCase;

class PolarityTest extends TestCase
{
    public function test_sin_words_state_a_prohibition_and_negated_lift_it(): void
    {
        $polarity = new Polarity;

        $this->assertSame(['forbidden'], $polarity->rulings('يأثم من فعل ذلك'));
        $this->assertSame(['forbidden'], $polarity->rulings('فهو آثم بذلك'));
        $this->assertSame(['forbidden'], $polarity->rulings('يأثمان إذا جمعا بين الصلاتين'));
        $this->assertSame(['permitted'], $polarity->rulings('ولا إثم عليه في ذلك'));
        $this->assertSame([], $polarity->rulings('وارتفعت أثمان الذهب'));
    }

    public function test_calling_a_saying_a_mistake_denies_its_rulings(): void
    {
        $polarity = new Polarity;

        $this->assertSame(['not-obligatory'], $polarity->rulings('ومن الخطأ القول بأنه يجب ذلك'));
        $this->assertSame(['not-obligatory'], $polarity->rulings('لا يصح القول بأنه يجب ذلك'));
        $this->assertSame(['not-obligatory'], $polarity->rulings('والقول بأنه يجب ذلك خطأ'));
        $this->assertSame(['obligatory'], $polarity->rulings('تجب الدية في قتل الخطأ'));
    }

    public function test_preference_wording_is_a_recommendation(): void
    {
        $polarity = new Polarity;

        $this->assertSame(['recommended'], $polarity->rulings('والأولى أن يصلي في بيته'));
        $this->assertSame(['recommended'], $polarity->rulings('الأفضل أن يصلي في بيته'));
        $this->assertSame(['recommended'], $polarity->rulings('والأحوط أن يعيد الصلاة'));
        $this->assertSame(['disliked'], $polarity->rulings('وهذا خلاف الأولى'));
        $this->assertSame([], $polarity->rulings('وأما المسألة الأولى فقد سبق بيانها'));
    }

    public function test_never_and_sentence_denials_negate_english_rulings(): void
    {
        $polarity = new Polarity;

        $this->assertSame(['not-forbidden'], $polarity->englishRulings('Wearing it is never forbidden.'));
        $this->assertSame(['forbidden'], $polarity->englishRulings('She is never permitted to touch it.'));
        $this->assertSame(['not-obligatory'], $polarity->englishRulings('It is not the case that she is required to cover her hair.'));
        $this->assertSame(['not-forbidden'], $polarity->englishRulings('It is untrue that wearing it is forbidden.'));
        $this->assertSame(['obligatory', 'not-obligatory'], $polarity->englishRulings('Zakat is due. It is wrong to say that she must pay it twice.'));
        $this->assertSame(['not-forbidden'], $polarity->englishRulings('Nobody holds that it is forbidden.'));
        $this->assertSame(['not-obligatory'], $polarity->englishRulings('No one says it is obligatory.'));
    }
}
