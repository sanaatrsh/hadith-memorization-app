<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Consistent API response envelope used across the whole API.
 *
 * {
 *   "success": true,
 *   "message": "Operation completed successfully.",
 *   "data": {}
 * }
 */
class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'Operation completed successfully.', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function error(string $message = 'Something went wrong.', int $status = 400, mixed $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
