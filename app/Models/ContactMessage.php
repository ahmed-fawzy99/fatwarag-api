<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $fillable = ['name', 'email', 'message', 'locale', 'status', 'notified_at'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }
}
