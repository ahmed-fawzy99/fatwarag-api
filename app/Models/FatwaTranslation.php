<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FatwaTranslation extends Model
{
    protected $fillable = ['fatwa_id', 'locale', 'kind', 'title', 'question', 'answer', 'source_summary', 'url', 'model'];

    protected function casts(): array
    {
        return ['question' => 'array', 'answer' => 'array', 'source_summary' => 'array'];
    }

    public function fatwa(): BelongsTo
    {
        return $this->belongsTo(Fatwa::class);
    }

    public function hasBody(): bool
    {
        return $this->answer !== null;
    }
}
