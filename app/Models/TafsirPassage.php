<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TafsirPassage extends Model
{
    public $timestamps = false;

    protected $fillable = ['tafsir_id', 'surah_number', 'ayah_from', 'ayah_to', 'text'];

    public function tafsir(): BelongsTo
    {
        return $this->belongsTo(Tafsir::class);
    }
}
