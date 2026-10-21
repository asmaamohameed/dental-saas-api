<?php

namespace App\Http\Requests\V1\Refund;

use App\Enums\RefundReasonCode;
use App\Enums\RefundType;
use App\Models\Invoice;
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

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->filled('reason_code') && $this->filled('reason')) {
            $merge['reason_code'] = $this->input('reason');
        }

        if (! $this->filled('reason_note') && $this->filled('notes')) {
            $merge['reason_note'] = $this->input('notes');
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->route('invoice');

        return [
            'type' => ['nullable', 'string', Rule::enum(RefundType::class)],
            'amount' => [
                Rule::requiredIf(fn () => $this->input('type') !== RefundType::FULL->value),
                'nullable',
                'numeric',
                'decimal:0,2',
                'min:0.01',
            ],
            'reason_code' => ['required', 'string', Rule::enum(RefundReasonCode::class)],
            'reason_note' => ['nullable', 'string', 'max:'.RefundReasonCode::NOTE_MAX_LENGTH],
            'method' => ['nullable', 'string', 'in:cash,card,transfer,other'],
            'invoice_item_id' => [
                'nullable',
                'uuid',
                Rule::exists('invoice_items', 'id')
                    ->where('invoice_id', $invoice->id)
                    ->whereNull('deleted_at'),
            ],
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
     * @return array{
     *     amount?: string,
     *     type?: string,
     *     reason_code: string,
     *     reason_note: string|null,
     *     method?: string,
     *     invoice_item_id?: string
     * }
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

        $payload = [
            'reason_code' => $reasonCode,
            'reason_note' => $reasonNote,
        ];

        $type = $data['type'] ?? null;
        if (is_string($type) && $type !== '') {
            $payload['type'] = $type;
        }

        $amount = $data['amount'] ?? null;
        if ($type !== RefundType::FULL->value) {
            if (! is_numeric($amount)) {
                throw new \UnexpectedValueException('Validated refund amount must be numeric.');
            }

            $payload['amount'] = bcadd((string) $amount, '0', 2);
        }

        if (isset($data['invoice_item_id']) && is_string($data['invoice_item_id'])) {
            $payload['invoice_item_id'] = $data['invoice_item_id'];
        }

        if (! array_key_exists('method', $data) || $data['method'] === null) {
            return $payload;
        }

        if (! is_string($data['method'])) {
            throw new \UnexpectedValueException('Validated refund method must be a string.');
        }

        $payload['method'] = $data['method'];

        return $payload;
    }
}
