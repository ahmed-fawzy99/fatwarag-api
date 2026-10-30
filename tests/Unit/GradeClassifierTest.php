<?php

namespace Tests\Unit;

use App\Enums\GradeLevel;
use App\Support\GradeClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GradeClassifierTest extends TestCase
{
    public static function phrases(): array
    {
        return [
            'sahih' => ['صحيح', GradeLevel::Sahih],
            'hasan sahih counts as sahih' => ['حسن صحيح', GradeLevel::Sahih],
            'bracketed with harakat' => ['[صَحِيحٌ]', GradeLevel::Sahih],
            'isnad daif' => ['إسناده ضعيف', GradeLevel::Daif],
            'very weak' => ['ضعيف جداً', GradeLevel::VeryDaif],
            'fabricated' => ['موضوع', GradeLevel::Mawdu],
            'corroborated is not graded' => ['له شواهد', GradeLevel::Other],
            'strong isnad is hasan' => ['إسناده قوي', GradeLevel::Hasan],
            'no basis is fabricated' => ['لا أصل له', GradeLevel::Mawdu],
            'wahi as a whole word' => ['إسناده واه', GradeLevel::VeryDaif],
            'negated strong' => ['غير قوي', GradeLevel::Daif],
            'negated sahih' => ['ليس بصحيح', GradeLevel::Daif],
            'does not hold' => ['لا يصح', GradeLevel::Daif],
            'plural sahih' => ['صحاح', GradeLevel::Sahih],
            'plural hasan' => ['الحسان', GradeLevel::Hasan],
            'empty' => ['', GradeLevel::Other],
        ];
    }

    #[DataProvider('phrases')]
    public function test_classifier_levels(string $phrase, GradeLevel $expected): void
    {
        $this->assertSame($expected, GradeClassifier::level($phrase));
    }
}
