<?php

namespace App\Models;

use App\Enums\GradeLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HadithGrading extends Model
{
    protected $fillable = ['hadith_id', 'dorar_hash', 'text', 'narrator', 'scholar', 'scholar_en', 'book', 'reference', 'phrase', 'level', 'takhrij', 'tab', 'position'];

    protected function casts(): array
    {
        return ['level' => GradeLevel::class];
    }

    public function hadith(): BelongsTo
    {
        return $this->belongsTo(Hadith::class);
    }

    public function sourceUrl(): ?string
    {
        return $this->dorar_hash ? 'https://dorar.net/h/'.$this->dorar_hash : null;
    }
}
