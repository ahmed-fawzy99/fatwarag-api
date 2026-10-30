<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Source extends Model
{
    protected $fillable = ['key', 'kind', 'tier', 'name_ar', 'name_en', 'base_url', 'settings', 'is_enabled'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_enabled' => 'boolean', 'tier' => 'integer'];
    }

    public function collectorState(): HasOne
    {
        return $this->hasOne(CollectorState::class);
    }

    public function fatwas(): HasMany
    {
        return $this->hasMany(Fatwa::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(SourceCategory::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CollectionTask::class);
    }
}
