<?php

namespace App\Models;

use App\Enums\GradeLevel;
use Illuminate\Database\Eloquent\Model;

class GlossaryTerm extends Model
{
    protected $fillable = ['key', 'label_ar', 'label_en', 'level', 'entry_ar', 'entry_en', 'position'];

    protected function casts(): array
    {
        return ['entry_ar' => 'array', 'entry_en' => 'array', 'level' => GradeLevel::class];
    }
}
