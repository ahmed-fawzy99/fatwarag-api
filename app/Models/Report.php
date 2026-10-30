<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $fillable = ['reason', 'comment', 'locale', 'context', 'status', 'notified_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'notified_at' => 'datetime'];
    }
}
