<?php

namespace App\Notifications;

use App\Models\ContactMessage;
use App\Models\Fatwa;
use App\Models\Hadith;
use App\Models\Report;
use Illuminate\Support\Str;

/**
 * The Telegram text for reports and contact messages. Each message carries everything the owner needs to
 * act without opening the site: where it came from, what was reported, the question, the summary and a link to the admin.
 *
 * Telegram cuts a message at 4,000 characters, so the admin link sits near the top and every free-text field is capped.
 */
class OwnerMessage
{
    private const REASONS = [
        'misrepresents' => 'The summary misrepresents the fatwa',
        'irrelevant' => 'Irrelevant result',
        'wrongLink' => 'Wrong link or highlight',
        'translation' => 'Translation issue',
        'other' => 'Other',
    ];

    private const LANGUAGES = ['ar' => 'Arabic', 'en' => 'English'];

    public static function report(Report $report): string
    {
        $context = $report->context ?? [];
        $part = (string) ($context['part'] ?? '');
        $lines = [
            "🚩 New report #{$report->id}",
            'Admin: '.self::adminUrl("reports/{$report->id}"),
            '',
            'Reason: '.(self::REASONS[$report->reason] ?? $report->reason),
            'Comment: '.self::cap($report->comment, 700, '(none)'),
            '',
            '📍 Where it was reported',
            'Page: '.self::cap($context['pageUrl'] ?? null, 400, '(unknown)'),
            'Part of the page: '.self::partLabel($part, (string) ($context['pageUrl'] ?? '')),
            'Language: '.(self::LANGUAGES[$report->locale] ?? $report->locale),
            '',
            '📄 What was reported',
            ...self::item($part, $context['item'] ?? [], $report->locale),
        ];

        foreach (['quote' => 'Quote in the fatwa', 'pointedTo' => 'It pointed to'] as $key => $label) {
            if (filled($context[$key] ?? null)) {
                $lines[] = "{$label}: ".self::cap($context[$key], 300);
            }
        }

        if (filled($context['referrerFatwaId'] ?? null)) {
            array_push($lines, ...self::referrer((string) $context['referrerFatwaId'], $report->locale));
        }

        $lines[] = '';
        $lines[] = '❓ Question asked';
        $lines[] = self::cap($context['question'] ?? null, 300, '(none: not a question page)');

        if (is_array($context['summary'] ?? null)) {
            $lines[] = '';
            $lines[] = '🧾 Summary as shown';
            // A summary in parts says more (each part's label, points and unanswered lines).
            $lines[] = self::cap(self::summaryText($context['summary']), empty($context['summary']['parts']) ? 900 : 1800);
        }

        $lines[] = '';
        $lines[] = 'Device: '.self::cap($context['device'] ?? null, 150, '(unknown)');
        $lines[] = 'Time: '.self::time($report->created_at);

        return implode("\n", $lines);
    }

    public static function contact(ContactMessage $message): string
    {
        return implode("\n", [
            "✉️ New contact message #{$message->id}",
            'Admin: '.self::adminUrl("contact-messages/{$message->id}"),
            '',
            'From: '.($message->name ? "{$message->name} <{$message->email}>" : $message->email),
            'Language: '.(self::LANGUAGES[$message->locale] ?? $message->locale),
            'Time: '.self::time($message->created_at),
            '',
            'Message:',
            self::cap($message->message, 3000),
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, string>
     */
    private static function item(string $part, array $item, string $locale): array
    {
        $prefix = $locale === 'en' ? '/en' : '';
        $site = rtrim((string) config('fatwarag.site_url'), '/');

        if ($part === 'fatwa') {
            $id = (string) ($item['fatwaId'] ?? '');
            $fatwa = $id === '' ? null : Fatwa::query()->with('source')->where('public_id', $id)->first();

            return $fatwa === null
                ? ["Fatwa: {$id} (not in the library any more)"]
                : [
                    'Title: '.self::cap($fatwa->title, 200),
                    "Number: {$fatwa->source_fatwa_id} ({$fatwa->source->name_en}), id {$fatwa->public_id}",
                    "In the app: {$site}{$prefix}/fatwas/{$fatwa->public_id}",
                    "On the source: {$fatwa->url}",
                ];
        }

        if ($part === 'quran') {
            $surah = (int) ($item['surah'] ?? 0);
            $ayah = isset($item['ayah']) ? (int) $item['ayah'] : null;

            return [
                $ayah ? "Quran {$surah}:{$ayah}" : "Quran, surah {$surah}",
                "In the app: {$site}{$prefix}/quran/{$surah}".($ayah ? "?ayah={$ayah}" : ''),
                'On the source: https://quran.com/'.$surah.($ayah ? "/{$ayah}" : ''),
            ];
        }

        if ($part === 'hadith') {
            $collection = (string) ($item['collection'] ?? 'dorar');
            $number = (int) ($item['number'] ?? 0);
            $hadith = $collection === 'dorar' ? Hadith::query()->find($number) : null;

            return array_values(array_filter([
                "Hadith {$collection} {$number}",
                $hadith ? 'Text: '.self::cap($hadith->text, 150) : null,
                "In the app: {$site}{$prefix}/hadith/{$collection}/{$number}",
                $hadith?->lookup_url ? "On the source: {$hadith->lookup_url}" : null,
            ]));
        }

        return ['The AI summary of the question below'];
    }

    /**
     * The fatwa whose quote link was followed to the reported page.
     *
     * @return array<int, string>
     */
    private static function referrer(string $id, string $locale): array
    {
        $fatwa = Fatwa::query()->with('source')->where('public_id', $id)->first();

        if ($fatwa === null) {
            return ["Referring fatwa: {$id} (not in the library any more)"];
        }

        $prefix = $locale === 'en' ? '/en' : '';

        return [
            'Referring fatwa: '.self::cap($fatwa->title, 200)." ({$fatwa->source_fatwa_id}, {$fatwa->source->name_en}), id {$fatwa->public_id}",
            '  In the app: '.rtrim((string) config('fatwarag.site_url'), '/')."{$prefix}/fatwas/{$fatwa->public_id}",
            "  On the source: {$fatwa->url}",
        ];
    }

    private static function partLabel(string $part, string $pageUrl): string
    {
        return match ($part) {
            'summary' => 'AI summary',
            'fatwa' => str_contains((string) parse_url($pageUrl, PHP_URL_PATH), '/fatwas/') ? 'Fatwa page' : 'Fatwa in the answer list',
            'quran' => 'Quran source page',
            'hadith' => 'Hadith source page',
            default => $part !== '' ? $part : '(unknown)',
        };
    }

    /**
     * The summary as the reader saw it: its headline and points, and for a question in parts each part under its
     * label, a part no fatwa answered as its unanswered line.
     *
     * @param  array<string, mixed>  $summary
     */
    private static function summaryText(array $summary): string
    {
        $lines = [$summary['headline'] ?? '', ...self::sectionLines($summary)];

        foreach (is_array($summary['parts'] ?? null) ? $summary['parts'] : [] as $i => $part) {
            if (! is_array($part)) {
                continue;
            }

            $label = (string) ($part['label'] ?? '');
            $lines[] = 'Part '.((int) $i + 1).': '.$label;

            if (($part['kind'] ?? null) === 'unanswered') {
                $lines[] = 'Unanswered: «لم نجد في الفتاوى أدناه جوابًا عن: '.$label.'»';

                continue;
            }

            if (filled($part['headline'] ?? null)) {
                $lines[] = (string) $part['headline'];
            }

            array_push($lines, ...self::sectionLines($part));
        }

        return implode("\n", array_filter($lines, fn ($line) => $line !== ''));
    }

    /**
     * @param  array<string, mixed>  $summary  a summary or one of its parts
     * @return list<string>
     */
    private static function sectionLines(array $summary): array
    {
        $point = fn (array $point): string => trim(($point['title'] ?? '').(filled($point['heldBy'] ?? null) ? " ({$point['heldBy']})" : '').': '.($point['body'] ?? ''), ': ');
        $lines = [];

        if (is_array($summary['agreed'] ?? null)) {
            $lines[] = 'Agreed: '.$point($summary['agreed']);
        }

        foreach ($summary['views'] ?? [] as $view) {
            if (is_array($view)) {
                $lines[] = '- '.$point($view);
            }
        }

        foreach ($summary['preferences'] ?? [] as $preference) {
            if (is_array($preference) && filled($preference['text'] ?? null)) {
                $lines[] = 'Preference: '.$preference['text'];
            }
        }

        if (is_array($summary['caution'] ?? null) && filled($summary['caution']['text'] ?? null)) {
            $lines[] = 'Caution: '.$summary['caution']['text'];
        }

        return $lines;
    }

    private static function adminUrl(string $path): string
    {
        return rtrim((string) config('fatwarag.admin_url'), '/').'/'.$path;
    }

    private static function time(mixed $time): string
    {
        return ($time ?? now())->copy()->utc()->format('Y-m-d H:i').' UTC';
    }

    private static function cap(mixed $text, int $max, string $empty = ''): string
    {
        $text = trim((string) $text);

        return $text === '' ? $empty : Str::limit($text, $max, '…');
    }
}
