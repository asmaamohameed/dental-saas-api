<?php

namespace App\Services;

use App\Models\TreatmentTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TreatmentTemplateService
{
    public function create(array $data): TreatmentTemplate
    {
        return TreatmentTemplate::create($data);
    }

    public function update(TreatmentTemplate $template, array $data): TreatmentTemplate
    {
        $template->update($data);

        return $template->fresh();
    }

    public function destroy(TreatmentTemplate $template): void
    {
        if ($template->patientTreatments()->exists()) {
            throw ValidationException::withMessages([
                'template' => 'This template is used by patient treatments and cannot be deleted. Deactivate it instead.',
            ]);
        }

        DB::transaction(function () use ($template) {
            $template->delete();
        });
    }
}
