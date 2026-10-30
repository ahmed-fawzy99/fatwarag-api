<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mufti extends Model
{
    protected $fillable = ['key', 'kind', 'name_ar', 'name_en', 'bio_ar', 'bio_en', 'source_url'];

    public function fatwas(): HasMany
    {
        return $this->hasMany(Fatwa::class);
    }
}
