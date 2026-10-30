<?php

namespace App\Console\Commands;

use App\Collectors\BinbazCollector;
use App\Collectors\Collector;
use App\Collectors\IslamqaCollector;
use App\Collectors\IslamwebCollector;
use Illuminate\Console\Command;

class RefreshFatwas extends Command
{
    /** @var array<string, class-string<Collector>> */
    public const COLLECTORS = [
        'islamqa' => IslamqaCollector::class,
        'binbaz' => BinbazCollector::class,
        'islamweb' => IslamwebCollector::class,
    ];

    protected $signature = 'fatwas:refresh {source : islamqa|binbaz|islamweb}';

    protected $description = 'Collect fatwas a source published since the last run, then import them (the 3-day refresh)';

    public function handle(): int
    {
        $key = (string) $this->argument('source');

        if ($key === 'fawzan') {
            $this->info('al-Fawzan is a finished printed book: nothing to refresh.');

            return self::SUCCESS;
        }

        if (! isset(self::COLLECTORS[$key])) {
            $this->error("Unknown source «{$key}». Use one of: ".implode(', ', array_keys(self::COLLECTORS)).'.');

            return self::FAILURE;
        }

        $state = app(self::COLLECTORS[$key])->refresh();
        $this->info("{$key}: {$state->status}".($state->stop_reason ? " ({$state->stop_reason})" : '').", {$state->items_collected} collected in total.");

        return self::SUCCESS;
    }
}
