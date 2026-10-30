<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HadithExplanation extends Model
{
    protected $fillable = ['dorar_sharh_id', 'text', 'source_url'];

    public function hadiths(): HasMany
    {
        return $this->hasMany(Hadith::class);
    }
}
