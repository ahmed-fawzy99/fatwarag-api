<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    protected $fillable = ['fingerprint', 'kind', 'message', 'context', 'occurrences', 'first_seen_at', 'last_seen_at', 'notified_at', 'resolved_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'notified_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
