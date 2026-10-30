<?php

namespace App\Http\Controllers\Api;

use App\Content\ErrorResponse;
use App\Content\FatwaPayloads;
use App\Content\Locale;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FatwaController extends Controller
{
    private const MAX_IDS = 50;

    /** GET /fatwas?ids=a,b,c&locale= : the fatwas in the given order; missing ids are skipped. */
    public function index(Request $request, FatwaPayloads $payloads): JsonResponse
    {
        $ids = Locale::list($request->query('ids'), self::MAX_IDS);

        if ($ids === [] && ! $request->has('ids')) {
            return ErrorResponse::validation('The ids parameter is required.');
        }

        return response()->json(array_values($payloads->many($ids, Locale::from($request))));
    }

    /** GET /fatwas/{publicId}?locale= */
    public function show(Request $request, string $publicId, FatwaPayloads $payloads): JsonResponse
    {
        $payload = $payloads->find($publicId, Locale::from($request));

        if ($payload === null) {
            return ErrorResponse::notFound('Fatwa not found.');
        }

        return response()->json($payload);
    }
}
