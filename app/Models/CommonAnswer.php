<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The pre-generated answer to a common question in one locale: the fatwa list (public ids, best first) and the summary
 * (`null` when its status is `failed`, which serves the fatwa list alone).
 */
class CommonAnswer extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['common_question_id', 'locale', 'fatwa_ids', 'summary', 'status', 'generated_at'];

    protected function casts(): array
    {
        return ['fatwa_ids' => 'array', 'summary' => 'array', 'generated_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<CommonQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(CommonQuestion::class, 'common_question_id');
    }

    /**
     * Whether the answer has been generated. Fatwas never change, so a generated answer is served as it is for good
     * (owner decision); only the admin or `common:generate --force`/`--only` regenerates it.
     */
    public function isGenerated(): bool
    {
        return $this->generated_at !== null;
    }
}
