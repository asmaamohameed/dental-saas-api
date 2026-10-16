<?php

namespace App\Http\Requests\V1\Refund;

use App\Enums\RefundReasonCode;
use App\Models\Refund;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Refund::class, $this->route('invoice')]);
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', Rule::enum(RefundReasonCode::class)],
            'reason_note' => ['nullable', 'string', 'max:'.RefundReasonCode::NOTE_MAX_LENGTH],
            'method' => ['nullable', 'string', 'in:cash,card,transfer,other'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $code = $this->input('reason_code');
            if ($code !== RefundReasonCode::OTHER->value) {
                return;
            }

            if (trim((string) $this->input('reason_note', '')) === '') {
                $validator->errors()->add(
                    'reason_note',
                    'A note is required when the refund reason is Other.'
                );
            }
        });
    }

    /**
     * @return array{reason_code: string, reason_note: string|null, method?: string}
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if (! is_array($data)) {
            return $data;
        }

        if (($data['reason_code'] ?? null) !== RefundReasonCode::OTHER->value) {
            $data['reason_note'] = null;
        } else {
            $data['reason_note'] = trim((string) ($data['reason_note'] ?? ''));
        }

        return $data;
    }
}
