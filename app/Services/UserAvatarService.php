<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserAvatarService
{
    public function store(User $user, UploadedFile $file): User
    {
        $this->deleteStoredObject($user->avatar_url);

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
        $path = sprintf(
            'tenants/%s/users/%s/%s.%s',
            $user->tenant_id,
            $user->id,
            (string) Str::uuid(),
            $extension
        );

        Storage::disk('avatars')->put($path, fopen($file->getRealPath(), 'r'), [
            'ContentType' => $file->getMimeType() ?: $file->getClientMimeType(),
        ]);

        $user->update([
            'avatar_url' => Storage::disk('avatars')->url($path),
        ]);

        return $user->fresh();
    }

    public function remove(User $user): User
    {
        $this->deleteStoredObject($user->avatar_url);

        $user->update(['avatar_url' => null]);

        return $user->fresh();
    }

    private function deleteStoredObject(?string $publicUrl): void
    {
        if (! $publicUrl) {
            return;
        }

        $path = $this->objectKeyFromPublicUrl($publicUrl);

        if ($path !== null && Storage::disk('avatars')->exists($path)) {
            Storage::disk('avatars')->delete($path);
        }
    }

    private function objectKeyFromPublicUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = ltrim($path, '/');
        $bucket = (string) config('filesystems.disks.avatars.bucket');

        if ($bucket !== '' && str_starts_with($path, $bucket.'/')) {
            return substr($path, strlen($bucket) + 1);
        }

        return $path;
    }
}
