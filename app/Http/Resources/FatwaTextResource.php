<?php

namespace App\Http\Resources;

use App\Content\SegmentPresenter;
use App\Models\Fatwa;
use App\Models\FatwaTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FatwaText (web/src/types/fatwa.ts): the Arabic text of a fatwa, or one of its translations.
 *
 * @property-read Fatwa|FatwaTranslation $resource
 */
class FatwaTextResource extends JsonResource
{
    public static $wrap = null;

    private SegmentPresenter $presenter;

    private ?string $fallbackUrl = null;

    public function presentedBy(SegmentPresenter $presenter): static
    {
        $this->presenter = $presenter;

        return $this;
    }

    /** The fatwa's own link, used when a translation carries none. */
    public function withFallbackUrl(?string $url): static
    {
        $this->fallbackUrl = $url;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $presenter = $this->presenter ??= SegmentPresenter::resolving([$this->resource->question, $this->resource->answer, $this->resource->source_summary]);

        return [
            'title' => $this->resource->title,
            'question' => $presenter->paragraphs($this->resource->question) ?? [],
            'answer' => $presenter->paragraphs($this->resource->answer) ?? [],
            'sourceSummary' => $presenter->paragraphs($this->resource->source_summary),
            'url' => $this->resource->url ?? $this->fallbackUrl,
        ];
    }
}
