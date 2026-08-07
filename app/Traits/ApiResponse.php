<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
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
     * Build a success response for a paginated resource collection.
     */
    protected function paginatedResponse($resourceCollection, ?string $message = null, int $code = 200): JsonResponse
    {
        return $this->successResponse([
            'items' => $resourceCollection->collection,
            'meta' => [
                'current_page' => $resourceCollection->currentPage(),
                'last_page' => $resourceCollection->lastPage(),
                'per_page' => $resourceCollection->perPage(),
                'total' => $resourceCollection->total(),
            ],
        ], $message, $code);
    }

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
