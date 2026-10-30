<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreReportRequest extends ApiFormRequest
{
    public const REASONS = ['misrepresents', 'irrelevant', 'wrongLink', 'translation', 'other'];

    public const PARTS = ['summary', 'fatwa', 'quran', 'hadith'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(self::REASONS)],
            'comment' => ['nullable', 'string', 'max:1000'],
            'locale' => ['required', Rule::in(['ar', 'en'])],
            'turnstileToken' => ['nullable', 'string', 'max:4096'],
            'context' => ['required', 'array', fn (string $attribute, mixed $value, \Closure $fail) => strlen((string) json_encode($value)) > 100_000 ? $fail('The report context is too large.') : null],
            'context.pageUrl' => ['required', 'string', 'max:2000'],
            'context.part' => ['required', Rule::in(self::PARTS)],
            'context.item' => ['nullable', 'array'],
            'context.question' => ['nullable', 'string', 'max:2000'],
            'context.summary' => ['nullable', 'array'],
            'context.quote' => ['nullable', 'string', 'max:2000'],
            'context.pointedTo' => ['nullable', 'string', 'max:2000'],
            'context.referrerFatwaId' => ['nullable', 'string', 'max:200'],
            'context.shareId' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reportContext(): array
    {
        return [
            ...$this->validated('context'),
            'device' => mb_substr((string) $this->userAgent(), 0, 300),
        ];
    }
}
