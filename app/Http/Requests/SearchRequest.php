<?php

namespace App\Http\Requests;

use App\Content\ErrorResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:3', 'max:1000'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
            'followUp' => ['nullable', 'array'],
            'followUp.original' => ['required_with:followUp', 'string', 'min:3', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'question' => is_string($this->input('question')) ? $this->squish($this->input('question')) : null,
            'followUp' => is_array($this->input('followUp')) && is_string($this->input('followUp.original'))
                ? ['original' => $this->squish($this->input('followUp.original'))]
                : null,
        ], fn (mixed $value): bool => $value !== null));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ErrorResponse::validation($validator));
    }

    public function question(): string
    {
        return (string) $this->validated('question');
    }

    public function locale(): string
    {
        return (string) ($this->validated('locale') ?? 'ar');
    }

    public function originalQuestion(): ?string
    {
        return $this->validated('followUp.original');
    }

    private function squish(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
