<?php

namespace App\Http\Controllers\Api;

use App\Content\ContentCache;
use App\Http\Controllers\Controller;
use App\Models\GlossaryTerm;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class GlossaryController extends Controller
{
    /** GET /glossary : the hadith-grading glossary (written once, verbatim from lay sources). */
    public function index(): JsonResponse
    {
        return response()->json(ContentCache::remember(['catalog'], 'glossary', fn () => $this->glossary()));
    }

    /**
     * @return array<string, mixed>
     */
    private function glossary(): array
    {
        $intro = Setting::get('glossary_intro', []);

        return [
            'intro' => ['ar' => $intro['ar'] ?? null, 'en' => $intro['en'] ?? null],
            'terms' => GlossaryTerm::query()->orderBy('position')->orderBy('id')->get()->map(fn (GlossaryTerm $term) => [
                'term' => $term->key,
                'labelAr' => $term->label_ar,
                'labelEn' => $term->label_en,
                'level' => $term->level->value,
                'ar' => $term->entry_ar,
                'en' => $term->entry_en,
            ])->all(),
        ];
    }
}
