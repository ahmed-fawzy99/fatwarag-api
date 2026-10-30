<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TranslationJob extends Model
{
    protected $fillable = ['locale', 'scope', 'status', 'total', 'done', 'failed', 'stop_reason'];
}
