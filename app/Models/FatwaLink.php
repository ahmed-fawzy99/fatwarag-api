<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FatwaLink extends Model
{
    protected $fillable = ['fatwa_id', 'target_source_fatwa_id', 'target_fatwa_id', 'anchor_text', 'position'];

    public function fatwa(): BelongsTo
    {
        return $this->belongsTo(Fatwa::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Fatwa::class, 'target_fatwa_id');
    }
}
