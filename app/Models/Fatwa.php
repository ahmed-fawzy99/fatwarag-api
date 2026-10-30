<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fatwa extends Model
{
    protected $fillable = [
        'public_id', 'source_id', 'source_fatwa_id', 'url', 'mufti_id', 'title', 'question', 'answer', 'source_summary',
        'origin', 'hijri_year', 'hijri_month', 'hijri_day', 'date_gregorian', 'is_cross_reference', 'search_text', 'collected_at', 'parser_version',
    ];

    protected $hidden = ['search_vector'];

    protected function casts(): array
    {
        return [
            'question' => 'array',
            'answer' => 'array',
            'source_summary' => 'array',
            'date_gregorian' => 'date',
            'is_cross_reference' => 'boolean',
            'collected_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function mufti(): BelongsTo
    {
        return $this->belongsTo(Mufti::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(FatwaTranslation::class);
    }

    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(SourceCategory::class, 'fatwa_source_category');
    }

    /** Fatwas this one points to («وانظر الفتوى رقم…»). */
    public function links(): HasMany
    {
        return $this->hasMany(FatwaLink::class)->orderBy('position');
    }

    /** Links from other fatwas that point to this one. */
    public function backlinks(): HasMany
    {
        return $this->hasMany(FatwaLink::class, 'target_fatwa_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(FatwaChunk::class)->orderBy('position');
    }
}
