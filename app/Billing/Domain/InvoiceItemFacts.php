<?php

namespace App\Billing\Domain;

/**
 * Plain inputs for item-state derivation. No models and no database values beyond scalars.
 */
final readonly class InvoiceItemFacts
{
    public function __construct(
        public bool $deleted,
        public bool $voided,
        public ?string $patientTreatmentToothId,
        public ?string $toothStatus,
        public ?string $patientTreatmentId,
        public ?string $clinicalStatus,
        public ?string $appointmentId,
        public ?string $appointmentStatus,
        public bool $appointmentWasCompleted,
    ) {}
}
