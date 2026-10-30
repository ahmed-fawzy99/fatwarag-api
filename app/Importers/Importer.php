<?php

namespace App\Importers;

/**
 * Parses one source's raw harvest (data/raw/<source>/) into fatwas. Imports are incremental and idempotent:
 * pages already imported and unchanged are skipped, and re-running never duplicates a fatwa.
 */
interface Importer
{
    /**
     * @param  ?int  $limit  stop after this many fatwas were created or updated
     * @param  bool  $force  re-parse pages even when they are unchanged since the last import
     */
    public function import(?int $limit = null, bool $force = false): ImportResult;
}
