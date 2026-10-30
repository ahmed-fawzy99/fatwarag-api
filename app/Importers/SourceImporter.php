<?php

namespace App\Importers;

use App\Content\RichTextBuilder;
use App\Models\Fatwa;
use App\Models\Source;
use App\Services\Alerts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Shared plumbing for the fatwa importers: where the raw store is, reading gzipped pages, skipping pages that are
 * unchanged since the last import, and reporting failures (one alert per source, resolved by a clean run).
 */
abstract class SourceImporter implements Importer
{
    /** Raise when the parser changes, so the next import re-applies it to pages that are otherwise unchanged. */
    public const PARSER_VERSION = 1;

    /** @var array<string, string>|null source_fatwa_id => "collected_at timestamp:parser version" */
    private ?array $collected = null;

    public function __construct(
        protected RichTextBuilder $builder,
        protected FatwaWriter $writer,
        protected LinkResolver $links,
        protected Alerts $alerts,
    ) {}

    abstract public function sourceKey(): string;

    protected function source(): Source
    {
        return Source::query()->where('key', $this->sourceKey())->firstOrFail();
    }

    protected function rawDir(string $path = ''): string
    {
        return rtrim(config('fatwarag.raw_path'), '/').'/'.$this->sourceKey().($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    /**
     * A raw page's text, or null when the file is missing, still being written, or not valid gzip.
     */
    protected function readGz(string $path): ?string
    {
        $raw = @file_get_contents($path);
        $text = $raw === false ? false : @gzdecode($raw);

        return $text === false || $text === '' ? null : $text;
    }

    /**
     * Whether this fatwa was already imported, by the current parser, from a page with this modification time. The
     * known rows are streamed once per run into a compact map (id => "mtime:version"), never loaded as models.
     */
    protected function isUnchanged(string $sourceFatwaId, int $mtime): bool
    {
        if ($this->collected === null) {
            $this->collected = [];
            $rows = DB::table('fatwas')->where('source_id', $this->source()->id)->select(['id', 'source_fatwa_id', 'collected_at', 'parser_version'])->lazyById(5000);

            foreach ($rows as $row) {
                $this->collected[$row->source_fatwa_id] = ($row->collected_at === null ? 0 : Carbon::parse($row->collected_at)->getTimestamp()).':'.$row->parser_version;
            }
        }

        return ($this->collected[$sourceFatwaId] ?? null) === $mtime.':'.static::PARSER_VERSION;
    }

    /**
     * Called once per page from the import loop (outside its try/catch): keeps the import lock alive on a time basis,
     * however many pages are skipped, and stops the import if another process took the lock.
     *
     * @throws ImportLockLost
     */
    protected function tick(): void
    {
        ImportLock::heartbeat($this->sourceKey());
    }

    protected function count(ImportResult $result, Fatwa $fatwa): void
    {
        $fatwa->wasRecentlyCreated ? $result->created++ : $result->updated++;
    }

    protected function fail(ImportResult $result, string $item, Throwable $exception): void
    {
        $result->failed++;

        if (count($result->failures) < 20) {
            $result->failures[] = "{$item}: ".mb_substr($exception->getMessage(), 0, 300);
        }
    }

    /**
     * Resolves cross-references and raises (or clears) the source's import alert.
     */
    protected function finish(ImportResult $result): ImportResult
    {
        $this->links->resolve();
        $this->collected = null;

        $fingerprint = "import:{$this->sourceKey()}";

        if ($result->failed > 0) {
            $this->alerts->raise(
                'import',
                $fingerprint,
                "{$result->failed} {$this->sourceKey()} pages failed to import ({$result->summary()}).",
                ['failures' => array_slice($result->failures, 0, 5)],
                "Check the {$this->sourceKey()} parser against the failing pages, then run: php artisan fatwas:import {$this->sourceKey()} --force",
            );
        } else {
            $this->alerts->resolve($fingerprint);
        }

        return $result;
    }
}
