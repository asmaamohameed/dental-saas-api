<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

class ServiceProtectedException extends \DomainException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $this->getMessage(),
        ], 422);
    }
}
