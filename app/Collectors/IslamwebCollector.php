<?php

namespace App\Collectors;

use App\Importers\Importer;
use App\Importers\IslamwebImporter;
use App\Models\CollectionTask;
use RuntimeException;

/**
 * islamweb refresh: reads the newest-first fatwa listing until a page holds nothing new (at most 10 pages), fetches
 * the new fatwas, and one level of the fatwas they cite (saved as cross-references, as in the first harvest).
 */
class IslamwebCollector extends Collector
{
    public function sourceKey(): string
    {
        return 'islamweb';
    }

    protected function importer(): Importer
    {
        return app(IslamwebImporter::class);
    }

    protected function discover(): void
    {
        $known = $this->knownIds();
        $pages = (int) config('fatwarag.collectors.islamweb_listing_pages', 10);

        for ($page = 1; $page <= $pages; $page++) {
            $response = $this->get("https://www.islamweb.net/ar/fatawa/?pageno={$page}");
            if (! $response->successful()) {
                throw new RuntimeException("Listing page {$page} returned HTTP {$response->status()}");
            }

            preg_match_all('#href="/ar/fatwa/(\d+)/#', $response->body(), $matches);
            $new = 0;

            foreach (array_unique($matches[1]) as $id) {
                if (! $this->isCollected($id, $known)) {
                    $this->queue('ar', $id, "https://www.islamweb.net/ar/fatwa/{$id}/", priority: 1);
                    $new++;
                }
            }

            if ($new === 0) {
                break;
            }
        }
    }

    protected function fetch(CollectionTask $task): bool
    {
        $response = $this->get($task->url);

        if ($response->status() === 404) {
            return false;
        }

        if (! $response->successful()) {
            throw new RuntimeException("HTTP {$response->status()}");
        }

        $body = $response->body();
        $via = $task->kind === 'xref' ? 'xref' : 'refresh';

        if (! str_contains($body, 'itemprop="acceptedAnswer"')) {
            $this->manifest(['kind' => 'ar', 'id' => $task->external_id, 'url' => $task->url, 'status' => 200, 'bytes' => strlen($body), 'note' => 'missing', 'via' => $via]);

            return false;
        }

        $this->save(sprintf('pages/ar/%d/%s.html.gz', intdiv((int) $task->external_id, 1000), $task->external_id), $body);
        $this->manifest(['kind' => 'ar', 'id' => $task->external_id, 'url' => $task->url, 'status' => 200, 'bytes' => strlen($body), 'note' => 'ok', 'via' => $via]);

        if ($task->kind === 'ar') {
            $this->queueCitedFatwas($task->external_id, $body);
        }

        return true;
    }

    /**
     * One level of cross-references: fatwas linked inside the answer.
     */
    private function queueCitedFatwas(string $id, string $body): void
    {
        $start = strpos($body, 'itemprop="acceptedAnswer"');
        $end = strpos($body, 'linkedtab', (int) $start) ?: strlen($body);
        preg_match_all('#href="(?:https?://(?:www\.)?islamweb\.net)?/ar/fatwa/(\d+)#i', substr($body, (int) $start, $end - (int) $start), $matches);
        $known = $this->knownIds();

        foreach (array_unique($matches[1]) as $cited) {
            if ($cited !== $id && ! $this->isCollected($cited, $known)) {
                $this->queue('xref', $cited, "https://www.islamweb.net/ar/fatwa/{$cited}/");
            }
        }
    }

    /**
     * @param  array<string, true>  $known
     */
    private function isCollected(string $id, array $known): bool
    {
        return isset($known[$id]) || is_file($this->rawDir(sprintf('pages/ar/%d/%s.html.gz', intdiv((int) $id, 1000), $id)));
    }
}
