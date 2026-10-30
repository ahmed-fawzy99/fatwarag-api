<?php

namespace App\Models;

use App\Services\CommonAnswers;
use Database\Factories\CommonQuestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A site-authored question shown on the home page («أسئلة شائعة»), whose answer is generated ahead of time
 * (`common:generate`). Its normalized texts are what a visitor's question is matched against, exactly.
 */
class CommonQuestion extends Model
{
    /** @use HasFactory<CommonQuestionFactory> */
    use HasFactory;

    protected $fillable = ['position', 'question_ar', 'question_en', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (CommonQuestion $question): void {
            $question->normalized_ar = CommonAnswers::normalize($question->question_ar, 'ar');
            $question->normalized_en = filled($question->question_en) ? CommonAnswers::normalize($question->question_en, 'en') : null;
        });

        // A reworded question no longer matches its stored answer: drop it, so the question is searched live until regenerated.
        static::saved(function (CommonQuestion $question): void {
            foreach (['ar', 'en'] as $locale) {
                if (! $question->wasRecentlyCreated && $question->wasChanged("normalized_{$locale}")) {
                    $question->answers()->where('locale', $locale)->delete();
                }
            }
        });
    }

    /**
     * @return HasMany<CommonAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(CommonAnswer::class);
    }

    public function questionFor(string $locale): ?string
    {
        return $locale === 'en' ? $this->question_en : $this->question_ar;
    }

    /**
     * @param  Builder<CommonQuestion>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
