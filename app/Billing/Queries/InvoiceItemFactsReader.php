<?php

namespace App\Billing\Queries;

use App\Billing\Domain\InvoiceItemFacts;
use App\Models\InvoiceItem;
use BackedEnum;

/**
 * Maps one invoice item row onto the pure facts the resolver accepts.
 */
final class InvoiceItemFactsReader
{
    public function read(InvoiceItem $item): InvoiceItemFacts
    {
        $item->loadMissing(['tooth', 'patientTreatment', 'appointment']);

        return new InvoiceItemFacts(
            deleted: $item->trashed(),
            voided: $item->voided_at !== null,
            patientTreatmentToothId: $item->patient_treatment_tooth_id,
            toothStatus: $this->scalar($item->tooth?->status),
            patientTreatmentId: $item->patient_treatment_id,
            clinicalStatus: $this->scalar($item->patientTreatment?->clinical_status),
            appointmentId: $item->appointment_id,
            appointmentStatus: $this->scalar($item->appointment?->status),
            appointmentWasCompleted: $item->appointment?->completed_at !== null,
        );
    }

    private function scalar(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
