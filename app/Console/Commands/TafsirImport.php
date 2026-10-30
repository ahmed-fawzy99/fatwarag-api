<?php

namespace App\Console\Commands;

use App\Importers\Foundations\TafsirImporter;
use Illuminate\Console\Command;

class TafsirImport extends Command
{
    protected $signature = 'tafsir:import {--raw= : Raw Quran folder (default data/raw/quran)} {--only=* : Only these tafsirs (muyassar, ibnKathir, saadi)}';

    protected $description = 'Import the Muyassar, Ibn Kathir and as-Sa\'di tafsirs as ayah-range passages';

    public function handle(TafsirImporter $importer): int
    {
        $raw = $this->option('raw') ?: base_path('../data/raw/quran');

        foreach ($importer->import($raw, $this->option('only') ?: null) as $key => $count) {
            $count === 0 ? $this->warn("{$key}: no passages found, kept what was stored") : $this->info("{$key}: {$count} passages");
        }

        return self::SUCCESS;
    }
}
