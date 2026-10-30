<?php

namespace App\Http\Controllers\Api;

use App\Content\ErrorResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportRequest;
use App\Models\Report;
use App\Notifications\OwnerMessage;
use App\Services\Telegram;
use App\Services\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReportController extends Controller
{
    /** POST /reports : saved first, then the owner is messaged; a Telegram failure never loses the report. */
    public function store(StoreReportRequest $request, Turnstile $turnstile, Telegram $telegram): JsonResponse
    {
        if (! $turnstile->verify($request->validated('turnstileToken'), $request->attributes->get('client_ip', $request->ip()))) {
            return ErrorResponse::make('turnstile_failed', 'The security check failed. Please try again.', 403);
        }

        $report = Report::query()->create([
            'reason' => $request->validated('reason'),
            'comment' => $request->validated('comment'),
            'locale' => $request->validated('locale'),
            'context' => $request->reportContext(),
        ]);

        try {
            if ($telegram->send(OwnerMessage::report($report))) {
                $report->forceFill(['notified_at' => now()])->save();
            }
        } catch (Throwable $exception) {
            Log::warning('Could not notify the owner of a report', ['report' => $report->id, 'error' => $exception->getMessage()]);
        }

        return response()->json(['id' => (string) $report->id], 201);
    }
}
