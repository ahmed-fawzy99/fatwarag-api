<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Settings;
use App\Filament\Widgets\AiSpendWidget;
use App\Models\AiUsage;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiSpend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_form_starts_from_config_defaults(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Settings::class)
            ->assertSchemaStateSet([
                'daily_questions' => config('fatwarag.limits.daily_questions'),
                'monthly_budget_usd' => config('fatwarag.limits.monthly_budget_usd'),
                'hourly_questions_per_visitor' => config('fatwarag.limits.hourly_questions_per_visitor'),
                'hourly_summaries_per_visitor' => config('fatwarag.limits.hourly_summaries_per_visitor'),
            ]);
    }

    public function test_saving_settings_updates_limits(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Settings::class)
            ->fillForm(['daily_questions' => 250, 'monthly_budget_usd' => 42.5, 'hourly_questions_per_visitor' => 7, 'hourly_summaries_per_visitor' => 5])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(250, (int) Setting::get('daily_questions'));
        $this->assertSame(7, (int) Setting::get('hourly_questions_per_visitor'));
        $this->assertSame(5, (int) Setting::get('hourly_summaries_per_visitor'));
        $this->assertSame(42.5, app(AiSpend::class)->monthlyBudget());
    }

    public function test_invalid_limits_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Settings::class)
            ->fillForm(['daily_questions' => 0, 'monthly_budget_usd' => -1, 'hourly_questions_per_visitor' => null])
            ->call('save')
            ->assertHasFormErrors(['daily_questions', 'monthly_budget_usd', 'hourly_questions_per_visitor']);

        $this->assertNull(Setting::get('daily_questions'));
    }

    public function test_ai_spend_widget_shows_month_spend_against_budget(): void
    {
        Setting::put('monthly_budget_usd', 50);
        AiUsage::query()->create(['purpose' => 'summary', 'model' => 'm', 'cost_usd' => 12.5]);
        AiUsage::query()->create(['purpose' => 'translation', 'model' => 'm', 'cost_usd' => 2.5]);
        AiUsage::query()->create(['purpose' => 'embedding', 'model' => 'm', 'cost_usd' => 99, 'counts_toward_budget' => false]);
        $this->actingAs(User::factory()->create());

        Livewire::test(AiSpendWidget::class)
            ->assertSee('15.00')
            ->assertSee('50.00')
            ->assertSee('Summary')
            ->assertSee('Translation')
            ->assertSee('Indexing (not budgeted)')
            ->assertSee('99.00');
    }
}
