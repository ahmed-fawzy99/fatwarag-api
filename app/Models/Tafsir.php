<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tafsir extends Model
{
    public $timestamps = false;

    protected $fillable = ['key', 'name_ar', 'name_en', 'source_url', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function passages(): HasMany
    {
        return $this->hasMany(TafsirPassage::class);
    }
}
