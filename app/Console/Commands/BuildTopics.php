<?php

namespace App\Console\Commands;

use App\Content\ContentCache;
use App\Importers\Muftis;
use App\Topics\CategoryMapper;
use App\Topics\TopicTreeBuilder;
use Illuminate\Console\Command;

class BuildTopics extends Command
{
    protected $signature = 'topics:build {--no-ai : Only exact name matches; leave other categories unmapped} {--max-cost=3 : Stop AI proposals once this run cost this much (USD)} {--min-fatwas= : Fatwas a subcategory needs to become a child topic (default: config fatwarag.topics.enrich_min_fatwas)}';

    protected $description = 'Build the shared topic tree from islamqa, deepen its leaves with islamweb/binbaz subcategories, map other sources\' categories onto it, file fatwas under topics, and create the mufti cards';

    public function handle(TopicTreeBuilder $tree, CategoryMapper $mapper): int
    {
        Muftis::ensureAll();

        $topics = $tree->build();
        $result = $mapper->map(useAi: ! $this->option('no-ai'), maxCost: (float) $this->option('max-cost'));
        $enriched = $tree->enrich($this->option('min-fatwas') !== null ? (int) $this->option('min-fatwas') : null);
        $rows = $mapper->syncFatwaTopics();

        $this->info(sprintf(
            'Topics: %d (%d new child topics; %d leaves have children). Categories mapped by name: %d, by AI: %d, still unmapped: %d (AI cost %.4f USD); %d moved to a child topic. Fatwa–topic rows: %d.',
            $topics + $enriched['created'], $enriched['created'], $enriched['leaves'], $result['exact'], $result['ai'], $result['unmapped'], $result['cost'], $enriched['moved'], $rows,
        ));

        // Topic assignment is written with raw SQL (no model events), so cached fatwas and lists would keep the old topics.
        ContentCache::forgetDerived();

        return self::SUCCESS;
    }
}
