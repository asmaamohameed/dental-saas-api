<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\XrayAttachment\StoreXrayAttachmentRequest;
use App\Http\Resources\V1\XrayAttachmentResource;
use App\Models\Patient;
use App\Models\XrayAttachment;
use App\Services\XrayAttachmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class XrayAttachmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly XrayAttachmentService $xrayAttachmentService) {}

    public function index(Patient $patient): JsonResponse
    {
        $this->authorize('viewAny', [XrayAttachment::class, $patient]);

        $attachments = $patient->xrayAttachments()->latest()->get();

        return $this->successResponse([
            'items' => XrayAttachmentResource::collection($attachments),
        ], 'X-ray attachments retrieved successfully.');
    }

    public function store(StoreXrayAttachmentRequest $request, Patient $patient): JsonResponse
    {
        $this->authorize('create', [XrayAttachment::class, $patient]);

        $attachment = $this->xrayAttachmentService->store(
            $patient,
            $request->file('file'),
            $request->user(),
            $request->validated('appointment_id'),
        );

        return $this->successResponse(
            new XrayAttachmentResource($attachment),
            'X-ray attachment uploaded successfully.',
            201,
        );
    }

    public function destroy(Patient $patient, XrayAttachment $xray): JsonResponse
    {
        if ($xray->patient_id !== $patient->id) {
            abort(404);
        }

        $this->authorize('delete', $xray);

        $this->xrayAttachmentService->delete($xray);

        return $this->successResponse(null, 'X-ray attachment deleted successfully.');
    }
}
