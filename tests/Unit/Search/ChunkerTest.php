<?php

namespace Tests\Unit\Search;

use App\Models\Fatwa;
use App\Search\Chunker;
use App\Support\Arabic;
use PHPUnit\Framework\TestCase;

class ChunkerTest extends TestCase
{
    public function test_first_chunk_is_title_plus_question(): void
    {
        $fatwa = $this->fatwa(
            title: 'حُكْمُ قراءة الحائض للقرآن',
            question: [[['type' => 'text', 'text' => 'هل يجوز للحائض أن تقرأ القرآن؟']]],
            answer: [[['type' => 'text', 'text' => 'الحمد لله.']]],
        );

        $chunks = (new Chunker)->chunk($fatwa);

        $this->assertSame(Arabic::normalize('حكم قراءة الحائض للقرآن').' '.Arabic::normalize('هل يجوز للحائض أن تقرأ القرآن'), $chunks[0]);
        $this->assertStringNotContainsString('ُ', $chunks[0]);
        $this->assertSame('الحمد لله', $chunks[1]);
    }

    public function test_answer_split_on_paragraphs_under_max_chars(): void
    {
        $paragraphs = [];

        foreach (range(1, 6) as $i) {
            $paragraphs[] = [['type' => 'text', 'text' => "فقرة{$i} ".str_repeat('كلمة ', 100)]];
        }

        $chunks = (new Chunker)->chunk($this->fatwa(title: 'عنوان', question: [], answer: $paragraphs));
        $answerChunks = array_slice($chunks, 1);

        $this->assertGreaterThan(1, count($answerChunks));

        foreach ($answerChunks as $chunk) {
            $this->assertLessThanOrEqual(Chunker::MAX_CHARS, mb_strlen($chunk));
        }

        // One paragraph of overlap: each chunk after the first starts with the last paragraph of the one before.
        foreach (array_slice($answerChunks, 1) as $index => $chunk) {
            preg_match_all('/فقره\d/u', $answerChunks[$index], $previous);
            $this->assertStringStartsWith(end($previous[0]), $chunk);
        }

        // Every paragraph is somewhere.
        foreach (range(1, 6) as $i) {
            $this->assertStringContainsString("فقره{$i}", implode(' ', $answerChunks));
        }
    }

    public function test_oversized_paragraph_is_split_without_losing_text(): void
    {
        $long = str_repeat('كلمه ', 1000);
        $chunks = (new Chunker)->chunk($this->fatwa(title: 'عنوان', question: [], answer: [[['type' => 'text', 'text' => $long]]]));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(Chunker::MAX_CHARS, mb_strlen($chunk));
        }

        $this->assertSame(1000, substr_count(implode(' ', array_slice($chunks, 1)), 'كلمه'));
    }

    public function test_empty_answer_gives_only_the_first_chunk(): void
    {
        $chunks = (new Chunker)->chunk($this->fatwa(title: 'عنوان', question: [], answer: []));

        $this->assertSame(['عنوان'], $chunks);
    }

    /**
     * @param  array<int, mixed>  $question
     * @param  array<int, mixed>  $answer
     */
    private function fatwa(string $title, array $question, array $answer): Fatwa
    {
        return (new Fatwa)->forceFill(['title' => $title, 'question' => $question, 'answer' => $answer]);
    }
}
