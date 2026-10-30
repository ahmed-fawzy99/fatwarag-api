<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AiSpendWidget;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The limits the owner can change without a deploy. Saved into the settings table, where the question and
 * summary limiters and AiSpend read them.
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Settings and AI spend';

    protected static ?string $navigationLabel = 'Settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'daily_questions' => Setting::get('daily_questions', config('fatwarag.limits.daily_questions')),
            'monthly_budget_usd' => Setting::get('monthly_budget_usd', config('fatwarag.limits.monthly_budget_usd')),
            'hourly_questions_per_visitor' => Setting::get('hourly_questions_per_visitor', config('fatwarag.limits.hourly_questions_per_visitor')),
            'hourly_summaries_per_visitor' => Setting::get('hourly_summaries_per_visitor', config('fatwarag.limits.hourly_summaries_per_visitor')),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Limits')
                    ->description('Applied immediately. At the monthly budget, summaries and first-open translations pause until next month.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('daily_questions')
                            ->label('Questions per day')
                            ->helperText('Across all visitors.')
                            ->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('monthly_budget_usd')
                            ->label('Monthly AI budget')
                            ->prefix('$')
                            ->numeric()->minValue(0)->required(),
                        TextInput::make('hourly_questions_per_visitor')
                            ->label('Questions per visitor per hour')
                            ->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('hourly_summaries_per_visitor')
                            ->label('Summaries per visitor per hour')
                            ->helperText('New summaries only; stored ones are free.')
                            ->numeric()->integer()->minValue(1)->required(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save settings')->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::put('daily_questions', (int) $data['daily_questions']);
        Setting::put('monthly_budget_usd', (float) $data['monthly_budget_usd']);
        Setting::put('hourly_questions_per_visitor', (int) $data['hourly_questions_per_visitor']);
        Setting::put('hourly_summaries_per_visitor', (int) $data['hourly_summaries_per_visitor']);

        Notification::make()->title('Settings saved')->success()->send();
    }

    protected function getFooterWidgets(): array
    {
        return [AiSpendWidget::class];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
