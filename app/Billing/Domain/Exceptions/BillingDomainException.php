<?php

namespace App\Billing\Domain\Exceptions;

use Illuminate\Http\JsonResponse;

class BillingDomainException extends \DomainException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $this->getMessage(),
        ], 422);
    }
}
