<?php

namespace App\Importers;

use Illuminate\Support\Facades\DB;

/**
 * Points cross-references («وانظر الفتوى رقم…») at their target fatwa once both are imported, whatever the order.
 */
class LinkResolver
{
    /**
     * @return int links newly resolved
     */
    public function resolve(): int
    {
        return DB::affectingStatement(
            'update fatwa_links set target_fatwa_id = target.id, updated_at = now()
             from fatwas origin, fatwas target
             where fatwa_links.fatwa_id = origin.id
               and target.source_id = origin.source_id
               and target.source_fatwa_id = fatwa_links.target_source_fatwa_id
               and fatwa_links.target_fatwa_id is null',
        );
    }
}
