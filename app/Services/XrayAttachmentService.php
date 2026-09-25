<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\User;
use App\Models\XrayAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class XrayAttachmentService
{
    public function store(
        Patient $patient,
        UploadedFile $file,
        User $user,
        ?string $appointmentId = null,
    ): XrayAttachment {
        $extension = $file->getClientOriginalExtension()
            ?: $file->guessExtension()
            ?: 'bin';

        $path = sprintf(
            'xrays/%s/%s/%s.%s',
            $patient->tenant_id,
            $patient->id,
            Str::uuid(),
            $extension,
        );

        Storage::disk('s3')->put($path, fopen($file->getRealPath(), 'r'));

        return $patient->xrayAttachments()->create([
            'appointment_id' => $appointmentId,
            'file_url' => $path,
            'file_type' => $file->getMimeType(),
            'uploaded_by' => $user->id,
        ]);
    }
}
