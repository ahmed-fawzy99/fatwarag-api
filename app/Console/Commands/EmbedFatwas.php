<?php

namespace App\Console\Commands;

use App\Search\EmbeddingIndexer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fatwas:embed {--limit= : Embed at most this many chunks} {--max-cost=45 : Total indexing spend cap in USD, across all runs}')]
#[Description('Chunk new or changed fatwas and embed every chunk without an embedding (voyage-4-large via OpenRouter)')]
class EmbedFatwas extends Command
{
    public function handle(EmbeddingIndexer $indexer): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $result = $indexer->run($limit, (float) $this->option('max-cost'));

        $this->info(sprintf(
            'Chunked %d fatwas, embedded %d chunks for %.4f USD.%s%s',
            $result['chunked'],
            $result['embedded'],
            $result['costUsd'],
            $result['stoppedAtCap'] ? ' Stopped at the cost cap.' : '',
            $result['failed'] ? ' Stopped on a provider failure; the next run resumes.' : '',
        ));

        return self::SUCCESS;
    }
}
