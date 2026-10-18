<?php

namespace App\Http\Requests\V1\Refund;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

class ListRefundsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAnyRefund', Payment::class);
    }

    public function rules(): array
    {
        return [
            'invoice_id' => ['nullable', 'uuid'],
            'patient_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:100'],
            'reason_code' => ['nullable', 'string', 'max:100'],
            'method' => ['nullable', 'string', 'in:cash,card,transfer,other'],
            'refunded_by' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
