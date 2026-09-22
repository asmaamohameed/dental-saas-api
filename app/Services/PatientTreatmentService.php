<?php

namespace App\Services;

use App\Enums\PatientTreatmentStatus;
use App\Enums\PatientTreatmentVisitStatus;
use App\Models\Component;
use App\Models\PatientTreatment;
use App\Models\TreatmentTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PatientTreatmentService
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PatientTreatment::query()
            ->with(['patient', 'template', 'doctor', 'visits.components']);

        if (! empty($filters['patient_id'])) {
            $query->where('patient_id', $filters['patient_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['doctor_id'])) {
            $query->where('doctor_id', $filters['doctor_id']);
        }

        return $query->latest()->paginate($perPage);
    }

    public function createFromTemplate(array $data, string $userId): PatientTreatment
    {
        return DB::transaction(function () use ($data, $userId) {
            /** @var TreatmentTemplate $template */
            $template = TreatmentTemplate::query()
                ->with(['visits.components.component'])
                ->findOrFail($data['treatment_template_id']);

            $treatment = PatientTreatment::create([
                'patient_id' => $data['patient_id'],
                'treatment_template_id' => $template->id,
                'doctor_id' => $data['doctor_id'] ?? null,
                'tooth_number' => $data['tooth_number'] ?? null,
                'diagnosis' => $data['diagnosis'] ?? null,
                'status' => $data['status'] ?? PatientTreatmentStatus::PROPOSED,
                'priority' => $data['priority'] ?? 'routine',
                'actual_price' => $data['actual_price'] ?? $template->default_price,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($template->visits as $templateVisit) {
                $visit = $treatment->visits()->create([
                    'visit_order' => $templateVisit->visit_order,
                    'name' => $templateVisit->name_en ?: $templateVisit->name_ar,
                    'description' => $templateVisit->description,
                    'status' => PatientTreatmentVisitStatus::PLANNED,
                ]);

                foreach ($templateVisit->components as $templateComponent) {
                    /** @var Component|null $component */
                    $component = $templateComponent->component;

                    $visit->components()->create([
                        'component_id' => $component?->id,
                        'name' => $component?->name_en ?: $component?->name_ar ?: 'Component',
                        'unit_price' => $templateComponent->unit_price,
                        'quantity' => $templateComponent->quantity,
                        'free_quantity' => $templateComponent->free_quantity,
                        'unit' => $component?->unit ?: 'piece',
                        'inventory_item_id' => $component?->inventory_item_id,
                    ]);
                }
            }

            return $treatment->load(['patient', 'template', 'doctor', 'visits.components']);
        });
    }

    public function update(PatientTreatment $treatment, array $data): PatientTreatment
    {
        $treatment->update($data);

        return $treatment->load(['patient', 'template', 'doctor', 'visits.components']);
    }

    public function nextForPatient(string $patientId): ?PatientTreatment
    {
        $inProgress = PatientTreatment::query()
            ->with(['template', 'doctor', 'visits.components'])
            ->where('patient_id', $patientId)
            ->where('status', PatientTreatmentStatus::IN_PROGRESS)
            ->whereHas('visits', fn ($query) => $query->whereNot('status', PatientTreatmentVisitStatus::COMPLETED))
            ->oldest()
            ->first();

        if ($inProgress) {
            return $inProgress;
        }

        return PatientTreatment::query()
            ->with(['template', 'doctor', 'visits.components'])
            ->where('patient_id', $patientId)
            ->where('status', PatientTreatmentStatus::PLANNED)
            ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->oldest()
            ->first();
    }
}
