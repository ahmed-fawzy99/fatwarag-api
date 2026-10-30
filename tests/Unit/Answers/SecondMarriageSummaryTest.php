<?php

namespace Tests\Unit\Answers;

use App\Answers\GroundingCheck;
use App\Answers\Polarity;
use PHPUnit\Framework\TestCase;

/**
 * False rejections found on a real summary («حكم الزواج الثاني دون معرفة الزوجة الأولى») that failed whole: a question
 * a fatwa quotes (or puts in order to answer it) was read as its ruling, «الأولى» ("the first" wife) was read as "preferable",
 * and «دون» ("without") was read as a comparative.
 */
class SecondMarriageSummaryTest extends TestCase
{
    private const QUOTED_QUESTION = 'والأولى أن يخبرها وأن يرضيها قبل أن يتزوج عليها، لكن هذا ليس على سبيل الوجوب. وقد سئل الشيخ ابن عثيمين رحمه الله: إذا كان الرجل يريد أن يتزوج زوجة أخرى فهل يشترط أن يستأذن امرأته الأولى وما الحكم لو تزوج بدون علمها؟ فأجاب: لا حرج عليه.';

    private const CLAIM = 'لا يلزم الرجل إذا أراد أن يتزوج زوجة أخرى أن يستأذن امرأته الأولى.';

    public function test_a_question_the_fatwa_quotes_gives_no_ruling(): void
    {
        $this->assertFalse((new Polarity)->contradictedBy(self::CLAIM, self::QUOTED_QUESTION));
    }

    public function test_the_same_words_stated_as_a_ruling_still_contradict(): void
    {
        $stated = 'وقد سئل الشيخ ابن عثيمين رحمه الله عن ذلك. يشترط للرجل إذا أراد أن يتزوج زوجة أخرى أن يستأذن امرأته الأولى.';

        $this->assertTrue((new Polarity)->contradictedBy(self::CLAIM, $stated));
    }

    public function test_a_view_the_fatwa_puts_to_answer_it_gives_no_ruling(): void
    {
        $fatwa = 'فمن قال: يشترط لمن أراد التزوج بزوجة ثانية أن يخبر الأولى أو زوجاته الأخريات، فهو المطالب بالدليل.';

        $this->assertFalse((new Polarity)->contradictedBy('لا يلزم الرجل أن يخبر زوجته الأولى أو زوجاته الأخريات إذا أراد التزوج بثانية.', $fatwa));
    }

    public function test_the_first_wife_is_not_a_preference(): void
    {
        $polarity = new Polarity;

        $this->assertSame(['not-obligatory'], $polarity->rulings('لا يلزم الرجل عند زواجه بثانية أن يعلم الأولى أو يأخذ رأيها.'));
        $this->assertSame(['obligatory'], $polarity->rulings('أما في البلد الواحد فلا بد من إعلام الأولى.'));
        $this->assertSame([], $polarity->rulings('العدل ممكن مع عدم علم الأولى في حق بعض الناس.'));
        $this->assertSame(['recommended'], $polarity->rulings('ولكن الأولى أن يخبرها بزواجه.'));
        $this->assertSame(['recommended'], $polarity->rulings('وهذا هو الأولى.'));
        $this->assertSame(['recommended'], $polarity->rulings('وإخبارها الأولى له.'));
    }

    public function test_without_is_not_a_comparative(): void
    {
        $source = 'هذا فيه تفصيل: يجوز أن يتزوج ثانية وثالثة بدون علم السابقات لا بأس بذلك، إذا كان مثلا سافر إلى بلد من البلدان، وتزوج فيها ويأتي إليها وقت سفره إلى هناك لا بأس بذلك.';
        $view = [
            'title' => 'الزواج دون علم السابقات في بلد آخر',
            'heldBy' => null,
            'body' => 'يجوز أن يتزوج ثانية وثالثة دون علم السابقات، لا بأس بذلك، إذا كان سافر إلى بلد من البلدان وتزوج فيها ويأتي إليها وقت سفره.',
            'citations' => ['baz-1'],
            'evidence' => [],
            'support' => [['fatwaId' => 'baz-1', 'quote' => $source]],
        ];

        $check = new GroundingCheck(minCoverage: 0.7, minSupportCoverage: 0.4);
        $check->prepare(['baz-1' => $source], ['baz-1' => ['ابن باز', 'باز']]);

        $this->assertNotNull($check->item('view', $view));
        $this->assertNull($check->item('view', [...$view, 'body' => 'يجوز أن يتزوج ثانية وثالثة دون علم السابقات فأكثر، لا بأس بذلك، إذا كان سافر إلى بلد من البلدان وتزوج فيها ويأتي إليها وقت سفره.']));
    }
}
