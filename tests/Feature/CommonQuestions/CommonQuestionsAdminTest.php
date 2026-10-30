<?php

namespace Tests\Feature\CommonQuestions;

use App\Filament\Resources\CommonAnswers\CommonAnswerResource;
use App\Filament\Resources\CommonQuestions\CommonQuestionResource;
use App\Filament\Resources\CommonQuestions\Pages\EditCommonQuestion;
use App\Filament\Resources\CommonQuestions\Pages\ListCommonQuestions;
use App\Jobs\GenerateCommonAnswer;
use App\Models\CommonQuestion;
use App\Models\User;
use Database\Seeders\CommonQuestionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CommonQuestionsAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_adds_the_questions_once_and_keeps_owner_edits(): void
    {
        $this->seed(CommonQuestionSeeder::class);
        CommonQuestion::query()->where('position', 1)->first()->update(['question_ar' => 'سؤال عدّله المالك']);

        $this->seed(CommonQuestionSeeder::class);

        $this->assertSame(count(CommonQuestionSeeder::QUESTIONS), CommonQuestion::query()->count());
        $this->assertSame('سؤال عدّله المالك', CommonQuestion::query()->where('position', 1)->value('question_ar'));
        $this->assertNotNull(CommonQuestion::query()->where('position', 2)->value('normalized_en'));
    }

    public function test_admin_can_open_the_pages(): void
    {
        $question = CommonQuestion::factory()->create();
        $answer = $question->answers()->create(['locale' => 'ar', 'fatwa_ids' => ['islamweb-1'], 'status' => 'ok', 'summary' => ['kind' => 'agree'], 'generated_at' => now()]);
        $this->actingAs(User::factory()->create());

        $this->get(CommonQuestionResource::getUrl('index'))->assertOk()->assertSee($question->question_ar);
        $this->get(CommonQuestionResource::getUrl('edit', ['record' => $question]))->assertOk();
        $this->get(CommonAnswerResource::getUrl('index'))->assertOk();
        $this->get(CommonAnswerResource::getUrl('view', ['record' => $answer]))->assertOk();
    }

    public function test_owner_can_edit_a_question_and_queue_its_regeneration(): void
    {
        Queue::fake();
        $question = CommonQuestion::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(EditCommonQuestion::class, ['record' => $question->getKey()])
            ->fillForm(['question_en' => 'Is zakat due on worn gold?'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('is zakat due on worn gold', $question->refresh()->normalized_en);

        Livewire::test(ListCommonQuestions::class)
            ->callAction(TestAction::make('regenerate')->table($question))
            ->assertNotified();

        Queue::assertPushed(GenerateCommonAnswer::class, fn (GenerateCommonAnswer $job): bool => $job->questionId === $question->id && $job->locales === ['ar', 'en']);
    }
}
