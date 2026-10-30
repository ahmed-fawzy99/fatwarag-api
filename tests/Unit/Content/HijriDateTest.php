<?php

namespace Tests\Unit\Content;

use App\Content\HijriDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HijriDateTest extends TestCase
{
    /**
     * @return array<string, array{string, ?array{day:int, month:int, year:int}}>
     */
    public static function formats(): array
    {
        return [
            'islamqa month name' => ['17/جمادى الأولى/1448', ['day' => 17, 'month' => 5, 'year' => 1448]],
            'numeric with suffix' => ['26/12/1418هـ', ['day' => 26, 'month' => 12, 'year' => 1418]],
            'english month' => ['24/Ramadan/1431', ['day' => 24, 'month' => 9, 'year' => 1431]],
            'no date' => ['بدون', null],
            'with gregorian equivalent' => ['17/جمادى الأولى/1448 الموافق 28/أكتوبر/2026', ['day' => 17, 'month' => 5, 'year' => 1448]],
            'spaces and weekday' => ['الأحد 14 رجب 1425 هـ', ['day' => 14, 'month' => 7, 'year' => 1425]],
            'arabic-indic digits' => ['١٥ صفر ١٤٣٨ هـ', ['day' => 15, 'month' => 2, 'year' => 1438]],
            'rabi al-thani' => ['3 ربيع الآخر 1430', ['day' => 3, 'month' => 4, 'year' => 1430]],
            'dhu al-hijjah english' => ['9/Dhul-Hijjah/1440', ['day' => 9, 'month' => 12, 'year' => 1440]],
            'gregorian only' => ['28/10/2026', null],
            'islamweb with gregorian after' => ['الخميس 22 رمضان 1425 هـ - 4-11-2004 م', ['day' => 22, 'month' => 9, 'year' => 1425]],
        ];
    }

    /**
     * @param  ?array{day:int, month:int, year:int}  $expected
     */
    #[DataProvider('formats')]
    public function test_hijri_formats(string $text, ?array $expected): void
    {
        $this->assertSame($expected, HijriDate::parse($text));
    }
}
