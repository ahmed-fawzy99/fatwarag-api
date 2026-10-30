<?php

namespace App\Console\Commands;

use App\Models\HadithQuote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class HadithsExportPending extends Command
{
    protected $signature = 'hadiths:export-pending {--raw= : Raw Dorar folder (default data/raw/dorar)}';

    protected $description = 'Write the pending hadith quotes to the Dorar harvester\'s queue folder, so it can look up quotes its own scan of the raw stores never saw';

    public function handle(): int
    {
        $dir = rtrim($this->option('raw') ?: config('fatwarag.raw_path').'/dorar', '/').'/queue';
        File::ensureDirectoryExists($dir);

        $rows = HadithQuote::query()
            ->where('status', 'pending')
            ->orderByDesc('fatwa_count')
            ->orderBy('id')
            ->get(['quote', 'fatwa_count'])
            ->map(fn (HadithQuote $q): array => ['quote' => $q->quote, 'fatwa_count' => (int) $q->fatwa_count])
            ->all();

        // Atomic: the harvester reads this file at any moment, so it must never see a half-written one.
        $path = $dir.'/extra_quotes.json';
        $tmp = $path.'.'.getmypid().'.tmp';
        File::put($tmp, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        rename($tmp, $path);

        $this->info(count($rows).' pending quotes exported.');

        return self::SUCCESS;
    }
}
