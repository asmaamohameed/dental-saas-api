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
            'temporary_url' => $this->temporaryUrl(),
            'uploaded_by' => $this->uploaded_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function temporaryUrl(): ?string
    {
        $path = $this->objectKey();

        if ($path === null) {
            return null;
        }

        return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(15));
    }

    private function objectKey(): ?string
    {
        $stored = $this->file_url;

        if (! str_contains($stored, '://')) {
            return ltrim($stored, '/');
        }

        $path = parse_url($stored, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = ltrim($path, '/');
        $bucket = (string) config('filesystems.disks.s3.bucket');

        if ($bucket !== '' && str_starts_with($path, $bucket.'/')) {
            return substr($path, strlen($bucket) + 1);
        }

        return $path;
    }
}
