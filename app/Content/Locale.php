<?php

namespace App\Content;

use Illuminate\Http\Request;

/**
 * Locales the API serves: Arabic (default) and English. Anything else is treated as Arabic.
 */
class Locale
{
    public const SUPPORTED = ['ar', 'en'];

    public static function from(Request $request): string
    {
        $locale = $request->query('locale');

        return is_string($locale) && in_array($locale, self::SUPPORTED, true) ? $locale : 'ar';
    }

    /**
     * Splits a comma-separated query value into distinct, non-empty strings.
     *
     * @return array<int, string>
     */
    public static function list(mixed $value, int $max = 50): array
    {
        if (! is_string($value)) {
            return [];
        }

        return array_slice(array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), fn (string $item): bool => $item !== ''))), 0, $max);
    }
}
