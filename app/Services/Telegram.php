<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends messages to the owner's Telegram chat. Failing to notify never breaks the action that triggered it.
 */
class Telegram
{
    private const MAX_LENGTH = 4000;

    public function send(string $text): bool
    {
        $token = config('fatwarag.telegram.token');
        $chatId = config('fatwarag.telegram.chat_id');

        if (! $token || ! $chatId) {
            return false;
        }

        try {
            return Http::timeout(15)
                ->retry(2, 500, throw: false)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => mb_substr($text, 0, self::MAX_LENGTH),
                    'disable_web_page_preview' => true,
                ])
                ->successful();
        } catch (Throwable $exception) {
            // The bot token is part of the request URL, so it must never reach the logs.
            Log::warning('Telegram notification failed', ['error' => str_replace($token, '[redacted]', $exception->getMessage())]);

            return false;
        }
    }
}
