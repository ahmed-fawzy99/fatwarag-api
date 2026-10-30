<?php

namespace App\Content;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;

/**
 * The API's error envelope: { "error": { "code": string, "message": string } }.
 */
class ErrorResponse
{
    public static function make(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    public static function notFound(string $message = 'Not found.'): JsonResponse
    {
        return self::make('not_found', $message, 404);
    }

    public static function validation(Validator|string $failure): JsonResponse
    {
        $message = $failure instanceof Validator ? (string) $failure->errors()->first() : $failure;

        return self::make('validation', $message, 422);
    }
}
