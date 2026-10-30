<?php

namespace App\Translation;

/**
 * Removes secrets from text that is logged or sent in an alert: configured API keys and bearer tokens.
 */
class Redact
{
    public static function secrets(string $text): string
    {
        $secrets = array_filter([
            config('fatwarag.openrouter.key'),
            config('ai.providers.openrouter.key'),
            config('fatwarag.telegram.token'),
            config('fatwarag.turnstile.secret'),
        ], fn ($secret) => is_string($secret) && strlen($secret) >= 6);

        $text = $secrets === [] ? $text : str_replace($secrets, '[redacted]', $text);

        return (string) preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[redacted]', $text);
    }
}
