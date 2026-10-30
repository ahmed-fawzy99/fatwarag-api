<?php

namespace Tests\Feature\Answers;

use App\Answers\EntailmentJudge;
use App\Answers\SummaryTranslator;
use App\Answers\SummaryWriter;
use App\Models\Fatwa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Support\MakesSearchableFatwas;
use Tests\Support\WritesSummaryLines;
use Tests\TestCase;

/**
 * A question asking several things is answered part by part; one part is the common case and stays as it was.
 */
class SummaryPartsTest extends TestCase
{
    use MakesSearchableFatwas;
    use RefreshDatabase;
    use WritesSummaryLines;

    private const DURING = 'إذا شك المصلي في عدد الركعات وهو في الصلاة فإنه يبني على الأقل ويسجد للسهو قبل السلام من صلاته.';

    private const AFTER = 'إذا شك المصلي في عدد الركعات بعد السلام من صلاته فلا يلتفت إلى هذا الشك وصلاته صحيحة ولا شيء عليه.';

    private const LABELS = ['إذا كان الشك أثناء الصلاة', 'إذا كان الشك بعد السلام'];

    private const QUESTION = 'نسيت في الركعة الثالثة ولا الرابعة؟ أولا لو كنت داخل الصلاة، ثانيا لو بعد الصلاة شكيت';

    protected function setUp(): void
    {
        parent::setUp();

        config(['fatwarag.summary.claims_judge' => true]);
        $this->fakeJudge();
        Http::fake();
    }

    public function test_a_two_part_question_is_answered_part_by_part(): void
    {
        SummaryWriter::fake([$this->twoParts()]);
        $events = [];

        $result = app(SummaryWriter::class)->write(self::QUESTION, $this->fatwas(), 'ar', function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        });

        $this->assertSame('ok', $result['status']);
        $summary = $result['summary'];
        $this->assertSame('agree', $summary['kind']);
        $this->assertSame('سؤالك من جزأين', $summary['headline']);
        $this->assertNull($summary['agreed']);
        $this->assertSame([], $summary['views']);
        $this->assertSame(self::LABELS, array_column($summary['parts'], 'label'));
        $this->assertSame(['agree', 'agree'], array_column($summary['parts'], 'kind'));
        $this->assertSame(self::DURING, $summary['parts'][0]['agreed']['body']);
        $this->assertSame(self::AFTER, $summary['parts'][1]['agreed']['body']);

        // Each item streams with its part, exactly as it shows in the final summary.
        $items = array_values(array_column(array_filter($events, fn (array $event): bool => $event[0] === 'item'), 1));
        $this->assertSame([[0, 'agreed', 0], [1, 'agreed', 0]], array_map(fn (array $item): array => [$item['part'], $item['section'], $item['index']], $items));
        $this->assertSame(self::LABELS[1], $items[1]['partLabel']);
        $this->assertSame($summary['parts'][1]['agreed'], $items[1]['item']);
        SummaryWriter::assertPromptedTimes(1);
    }

    public function test_a_part_no_fatwa_answers_is_shown_unanswered(): void
    {
        SummaryWriter::fake([$this->summaryLines(
            ['parts' => self::LABELS],
            ['for' => 1, ...$this->headerLine('agree', self::DURING)],
            ['for' => 1, ...$this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::DURING, ['F1'], ['F1.S1'])],
            ['for' => 2, 'kind' => 'none'],
        )]);

        $summary = app(SummaryWriter::class)->write(self::QUESTION, $this->fatwas(), 'ar')['summary'];

        $this->assertSame(['agree', 'unanswered'], array_column($summary['parts'], 'kind'));
        $this->assertSame(['label' => self::LABELS[1], 'kind' => 'unanswered', 'headline' => null, 'agreed' => null, 'views' => [], 'preferences' => [], 'caution' => null, 'note' => null], $summary['parts'][1]);
        SummaryWriter::assertPromptedTimes(1);
    }

    public function test_a_part_that_fails_its_checks_is_unanswered_while_the_other_stands(): void
    {
        SummaryWriter::fake([$this->twoParts(), $this->twoParts()]);
        $this->fakeJudge(['p2_agreed' => 'not_supported']);
        $events = [];

        $summary = app(SummaryWriter::class)->write(self::QUESTION, $this->fatwas(), 'ar', function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        })['summary'];

        $this->assertSame(['agree', 'unanswered'], array_column($summary['parts'], 'kind'));
        // The failed part was written again once; the answered part was sent once and kept.
        SummaryWriter::assertPromptedTimes(2);
        SummaryWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('part 1 passed and is kept as it is; part 2 was rejected'));
        $this->assertSame([0], array_column(array_column(array_filter($events, fn (array $event): bool => $event[0] === 'item'), 1), 'part'));
    }

    public function test_a_rewrite_answers_the_part_that_failed_and_keeps_the_other(): void
    {
        $failing = $this->summaryLines(
            ['parts' => self::LABELS],
            ['for' => 1, ...$this->headerLine('agree', self::DURING)],
            ['for' => 1, ...$this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::DURING, ['F1'], ['F1.S1'])],
            ['for' => 2, ...$this->headerLine('agree', self::AFTER)],
            ['for' => 2, ...$this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::AFTER, ['F2'], ['F9.S1'])],
        );
        SummaryWriter::fake([$failing, $this->twoParts()]);
        $events = [];

        $summary = app(SummaryWriter::class)->write(self::QUESTION, $this->fatwas(), 'ar', function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        })['summary'];

        $this->assertSame(['agree', 'agree'], array_column($summary['parts'], 'kind'));
        $this->assertSame(self::AFTER, $summary['parts'][1]['agreed']['body']);
        $this->assertSame([0, 1], array_column(array_column(array_filter($events, fn (array $event): bool => $event[0] === 'item'), 1), 'part'));
    }

    public function test_a_rewrite_never_judges_or_translates_the_kept_part_again(): void
    {
        $failing = $this->summaryLines(
            ['parts' => self::LABELS],
            ['for' => 1, ...$this->headerLine('agree', self::DURING)],
            ['for' => 1, ...$this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::DURING, ['F1'], ['F1.S1'])],
            ['for' => 2, ...$this->headerLine('agree', self::AFTER)],
            ['for' => 2, ...$this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::AFTER, ['F2'], ['F9.S1'])],
        );
        // The rewrite writes part 1 again anyway (differently): it is ignored, and the kept part stands.
        $rewrite = $this->summaryLines(
            ['parts' => self::LABELS],
            ['for' => 1, ...$this->headerLine('agree', 'نص آخر')],
            ['for' => 1, ...$this->pointLine('agreed', 'عنوان آخر', self::DURING, ['F1'], ['F1.S1'])],
            ['for' => 2, ...$this->headerLine('agree', self::AFTER)],
            ['for' => 2, ...$this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::AFTER, ['F2'], ['F2.S1'])],
        );
        SummaryWriter::fake([$failing, $rewrite]);
        $english = [
            'label0' => 'If the doubt is during the prayer',
            'label1' => 'If the doubt is after the salam',
            'p1_agreed.title' => 'What the sources agree on',
            'p1_agreed.body' => 'If a person praying doubts the number of rak‘ahs while in the prayer, he builds on the lower number and prostrates for forgetfulness before the salam.',
            'p2_agreed.title' => 'What the sources agree on',
            'p2_agreed.body' => 'If a person praying doubts the number of rak‘ahs after the salam, he pays no attention to this doubt, his prayer is valid, and there is nothing on him.',
        ];
        $translated = [];
        SummaryTranslator::fake(function (string $prompt) use ($english, &$translated): array {
            $asked = json_decode(substr($prompt, (int) strpos($prompt, '{')), true) ?? [];
            array_push($translated, ...array_keys($asked));

            return ['fields' => array_values(array_map(fn (string $id): array => ['id' => $id, 'text' => $english[$id]], array_values(array_filter(array_keys($asked), fn (string $id): bool => isset($english[$id])))))];
        });
        $judged = [];
        EntailmentJudge::fake(function (string $prompt) use (&$judged): array {
            preg_match_all('/^### (\S+)/mu', $prompt, $ids);
            array_push($judged, ...$ids[1]);

            return ['verdicts' => array_map(fn (string $id): array => ['id' => $id, 'verdict' => 'supported', 'reason' => ''], $ids[1])];
        });

        $summary = app(SummaryWriter::class)->write('During the prayer, and after it?', $this->fatwas(), 'en')['summary'];

        $this->assertSame(['agree', 'agree'], array_column($summary['parts'], 'kind'));
        $this->assertSame(['If the doubt is during the prayer', 'If the doubt is after the salam'], array_column($summary['parts'], 'label'));
        $this->assertSame($english['p1_agreed.body'], $summary['parts'][0]['agreed']['body']);
        // Part 1 and the labels were judged and translated once, with the first draft; only part 2 again.
        $this->assertSame(1, count(array_keys($judged, 'p1_agreed', true)));
        $this->assertSame(1, count(array_keys($translated, 'p1_agreed.body', true)));
        $this->assertSame(1, count(array_keys($translated, 'label0', true)));
        $this->assertSame(1, count(array_keys($judged, 'p2_agreed', true)));
        SummaryWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('part 1 passed and is kept as it is; part 2 was rejected'));
    }

    public function test_items_without_their_part_belong_to_the_part_being_written(): void
    {
        SummaryWriter::fake([$this->summaryLines(
            ['parts' => self::LABELS],
            $this->headerLine('agree', self::DURING),
            $this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::DURING, ['F1'], ['F1.S1']),
            $this->headerLine('agree', self::AFTER),
            $this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::AFTER, ['F2'], ['F2.S1']),
        )]);

        $summary = app(SummaryWriter::class)->write(self::QUESTION, $this->fatwas(), 'ar')['summary'];

        $this->assertSame(['agree', 'agree'], array_column($summary['parts'], 'kind'));
        $this->assertSame(self::AFTER, $summary['parts'][1]['agreed']['body']);
    }

    public function test_a_disagreement_needs_two_views_within_its_own_part(): void
    {
        $output = $this->summaryLines(
            ['parts' => self::LABELS],
            ['for' => 1, ...$this->headerLine('agree', self::DURING)],
            ['for' => 1, ...$this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::DURING, ['F1'], ['F1.S1'])],
            ['for' => 2, ...$this->headerLine('differ', 'اختلفت المصادر')],
            ['for' => 2, ...$this->pointLine('view', 'القول الأول', self::AFTER, ['F2'], ['F2.S1'])],
            ['for' => 2, ...$this->pointLine('view', 'القول الثاني', self::AFTER, ['F3'], ['F3.S1'])],
        );

        // One view of the second part fails: that part can't show a disagreement, the first part stands.
        SummaryWriter::fake([$output]);
        $this->fakeJudge(['p2_view1' => 'not_supported']);
        $events = [];

        $summary = app(SummaryWriter::class)->write(self::QUESTION, $this->fatwas(), 'ar', function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        })['summary'];

        $this->assertSame(['agree', 'unanswered'], array_column($summary['parts'], 'kind'));
        $this->assertSame([['agreed', 0]], array_map(fn (array $item): array => [$item['section'], $item['part']], array_values(array_column(array_filter($events, fn (array $event): bool => $event[0] === 'item'), 1))));

        // Both views pass: the second part differs, with the neutral headline for that part.
        SummaryWriter::fake([$output]);
        $this->fakeJudge();

        $summary = app(SummaryWriter::class)->write(self::QUESTION, $this->fatwas(), 'ar')['summary'];

        $this->assertSame('differ', $summary['kind']);
        $this->assertSame(['agree', 'differ'], array_column($summary['parts'], 'kind'));
        $this->assertSame('اختلفت المصادر في هذه المسألة على قولين', $summary['parts'][1]['headline']);
        $this->assertCount(2, $summary['parts'][1]['views']);
    }

    public function test_a_draft_whose_every_part_fails_is_rewritten(): void
    {
        SummaryWriter::fake([$this->twoParts(), $this->twoParts()]);
        $this->fakeJudge(['p1_agreed' => 'not_supported', 'p2_agreed' => 'not_supported']);

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write(self::QUESTION, $this->fatwas(), 'ar'));
        SummaryWriter::assertPromptedTimes(2);
    }

    public function test_one_part_is_written_and_shown_as_before(): void
    {
        SummaryWriter::fake([$this->summaryLines(
            $this->headerLine('agree', self::DURING),
            $this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::DURING, ['F1'], ['F1.S1']),
        )]);
        $events = [];

        $summary = app(SummaryWriter::class)->write('سؤال', $this->fatwas(), 'ar', function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        })['summary'];

        $this->assertSame(['kind', 'headline', 'agreed', 'views', 'preferences', 'caution', 'note', 'extraFatwaIds'], array_keys($summary));
        $this->assertSame(self::DURING, $summary['agreed']['body']);
        $this->assertNull($summary['note']); // nothing pruned or dropped: no line
        $items = array_values(array_column(array_filter($events, fn (array $event): bool => $event[0] === 'item'), 1));
        $this->assertSame([['section', 'index', 'item']], array_map(array_keys(...), $items));
    }

    public function test_a_label_that_states_a_ruling_is_replaced_by_a_neutral_one(): void
    {
        SummaryWriter::fake([$this->twoParts([self::LABELS[0], 'يجب سجود السهو إذا كان الشك بعد السلام'])]);

        $summary = app(SummaryWriter::class)->write(self::QUESTION, $this->fatwas(), 'ar')['summary'];

        $this->assertSame([self::LABELS[0], 'الجزء الثاني من السؤال'], array_column($summary['parts'], 'label'));
    }

    public function test_english_parts_show_checked_translations_of_their_labels(): void
    {
        SummaryWriter::fake([$this->twoParts()]);
        $english = [
            'label0' => 'If the doubt is during the prayer',
            'label1' => 'If the doubt is after the salam',
            'p1_agreed.title' => 'What the sources agree on',
            'p1_agreed.body' => 'If a person praying doubts the number of rak‘ahs while in the prayer, he builds on the lower number and prostrates for forgetfulness before the salam.',
            'p2_agreed.title' => 'What the sources agree on',
            'p2_agreed.body' => 'If a person praying doubts the number of rak‘ahs after the salam, he pays no attention to this doubt, his prayer is valid, and there is nothing on him.',
        ];
        SummaryTranslator::fake(function (string $prompt) use ($english): array {
            $asked = json_decode(substr($prompt, (int) strpos($prompt, '{')), true) ?? [];

            return ['fields' => array_values(array_map(fn (string $id): array => ['id' => $id, 'text' => $english[$id]], array_values(array_filter(array_keys($asked), fn (string $id): bool => isset($english[$id])))))];
        });
        $this->fakeJudge(['label1' => 'not_supported']);

        $summary = app(SummaryWriter::class)->write('During the prayer, and after it?', $this->fatwas(), 'en')['summary'];

        $this->assertSame('Your question has two parts', $summary['headline']);
        // A label whose translation the judge rejects shows its neutral label.
        $this->assertSame(['If the doubt is during the prayer', 'Part 2 of the question'], array_column($summary['parts'], 'label'));
        $this->assertSame($english['p2_agreed.body'], $summary['parts'][1]['agreed']['body']);
        SummaryTranslator::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('label0'));
    }

    /**
     * @param  list<string>  $labels
     */
    private function twoParts(array $labels = self::LABELS): string
    {
        return $this->summaryLines(
            ['parts' => $labels],
            ['for' => 1, ...$this->headerLine('agree', self::DURING)],
            ['for' => 1, ...$this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::DURING, ['F1'], ['F1.S1'])],
            ['for' => 2, ...$this->headerLine('agree', self::AFTER)],
            ['for' => 2, ...$this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::AFTER, ['F2'], ['F2.S1'])],
        );
    }

    /**
     * @return Collection<int, Fatwa>
     */
    private function fatwas(): Collection
    {
        return collect([
            $this->searchFatwa('الشك في عدد الركعات أثناء الصلاة', self::DURING),
            $this->searchFatwa('الشك في عدد الركعات بعد السلام', self::AFTER, 'binbaz', 2),
            $this->searchFatwa('الشك بعد الفراغ من الصلاة', self::AFTER, 'islamqa', 3),
        ]);
    }
}
