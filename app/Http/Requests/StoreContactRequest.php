<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreContactRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'locale' => ['required', Rule::in(['ar', 'en'])],
            'turnstileToken' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
