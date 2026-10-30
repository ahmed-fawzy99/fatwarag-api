<?php

namespace Tests\Unit\Answers;

use App\Answers\SourceSentences;
use App\Models\Fatwa;
use PHPUnit\Framework\TestCase;

class SourceSentencesTest extends TestCase
{
    public function test_text_is_split_at_sentence_ends_and_short_fragments_join_a_neighbour(): void
    {
        $sentences = new SourceSentences;
        $sentences->addText('w-1', "نعم. يجوز للمسافر أن يجمع بين الصلاتين إذا جد به السير؛ وهذا قول جمهور أهل العلم.\nوزنه 2.5 جرام تقريبا عند بعضهم! والله أعلم.");

        $this->assertSame([
            ['id' => 'F1.S1', 'text' => 'نعم. يجوز للمسافر أن يجمع بين الصلاتين إذا جد به السير؛'],
            ['id' => 'F1.S2', 'text' => 'وهذا قول جمهور أهل العلم.'],
        ], array_map(fn (array $sentence): array => array_diff_key($sentence, ['display' => true]), $sentences->paragraphs('F1', 'answer')[0]));
        $this->assertSame('وزنه 2.5 جرام تقريبا عند بعضهم! والله أعلم.', $sentences->sentence('F1.S3')['quote']);
    }

    /**
     * Review fix rounds 1–3: «:» never ends a sentence, so a reporting lead-in stays with the view it introduces. Each
     * sentence comes with the sentences before it in its paragraph, the rest of the fatwa, and the passage the judge reads
     * (its paragraph, and the one before when it opens its paragraph).
     */
    public function test_a_sentence_comes_with_what_surrounds_it(): void
    {
        $sentences = new SourceSentences;
        $sentences->addText('w-1', "وقد ذكر بعض الفقهاء أقوالا، منها: يجوز للمسافر أن يجمع بين الظهر والعصر إذا جد به السير.\nوذكر بعض الفقهاء في المسألة أقوالا منها:\nيجوز للمسافر أن يجمع بين الصلاتين في وقت إحداهما. وهذا قول معروف عند جماعة. والراجح عندنا أن الجمع لا يجوز إلا لعذر بين.");

        $this->assertSame('وقد ذكر بعض الفقهاء أقوالا، منها: يجوز للمسافر أن يجمع بين الظهر والعصر إذا جد به السير.', $sentences->sentence('F1.S1')['quote']);

        $third = $sentences->sentence('F1.S3');
        // Back to its paragraph's start, and the paragraph before as it opens its own; forward to the fatwa's end.
        $this->assertSame(['وذكر بعض الفقهاء في المسألة أقوالا منها:'], $third['before']);
        $this->assertSame(['وهذا قول معروف عند جماعة.', 'والراجح عندنا أن الجمع لا يجوز إلا لعذر بين.'], $third['after']);
        $this->assertSame([$sentences->sentence('F1.S2')['quote'], $third['quote'], ...$third['after']], $sentences->sentence('F1.S1')['after']);
        $this->assertSame([2, 3, 4, 5], array_keys($third['passage']['sentences']));
        $this->assertSame([3, 4, 5], array_keys($sentences->sentence('F1.S5')['passage']['sentences']));
    }

    public function test_the_question_gets_no_numbers(): void
    {
        $sentences = new SourceSentences;
        $sentences->addText('q-1', 'السؤال: هل يجوز لبس الذهب للرجال في أي حال؟ الجواب: لا يجوز للرجل لبس خاتم الذهب بحال.');

        $this->assertSame(['fatwaId' => 'q-1', 'quote' => 'لا يجوز للرجل لبس خاتم الذهب بحال.'], array_intersect_key($sentences->sentence('F1.S1'), ['fatwaId' => 1, 'quote' => 1]));
        $this->assertNull($sentences->sentence('F1.S2'));
    }

    public function test_a_quoted_verse_or_hadith_is_never_split_and_stays_with_its_lead_in(): void
    {
        $sentences = new SourceSentences;
        $label = $sentences->addFatwa(new Fatwa([
            'public_id' => 'w-2',
            'answer' => [[
                ['type' => 'text', 'text' => 'واستدلوا بقوله صلى الله عليه وسلم: '],
                ['type' => 'hadith', 'text' => 'إنما الأعمال بالنيات. وإنما لكل امرئ ما نوى'],
                ['type' => 'text', 'text' => '. وهو حديث صحيح متفق عليه عند أهل العلم.'],
            ]],
        ]));

        $paragraph = $sentences->paragraphs($label, 'answer')[0];
        $this->assertSame('واستدلوا بقوله صلى الله عليه وسلم: [F1.E1 حديث]«إنما الأعمال بالنيات. وإنما لكل امرئ ما نوى».', $paragraph[0]['display']);
        $this->assertSame('واستدلوا بقوله صلى الله عليه وسلم: إنما الأعمال بالنيات. وإنما لكل امرئ ما نوى.', $paragraph[0]['text']);
        $this->assertSame('hadith', $sentences->markers()['w-2'][1]['type']);
    }

    public function test_ids_resolve_only_to_known_sentences_of_cited_fatwas(): void
    {
        $sentences = new SourceSentences;
        $sentences->addText('w-1', 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما.');
        $sentences->addText('w-2', 'لا يجوز الجمع بين الصلاتين للمسافر في غير عرفة ومزدلفة.');

        $resolved = $sentences->resolve([
            'citations' => ['F1', 'F7', 'w-2'],
            'support' => ['F1.S1', 'F2.S1', 'F1.S4', 'F1.S1', ' F1.S1 ', 'w-1'],
            'evidence' => ['F1.E1', 'F2.E1', 'E3'],
        ]);

        $this->assertSame(['w-1'], $resolved['citations']);
        $this->assertSame([['fatwaId' => 'w-1', 'quote' => 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما.', 'id' => 'F1.S1']], array_map(fn (array $quote): array => array_intersect_key($quote, ['fatwaId' => 1, 'quote' => 1, 'id' => 1]), $resolved['support']));
        $this->assertSame([['fatwaId' => 'w-1', 'marker' => 1], ['fatwaId' => 'w-2', 'marker' => 1]], $resolved['evidence']);
    }
}
