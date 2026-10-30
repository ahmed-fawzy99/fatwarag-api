<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Surah extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'number';

    protected $fillable = ['number', 'name_ar', 'name_en', 'ayah_count'];

    public function ayahs(): HasMany
    {
        return $this->hasMany(Ayah::class, 'surah_number', 'number')->orderBy('number');
    }
}
