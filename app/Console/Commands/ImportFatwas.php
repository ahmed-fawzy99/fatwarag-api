<?php

namespace App\Console\Commands;

use App\Importers\BinbazImporter;
use App\Importers\FawzanImporter;
use App\Importers\Importer;
use App\Importers\ImportLock;
use App\Importers\ImportLockLost;
use App\Importers\IslamqaImporter;
use App\Importers\IslamwebImporter;
use Illuminate\Console\Command;

class ImportFatwas extends Command
{
    /** @var array<string, class-string<Importer>> */
    public const IMPORTERS = [
        'fawzan' => FawzanImporter::class,
        'islamqa' => IslamqaImporter::class,
        'binbaz' => BinbazImporter::class,
        'islamweb' => IslamwebImporter::class,
    ];

    protected $signature = 'fatwas:import {source : fawzan|islamqa|binbaz|islamweb} {--limit= : Stop after this many fatwas were written} {--force : Re-parse pages that are unchanged since the last import}';

    protected $description = 'Import fatwas from a source\'s raw harvest (data/raw/<source>); incremental and safe to re-run';

    public function handle(): int
    {
        $key = (string) $this->argument('source');
        $class = self::IMPORTERS[$key] ?? null;

        if ($class === null || ! class_exists($class)) {
            $this->error("Unknown source «{$key}». Use one of: ".implode(', ', array_keys(self::IMPORTERS)).'.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        try {
            $result = ImportLock::run($key, fn () => app($class)->import($limit, (bool) $this->option('force')));
        } catch (ImportLockLost $lost) {
            $this->error($lost->getMessage());

            return self::FAILURE;
        }

        if ($result === false) {
            $this->info("{$key}: another import of this source is running; skipped.");

            return self::SUCCESS;
        }

        $this->info(sprintf('%s: %s. Peak memory %d MB.', $key, $result->summary(), memory_get_peak_usage(true) / 1048576));
        foreach (array_slice($result->failures, 0, 5) as $failure) {
            $this->warn($failure);
        }

        return self::SUCCESS;
    }
}
