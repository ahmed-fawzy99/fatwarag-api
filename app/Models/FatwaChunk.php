<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HalfVector;
use Pgvector\Laravel\HasNeighbors;

class FatwaChunk extends Model
{
    use HasNeighbors;

    protected $fillable = ['fatwa_id', 'position', 'content', 'embedding', 'embedding_input_type'];

    protected function casts(): array
    {
        return ['embedding' => HalfVector::class];
    }

    public function fatwa(): BelongsTo
    {
        return $this->belongsTo(Fatwa::class);
    }
}
