<?php

namespace App\Console\Commands;

use App\Importers\Foundations\DorarImporter;
use Illuminate\Console\Command;

class HadithsImport extends Command
{
    protected $signature = 'hadiths:import {--raw= : Raw Dorar folder (default data/raw/dorar)}';

    protected $description = 'Resolve pending hadith quotes from the harvested Dorar pages into hadiths with all gradings';

    public function handle(): int
    {
        $counts = (new DorarImporter($this->option('raw') ?: null))->import();

        $this->info("Hadith quotes: {$counts['found']} found, {$counts['not_found']} not found, {$counts['pending']} still pending, {$counts['failed']} failed.");

        return self::SUCCESS;
    }
}
