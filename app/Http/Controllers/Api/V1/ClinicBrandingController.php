<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Clinic\UpdateClinicLogoRequest;
use App\Http\Resources\V1\TenantResource;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class ClinicBrandingController extends Controller
{
    public function updateLogo(UpdateClinicLogoRequest $request): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail(app(CurrentTenant::class)->id());
        $this->deleteExistingLogo($tenant);

        $file = $request->file('logo');
        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'png';
        $relativeDir = "tenant-logos/{$tenant->id}";
        $absoluteDir = public_path($relativeDir);

        File::ensureDirectoryExists($absoluteDir);

        $relativePath = "{$relativeDir}/logo.{$extension}";
        $file->move($absoluteDir, "logo.{$extension}");

        $tenant->update(['logo_path' => $relativePath]);

        return $this->successResponse([
            'tenant' => new TenantResource($tenant->fresh()),
        ], 'Clinic logo updated successfully.');
    }

    private function deleteExistingLogo(Tenant $tenant): void
    {
        if (! $tenant->logo_path) {
            return;
        }

        $publicFile = public_path($tenant->logo_path);
        if (is_file($publicFile)) {
            File::delete($publicFile);

            return;
        }

        if (Storage::disk('public')->exists($tenant->logo_path)) {
            Storage::disk('public')->delete($tenant->logo_path);
        }
    }
}
