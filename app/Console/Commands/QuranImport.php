<?php

namespace App\Console\Commands;

use App\Importers\Foundations\QuranImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class QuranImport extends Command
{
    protected $signature = 'quran:import {--raw= : Raw Quran folder (default data/raw/quran)} {--sahih= : Saheeh International JSON (default docs/en-sahih-international-with-footnote-tags.json)}';

    protected $description = 'Import the Quran text, surah names and the Saheeh International translation';

    public function handle(QuranImporter $importer): int
    {
        $raw = $this->option('raw') ?: rtrim(config('fatwarag.raw_path'), '/').'/quran';
        $sahih = $this->option('sahih') ?: base_path('../docs/en-sahih-international-with-footnote-tags.json');

        if (! File::exists("{$raw}/dl/qc_qpc_hafs.json") || ! File::exists($sahih)) {
            $this->error('Quran source files are missing.');

            return self::FAILURE;
        }

        $counts = $importer->import("{$raw}/dl/qc_qpc_hafs.json", $sahih, $importer->chapters("{$raw}/chapters.json"));
        $this->info("Imported {$counts['surahs']} surahs and {$counts['ayahs']} ayahs.");

        return self::SUCCESS;
    }
}
