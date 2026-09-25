<?php

namespace App\Http\Resources\V1;

use App\Models\XrayAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin XrayAttachment
 */
class XrayAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'appointment_id' => $this->appointment_id,
            'file_type' => $this->file_type,
            'temporary_url' => Storage::disk('s3')->temporaryUrl(
                $this->file_url,
                now()->addMinutes(15),
            ),
            'uploaded_by' => $this->uploaded_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
