<?php

namespace Tests\Feature\Collectors;

use App\Importers\LinkResolver;
use App\Models\Fatwa;
use App\Models\FatwaLink;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkResolverTest extends TestCase
{
    use RefreshDatabase;

    private function fatwa(Source $source, string $id): Fatwa
    {
        return Fatwa::query()->create([
            'public_id' => "{$source->key}-{$id}", 'source_id' => $source->id, 'source_fatwa_id' => $id, 'url' => "https://example.test/{$id}",
            'title' => 'عنوان', 'question' => [], 'answer' => [], 'search_text' => 'عنوان',
        ]);
    }

    public function test_link_resolves_when_target_imported_later(): void
    {
        $islamweb = Source::query()->create(['key' => 'islamweb', 'kind' => 'fatwa', 'tier' => 1, 'name_ar' => 'إسلام ويب', 'name_en' => 'Islamweb', 'base_url' => 'https://www.islamweb.net']);
        $islamqa = Source::query()->create(['key' => 'islamqa', 'kind' => 'fatwa', 'tier' => 1, 'name_ar' => 'الإسلام سؤال وجواب', 'name_en' => 'IslamQA', 'base_url' => 'https://islamqa.info']);
        $origin = $this->fatwa($islamweb, '100');
        $link = FatwaLink::query()->create(['fatwa_id' => $origin->id, 'target_source_fatwa_id' => '200']);
        $this->fatwa($islamqa, '200');

        $this->assertSame(0, app(LinkResolver::class)->resolve(), 'a fatwa of another source with the same number is not the target');

        $target = $this->fatwa($islamweb, '200');
        $this->assertSame(1, app(LinkResolver::class)->resolve());
        $this->assertSame($target->id, $link->refresh()->target_fatwa_id);
    }
}
