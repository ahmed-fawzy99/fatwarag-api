<?php

namespace App\Http\Controllers\Api;

use App\Content\ErrorResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactRequest;
use App\Models\ContactMessage;
use App\Notifications\OwnerMessage;
use App\Services\Telegram;
use App\Services\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ContactController extends Controller
{
    /** POST /contact : saved first, then the owner is messaged; a Telegram failure never loses the message. */
    public function store(StoreContactRequest $request, Turnstile $turnstile, Telegram $telegram): JsonResponse
    {
        if (! $turnstile->verify($request->validated('turnstileToken'), $request->attributes->get('client_ip', $request->ip()))) {
            return ErrorResponse::make('turnstile_failed', 'The security check failed. Please try again.', 403);
        }

        $message = ContactMessage::query()->create($request->safe()->only(['name', 'email', 'message', 'locale']));

        try {
            if ($telegram->send(OwnerMessage::contact($message))) {
                $message->forceFill(['notified_at' => now()])->save();
            }
        } catch (Throwable $exception) {
            Log::warning('Could not notify the owner of a contact message', ['message' => $message->id, 'error' => $exception->getMessage()]);
        }

        return response()->json(['id' => (string) $message->id], 201);
    }
}
