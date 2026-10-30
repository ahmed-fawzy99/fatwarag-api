<?php

namespace Tests\Support;

/**
 * The summary model's output as tests fake it: JSON Lines, a first line with kind and headline, then one line per
 * item citing fatwa labels («F1»), sentence ids («F1.S2») and evidence markers («F1.E1»).
 */
trait WritesSummaryLines
{
    /**
     * @param  array<string, mixed>  ...$lines
     */
    protected function summaryLines(array ...$lines): string
    {
        return implode("\n", array_map(fn (array $line): string => (string) json_encode($line, JSON_UNESCAPED_UNICODE), $lines));
    }

    /**
     * @return array<string, mixed>
     */
    protected function headerLine(string $kind = 'agree', string $headline = 'تذكر المصادر أن الحائض لا تمس المصحف'): array
    {
        return ['kind' => $kind, 'headline' => $headline];
    }

    /**
     * @param  list<string>  $citations
     * @param  list<string>  $support
     * @param  list<string>  $evidence
     * @return array<string, mixed>
     */
    protected function pointLine(string $part, string $title, string $body, array $citations, array $support, array $evidence = [], string $heldBy = ''): array
    {
        return ['part' => $part, 'title' => $title, 'heldBy' => $heldBy, 'body' => $body, 'citations' => $citations, 'evidence' => $evidence, 'support' => $support];
    }

    /**
     * @param  list<string>  $citations
     * @param  list<string>  $support
     * @return array<string, mixed>
     */
    protected function noteLine(string $part, string $text, array $citations, array $support, array $evidence = []): array
    {
        return ['part' => $part, 'text' => $text, 'citations' => $citations, 'evidence' => $evidence, 'support' => $support];
    }
}
