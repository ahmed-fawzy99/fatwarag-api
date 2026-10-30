<?php

namespace App\Http\Requests;

use App\Content\ErrorResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A form request whose validation errors use the API's error envelope: { error: { code: "validation", message } }.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ErrorResponse::validation($validator));
    }
}
