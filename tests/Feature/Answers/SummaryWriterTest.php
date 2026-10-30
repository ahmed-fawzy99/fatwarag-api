<?php

namespace Tests\Feature\Answers;

use App\Answers\EntailmentJudge;
use App\Answers\GroundingCheck;
use App\Answers\PruneJudge;
use App\Answers\SummaryTasks;
use App\Answers\SummaryTranslator;
use App\Answers\SummaryWriter;
use App\Models\AiUsage;
use App\Models\Fatwa;
use App\Models\FatwaLink;
use App\Models\Hadith;
use App\Models\HadithQuote;
use App\Models\Setting;
use App\Support\Arabic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\MakesSearchableFatwas;
use Tests\Support\WritesSummaryLines;
use Tests\TestCase;

class SummaryWriterTest extends TestCase
{
    use MakesSearchableFatwas;
    use RefreshDatabase;
    use WritesSummaryLines;

    private const HADITH = 'لا يمس القرآن إلا طاهر';

    /** The first fatwa's first sentence (F1.S1), as stored. */
    private const NO_TOUCH = 'الحمد لله، لا يجوز للحائض أن تمسّ المصحف من غير حائل باتفاق الأئمة الأربعة، لقوله تعالى: لَا يَمَسُّهُ إِلَّا الْمُطَهَّرُونَ';

    private const AGREED_BODY = 'لا تمسّ الحائض المصحف من غير حائل، ونُقل ذلك عن الأئمة الأربعة.';

    private const PREFERENCE = 'ويرى مصدر islamweb أنه لا يجوز للحائض مس المصحف من غير حائل.';

    protected function setUp(): void
    {
        parent::setUp();

        // These tests cover the claims judge too: it is forced on here (off by default, owner decision); the tests
        // with it off say so.
        config(['fatwarag.summary.claims_judge' => true]);
        $this->fakeJudge();

        Http::fake();
    }

    public function test_grounded_summary_is_returned_with_evidence_from_stored_text(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $hadith = Hadith::query()->create(['text' => self::HADITH, 'search_text' => Arabic::normalize(self::HADITH)]);
        HadithQuote::query()->create(['quote_key' => Arabic::quoteKey(self::HADITH), 'quote' => self::HADITH, 'status' => 'found', 'hadith_id' => $hadith->id]);

        SummaryWriter::fake([$this->modelOutput()]);

        $result = app(SummaryWriter::class)->write('هل تمس الحائض المصحف؟', collect([$fatwa, $other]), 'ar');

        $this->assertSame('ok', $result['status']);
        $summary = $result['summary'];
        $this->assertSame('agree', $summary['kind']);
        $this->assertSame([$fatwa->public_id], $summary['agreed']['citations']);
        $this->assertArrayNotHasKey('support', $summary['agreed']);
        $this->assertSame([
            ['type' => 'quran', 'surah' => 56, 'ayah' => 79, 'ayahEnd' => null],
            [
                'type' => 'hadith', 'quote' => self::HADITH, 'quoteEn' => null, 'fatwaId' => $fatwa->public_id,
                'ref' => ['collection' => 'dorar', 'number' => $hadith->id],
                'gradingInFatwa' => 'حديث حسن', 'gradingScope' => 'this', 'gradingContext' => 'رواه مالك وهو حديث حسن',
            ],
        ], $summary['agreed']['evidence']);
        $this->assertSame([], $summary['views']);
        $this->assertNull($summary['caution']);

        SummaryWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('[F1.S2] وقال ﷺ: [F1.E2 حديث]«'.self::HADITH.'»')
            && $prompt->contains('<fatwa id="F1"') && $prompt->contains('<fatwa id="F2"'));
    }

    public function test_each_sentence_is_numbered_but_the_question_is_not(): void
    {
        $fatwa = $this->searchFatwa('قراءة الحائض', question: 'هل يجوز للحائض قراءة القرآن؟ أفيدوني.', answer: [
            [['type' => 'text', 'text' => 'نعم. يجوز لها أن تقرأ القرآن من حفظها: لطول مدة الحيض؟ والله أعلم. وهذا قول جمع من أهل العلم المحققين.']],
            [['type' => 'text', 'text' => "وقد ذكر أهل العلم ذلك في كتبهم المعروفة\nوهذا قول جماعة من الفقهاء المتقدمين."]],
        ]);

        SummaryWriter::fake([$this->summaryLines($this->headerLine(), $this->pointLine('agreed', 'قراءة الحائض', 'يجوز لها أن تقرأ القرآن من حفظها لطول مدة الحيض.', ['F1'], ['F1.S1']))]);

        $this->assertSame('ok', app(SummaryWriter::class)->write('سؤال', collect([$fatwa]), 'ar')['status']);

        SummaryWriter::assertPrompted(function (AgentPrompt $prompt): bool {
            // «نعم.» is too short to stand alone, so it joins the next sentence; «؟» ends a sentence, «:» doesn't, a line
            // break does; the short closing formula joins its neighbour.
            return $prompt->contains('[F1.S1] نعم. يجوز لها أن تقرأ القرآن من حفظها: لطول مدة الحيض؟ [F1.S2] والله أعلم. وهذا قول جمع')
                && $prompt->contains('[F1.S3] وقد ذكر أهل العلم ذلك في كتبهم المعروفة [F1.S4] وهذا قول')
                && $prompt->contains("السؤال:\nهل يجوز للحائض قراءة القرآن؟ أفيدوني.");
        });
    }

    public function test_sentence_ids_are_resolved_to_the_stored_sentences_the_judge_reads(): void
    {
        [$fatwa, $other] = $this->fatwas();
        SummaryWriter::fake([$this->modelOutput()]);
        $this->recordJudgePrompts();

        app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar');

        $this->assertStringContainsString('⟦'.self::NO_TOUCH.'⟧', $this->judgePrompts[0]);
    }

    public function test_unknown_ids_and_ids_of_uncited_fatwas_give_no_support(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $output = $this->summaryLines(
            $this->headerLine(),
            // F1.S1 is cited only through F2, which the point doesn't cite; F1.S9 doesn't exist.
            $this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::AGREED_BODY, ['F2'], ['F1.S1', 'F2.S9', 'F9.S1']),
        );

        SummaryWriter::fake([$output, $output]);

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar'));
        SummaryWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('no support: cite the sentences'));
    }

    public function test_support_needs_eight_meaningful_words_in_all(): void
    {
        $fatwa = $this->searchFatwa('حكم', answer: [
            [['type' => 'text', 'text' => 'لا يجوز ذلك للحائض. والله أعلم.']],
            [['type' => 'text', 'text' => 'ولا يجوز لها مس المصحف أيضا.']],
        ]);
        $point = fn (array $support): string => $this->summaryLines($this->headerLine('agree', 'لا يجوز للحائض مس المصحف'), $this->pointLine('agreed', 'ما اتفقت عليه المصادر', 'لا يجوز للحائض مس المصحف.', ['F1'], $support));

        SummaryWriter::fake([$point(['F1.S1']), $point(['F1.S1'])]);
        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa]), 'ar'));

        SummaryWriter::fake([$point(['F1.S1', 'F1.S2'])]);
        $this->assertSame('ok', app(SummaryWriter::class)->write('سؤال', collect([$fatwa]), 'ar')['status']);
    }

    public function test_a_single_json_object_is_read_too(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([json_encode(['kind' => 'agree', 'headline' => 'تذكر المصادر أن الحائض لا تمس المصحف', 'items' => [$this->agreedLine()]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]);

        $this->assertSame('ok', app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar')['status']);
    }

    public function test_point_without_verbatim_support_is_dropped(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->summaryLines(
            $this->headerLine('differ'),
            $this->pointLine('view', 'ما اتفقت عليه المصادر', self::AGREED_BODY, ['F1'], ['F1.S1']),
            $this->pointLine('view', 'قول مخترع', 'كلام لم يرد في أي فتوى.', ['F2'], ['F2.S7']),
            $this->pointLine('view', 'القول الآخر', 'يجوز لها أن تقرأ القرآن من حفظها.', ['F2'], ['F2.S1']),
        )]);

        $result = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar');

        $this->assertSame(['ما اتفقت عليه المصادر', 'القول الآخر'], array_column($result['summary']['views'], 'title'));
    }

    public function test_all_points_dropped_returns_failed(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $bad = $this->summaryLines($this->headerLine(), $this->agreedLine(['support' => ['F2.S1']]));

        SummaryWriter::fake([$bad, $bad]);

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar'));
    }

    public function test_summary_paused_when_budget_exhausted(): void
    {
        [$fatwa] = $this->fatwas();
        Setting::put('monthly_budget_usd', 1);
        AiUsage::query()->create(['purpose' => 'summary', 'model' => 'x', 'input_tokens' => 1, 'output_tokens' => 1, 'cost_usd' => 1, 'counts_toward_budget' => true]);

        SummaryWriter::fake()->preventStrayPrompts();

        $this->assertSame(['status' => 'paused'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa]), 'ar'));
        SummaryWriter::assertNeverPrompted();
    }

    public function test_upstream_failure_degrades(): void
    {
        [$fatwa] = $this->fatwas();

        SummaryWriter::fake(fn () => throw new RuntimeException('provider timeout'));

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa]), 'ar'));
    }

    public function test_malformed_output_degrades(): void
    {
        [$fatwa] = $this->fatwas();

        SummaryWriter::fake(['not a summary', '{"unexpected": "shape"}']);

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa]), 'ar'));
    }

    public function test_english_summary_is_a_translation_of_the_checked_arabic(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->summaryLines($this->headerLine(), $this->agreedLine(['heldBy' => 'مصدر islamweb']))]);
        $this->fakeTranslator([
            'headline' => 'The sources say a menstruating woman does not touch the mushaf',
            'agreed.title' => 'What the sources agree on',
            'agreed.heldBy' => 'Source islamweb',
            'agreed.body' => 'She does not touch the mushaf without a barrier; this is reported from the four imams.',
        ]);

        $summary = app(SummaryWriter::class)->write('Can she touch the Quran?', collect([$fatwa, $other]), 'en')['summary'];

        $this->assertSame('The sources say a menstruating woman does not touch the mushaf', $summary['headline']);
        $this->assertSame('What the sources agree on', $summary['agreed']['title']);
        $this->assertSame('Source islamweb', $summary['agreed']['heldBy']);
        $this->assertSame('She does not touch the mushaf without a barrier; this is reported from the four imams.', $summary['agreed']['body']);
        $this->assertCount(2, $summary['agreed']['evidence']);
        SummaryWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('اكتب الخلاصة بالعربية'));
        SummaryTranslator::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('لا تمسّ الحائض المصحف') && $prompt->contains('مصدر islamweb = Source islamweb'));
    }

    public function test_unfaithful_translation_fails_the_point(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->modelOutput()]);
        $this->fakeTranslator([
            'headline' => 'The sources say a menstruating woman does not touch the mushaf',
            'agreed.title' => 'What the sources agree on',
            'agreed.body' => 'She may touch the mushaf without a barrier, as the four imams agree.',
        ]);
        // Nothing lexical marks this flip (the Arabic states no ruling word), so the judge is what catches it.
        $this->fakeJudge(['agreed.body' => 'contradicted']);

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('Can she touch the Quran?', collect([$fatwa, $other]), 'en'));
    }

    public function test_judge_drops_items_it_does_not_support(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->summaryLines($this->headerLine(), $this->agreedLine(), $this->noteLine('preference', self::PREFERENCE, ['F1'], ['F1.S1']))]);
        $this->fakeJudge(['preference0' => 'not_supported']);

        $summary = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar')['summary'];

        $this->assertSame([], $summary['preferences']);
        $this->assertNotNull($summary['agreed']);
    }

    public function test_judge_contradicting_the_only_point_fails_after_one_rewrite(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->modelOutput(), $this->modelOutput()]);
        $this->fakeJudge(['agreed' => 'contradicted']);

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar'));
        SummaryWriter::assertPromptedTimes(2);
    }

    /**
     * Review round 3: the judge reads each quote with at most its own clause (≤ 40 words), never the whole
     * comma-joined paragraph, so a view the fatwa reports and rejects elsewhere can't be credited to its mufti.
     */
    public function test_judge_reads_each_cited_sentence_inside_its_paragraph(): void
    {
        $fatwa = $this->searchFatwa('قراءة الحائض', 'اختلف العلماء في قراءة الحائض للقرآن، فذهب بعضهم إلى أنه يجوز للحائض أن تقرأ القرآن من حفظها لطول مدة الحيض. '
            .'والصحيح أنه لا يجوز للحائض أن تقرأ القرآن حتى تطهر لعموم الأدلة الواردة في ذلك، وقد سبق تفصيل ذلك في فتاوى كثيرة ومراجع عديدة.');

        SummaryWriter::fake([$this->summaryLines(
            $this->headerLine('agree', 'لا يجوز للحائض أن تقرأ القرآن حتى تطهر'),
            $this->pointLine('agreed', 'ما اتفقت عليه المصادر', 'لا يجوز للحائض أن تقرأ القرآن حتى تطهر لعموم الأدلة.', ['F1'], ['F1.S2']),
        )]);
        $this->recordJudgePrompts();

        $this->assertSame('ok', app(SummaryWriter::class)->write('سؤال', collect([$fatwa]), 'ar')['status']);

        // Review fix round 2: the judge reads the whole paragraph, the cited sentence marked, the reported view before it
        // as unmarked context.
        $this->assertStringContainsString('⟦والصحيح أنه لا يجوز للحائض أن تقرأ القرآن حتى تطهر', $this->judgePrompts[0]);
        $this->assertStringContainsString('اختلف العلماء في قراءة الحائض للقرآن، فذهب بعضهم إلى أنه يجوز', $this->judgePrompts[0]);
        $this->assertStringNotContainsString('⟦اختلف', $this->judgePrompts[0]);
        $this->assertStringContainsString('ذهب بعضهم', app(EntailmentJudge::class)->instructions());
    }

    public function test_rewrite_is_skipped_when_the_first_draft_spent_the_budget(): void
    {
        [$fatwa, $other] = $this->fatwas();
        Setting::put('monthly_budget_usd', 1);
        $bad = $this->summaryLines($this->headerLine(), $this->agreedLine(['support' => ['F1.S99']]));

        SummaryWriter::fake(function () use ($bad): string {
            AiUsage::query()->create(['purpose' => 'summary', 'model' => 'x', 'input_tokens' => 1, 'output_tokens' => 1, 'cost_usd' => 1, 'counts_toward_budget' => true]);

            return $bad;
        });

        $this->assertSame(['status' => 'paused'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar'));
        SummaryWriter::assertPromptedTimes(1);
    }

    public function test_judge_failure_fails_closed(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->modelOutput()]);
        EntailmentJudge::fake(fn () => throw new RuntimeException('judge timeout'));

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar'));
    }

    public function test_failed_draft_is_rewritten_once_with_the_reasons(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $bad = $this->summaryLines($this->headerLine(), $this->agreedLine(['support' => ['F1.S99']]));

        SummaryWriter::fake([$bad, $this->modelOutput()]);

        $result = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar');

        $this->assertSame('ok', $result['status']);
        SummaryWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('rejected by the automatic checker') && $prompt->contains('no support'));
    }

    public function test_agree_headline_goes_through_the_judge(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->modelOutput()]);
        $this->fakeJudge(['headline' => 'not_supported']);

        $summary = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar')['summary'];

        $this->assertSame($summary['agreed']['title'], $summary['headline']);
    }

    public function test_translation_judge_failure_fails_closed(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->modelOutput()]);
        $this->fakeTranslator([
            'headline' => 'The sources say a menstruating woman does not touch the mushaf',
            'agreed.title' => 'What the sources agree on',
            'agreed.body' => 'She does not touch the mushaf without a barrier; this is reported from the four imams.',
        ]);
        EntailmentJudge::fake(function (string $prompt): array {
            if (str_contains($prompt, 'CLAIM (English)')) {
                throw new RuntimeException('judge down');
            }

            preg_match_all('/^### (\S+)/mu', $prompt, $ids);

            return ['verdicts' => array_map(fn (string $id): array => ['id' => $id, 'verdict' => 'supported', 'reason' => ''], $ids[1])];
        });

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('Can she touch the Quran?', collect([$fatwa, $other]), 'en'));
    }

    public function test_translation_failure_degrades(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->modelOutput()]);
        SummaryTranslator::fake(fn () => throw new RuntimeException('haiku down'));

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('Can she touch the Quran?', collect([$fatwa, $other]), 'en'));
    }

    public function test_cross_referenced_fatwas_are_read_and_returned_when_cited(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $crossRef = $this->searchFatwa('حكم مس المصحف بحائل', 'يجوز مس المصحف بحائل منفصل عنه كالقفاز أو الخرقة الطاهرة عند الحاجة.');
        $unused = $this->searchFatwa('فتوى أخرى', 'نص لا علاقة له بالسؤال المطروح في هذه المسألة.');
        FatwaLink::query()->create(['fatwa_id' => $fatwa->id, 'target_source_fatwa_id' => $crossRef->source_fatwa_id, 'target_fatwa_id' => $crossRef->id]);
        FatwaLink::query()->create(['fatwa_id' => $fatwa->id, 'target_source_fatwa_id' => $unused->source_fatwa_id, 'target_fatwa_id' => $unused->id, 'position' => 1]);

        SummaryWriter::fake([$this->summaryLines($this->headerLine(), $this->agreedLine([
            'body' => self::AGREED_BODY.' ويجوز مسه بحائل منفصل كالقفاز.',
            'citations' => ['F1', 'F3'],
            'support' => ['F1.S1', 'F3.S1'],
        ]))]);

        $summary = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar')['summary'];

        $this->assertSame([$fatwa->public_id, $crossRef->public_id], $summary['agreed']['citations']);
        $this->assertSame([$crossRef->public_id], $summary['extraFatwaIds']);
        SummaryWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('id="F3" crossref-of="F1"')
            && $prompt->contains('id="F4" crossref-of="F1"'));
    }

    public function test_preference_must_name_its_source(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->summaryLines(
            $this->headerLine(),
            $this->agreedLine(),
            $this->noteLine('preference', 'ويرى بعض أهل العلم أنه لا يجوز للحائض مس المصحف.', ['F1'], ['F1.S1']),
            $this->noteLine('preference', 'ويرى مصدر islamweb أنه لا يجوز للحائض مس المصحف.', ['F1'], ['F1.S1']),
        )]);

        $summary = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar')['summary'];

        $this->assertSame(['ويرى مصدر islamweb أنه لا يجوز للحائض مس المصحف.'], array_column($summary['preferences'], 'text'));
    }

    public function test_cost_and_duration_are_recorded_toward_the_budget(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->modelOutput()]);

        app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar');

        $usage = AiUsage::query()->where('purpose', SummaryWriter::PURPOSE)->sole();
        $this->assertTrue($usage->counts_toward_budget);
        $this->assertNotNull($usage->duration_ms);
        $this->assertNotContains(null, AiUsage::query()->where('purpose', EntailmentJudge::PURPOSE)->pluck('duration_ms')->all());
    }

    public function test_evidence_from_an_uncited_fatwa_or_unknown_marker_is_dropped(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->summaryLines($this->headerLine(), $this->agreedLine(['evidence' => ['F2.E1', 'F1.E99', 'E1', 'F1.S1']]))]);

        $result = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar');

        $this->assertSame([], $result['summary']['agreed']['evidence']);
    }

    public function test_rewrite_is_skipped_once_the_time_budget_is_spent(): void
    {
        config(['fatwarag.summary.rewrite_budget_seconds' => 0]);
        Log::spy();
        [$fatwa, $other] = $this->fatwas();
        $bad = $this->summaryLines($this->headerLine(), $this->agreedLine(['support' => ['F1.S99']]));

        SummaryWriter::fake([$bad, $this->modelOutput()]);

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('سؤال سري عن المصحف', collect([$fatwa, $other]), 'ar'));
        SummaryWriter::assertPromptedTimes(1);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'time budget')
            && ! str_contains($message.json_encode($context, JSON_UNESCAPED_UNICODE), 'سؤال سري'))->once();
    }

    public function test_every_listed_fatwa_is_read_whole(): void
    {
        $filler = fn (int $i): array => [['type' => 'text', 'text' => "فقرة استطرادية رقم {$i} في تاريخ المسألة وأقوال المتقدمين وتفاصيل لا تمس السؤال المطروح هنا ولا تفيده كثيرا بل تطيل الكلام."]];
        $long = $this->searchFatwa('قراءة الحائض للقرآن', question: 'هل يجوز للحائض قراءة القرآن؟ '.str_repeat('وأريد تفصيلا في المسألة من فضلكم. ', 40), answer: [
            [['type' => 'text', 'text' => 'اختلف أهل العلم في قراءة الحائض للقرآن على قولين مشهورين.']],
            ...array_map($filler, range(1, 30)),
            [['type' => 'text', 'text' => 'واستدل المانعون بقوله ﷺ: '], ['type' => 'hadith', 'text' => self::HADITH, 'quoteKey' => Arabic::quoteKey(self::HADITH)]],
            [['type' => 'text', 'text' => 'والله أعلم.']],
        ]);
        $others = array_map(fn (int $i): Fatwa => $this->searchFatwa("فتوى رقم {$i}", "نص الفتوى رقم {$i} في المسألة كاملا."), range(2, 5));

        SummaryWriter::fake([$this->modelOutput()]);

        app(SummaryWriter::class)->write('هل يجوز للحائض قراءة القرآن؟', collect([$long, ...$others]), 'ar');

        SummaryWriter::assertPrompted(function (AgentPrompt $prompt) use (&$text): bool {
            $text = $prompt->prompt;

            return true;
        });
        // All five, each with its whole question and answer: every paragraph, the closing formula, no gap marks.
        $this->assertStringContainsString('<fatwa id="F5"', $text);
        $this->assertStringContainsString('نص الفتوى رقم 5 في المسألة كاملا.', $text);
        $this->assertStringContainsString(trim(str_repeat('وأريد تفصيلا في المسألة من فضلكم. ', 40)), $text);
        $this->assertStringContainsString('فقرة استطرادية رقم 30 في تاريخ المسألة', $text);
        $this->assertStringContainsString('[F1.E1 حديث]«'.self::HADITH.'»', $text);
        $this->assertStringContainsString('والله أعلم.', $text);
        $this->assertStringNotContainsString('…', $text);
    }

    public function test_cross_referenced_fatwas_are_read_whole_at_most_two_per_fatwa_each_once(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $long = 'يجوز مس المصحف بحائل منفصل عنه كالقفاز أو الخرقة الطاهرة عند الحاجة. '.str_repeat('وهذا كلام طويل في تفصيل المسألة وأدلتها عند أهل العلم. ', 120).'وهذا آخر الفتوى المحال إليها.';
        [$first, $second, $third] = array_map(fn (int $i): Fatwa => $this->searchFatwa("فتوى محال إليها {$i}", $i === 1 ? $long : "نص الفتوى المحال إليها رقم {$i}."), [1, 2, 3]);

        foreach ([$first, $second, $third] as $position => $target) {
            FatwaLink::query()->create(['fatwa_id' => $fatwa->id, 'target_source_fatwa_id' => $target->source_fatwa_id, 'target_fatwa_id' => $target->id, 'position' => $position]);
        }

        FatwaLink::query()->create(['fatwa_id' => $other->id, 'target_source_fatwa_id' => $first->source_fatwa_id, 'target_fatwa_id' => $first->id]);

        SummaryWriter::fake([$this->modelOutput()]);

        app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar');

        SummaryWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('id="F3" crossref-of="F1"')
            && $prompt->contains('id="F4" crossref-of="F1"')
            && ! $prompt->contains('id="F5"')
            && $prompt->contains('وهذا آخر الفتوى المحال إليها.')
            && ! $prompt->contains('نص الفتوى المحال إليها رقم 3'));
    }

    public function test_past_the_model_context_whole_cross_references_are_left_out_first(): void
    {
        Log::spy();
        [$fatwa, $other] = $this->fatwas();
        $crossRef = $this->searchFatwa('فتوى محال إليها', str_repeat('وهذا كلام طويل في تفصيل المسألة وأدلتها عند أهل العلم. ', 400));
        FatwaLink::query()->create(['fatwa_id' => $fatwa->id, 'target_source_fatwa_id' => $crossRef->source_fatwa_id, 'target_fatwa_id' => $crossRef->id]);
        // Room for the two listed fatwas, not for the long one they refer to.
        config(['fatwarag.summary.max_input_tokens' => (int) ((mb_strlen(app(SummaryWriter::class)->instructions()) + 2000 + 4000) / 2)]);

        SummaryWriter::fake([$this->modelOutput()]);

        $this->assertSame('ok', app(SummaryWriter::class)->write('سؤال سري عن المصحف', collect([$fatwa, $other]), 'ar')['status']);

        SummaryWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('<fatwa id="F2"') && $prompt->contains('لطول مدة الحيض')
            && ! $prompt->contains('crossref-of') && ! $prompt->contains('وهذا كلام طويل'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'model context')
            && $context['cross_refs_dropped'] === 1 && $context['fatwas_dropped'] === 0
            && ! str_contains($message.json_encode($context, JSON_UNESCAPED_UNICODE), 'سؤال سري'))->once();
    }

    public function test_past_the_model_context_listed_fatwas_go_only_after_every_cross_reference_lowest_ranked_first(): void
    {
        Log::spy();
        [$fatwa, $other] = $this->fatwas();
        $third = $this->searchFatwa('فتوى ثالثة طويلة', str_repeat('وهذا كلام طويل في الفتوى الثالثة وتفصيلها عند أهل العلم. ', 60));
        $crossRef = $this->searchFatwa('فتوى محال إليها قصيرة', 'نص قصير للفتوى المحال إليها.');
        FatwaLink::query()->create(['fatwa_id' => $fatwa->id, 'target_source_fatwa_id' => $crossRef->source_fatwa_id, 'target_fatwa_id' => $crossRef->id]);
        // Room for the first two listed fatwas and the short cross-reference, not for the long third fatwa too.
        config(['fatwarag.summary.max_input_tokens' => (int) ((mb_strlen(app(SummaryWriter::class)->instructions()) + 2000 + 2500) / 2)]);

        SummaryWriter::fake([$this->modelOutput()]);

        app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other, $third]), 'ar');

        // The cross-reference went first, though it would have fit beside the first two; then the lowest-ranked fatwa.
        SummaryWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('<fatwa id="F2"') && ! $prompt->contains('id="F3"')
            && ! $prompt->contains('نص قصير للفتوى المحال إليها'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'model context')
            && $context['cross_refs_dropped'] === 1 && $context['fatwas_dropped'] === 1)->once();
    }

    public function test_english_drops_the_translation_of_an_item_the_judge_drops(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->summaryLines($this->headerLine(), $this->agreedLine(), $this->noteLine('preference', self::PREFERENCE, ['F1'], ['F1.S1']))]);
        $this->fakeTranslator([
            'headline' => 'The sources say a menstruating woman does not touch the mushaf',
            'agreed.title' => 'What the sources agree on',
            'agreed.body' => 'She does not touch the mushaf without a barrier; this is reported from the four imams.',
            'preference0.text' => 'Islamweb holds that it is not permissible for her to touch the mushaf without a barrier.',
        ]);
        $prompts = [];
        EntailmentJudge::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;
            preg_match_all('/^### (\S+)/mu', $prompt, $ids);

            return ['verdicts' => array_map(fn (string $id): array => ['id' => $id, 'verdict' => in_array($id, ['preference0', 'headline'], true) ? 'not_supported' : 'supported', 'reason' => ''], $ids[1])];
        });

        $summary = app(SummaryWriter::class)->write('Can she touch the Quran?', collect([$fatwa, $other]), 'en')['summary'];

        // Translated alongside the judge, from the lexically checked Arabic, preference included.
        SummaryTranslator::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('preference0.text'));
        $this->assertSame([], $summary['preferences']);
        // The judged-out headline falls back to the agreed title, and so does its English.
        $this->assertSame('What the sources agree on', $summary['headline']);
        $translationChecks = array_values(array_filter($prompts, fn (string $prompt): bool => str_contains($prompt, 'CLAIM (English)')));
        $this->assertCount(1, $translationChecks);
        $this->assertStringNotContainsString('Islamweb holds', $translationChecks[0]);
        $this->assertStringNotContainsString('The sources say a menstruating', $translationChecks[0]);
    }

    public function test_items_stream_in_display_order_once_checked_and_match_the_final_summary(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->summaryLines(
            $this->headerLine(),
            $this->agreedLine(),
            $this->noteLine('preference', 'ويرى مصدر islamweb أن الحائض لا تمس المصحف.', ['F1'], ['F1.S1']),
            $this->noteLine('preference', self::PREFERENCE, ['F1'], ['F1.S1']),
        )]);
        $this->fakeJudge(['preference0' => 'not_supported']);
        $events = [];

        $result = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar', function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        });

        $this->assertSame(['reading', 'writing', 'checking'], array_column(array_column(array_filter($events, fn (array $event): bool => $event[0] === 'stage'), 1), 'stage'));
        $items = array_column(array_filter($events, fn (array $event): bool => $event[0] === 'item'), 1);
        $this->assertSame([['agreed', 0], ['preference', 0]], array_map(fn (array $item): array => [$item['section'], $item['index']], array_values($items)));
        $this->assertSame($result['summary']['agreed'], array_values($items)[0]['item']);
        $this->assertSame($result['summary']['preferences'][0], array_values($items)[1]['item']);
    }

    public function test_a_disagreement_shows_no_view_until_two_have_passed_and_never_a_lone_view_that_doesnt_say_whose(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $differ = $this->summaryLines(
            $this->headerLine('differ'),
            $this->agreedLine(['title' => 'ما اتفقت عليه المصادر']),
            $this->pointLine('view', 'القول الأول', self::AGREED_BODY, ['F1'], ['F1.S1']),
            $this->pointLine('view', 'القول الثاني', 'يجوز لها أن تقرأ القرآن من حفظها.', ['F2'], ['F2.S1']),
        );
        $events = [];
        $record = function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        };

        // One side fails the judge and the other doesn't say whose view it is: no view is shown, only the agreed point
        // with the line pointing to the fatwas for the views.
        SummaryWriter::fake([$differ]);
        $this->fakeJudge(['view1' => 'not_supported']);

        $result = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar', $record);
        $this->assertSame('differ', $result['summary']['kind']);
        $this->assertSame([], $result['summary']['views']);
        $this->assertSame(GroundingCheck::NOTE_DETAILS, $result['summary']['note']); // no view shown
        $this->assertSame([['agreed', 0]], array_map(fn (array $event): array => [$event[1]['section'], $event[1]['index']], array_values(array_filter($events, fn (array $event): bool => $event[0] === 'item'))));

        // Both pass: the agreed point first, then both views.
        $events = [];
        SummaryWriter::fake([$differ]);
        $this->fakeJudge();

        $result = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar', $record);

        $items = array_values(array_column(array_filter($events, fn (array $event): bool => $event[0] === 'item'), 1));
        $this->assertSame([['agreed', 0], ['view', 0], ['view', 1]], array_map(fn (array $item): array => [$item['section'], $item['index']], $items));
        $this->assertSame($result['summary']['views'], array_column(array_slice($items, 1), 'item'));
    }

    public function test_english_items_stream_translated(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->modelOutput()]);
        $this->fakeTranslator([
            'headline' => 'The sources say a menstruating woman does not touch the mushaf',
            'agreed.title' => 'What the sources agree on',
            'agreed.body' => 'She does not touch the mushaf without a barrier; this is reported from the four imams.',
        ]);
        $events = [];

        $result = app(SummaryWriter::class)->write('Can she touch the Quran?', collect([$fatwa, $other]), 'en', function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        });

        $this->assertContains(['stage', ['stage' => 'translating']], $events);
        $items = array_values(array_column(array_filter($events, fn (array $event): bool => $event[0] === 'item'), 1));
        $this->assertSame($result['summary']['agreed'], $items[0]['item']);
        $this->assertSame('She does not touch the mushaf without a barrier; this is reported from the four imams.', $items[0]['item']['body']);
    }

    public function test_a_draft_that_can_no_longer_pass_is_stopped_and_rewritten_at_once(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $doomed = $this->summaryLines(
            $this->headerLine(),
            $this->agreedLine(['support' => ['F1.S99']]),
            $this->noteLine('preference', self::PREFERENCE, ['F1'], ['F1.S99']),
            $this->noteLine('caution', self::PREFERENCE, ['F1'], ['F1.S99']),
            $this->noteLine('caution', self::PREFERENCE, ['F1'], ['F1.S1']),
        );

        SummaryWriter::fake([$doomed, $doomed]);
        EntailmentJudge::fake()->preventStrayPrompts();

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar'));

        // The agreed point and the preference both fail the safeguard and the draft has moved past them: it is stopped
        // there, nothing is judged, and both drafts' tokens are counted.
        EntailmentJudge::assertNeverPrompted();
        SummaryWriter::assertPromptedTimes(2);
        $this->assertSame(2, AiUsage::query()->where('purpose', SummaryWriter::PURPOSE)->where('output_tokens', '>', 0)->count());
    }

    /**
     * Review fix round 1: a cited list item after a reporting lead-in («…أقوالا منها:») goes to the judge with that
     * whole sentence, and a point resting only on it is dropped.
     */
    public function test_a_view_reported_by_a_lead_in_is_never_shown_as_the_fatwas_ruling(): void
    {
        $fatwa = $this->searchFatwa('الجمع للمسافر', answer: [
            [['type' => 'text', 'text' => 'وذكر بعض الفقهاء في هذه المسألة أقوالا كثيرة منها:']],
            [['type' => 'text', 'text' => 'يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.']],
            [['type' => 'text', 'text' => 'والراجح عندنا أنه لا يجوز الجمع إلا لعذر بين في السفر الطويل.']],
        ]);
        $line = fn (string $body): string => $this->summaryLines($this->headerLine('agree', 'الجمع للمسافر'), $this->pointLine('agreed', 'الجمع للمسافر', $body, ['F1'], ['F1.S2']));

        SummaryWriter::fake([$line('يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.'), $line('يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.')]);
        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa]), 'ar'));

        SummaryWriter::fake([$line('ذكر بعض الفقهاء أنه يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.')]);
        $this->recordJudgePrompts();
        $this->assertSame('ok', app(SummaryWriter::class)->write('سؤال', collect([$fatwa]), 'ar')['status']);
        // It opens its paragraph, so the judge also reads the paragraph before.
        $this->assertStringContainsString('وذكر بعض الفقهاء في هذه المسألة أقوالا كثيرة منها: ⟦يجوز للمسافر أن يجمع بين الظهر والعصر في وقت إحداهما إذا جد به السير.⟧', $this->judgePrompts[0]);
    }

    /**
     * Review fix rounds 1–2: leaving the contradicting sentence out of the support doesn't hide it: a cited fatwa that
     * gives the opposite ruling on the same thing elsewhere rejects the point.
     */
    public function test_a_cited_fatwas_opposite_ruling_elsewhere_rejects_the_point(): void
    {
        $fatwa = $this->searchFatwa('خاتم الذهب', answer: [
            [['type' => 'text', 'text' => 'يحرم على الرجل لبس خاتم الذهب لورود النهي الصريح عن ذلك في الأحاديث الصحيحة.']],
            [['type' => 'text', 'text' => 'ويجوز للرجل لبس خاتم الفضة لورود ذلك في السنة الصحيحة عن النبي.']],
        ]);
        // Cites only the silver-ring sentence for a claim about the gold ring.
        $line = $this->summaryLines(
            $this->headerLine('agree', 'خاتم الذهب'),
            $this->pointLine('agreed', 'خاتم الذهب', 'يجوز للرجل لبس خاتم الذهب لورود ذلك في السنة الصحيحة.', ['F1'], ['F1.S2']),
        );

        SummaryWriter::fake([$line, $line]);
        EntailmentJudge::fake()->preventStrayPrompts();

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa]), 'ar'));
        EntailmentJudge::assertNeverPrompted();
        SummaryWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('a cited fatwa gives a different ruling on the same thing'));
    }

    public function test_a_reader_who_leaves_stops_the_summary_with_no_rewrite(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $bad = $this->summaryLines($this->headerLine(), $this->agreedLine(['support' => ['F1.S99']]));

        SummaryWriter::fake([$bad, $this->modelOutput()]);
        $asked = 0;

        $result = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar', null, function () use (&$asked): bool {
            return ++$asked < 3;
        });

        $this->assertSame(['status' => 'failed'], $result);
        SummaryWriter::assertPromptedTimes(1);
        // Review fix round 2: the stopped draft's tokens are still counted.
        $this->assertGreaterThan(0, AiUsage::query()->where('purpose', SummaryWriter::PURPOSE)->sum('output_tokens'));
    }

    public function test_a_summary_past_its_wall_clock_limit_stops_as_failed(): void
    {
        config(['fatwarag.summary.wall_seconds' => 0]);
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->modelOutput(), $this->modelOutput()]);

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar'));
        SummaryWriter::assertPromptedTimes(1);
        $this->assertSame(1, AiUsage::query()->where('purpose', SummaryWriter::PURPOSE)->count());
    }

    public function test_a_rewrite_abandons_the_earlier_drafts_pending_checks(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $tasks = new class('sync') extends SummaryTasks
        {
            public int $cancelled = 0;

            public function cancel(): void
            {
                $this->cancelled++;
                parent::cancel();
            }
        };
        $this->app->instance(SummaryTasks::class, $tasks);

        SummaryWriter::fake([$this->summaryLines($this->headerLine(), $this->agreedLine(['support' => ['F1.S99']])), $this->modelOutput()]);

        $this->assertSame('ok', app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar')['status']);
        // Before each of the two drafts, and once more when the summary ends.
        $this->assertSame(3, $tasks->cancelled);
    }

    /**
     * Owner decision: with the claims judge off (the default), an item is shown as soon as it passes every lexical
     * check, and the judge is never asked.
     */
    public function test_without_the_claims_judge_items_stand_on_the_lexical_checks(): void
    {
        config(['fatwarag.summary.claims_judge' => false]);
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->summaryLines(
            $this->headerLine(),
            $this->agreedLine(),
            $this->noteLine('preference', self::PREFERENCE, ['F1'], ['F1.S1']),
            $this->noteLine('preference', 'ويرى بعض أهل العلم أنه لا يجوز للحائض مس المصحف.', ['F1'], ['F1.S1']),
        )]);
        EntailmentJudge::fake()->preventStrayPrompts();
        $events = [];

        $result = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar', function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        });

        EntailmentJudge::assertNeverPrompted();
        $this->assertSame('ok', $result['status']);
        $this->assertSame([self::PREFERENCE], array_column($result['summary']['preferences'], 'text'));
        $this->assertSame([['agreed', 0], ['preference', 0]], array_map(fn (array $event): array => [$event[1]['section'], $event[1]['index']], array_values(array_filter($events, fn (array $event): bool => $event[0] === 'item'))));
    }

    public function test_without_the_claims_judge_english_still_goes_through_the_translation_judge(): void
    {
        config(['fatwarag.summary.claims_judge' => false]);
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->modelOutput()]);
        $this->fakeTranslator([
            'headline' => 'The sources say a menstruating woman does not touch the mushaf',
            'agreed.title' => 'What the sources agree on',
            'agreed.body' => 'She may touch the mushaf without a barrier, as the four imams agree.',
        ]);
        $this->recordJudgePrompts();
        $this->fakeJudge(['agreed.body' => 'contradicted']);

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('Can she touch the Quran?', collect([$fatwa, $other]), 'en'));
        EntailmentJudge::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('CLAIM (English)'));
        EntailmentJudge::assertNotPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Check each claim against its evidence'));
    }

    /**
     * Review fix round 4: step processes boot only when a step will run (the claims judge, or the translation for
     * English), not for an Arabic summary with the judge off.
     */
    public function test_step_processes_are_warmed_only_when_a_step_will_run(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $lines = $this->summaryLines($this->headerLine(), $this->agreedLine());

        foreach ([[false, 'ar', false], [true, 'ar', true], [false, 'en', true]] as [$judge, $locale, $warmed]) {
            config(['fatwarag.summary.claims_judge' => $judge]);
            SummaryWriter::fake([$lines]);
            $this->fakeTranslator([]);
            $this->fakeJudge([]);
            $tasks = Mockery::mock(SummaryTasks::class, ['sync'])->makePartial();
            $expectation = $tasks->shouldReceive('warm')->passthru();
            $warmed ? $expectation->atLeast()->once() : $expectation->never();
            $this->app->instance(SummaryTasks::class, $tasks);

            app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), $locale);
            $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
            Mockery::close();
        }
    }

    public function test_a_line_for_an_earlier_part_after_a_later_one_is_ignored(): void
    {
        [$fatwa, $other] = $this->fatwas();

        SummaryWriter::fake([$this->summaryLines(
            $this->headerLine(),
            $this->noteLine('preference', self::PREFERENCE, ['F1'], ['F1.S1']),
            $this->agreedLine(),
        )]);

        // The agreed point after the preference is ignored; the preference that passed stands, under a neutral headline.
        $summary = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar')['summary'];
        $this->assertNull($summary['agreed']);
        $this->assertSame([self::PREFERENCE], array_column($summary['preferences'], 'text'));
        $this->assertSame(GroundingCheck::SOURCES_SAY_HEADLINE, $summary['headline']);
    }

    public function test_a_sentence_the_judge_finds_unsupported_is_pruned_and_the_rest_judged_again_for_what_it_left_out(): void
    {
        config(['fatwarag.summary.prune' => true]); // pruning is off by default for v1
        [$fatwa, $other] = $this->fatwas();
        $kept = 'لا يجوز للحائض أن تمسّ المصحف من غير حائل باتفاق الأئمة الأربعة.';
        SummaryWriter::fake([$this->summaryLines(
            $this->headerLine(),
            $this->agreedLine(['body' => $kept.' ونُقل ذلك عن الأئمة الأربعة.']),
        )]);
        $prompts = [];
        // The claims judge names sentence 2 of the agreed point.
        EntailmentJudge::fake(fn (): array => ['verdicts' => [['id' => 'agreed', 'verdict' => 'not_supported', 'reason' => 'faked', 'sentences' => [2]]]]);
        $this->fakePruneJudge($prompts);
        $events = [];

        $result = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar', function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        });

        $this->assertSame($kept, $result['summary']['agreed']['body']);
        $this->assertSame(GroundingCheck::NOTE_DETAILS, $result['summary']['note']); // a sentence was pruned
        // The prune is judged once more, with the kept text and the removed sentence side by side.
        $this->assertCount(1, $prompts);
        $this->assertStringContainsString("KEPT TEXT:\n", $prompts[0]);
        $this->assertStringContainsString("REMOVED SENTENCES:\n- ونُقل ذلك عن الأئمة الأربعة.", $prompts[0]);
        $items = array_values(array_column(array_filter($events, fn (array $event): bool => $event[0] === 'item'), 1));
        $this->assertSame([$result['summary']['agreed']], array_column($items, 'item'));
    }

    public function test_with_pruning_off_a_judge_named_sentence_fails_its_item_whole_and_the_rest_survives(): void
    {
        [$fatwa, $other] = $this->fatwas();
        SummaryWriter::fake([$this->summaryLines(
            $this->headerLine(),
            $this->agreedLine(['body' => 'لا يجوز للحائض أن تمسّ المصحف من غير حائل باتفاق الأئمة الأربعة. ونُقل ذلك عن الأئمة الأربعة.']),
            $this->noteLine('preference', self::PREFERENCE, ['F1'], ['F1.S1']),
        )]);
        EntailmentJudge::fake(fn (string $prompt): array => ['verdicts' => str_contains($prompt, '### agreed')
            ? [['id' => 'agreed', 'verdict' => 'not_supported', 'reason' => 'faked', 'sentences' => [2]]]
            : [['id' => 'preference0', 'verdict' => 'supported', 'reason' => 'faked', 'sentences' => []]]]);
        PruneJudge::fake()->preventStrayPrompts();

        $summary = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar')['summary'];

        $this->assertNull($summary['agreed']);
        $this->assertSame([self::PREFERENCE], array_column($summary['preferences'], 'text'));
        $this->assertSame(GroundingCheck::NOTE_DETAILS, $summary['note']);
        PruneJudge::assertNeverPrompted();
    }

    /**
     * @return array<string, array{0: array<string, mixed>|null}>
     */
    public static function omissionVerdicts(): array
    {
        $pass = ['verdict' => 'supported', 'drops_condition' => false, 'dangling_reference' => false, 'wrong_holder' => false];

        return [
            'a dropped condition' => [[...$pass, 'drops_condition' => true]],
            'a reference re-pointed' => [[...$pass, 'dangling_reference' => true]],
            'a holder changed' => [[...$pass, 'wrong_holder' => true]],
            'not supported' => [[...$pass, 'verdict' => 'not_supported']],
            'no verdict' => [null],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $verdict
     */
    #[DataProvider('omissionVerdicts')]
    public function test_a_prune_the_omission_check_flags_is_dropped_never_shown_pruned(?array $verdict): void
    {
        config(['fatwarag.summary.prune' => true]); // pruning is off by default for v1
        [$fatwa, $other] = $this->fatwas();
        SummaryWriter::fake([$this->summaryLines(
            $this->headerLine(),
            $this->agreedLine(['body' => 'لا يجوز للحائض أن تمسّ المصحف من غير حائل باتفاق الأئمة الأربعة. ونُقل ذلك عن الأئمة الأربعة.']),
            $this->noteLine('preference', self::PREFERENCE, ['F1'], ['F1.S1']),
        )]);
        EntailmentJudge::fake(fn (string $prompt): array => ['verdicts' => str_contains($prompt, '### agreed')
            ? [['id' => 'agreed', 'verdict' => 'not_supported', 'reason' => 'faked', 'sentences' => [2]]]
            : [['id' => 'preference0', 'verdict' => 'supported', 'reason' => 'faked', 'sentences' => []]]]);
        PruneJudge::fake(fn (): array => ['verdicts' => $verdict === null ? [] : [['id' => 'agreed', 'reason' => 'faked', ...$verdict]]]);

        $summary = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar')['summary'];

        // The agreed point is dropped; the rest of the summary stands, pointing to the fatwas for the details.
        $this->assertNull($summary['agreed']);
        $this->assertSame([self::PREFERENCE], array_column($summary['preferences'], 'text'));
        $this->assertSame(GroundingCheck::NOTE_DETAILS, $summary['note']);
    }

    public function test_a_failed_omission_check_drops_only_the_pruned_item(): void
    {
        config(['fatwarag.summary.prune' => true]); // pruning is off by default for v1
        [$fatwa, $other] = $this->fatwas();
        SummaryWriter::fake([$this->summaryLines(
            $this->headerLine(),
            $this->agreedLine(['body' => 'لا يجوز للحائض أن تمسّ المصحف من غير حائل باتفاق الأئمة الأربعة. ونُقل ذلك عن الأئمة الأربعة.']),
            $this->noteLine('preference', self::PREFERENCE, ['F1'], ['F1.S1']),
        )]);
        EntailmentJudge::fake(fn (string $prompt): array => ['verdicts' => str_contains($prompt, '### agreed')
            ? [['id' => 'agreed', 'verdict' => 'not_supported', 'reason' => 'faked', 'sentences' => [2]]]
            : [['id' => 'preference0', 'verdict' => 'supported', 'reason' => 'faked', 'sentences' => []]]]);
        PruneJudge::fake(fn () => throw new RuntimeException('down'));

        $summary = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar')['summary'];

        $this->assertNull($summary['agreed']);
        $this->assertCount(1, $summary['preferences']);
    }

    /**
     * Fakes the omission check as passing every pruned item, keeping its prompts.
     *
     * @param  list<string>  $prompts
     */
    private function fakePruneJudge(array &$prompts): void
    {
        PruneJudge::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;
            preg_match_all('/^### (\S+)/mu', $prompt, $ids);

            return ['verdicts' => array_map(fn (string $id): array => ['id' => $id, 'reason' => 'faked', 'verdict' => 'supported', 'drops_condition' => false, 'dangling_reference' => false, 'wrong_holder' => false], $ids[1])];
        });
    }

    public function test_the_writers_details_flag_adds_the_details_note_and_a_missing_or_false_flag_changes_nothing(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $notes = [];

        foreach ([['details' => true], ['details' => false], []] as $flag) {
            SummaryWriter::fake([$this->summaryLines([...$this->headerLine(), ...$flag], $this->agreedLine())]);
            $this->fakeJudge();
            $notes[] = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar')['summary']['note'];
        }

        $this->assertSame([GroundingCheck::NOTE_DETAILS, null, null], $notes);
    }

    public function test_the_writers_details_flag_never_overrides_the_other_views_note(): void
    {
        [$fatwa, $other] = $this->fatwas();
        SummaryWriter::fake([$this->summaryLines(
            [...$this->headerLine('differ'), 'details' => true],
            $this->pointLine('view', 'القول الأول', self::AGREED_BODY, ['F1'], ['F1.S1']),
            $this->pointLine('view', 'القول الثاني', 'يجوز لها أن تقرأ القرآن من حفظها لطول مدة الحيض.', ['F2'], ['F2.S1'], heldBy: 'الشيخ ابن باز'),
        )]);
        $this->fakeJudge(['view0' => 'not_supported']);

        $this->assertSame(GroundingCheck::NOTE_OTHER_VIEWS, app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar')['summary']['note']);
    }

    public function test_a_judged_sentence_whose_removal_leaves_nothing_meaningful_drops_the_point(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $output = $this->summaryLines(
            $this->headerLine(),
            $this->agreedLine(['body' => 'لا يجوز للحائض أن تمسّ المصحف من غير حائل باتفاق الأئمة الأربعة. ونُقل ذلك عنهم.']),
        );
        SummaryWriter::fake([$output, $output]);
        // The judge names the lead sentence, which carries the point's ruling (its title states none): nothing is left.
        EntailmentJudge::fake(fn (): array => ['verdicts' => [['id' => 'agreed', 'verdict' => 'not_supported', 'reason' => 'faked', 'sentences' => [1]]]]);

        $this->assertSame(['status' => 'failed'], app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar'));
        EntailmentJudge::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '### agreed'));
    }

    public function test_a_disagreement_left_with_one_side_shows_it_attributed_and_points_to_the_other_views(): void
    {
        [$fatwa, $other] = $this->fatwas();
        SummaryWriter::fake([$this->summaryLines(
            $this->headerLine('differ'),
            $this->pointLine('view', 'القول الأول', self::AGREED_BODY, ['F1'], ['F1.S1']),
            $this->pointLine('view', 'القول الثاني', 'يجوز لها أن تقرأ القرآن من حفظها لطول مدة الحيض.', ['F2'], ['F2.S1'], heldBy: 'الشيخ ابن باز'),
        )]);
        $this->fakeJudge(['view0' => 'not_supported']);
        $events = [];

        $result = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar', function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        });

        $summary = $result['summary'];
        $this->assertSame('differ', $summary['kind']);
        $this->assertSame(GroundingCheck::SOURCES_SAY_HEADLINE, $summary['headline']);
        $this->assertSame(GroundingCheck::NOTE_OTHER_VIEWS, $summary['note']);
        $this->assertSame(['يجوز لها أن تقرأ القرآن من حفظها لطول مدة الحيض.'], array_column($summary['views'], 'body'));
        $this->assertNotNull($summary['views'][0]['heldBy']);
        $items = array_values(array_column(array_filter($events, fn (array $event): bool => $event[0] === 'item'), 1));
        $this->assertSame([['view', 0]], array_map(fn (array $item): array => [$item['section'], $item['index']], $items));
        $this->assertSame($summary['views'][0], $items[0]['item']);
    }

    public function test_a_summary_fails_only_when_nothing_at_all_survives(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $output = $this->summaryLines(
            $this->headerLine('differ'),
            $this->pointLine('view', 'القول الأول', self::AGREED_BODY, ['F1'], ['F1.S1']),
            $this->pointLine('view', 'القول الثاني', 'يجوز لها أن تقرأ القرآن من حفظها لطول مدة الحيض.', ['F2'], ['F2.S1'], heldBy: 'الشيخ ابن باز'),
        );
        SummaryWriter::fake([$output, $output]);
        $this->fakeJudge(['view0' => 'not_supported', 'view1' => 'contradicted']);
        $events = [];

        $result = app(SummaryWriter::class)->write('سؤال', collect([$fatwa, $other]), 'ar', function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        });

        $this->assertSame(['status' => 'failed'], $result);
        $this->assertSame([], array_filter($events, fn (array $event): bool => $event[0] === 'item'));
        SummaryWriter::assertPromptedTimes(2);
    }

    public function test_tracing_reports_each_item_with_why_the_safeguard_rejected_it(): void
    {
        [$fatwa, $other] = $this->fatwas();
        $output = $this->summaryLines(
            $this->headerLine(),
            $this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::AGREED_BODY, ['F2'], ['F9.S1']),
        );
        SummaryWriter::fake([$output, $output]);
        $events = [];

        $result = app(SummaryWriter::class)
            ->tracing(function (string $event, array $data) use (&$events): void {
                $events[] = [$event, $data];
            })
            ->write('سؤال', collect([$fatwa, $other]), 'ar');

        $this->assertSame('failed', $result['status']);
        $items = array_values(array_filter($events, fn (array $event): bool => $event[0] === 'item'));
        $this->assertCount(2, $items); // the draft and its rewrite
        $this->assertNull($items[0][1]['checked']);
        $this->assertStringStartsWith('no support', $items[0][1]['rejections'][0]['reason']);
        $this->assertSame([1, 2], array_column(array_column(array_filter($events, fn (array $event): bool => $event[0] === 'attempt'), 1), 'attempt'));
    }

    /**
     * @return array{0: Fatwa, 1: Fatwa}
     */
    private function fatwas(): array
    {
        $fatwa = $this->searchFatwa('مس المصحف للحائض', answer: [
            [['type' => 'text', 'text' => 'الحمد لله، لا يجوز للحائض أن تمسّ المصحف من غير حائل باتفاق الأئمة الأربعة، لقوله تعالى: '],
                ['type' => 'quran', 'text' => 'لَا يَمَسُّهُ إِلَّا الْمُطَهَّرُونَ', 'surah' => 56, 'ayah' => 79, 'ayahEnd' => null]],
            [['type' => 'text', 'text' => 'وقال ﷺ: '],
                ['type' => 'hadith', 'text' => self::HADITH, 'quoteKey' => Arabic::quoteKey(self::HADITH), 'gradingInFatwa' => 'حديث حسن', 'gradingScope' => 'this', 'gradingContext' => 'رواه مالك وهو حديث حسن']],
        ]);
        $other = $this->searchFatwa('قراءة الحائض', 'يجوز لها أن تقرأ القرآن من حفظها لطول مدة الحيض.', 'binbaz', 2);

        return [$fatwa, $other];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function agreedLine(array $overrides = []): array
    {
        return [...$this->pointLine('agreed', 'ما اتفقت عليه المصادر', self::AGREED_BODY, ['F1'], ['F1.S1'], ['F1.E1', 'F1.E2', 'F1.E1']), ...$overrides];
    }

    private function modelOutput(): string
    {
        return $this->summaryLines($this->headerLine(), $this->agreedLine());
    }

    /**
     * Fakes the translator: each call gets the English of the field ids it was asked for.
     *
     * @param  array<string, string>  $english  field id => English
     */
    private function fakeTranslator(array $english): void
    {
        SummaryTranslator::fake(function (string $prompt) use ($english): array {
            $asked = json_decode(substr($prompt, (int) strpos($prompt, '{')), true) ?? [];

            return ['fields' => array_values(array_map(
                fn (string $id): array => ['id' => $id, 'text' => $english[$id]],
                array_values(array_filter(array_keys($asked), fn (string $id): bool => isset($english[$id]))),
            ))];
        });
    }

    /**
     * Fakes the judge as supporting everything, keeping its prompts in `judgePrompts`.
     */
    private function recordJudgePrompts(): void
    {
        $this->judgePrompts = [];
        EntailmentJudge::fake(function (string $prompt): array {
            $this->judgePrompts[] = $prompt;
            preg_match_all('/^### (\S+)/mu', $prompt, $ids);

            return ['verdicts' => array_map(fn (string $id): array => ['id' => $id, 'verdict' => 'supported', 'reason' => ''], $ids[1])];
        });
    }

    /** @var list<string> */
    private array $judgePrompts = [];
}
