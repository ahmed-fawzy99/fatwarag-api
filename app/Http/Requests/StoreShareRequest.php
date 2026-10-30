<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreShareRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answerId' => ['required', 'string', 'regex:/^[0-9a-f]{32}$/'],
            'locale' => ['required', Rule::in(['ar', 'en'])],
            'turnstileToken' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
