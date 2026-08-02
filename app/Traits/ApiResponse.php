<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Build a success response
     *
     * @param  mixed  $data
     */
    protected function successResponse($data = null, ?string $message = null, int $code = 200): JsonResponse
    {
        $response = ['status' => 'success'];

        if (! is_null($message)) {
            $response['message'] = $message;
        }

        if (! is_null($data)) {
            $response['data'] = $data;
        }

        return response()->json($response, $code);
    }

    /**
     * Build an error response
     *
     * @param  mixed  $errors
     */
    protected function errorResponse(string $message, int $code = 400, $errors = null): JsonResponse
    {
        $response = [
            'status' => 'error',
            'message' => $message,
        ];

        if (! is_null($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }
}
