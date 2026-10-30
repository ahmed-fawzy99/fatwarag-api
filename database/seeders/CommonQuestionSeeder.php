<?php

namespace Database\Seeders;

use App\Models\CommonQuestion;
use Illuminate\Database\Seeder;

/**
 * The home page's common questions («أسئلة شائعة»). Re-runnable: a position that already has a question is left as
 * the owner edited it.
 */
class CommonQuestionSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string}> */
    public const QUESTIONS = [
        ['هل يجوز للمسافر أن يجمع ويقصر الصلاة؟', 'Can a traveller combine and shorten prayers?'],
        ['هل تجب الزكاة في الذهب الملبوس للزينة؟', 'Is zakat due on gold jewellery worn for adornment?'],
        ['ما حكم صلاة الحاجة وكيف تُصلّى؟', 'What is the ruling on the prayer of need, and how is it prayed?'],
        ['ما حكم من شك في صلاته: كم صلى؟', 'What should someone do who is unsure how many rak‘ahs they have prayed?'],
        ['هل يجوز للحائض قراءة القرآن من حفظها؟', 'May a menstruating woman recite the Quran from memory?'],
        ['ما حكم صلاة الوتر، وهل يجوز تركها؟', 'What is the ruling on the witr prayer, and may it be left?'],
        ['هل يجوز المسح على الجوارب في الوضوء؟', 'Is it permissible to wipe over socks in wudu?'],
        ['ما كفارة اليمين إذا حنث الحالف؟', 'What is the expiation for breaking an oath?'],
        ['هل يقع الطلاق في حال الغضب الشديد؟', 'Does a divorce count if it is pronounced in intense anger?'],
        ['ما حكم أخذ قرض بفائدة من البنك لشراء بيت؟', 'What is the ruling on taking an interest-bearing bank loan to buy a house?'],
    ];

    public function run(): void
    {
        foreach (self::QUESTIONS as $index => [$arabic, $english]) {
            CommonQuestion::query()->firstOrCreate(['position' => $index + 1], ['question_ar' => $arabic, 'question_en' => $english]);
        }
    }
}
