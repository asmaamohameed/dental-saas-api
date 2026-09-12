<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

class InvoiceHasPaymentsException extends \DomainException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $this->getMessage(),
        ], 422);
    }
}
