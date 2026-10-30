<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    protected $table = 'ai_usages';

    public const UPDATED_AT = null;

    protected $fillable = ['purpose', 'model', 'input_tokens', 'output_tokens', 'cost_usd', 'counts_toward_budget', 'duration_ms'];

    protected function casts(): array
    {
        return ['cost_usd' => 'decimal:6', 'counts_toward_budget' => 'boolean', 'duration_ms' => 'integer'];
    }
}
