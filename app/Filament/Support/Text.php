<?php

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;

/**
 * Renders stored content (which is Arabic, often with harakat) for the admin. Every block gets dir="auto" so it
 * lays out right-to-left or left-to-right by its own text.
 */
final class Text
{
    /**
     * Fatwa question/answer: a list of paragraphs, each a list of segments ({type, text, ...}). Plain strings are
     * accepted too, so an odd or older shape still shows something instead of failing.
     *
     * @param  array<int, mixed>|null  $paragraphs
     */
    public static function paragraphs(?array $paragraphs): HtmlString
    {
        $html = '';

        foreach ($paragraphs ?? [] as $paragraph) {
            $segments = is_array($paragraph) && array_is_list($paragraph) ? $paragraph : [$paragraph];
            $html .= '<p dir="auto" class="mb-3 leading-relaxed">';

            foreach ($segments as $segment) {
                if (is_string($segment)) {
                    $html .= e($segment);

                    continue;
                }

                if (! is_array($segment)) {
                    continue;
                }

                $text = e((string) ($segment['text'] ?? ''));
                $type = (string) ($segment['type'] ?? 'text');
                $html .= $type === 'text'
                    ? $text
                    : '<mark title="'.e($type).'" style="background:rgba(91,107,58,.18);padding:0 .15em;border-radius:.2em">'.$text.'</mark>';
            }

            $html .= '</p>';
        }

        return new HtmlString($html ?: '<span class="text-gray-500">-</span>');
    }

    /**
     * A labelled list of arbitrary context values (report context). Nested values are shown as indented JSON.
     *
     * @param  array<string, mixed>|null  $values
     */
    public static function definitionList(?array $values): HtmlString
    {
        if (! $values) {
            return new HtmlString('<span class="text-gray-500">-</span>');
        }

        $html = '<dl class="grid gap-3">';

        foreach ($values as $key => $value) {
            $shown = is_scalar($value) || $value === null
                ? e((string) $value)
                : '<pre style="white-space:pre-wrap;margin:0">'.e(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)).'</pre>';
            $html .= '<div><dt class="text-sm font-medium text-gray-500">'.e((string) $key).'</dt><dd dir="auto" class="mt-1">'.$shown.'</dd></div>';
        }

        return new HtmlString($html.'</dl>');
    }
}
