<?php

namespace Tests\Unit\Answers;

use App\Answers\GroundingCheck;
use App\Answers\SourceSentences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review fix round 6 (scratchpad/rv6): a fronted subject carries into a joined ruling only when that ruling names no
 * subject or object of its own after its verb («ويباح للنساء»); a pronoun party («لهم») carries the party only; «بل»
 * opens a new clause only before a ruling, else it is emphasis inside its clause.
 */
class ReviewRoundSixTest extends TestCase
{
    private const SOURCES = [
        'f1' => 'التدخين يحرم لضرره ويجوز شرب القهوة باعتدال لمن احتاج إليها.',
        'f2' => 'الذهب يحرم على الرجال ويجوز لهم لبس خاتم الفضة.',
        'f3' => 'الخمر يحرم بيعها وشراؤها ويحل الخل المتخذ منها.',
        'b1' => 'الذهب الملبوس يحرم على الرجال ويباح للنساء بإجماع أهل العلم.',
        'r1' => 'يحرم الربا بل هو من أكبر الكبائر ويجوز البيع بالتقسيط.',
    ];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejected(): array
    {
        return [
            'fronted subject into a clause with its own object' => ['يجوز التدخين.', 'f1'],
            'fronted subject, copied qualifiers' => ['يجوز التدخين باعتدال لمن احتاج إليه.', 'f1'],
            'pronoun party carries the party, not the subject' => ['يجوز للرجال لبس الذهب.', 'f2'],
            'fronted subject into a clause with its own subject' => ['يحل الخمر.', 'f3'],
            'riba permitted' => ['يجوز الربا.', 'r1'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_rejected_lexically(string $body, string $source): void
    {
        $this->assertNull($this->check($body, $source));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function honest(): array
    {
        return [
            'elided subject carried' => ['يباح الذهب الملبوس للنساء.', 'b1'],
            'the joined clause\'s own object' => ['يجوز شرب القهوة باعتدال لمن احتاج إليها.', 'f1'],
            'the pronoun party\'s own object' => ['يجوز للرجال لبس خاتم الفضة.', 'f2'],
            '«بل» as emphasis' => ['يحرم الربا بل هو من أكبر الكبائر.', 'r1'],
        ];
    }

    #[DataProvider('honest')]
    public function test_honest_passes(string $body, string $source): void
    {
        $this->assertNotNull($this->check($body, $source));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function check(string $body, string $source): ?array
    {
        $sentences = new SourceSentences;

        foreach (self::SOURCES as $id => $text) {
            $sentences->addText($id, $text);
        }

        $label = $sentences->label($source);
        $check = new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4);
        $check->prepare(self::SOURCES, array_fill_keys(array_keys(self::SOURCES), ['إسلام ويب']));

        return $check->item('agreed', $sentences->resolve(['title' => 'الحكم', 'heldBy' => null, 'body' => $body, 'citations' => [$label], 'evidence' => [], 'support' => ["{$label}.S1"]]));
    }
}
