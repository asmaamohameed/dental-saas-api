<?php

namespace Tests\Feature\Auth;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('avatars', [
            'url' => 'http://localhost',
        ]);
    }

    public function test_authenticated_user_can_upload_profile_avatar(): void
    {
        $user = $this->actingAsTenantUser();

        $response = $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
        ])->assertOk();

        $response->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user.id', $user->id);

        $avatarUrl = $response->json('data.user.avatar_url');
        $this->assertIsString($avatarUrl);
        $this->assertStringStartsWith('http', $avatarUrl);

        $user->refresh();
        $this->assertSame($avatarUrl, $user->avatar_url);

        $path = parse_url($avatarUrl, PHP_URL_PATH);
        $this->assertIsString($path);
        $objectKey = ltrim($path, '/');
        $bucket = config('filesystems.disks.avatars.bucket');
        if (str_starts_with($objectKey, $bucket.'/')) {
            $objectKey = substr($objectKey, strlen($bucket) + 1);
        }
        Storage::disk('avatars')->assertExists($objectKey);
    }

    public function test_uploading_new_avatar_replaces_and_deletes_previous_object(): void
    {
        $user = $this->actingAsTenantUser();

        $first = $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg'),
        ])->assertOk();

        $firstUrl = $first->json('data.user.avatar_url');
        $firstKey = $this->objectKeyFromFakeUrl($firstUrl);

        $second = $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->image('second.webp'),
        ])->assertOk();

        $secondUrl = $second->json('data.user.avatar_url');
        $this->assertNotSame($firstUrl, $secondUrl);

        Storage::disk('avatars')->assertMissing($firstKey);
        Storage::disk('avatars')->assertExists($this->objectKeyFromFakeUrl($secondUrl));

        $user->refresh();
        $this->assertSame($secondUrl, $user->avatar_url);
    }

    public function test_authenticated_user_can_remove_profile_avatar(): void
    {
        $user = $this->actingAsTenantUser();

        $upload = $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png'),
        ])->assertOk();

        $avatarUrl = $upload->json('data.user.avatar_url');
        $objectKey = $this->objectKeyFromFakeUrl($avatarUrl);

        $this->deleteJson('/api/v1/auth/me/avatar')
            ->assertOk()
            ->assertJsonPath('data.user.avatar_url', null);

        Storage::disk('avatars')->assertMissing($objectKey);

        $user->refresh();
        $this->assertNull($user->avatar_url);
    }

    public function test_avatar_upload_rejects_invalid_file_type(): void
    {
        $this->actingAsTenantUser();

        $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }

    public function test_unauthenticated_user_cannot_upload_avatar(): void
    {
        $this->postJson('/api/v1/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png'),
        ])->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_remove_avatar(): void
    {
        $this->deleteJson('/api/v1/auth/me/avatar')->assertUnauthorized();
    }

    private function objectKeyFromFakeUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->assertIsString($path);
        $objectKey = ltrim($path, '/');
        $bucket = config('filesystems.disks.avatars.bucket');
        if (str_starts_with($objectKey, $bucket.'/')) {
            $objectKey = substr($objectKey, strlen($bucket) + 1);
        }

        return $objectKey;
    }
}
