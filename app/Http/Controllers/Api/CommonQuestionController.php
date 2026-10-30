<?php

namespace App\Http\Controllers\Api;

use App\Content\ContentCache;
use App\Content\Locale;
use App\Http\Controllers\Controller;
use App\Models\CommonQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommonQuestionController extends Controller
{
    /**
     * GET /common-questions?locale= : the home page's common questions, in order, in the reader's language (questions
     * without an English wording are left out of the English list).
     */
    public function index(Request $request): JsonResponse
    {
        $locale = Locale::from($request);

        return response()->json(ContentCache::remember(['common-questions'], "common-questions:{$locale}", fn (): array => CommonQuestion::query()
            ->active()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (CommonQuestion $question): bool => filled($question->questionFor($locale)))
            ->map(fn (CommonQuestion $question): array => ['id' => $question->id, 'question' => $question->questionFor($locale)])
            ->values()
            ->all()));
    }
}
