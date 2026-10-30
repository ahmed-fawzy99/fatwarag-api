<?php

namespace Tests\Unit\Answers;

use App\Answers\QuestionGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuestionGateWordsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function meaningless(): array
    {
        return [
            'one letter repeated' => ['غغغغغغغغغغغغغغغغ'],
            'laughter' => ['ههههههههه'],
            'a syllable repeated' => ['هاهاهاها'],
            'keyboard row' => ['asdfghjkl'],
            'keyboard row backwards' => ['lkjhgf'],
            'arabic keyboard row' => ['شسيبلاتن'],
            'symbols only' => ['؟؟؟؟'],
            'digits only' => ['١٢٣٤٥'],
            'digits and symbols' => ['12 + 34 = ?'],
            'mostly mashing' => ['asdfgh qwerty ما'],
            'tatweel and harakat' => ['غـــغَـــغُ'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function real(): array
    {
        return [
            'short ruling' => ['ما حكم الربا'],
            'bare term' => ['الوضوء'],
            'two words' => ['هل يجوز'],
            'long question' => ['حكم الزواج الثاني دون معرفة الزوجة الأولى'],
            'row letters in a real word' => ['البلاد'],
            'english' => ['is music halal'],
            'english term' => ['zakat'],
            'stretched word among real ones' => ['جزاكم الله خيرا جداااا'],
            'with a number' => ['كم ركعة في صلاة الظهر 4'],
        ];
    }

    #[DataProvider('meaningless')]
    public function test_a_question_with_no_real_words_is_meaningless(string $question): void
    {
        $this->assertTrue(QuestionGate::meaningless($question));
    }

    #[DataProvider('real')]
    public function test_a_real_question_is_not_meaningless(string $question): void
    {
        $this->assertFalse(QuestionGate::meaningless($question));
    }
}
