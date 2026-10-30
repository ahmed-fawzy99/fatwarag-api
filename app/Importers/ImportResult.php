<?php

namespace App\Importers;

/**
 * What one import run did. `skipped` counts pages that hold no fatwa (soft 404s, redirect-only answers, unreadable
 * files) and fatwas that were unchanged; `failed` counts pages that threw while parsing.
 */
final class ImportResult
{
    /** @var list<string> */
    public array $failures = [];

    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public int $failed = 0,
    ) {}

    public function written(): int
    {
        return $this->created + $this->updated;
    }

    public function summary(): string
    {
        return "created {$this->created}, updated {$this->updated}, skipped {$this->skipped}, failed {$this->failed}";
    }
}
