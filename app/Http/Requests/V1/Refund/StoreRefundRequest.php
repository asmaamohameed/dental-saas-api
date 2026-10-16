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
    public function validated($key = null, $default = null): array
    {
        if ($key !== null) {
            throw new \BadMethodCallException('StoreRefundRequest::validated() does not accept a key.');
        }

        $data = parent::validated();

        $reasonCode = $data['reason_code'] ?? null;
        if (! is_string($reasonCode)) {
            throw new \UnexpectedValueException('Validated refund reason_code must be a string.');
        }

        $reasonNote = null;
        if ($reasonCode === RefundReasonCode::OTHER->value) {
            $note = $data['reason_note'] ?? null;
            $reasonNote = is_string($note) ? trim($note) : null;
        }

        if (! array_key_exists('method', $data) || $data['method'] === null) {
            return [
                'reason_code' => $reasonCode,
                'reason_note' => $reasonNote,
            ];
        }

        if (! is_string($data['method'])) {
            throw new \UnexpectedValueException('Validated refund method must be a string.');
        }

        return [
            'reason_code' => $reasonCode,
            'reason_note' => $reasonNote,
            'method' => $data['method'],
        ];
    }
}
