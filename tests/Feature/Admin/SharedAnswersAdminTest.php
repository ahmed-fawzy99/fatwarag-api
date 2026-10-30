<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\SharedAnswers\Pages\ListSharedAnswers;
use App\Filament\Resources\SharedAnswers\SharedAnswerResource;
use App\Models\SharedAnswer;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SharedAnswersAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_view_shared_answers(): void
    {
        $share = SharedAnswer::factory()->withSummary()->create();
        $this->actingAs(User::factory()->create());

        $this->get(SharedAnswerResource::getUrl('index'))->assertOk()->assertSee($share->public_id);
        $this->get(SharedAnswerResource::getUrl('view', ['record' => $share]))->assertOk()->assertSee($share->question);
        $this->assertSame('1', SharedAnswerResource::getNavigationBadge());
    }

    public function test_admin_can_delete_a_shared_answer(): void
    {
        $share = SharedAnswer::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(ListSharedAnswers::class)->callAction(TestAction::make(DeleteAction::getDefaultName())->table($share));

        $this->assertModelMissing($share);
        $this->assertNull(SharedAnswerResource::getNavigationBadge());
    }
}
