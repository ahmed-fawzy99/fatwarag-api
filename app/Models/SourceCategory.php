<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SourceCategory extends Model
{
    protected $fillable = ['source_id', 'parent_id', 'external_id', 'name', 'path', 'fatwa_count', 'topic_id', 'mapping_status'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(SourceCategory::class, 'parent_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function fatwas(): BelongsToMany
    {
        return $this->belongsToMany(Fatwa::class, 'fatwa_source_category');
    }
}
