<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hadith extends Model
{
    protected $fillable = ['text', 'search_text', 'narrator', 'translation_en', 'hadith_explanation_id', 'lookup_url'];

    public function gradings(): HasMany
    {
        return $this->hasMany(HadithGrading::class)->orderBy('position');
    }

    public function explanation(): BelongsTo
    {
        return $this->belongsTo(HadithExplanation::class, 'hadith_explanation_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(HadithQuote::class);
    }
}
