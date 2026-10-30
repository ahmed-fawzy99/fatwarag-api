<?php

namespace App\Answers;

/**
 * Reads the summary model's streamed output as it arrives: one JSON object per line (JSON Lines). Each complete line
 * that is a JSON object is returned once; anything else (a code fence, stray prose, a broken line) is skipped.
 */
class DraftLines
{
    private string $buffer = '';

    private string $all = '';

    private int $objects = 0;

    /**
     * @return list<array<string, mixed>> the objects completed by this chunk of text
     */
    public function push(string $text): array
    {
        $this->buffer .= $text;
        $this->all .= $text;
        $objects = [];

        while (($end = strpos($this->buffer, "\n")) !== false) {
            $object = $this->decode(substr($this->buffer, 0, $end));
            $this->buffer = substr($this->buffer, $end + 1);

            if ($object !== null) {
                $objects[] = $object;
            }
        }

        return $objects;
    }

    /**
     * The last line once the output has ended. If no line was an object, the whole output is read as one JSON object
     * holding the lines as `items` (a model that wrote a single object instead of lines).
     *
     * @return list<array<string, mixed>>
     */
    public function finish(): array
    {
        $object = $this->decode($this->buffer);
        $this->buffer = '';

        if ($object !== null) {
            return [$object];
        }

        if ($this->objects > 0) {
            return [];
        }

        $whole = json_decode((string) preg_replace('/^\s*```(?:json)?|```\s*$/u', '', $this->all), true);

        if (! is_array($whole) || ! isset($whole['kind']) || ! is_array($whole['items'] ?? null)) {
            return [];
        }

        return [
            ['kind' => $whole['kind'], 'headline' => $whole['headline'] ?? null],
            ...array_values(array_filter($whole['items'], 'is_array')),
        ];
    }

    /**
     * Everything received so far.
     */
    public function received(): string
    {
        return $this->all;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $line): ?array
    {
        $line = rtrim(trim($line), ',');

        if (! str_starts_with($line, '{')) {
            return null;
        }

        $object = json_decode($line, true);

        if (! is_array($object)) {
            return null;
        }

        $this->objects++;

        return $object;
    }
}
