<?php

namespace App\Enums;

/**
 * How strong a hadith grading is, strongest first. Matches GradeLevel in web/src/types/content.ts.
 */
enum GradeLevel: string
{
    case Sahih = 'sahih';
    case Hasan = 'hasan';
    case Daif = 'daif';
    case VeryDaif = 'very_daif';
    case Mawdu = 'mawdu';
    case Other = 'other';

    public function strength(): int
    {
        return match ($this) {
            self::Sahih => 0,
            self::Hasan => 1,
            self::Daif => 2,
            self::VeryDaif => 3,
            self::Mawdu => 4,
            self::Other => 5,
        };
    }

    public function isAccepted(): bool
    {
        return in_array($this, [self::Sahih, self::Hasan], true);
    }
}
