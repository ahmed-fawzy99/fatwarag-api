<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Settings;
use App\Filament\Resources\Alerts\AlertResource;
use App\Filament\Resources\Alerts\Pages\ListAlerts;
use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Filament\Resources\Fatwas\FatwaResource;
use App\Filament\Resources\Fatwas\Pages\ListFatwas;
use App\Filament\Resources\HadithQuotes\HadithQuoteResource;
use App\Filament\Resources\Muftis\MuftiResource;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\SourceCategories\Pages\ListSourceCategories;
use App\Filament\Resources\SourceCategories\SourceCategoryResource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\SourceResource;
use App\Filament\Resources\Topics\Pages\EditTopic;
use App\Filament\Resources\Topics\TopicResource;
use App\Filament\Resources\TranslationJobs\TranslationJobResource;
use App\Models\Alert;
use App\Models\CollectorState;
use App\Models\ContactMessage;
use App\Models\Fatwa;
use App\Models\FatwaLink;
use App\Models\HadithQuote;
use App\Models\Mufti;
use App\Models\Report;
use App\Models\Source;
use App\Models\SourceCategory;
use App\Models\Topic;
use App\Models\TranslationJob;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string}>
     */
    public static function resources(): array
    {
        return collect([
            ReportResource::class, ContactMessageResource::class, SourceResource::class, AlertResource::class,
            MuftiResource::class, TopicResource::class, SourceCategoryResource::class,
            TranslationJobResource::class, FatwaResource::class, HadithQuoteResource::class,
        ])->mapWithKeys(fn (string $class) => [class_basename($class) => [$class]])->all();
    }

    private function seedOneOfEach(): void
    {
        $source = Source::query()->create(['key' => 'islamqa', 'kind' => 'fatwa', 'tier' => 1, 'name_ar' => 'الإسلام سؤال وجواب', 'name_en' => 'Islam Q&A', 'base_url' => 'https://example.test']);
        CollectorState::query()->create(['source_id' => $source->id, 'status' => 'running', 'items_collected' => 3]);
        $mufti = Mufti::query()->create(['key' => 'ibn-baz', 'kind' => 'person', 'name_ar' => 'ابن باز']);
        $fatwa = Fatwa::query()->create([
            'public_id' => 'islamqa-1', 'source_id' => $source->id, 'source_fatwa_id' => '1', 'url' => 'https://example.test/1',
            'mufti_id' => $mufti->id, 'title' => 'حكم الصلاة', 'question' => [], 'answer' => [], 'search_text' => 'حكم الصلاة',
        ]);
        FatwaLink::query()->create(['fatwa_id' => $fatwa->id, 'target_source_fatwa_id' => '999']);
        HadithQuote::query()->create(['quote_key' => str_repeat('a', 64), 'quote' => 'إنما الأعمال بالنيات', 'status' => 'not_found']);
        $topic = Topic::query()->create(['key' => 'prayer', 'name_ar' => 'الصلاة']);
        SourceCategory::query()->create(['source_id' => $source->id, 'external_id' => '5', 'name' => 'الصلاة', 'topic_id' => $topic->id, 'mapping_status' => 'proposed']);
        Report::query()->create(['reason' => 'wrong_content', 'locale' => 'ar', 'context' => ['url' => '/fatwa/islamqa-1', 'question' => 'سؤال']]);
        ContactMessage::query()->create(['email' => 'a@example.test', 'message' => 'مرحبا', 'locale' => 'ar']);
        Alert::query()->create(['fingerprint' => 'x', 'kind' => 'collector', 'message' => 'failed', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        TranslationJob::query()->create(['locale' => 'en', 'scope' => 'topics', 'total' => 10, 'done' => 4]);
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get(ReportResource::getUrl('index'))->assertRedirect('/admin/login');
        $this->get(Settings::getUrl())->assertRedirect('/admin/login');
    }

    #[DataProvider('resources')]
    public function test_admin_can_open_each_resource_index(string $resource): void
    {
        $this->seedOneOfEach();

        $this->actingAs(User::factory()->create())
            ->get($resource::getUrl('index'))
            ->assertOk();
    }

    public function test_sources_show_their_tier(): void
    {
        $this->seedOneOfEach();
        $source = Source::query()->first();
        $source->update(['tier' => 3]);
        $this->actingAs(User::factory()->create());

        Livewire::test(ListSources::class)
            ->assertTableColumnStateSet('tier', 3, $source);

        $this->get(SourceResource::getUrl('view', ['record' => $source]))
            ->assertOk()
            ->assertSee('Tier');
    }

    public function test_topic_cannot_be_moved_under_its_own_descendant(): void
    {
        $root = Topic::query()->create(['key' => 'root', 'name_ar' => 'أصل']);
        $child = Topic::query()->create(['key' => 'child', 'name_ar' => 'فرع', 'parent_id' => $root->id]);
        $grandchild = Topic::query()->create(['key' => 'grandchild', 'name_ar' => 'حفيد', 'parent_id' => $child->id]);
        $this->actingAs(User::factory()->create());

        Livewire::test(EditTopic::class, ['record' => $root->getKey()])
            ->fillForm(['parent_id' => $grandchild->id])
            ->call('save')
            ->assertHasFormErrors(['parent_id']);

        $this->assertNull($root->refresh()->parent_id);

        Livewire::test(EditTopic::class, ['record' => $root->getKey()])
            ->fillForm(['parent_id' => $root->id])
            ->call('save')
            ->assertHasFormErrors(['parent_id']);
    }

    public function test_topic_key_is_read_only_when_editing(): void
    {
        $topic = Topic::query()->create(['key' => 'prayer', 'name_ar' => 'الصلاة']);
        $this->actingAs(User::factory()->create());

        Livewire::test(EditTopic::class, ['record' => $topic->getKey()])
            ->assertFormFieldIsDisabled('key')
            ->fillForm(['name_en' => 'Prayer'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('prayer', $topic->refresh()->key);
    }

    public function test_fatwa_search_treats_percent_and_underscore_literally(): void
    {
        $this->seedOneOfEach();
        $fatwa = Fatwa::query()->first();
        $this->actingAs(User::factory()->create());

        Livewire::test(ListFatwas::class)
            ->searchTable('%')
            ->assertCanNotSeeTableRecords([$fatwa]);
    }

    public function test_admin_can_open_dashboard_and_settings(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk();
        $this->get(Settings::getUrl())->assertOk();
    }

    public function test_admin_can_open_a_report_with_its_context(): void
    {
        $this->seedOneOfEach();
        $report = Report::query()->first();

        $this->actingAs(User::factory()->create())
            ->get(ReportResource::getUrl('view', ['record' => $report]))
            ->assertOk()
            ->assertSee('/fatwa/islamqa-1');
    }

    public function test_report_moves_through_its_status_workflow(): void
    {
        $this->seedOneOfEach();
        $report = Report::query()->first();
        $this->actingAs(User::factory()->create());

        Livewire::test(ListReports::class)
            ->callAction(TestAction::make('markReviewed')->table($report))
            ->assertNotified();
        $this->assertSame('reviewed', $report->refresh()->status);

        Livewire::test(ListReports::class)
            ->removeTableFilters()
            ->callAction(TestAction::make('markResolved')->table($report));
        $this->assertSame('resolved', $report->refresh()->status);
    }

    public function test_alert_can_be_resolved(): void
    {
        $this->seedOneOfEach();
        $alert = Alert::query()->first();
        $this->actingAs(User::factory()->create());

        Livewire::test(ListAlerts::class)
            ->callAction(TestAction::make('resolve')->table($alert));

        $this->assertNotNull($alert->refresh()->resolved_at);
    }

    public function test_category_mapping_can_be_approved_and_changed(): void
    {
        $this->seedOneOfEach();
        $category = SourceCategory::query()->first();
        $other = Topic::query()->create(['key' => 'fasting', 'name_ar' => 'الصيام']);
        $this->actingAs(User::factory()->create());

        Livewire::test(ListSourceCategories::class)
            ->callAction(TestAction::make('approve')->table($category));
        $this->assertSame('approved', $category->refresh()->mapping_status);

        Livewire::test(ListSourceCategories::class)
            ->removeTableFilters()
            ->callAction(TestAction::make('changeTopic')->table($category), ['topic_id' => $other->id]);
        $category->refresh();
        $this->assertSame($other->id, $category->topic_id);
        $this->assertSame('approved', $category->mapping_status);
    }

    public function test_fatwas_are_read_only_and_searchable(): void
    {
        $this->seedOneOfEach();
        $fatwa = Fatwa::query()->first();
        $source = Source::query()->first();
        $other = Fatwa::query()->create([
            'public_id' => 'islamqa-2', 'source_id' => $source->id, 'source_fatwa_id' => '2', 'url' => 'https://example.test/2',
            'title' => 'زكاة المال', 'question' => [], 'answer' => [], 'search_text' => 'زكاة المال',
        ]);
        $this->actingAs(User::factory()->create());

        $this->assertFalse(FatwaResource::canCreate());
        $this->assertFalse(FatwaResource::canEdit($fatwa));
        $this->assertFalse(FatwaResource::canDelete($fatwa));

        Livewire::test(ListFatwas::class)
            ->searchTable('زكاة')
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$fatwa]);
    }

    public function test_source_shows_unmatched_references(): void
    {
        $this->seedOneOfEach();
        $source = Source::query()->first();

        $this->assertSame(1, SourceResource::unmatchedHadithQuotes());
        $this->assertSame(1, SourceResource::unresolvedFatwaLinks($source));
    }
}
