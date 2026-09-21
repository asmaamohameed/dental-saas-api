<?php

namespace App\Http\Requests\V1\AuditLog;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', AuditLog::class);
    }

    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'uuid', 'exists:users,id'],
            'action' => ['sometimes', Rule::enum(AuditAction::class)],
            'auditable_type' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ];
    }
}
