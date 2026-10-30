<?php

namespace App\Console\Commands;

use App\Content\ContentCache;
use Illuminate\Console\Command;

class ContentCacheClear extends Command
{
    protected $signature = 'content:cache-clear';

    protected $description = 'Clear the cached fatwas, content lists, topic tree, catalog and Quran pages. Run it after changing fatwa topics in bulk (pivot changes fire no model events).';

    public function handle(): int
    {
        ContentCache::forgetDerived();
        ContentCache::forget(['catalog']);

        foreach (range(1, 114) as $surah) {
            ContentCache::forget(["surah:{$surah}"]);
        }

        $this->info('Content caches cleared (cached fatwas, lists, the topic tree, catalog and Quran pages; the hourly refresh clears fatwas, lists, topics and hadith lists too). Hadiths are cleared as they change.');

        return self::SUCCESS;
    }
}
