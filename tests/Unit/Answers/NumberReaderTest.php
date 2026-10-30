<?php

namespace Tests\Unit\Answers;

use App\Answers\NumberReader;
use PHPUnit\Framework\TestCase;

class NumberReaderTest extends TestCase
{
    public function test_numbers_in_digits_and_words_are_read(): void
    {
        $reader = new NumberReader;

        $this->assertSame(['85'], $reader->values('خمسة وثمانون جرامًا'));
        $this->assertSame(['185'], $reader->values('مائة وخمسة وثمانون جراما'));
        $this->assertSame(['92'], $reader->values('٩٢ جرامًا'));
        $this->assertSame(['1/4', '1/10'], $reader->values('ربع العشر'));
        $this->assertSame(['350'], $reader->values('ثلاثمائة وخمسون ريالا'));
    }

    public function test_attached_prefixes_are_read(): void
    {
        $reader = new NumberReader;

        $this->assertSame(['30'], $reader->values('بثلاثين مثقالا'));
        $this->assertSame(['85'], $reader->values('لخمسة وثمانين جراما'));
        $this->assertSame(['70'], $reader->values('لسبعين يوما'));
        $this->assertSame(['4'], $reader->values('الأئمة الأربعة'));
    }

    public function test_english_numbers_are_read(): void
    {
        $reader = new NumberReader;

        $this->assertSame(['200'], $reader->values('two hundred grams'));
        $this->assertSame(['85'], $reader->values('eighty-five grams'));
        $this->assertSame([], $reader->values('two prayers'));
        $this->assertSame(['2.5'], $reader->values('2.5 percent'));
    }

    public function test_qualifier_follows_the_number(): void
    {
        $this->assertSame(['مثقالا'], array_slice((new NumberReader)->occurrences('عشرون مثقالًا')[0]['next'], 0, 1));
    }
}
