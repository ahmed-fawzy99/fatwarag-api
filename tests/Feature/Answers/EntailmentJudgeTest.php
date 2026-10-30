<?php

namespace Tests\Feature\Answers;

use App\Answers\EntailmentJudge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntailmentJudgeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Review round 3: an id answered twice keeps its stricter verdict, whichever comes last.
     */
    public function test_duplicate_verdict_id_keeps_the_stricter_verdict(): void
    {
        $items = [
            'agreed' => ['claim' => 'لا يجوز', 'quotes' => ['لا يجوز']],
            'view0' => ['claim' => 'يجوز', 'quotes' => ['يجوز']],
        ];

        EntailmentJudge::fake([['verdicts' => [
            ['id' => 'agreed', 'verdict' => 'not_supported', 'reason' => 'first'],
            ['id' => 'agreed', 'verdict' => 'supported', 'reason' => 'second'],
            ['id' => 'view0', 'verdict' => 'supported', 'reason' => 'first'],
            ['id' => 'view0', 'verdict' => 'contradicted', 'reason' => 'second'],
            ['id' => 'view0', 'verdict' => 'not_supported', 'reason' => 'third'],
        ]]]);

        $verdicts = app(EntailmentJudge::class)->claims($items);

        $this->assertFalse(EntailmentJudge::supported($verdicts, 'agreed'));
        $this->assertSame('not_supported', $verdicts['agreed']['verdict']);
        $this->assertSame('contradicted', $verdicts['view0']['verdict']);
    }
}
