<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectorState extends Model
{
    protected $fillable = ['source_id', 'status', 'stop_reason', 'cursor', 'items_collected', 'last_started_at', 'last_finished_at', 'last_checked_at'];

    protected function casts(): array
    {
        return ['cursor' => 'array', 'last_started_at' => 'datetime', 'last_finished_at' => 'datetime', 'last_checked_at' => 'datetime'];
    }

    /** A running collector touches its state at least this often; one silent for longer has crashed. */
    public const HEARTBEAT_MINUTES = 30;

    /**
     * «interrupted» when the stored status is still «running» but the collector stopped reporting (it crashed or was
     * killed); the next run resumes its unfinished tasks.
     */
    protected function status(): Attribute
    {
        return Attribute::get(fn (?string $status): ?string => $status === 'running' && $this->updated_at?->lt(now()->subMinutes(self::HEARTBEAT_MINUTES))
            ? 'interrupted'
            : $status);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
