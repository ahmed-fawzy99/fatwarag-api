<?php

namespace App\Collectors;

use App\Importers\BinbazImporter;
use App\Importers\Importer;
use App\Models\CollectionTask;
use RuntimeException;

/**
 * binbaz refresh: probes ids above the highest one collected with `/fatwas/<id>/x` (no redirects followed: a 302
 * gives the canonical URL, a 404 a gap) until 50 ids in a row are missing. The next id to probe is the cursor, so a
 * stopped run resumes there. Never requests the slug-less `/fatwas/<n>`, which redirects to a different fatwa.
 */
class BinbazCollector extends Collector
{
    public function sourceKey(): string
    {
        return 'binbaz';
    }

    protected function importer(): Importer
    {
        return app(BinbazImporter::class);
    }

    protected function discover(): void
    {
        $known = array_map('intval', array_keys($this->knownIds()));
        $id = max((int) ($this->state->cursor['next_id'] ?? 1), $known === [] ? 1 : max($known) + 1);
        $misses = 0;
        $limit = (int) config('fatwarag.collectors.binbaz_miss_limit', 50);
        $lastFound = $id - 1;

        while ($misses < $limit) {
            $response = $this->get("https://binbaz.org.sa/fatwas/{$id}/x", ['allow_redirects' => false]);

            if (in_array($response->status(), [301, 302], true) && ($location = $response->header('Location')) !== '') {
                $this->queue('ar', (string) $id, str_starts_with($location, 'http') ? $location : "https://binbaz.org.sa{$location}");
                $lastFound = $id;
                $misses = 0;
            } elseif ($response->status() === 404) {
                $misses++;
            } else {
                throw new RuntimeException("Probe of fatwa {$id} returned HTTP {$response->status()}");
            }

            $id++;
            $this->state->update(['cursor' => ['next_id' => $id]]);
        }

        $this->state->update(['cursor' => ['next_id' => $lastFound + 1]]);
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

        if (! str_contains($body, '<article class="fatwa"')) {
            $this->manifest(['kind' => 'ar', 'id' => $task->external_id, 'url' => $task->url, 'status' => 200, 'bytes' => strlen($body), 'note' => 'missing']);

            return false;
        }

        $this->save(sprintf('pages/ar/%02d/%s.html.gz', (int) $task->external_id % 100, $task->external_id), $body);
        $this->manifest(['kind' => 'ar', 'id' => $task->external_id, 'url' => $task->url, 'status' => 200, 'bytes' => strlen($body), 'note' => 'ok']);

        return true;
    }
}
