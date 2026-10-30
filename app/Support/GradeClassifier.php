<?php

namespace App\Support;

use App\Enums\GradeLevel;

/**
 * Classifies a grading phrase («صحيح», «إسناده ضعيف», «لا أصل له») into a GradeLevel, for colour and ordering only.
 * A faithful port of levelFromPhrase in web/src/lib/helpers/grades.ts; keep the two in step.
 */
final class GradeClassifier
{
    public static function level(string $phrase): GradeLevel
    {
        $text = Arabic::normalize($phrase);

        return match (true) {
            (bool) preg_match('/موضوع|باطل|مكذوب|لا اصل له/u', $text) => GradeLevel::Mawdu,
            // «واه» only as a whole word: «شواهد» (corroborating reports) contains it but is not a weakness.
            (bool) preg_match('/منكر|ضعيف جدا|(^|\s)واهي?($|\s)|متروك/u', $text) => GradeLevel::VeryDaif,
            // A negated acceptance word is a rejection: «غير قوي», «ليس بصحيح», «لا يصح».
            (bool) preg_match('/(^|\s)(غير|ليس|ليست)\s+(ب|بال)?(صحيح|قوي|حسن|ثابت|جيد)|لا (يصح|تصح|يثبت|تثبت)/u', $text) => GradeLevel::Daif,
            (bool) preg_match('/ضعيف|ضعف|مرسل|منقطع|شاذ|معلول|(^|\s)معل($|\s)/u', $text) => GradeLevel::Daif,
            (bool) preg_match('/صحيح|صحاح|صحح|ثابت|جيد|متواتر/u', $text) => GradeLevel::Sahih,
            (bool) preg_match('/حسن|(^|\s)(ال)?حسان($|\s)|قوي/u', $text) => GradeLevel::Hasan,
            default => GradeLevel::Other,
        };
    }
}
