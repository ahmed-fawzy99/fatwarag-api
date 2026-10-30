<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionTask extends Model
{
    protected $fillable = ['source_id', 'kind', 'external_id', 'url', 'payload', 'priority', 'status', 'attempts', 'last_error', 'available_at', 'done_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
