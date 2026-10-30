<?php

namespace App\Translation;

use App\Services\AiSpend;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Throwable;

/**
 * Translates a list of Arabic strings to English with the translation model, and records what the call cost.
 */
class TextTranslator
{
    public function __construct(private AiSpend $spend) {}

    /**
     * @param  array<int, string>  $strings
     * @param  'titles'|'text'  $kind
     * @return array{translations: array<int, string>, cost: float}
     *
     * @throws TranslationFailed
     * @throws TranslationTimedOut
     */
    public function translate(array $strings, string $kind, bool $countsTowardBudget, ?int $timeout = null): array
    {
        $model = (string) config('fatwarag.openrouter.models.translation');

        $prompt = json_encode(['items' => array_values($strings)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = (new TranslatorAgent($kind))->prompt(
                $prompt,
                provider: Lab::OpenRouter,
                model: $model,
                timeout: $timeout,
            );
        } catch (Throwable $exception) {
            $this->logCause($exception, $model, $timeout);

            $message = Redact::secrets($exception->getMessage());

            if ($this->isTimeout($exception)) {
                // The provider may have kept generating (and billing) after we gave up: count at least the input we sent.
                $estimate = (int) ceil(mb_strlen($prompt) / 2);
                $this->spend->record('translation', $model, $estimate, 0, $this->cost($estimate, 0), $countsTowardBudget);
            }

            throw $this->isTimeout($exception) ? new TranslationTimedOut($message) : new TranslationFailed($message);
        }

        $cost = $this->cost($response->usage->inputTokens, $response->usage->outputTokens);
        $this->spend->record('translation', $model, $response->usage->inputTokens, $response->usage->outputTokens, $cost, $countsTowardBudget);

        $translations = $response['translations'] ?? null;

        if (! is_array($translations) || count($translations) !== count($strings) || array_filter($translations, fn ($item) => ! is_string($item)) !== []) {
            throw new TranslationFailed('The translation came back malformed.');
        }

        // An item the model left empty (a symbol, say) stays as it was written.
        $translations = array_map(fn (string $translated, string $original) => trim($translated) === '' ? $original : $translated, array_values($translations), array_values($strings));

        return ['translations' => $translations, 'cost' => $cost];
    }

    /** Logs the whole exception chain (the SDK hides the real cause behind "could not connect"), with secrets removed. */
    private function logCause(Throwable $exception, string $model, ?int $timeout): void
    {
        $chain = [];

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $chain[] = Redact::secrets($current::class.': '.$current->getMessage());
        }

        Log::warning('Translation call failed', [
            'model' => $model,
            'timeout' => $timeout,
            'timedOut' => $this->isTimeout($exception),
            'chain' => $chain,
        ]);
    }

    /** The SDK wraps a slow provider as "could not connect"; the original cURL timeout is in the chain. */
    private function isTimeout(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ConnectionException && (str_contains(strtolower($current->getMessage()), 'timed out') || str_contains($current->getMessage(), 'cURL error 28'))) {
                return true;
            }
        }

        return false;
    }

    public function cost(int $inputTokens, int $outputTokens): float
    {
        $pricing = config('fatwarag.translation.pricing');

        return ($inputTokens * $pricing['input_per_million'] + $outputTokens * $pricing['output_per_million']) / 1_000_000;
    }
}
