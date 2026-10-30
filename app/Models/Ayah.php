<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ayah extends Model
{
    public $timestamps = false;

    protected $fillable = ['surah_number', 'number', 'text', 'search_text', 'translation_en', 'footnotes_en'];

    protected function casts(): array
    {
        return ['footnotes_en' => 'array'];
    }

    public function surah(): BelongsTo
    {
        return $this->belongsTo(Surah::class, 'surah_number', 'number');
    }
}
