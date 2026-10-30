<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HadithQuote extends Model
{
    protected $fillable = ['quote_key', 'quote', 'hadith_id', 'status', 'fatwa_count'];

    public function hadith(): BelongsTo
    {
        return $this->belongsTo(Hadith::class);
    }
}
