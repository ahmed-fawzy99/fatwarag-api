<?php

namespace App\Models;

use Database\Factories\SharedAnswerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An answer a visitor chose to share: the question, the fatwa list (public ids, best first) and the final summary
 * (`null` when there was none, e.g. after a failed summary). Created only by an explicit share; never expires.
 *
 * @property string $public_id
 * @property string $question
 * @property string|null $follow_up
 * @property string $locale
 * @property list<string> $fatwa_ids
 * @property array<string, mixed>|null $summary
 */
class SharedAnswer extends Model
{
    /** @use HasFactory<SharedAnswerFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** Length of the public id: 12 base62 characters (about 71 bits from a CSPRNG), so ids can't be guessed. */
    public const PUBLIC_ID_LENGTH = 12;

    protected $fillable = ['public_id', 'question', 'follow_up', 'locale', 'fatwa_ids', 'summary', 'ip_hash'];

    protected $hidden = ['ip_hash'];

    protected function casts(): array
    {
        return ['fatwa_ids' => 'array', 'summary' => 'array', 'created_at' => 'datetime'];
    }

    public static function newPublicId(): string
    {
        do {
            $id = Str::random(self::PUBLIC_ID_LENGTH);
        } while (self::query()->where('public_id', $id)->exists());

        return $id;
    }
}
