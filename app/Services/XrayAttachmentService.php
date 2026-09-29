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
    private const DISK = 's3';

    public function store(
        Patient $patient,
        UploadedFile $file,
        User $user,
        ?string $appointmentId = null,
    ): XrayAttachment {
        $extension = strtolower($file->getClientOriginalExtension()
            ?: $file->guessExtension()
            ?: 'bin');

        $path = sprintf(
            'xrays/%s/%s/%s.%s',
            $patient->tenant_id,
            $patient->id,
            Str::uuid(),
            $extension,
        );

        Storage::disk(self::DISK)->put($path, fopen($file->getRealPath(), 'r'), [
            'ContentType' => $file->getMimeType() ?: $file->getClientMimeType(),
        ]);

        $attachment = new XrayAttachment([
            'appointment_id' => $appointmentId,
            'file_url' => $path,
            'file_type' => $file->getMimeType() ?: $file->getClientMimeType(),
            'uploaded_by' => $user->id,
        ]);
        $patient->xrayAttachments()->save($attachment);

        return $attachment;
    }

    public function delete(XrayAttachment $attachment): void
    {
        $this->deleteStoredObject($attachment->file_url);
        $attachment->delete();
    }

    private function deleteStoredObject(string $storedPath): void
    {
        $path = $this->objectKeyFromStoredValue($storedPath);

        if ($path !== null && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    private function objectKeyFromStoredValue(string $stored): ?string
    {
        if (! str_contains($stored, '://')) {
            return ltrim($stored, '/');
        }

        $path = parse_url($stored, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = ltrim($path, '/');
        $bucket = (string) config('filesystems.disks.'.self::DISK.'.bucket');

        if ($bucket !== '' && str_starts_with($path, $bucket.'/')) {
            return substr($path, strlen($bucket) + 1);
        }

        return $path;
    }
}
