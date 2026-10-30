<?php

namespace App\Collectors;

use App\Importers\Importer;
use App\Importers\IslamqaImporter;
use App\Models\CollectionTask;
use RuntimeException;

/**
 * islamqa refresh: the answer sitemaps list every id; anything not yet collected is fetched in Arabic, plus its
 * English page when the English sitemap lists it. (`lastmod` and id order are useless for finding new answers.)
 */
class IslamqaCollector extends Collector
{
    private const SITEMAPS = [
        'ar' => ['https://islamqa.info/sitemaps/ar/answers/1/sitemap.xml', 'https://islamqa.info/sitemaps/ar/answers/2/sitemap.xml'],
        'en' => ['https://islamqa.info/sitemaps/en/answers/1/sitemap.xml'],
    ];

    public function sourceKey(): string
    {
        return 'islamqa';
    }

    protected function importer(): Importer
    {
        return app(IslamqaImporter::class);
    }

    protected function discover(): void
    {
        $ids = ['ar' => [], 'en' => []];

        foreach (self::SITEMAPS as $lang => $urls) {
            foreach ($urls as $url) {
                $response = $this->get($url);
                if (! $response->successful()) {
                    throw new RuntimeException("Sitemap {$url} returned HTTP {$response->status()}");
                }

                preg_match_all('#<loc>\s*https://islamqa\.info/'.$lang.'/answers/(\d+)\s*</loc>#', $response->body(), $matches);
                $ids[$lang] += array_fill_keys($matches[1], true);
            }
        }

        $known = $this->knownIds();

        foreach (array_keys($ids['ar']) as $id) {
            $id = (string) $id;
            if (isset($known[$id]) || is_file($this->rawDir($this->path('ar', $id)))) {
                continue;
            }

            $this->queue('ar', $id, "https://islamqa.info/ar/answers/{$id}", priority: 1);

            if (isset($ids['en'][$id])) {
                $this->queue('en', $id, "https://islamqa.info/en/answers/{$id}");
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

        if (! str_contains($body, 'data-sut="question-title"')) {
            $this->manifest(['kind' => $task->kind, 'id' => $task->external_id, 'url' => $task->url, 'status' => 200, 'bytes' => strlen($body), 'note' => 'missing']);

            return false;
        }

        $this->save($this->path($task->kind, $task->external_id), $body);
        $this->manifest(['kind' => $task->kind, 'id' => $task->external_id, 'url' => $task->url, 'status' => 200, 'bytes' => strlen($body), 'note' => 'ok']);

        return true;
    }

    private function path(string $lang, string $id): string
    {
        return sprintf('pages/%s/%02d/%s.html.gz', $lang, (int) $id % 100, $id);
    }
}
