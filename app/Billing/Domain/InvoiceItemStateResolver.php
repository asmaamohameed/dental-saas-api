<?php

namespace App\Billing\Domain;

/**
 * Derived invoice-item state. Keep the rules identical to InvoiceItemStateSql.
 *
 * Visit lines: completed is done. checked_in, in_progress, and scheduled are planned.
 * cancelled and no_show stay done when the appointment still has completed_at
 * (those transitions do not clear it) and stay planned when it was never completed.
 * Manual lines stay planned so they remain deletable; countsAsPerformed() is separate.
 */
final class InvoiceItemStateResolver
{
    public function resolve(InvoiceItemFacts $facts): InvoiceItemState
    {
        if ($facts->deleted) {
            return InvoiceItemState::DELETED;
        }

        if ($facts->voided) {
            return InvoiceItemState::VOIDED;
        }

        if ($this->present($facts->patientTreatmentToothId)) {
            return $this->fromToothStatus($facts->toothStatus);
        }

        if ($this->present($facts->appointmentId)) {
            return $this->fromAppointment($facts);
        }

        if ($this->present($facts->patientTreatmentId)) {
            return $this->fromClinicalStatus($facts->clinicalStatus);
        }

        return InvoiceItemState::PLANNED;
    }

    /**
     * Not deleted, not voided, and derived state is not skipped.
     * Deleted and voided are checked on the flags: resolve() would classify them
     * as those states, which are not skipped, and they must still be excluded.
     */
    public function billable(InvoiceItemFacts $facts): bool
    {
        if ($facts->deleted || $facts->voided) {
            return false;
        }

        return $this->resolve($facts) !== InvoiceItemState::SKIPPED;
    }

    public function countsAsPerformed(InvoiceItemFacts $facts): bool
    {
        if ($facts->deleted || $facts->voided) {
            return false;
        }

        if ($this->isManual($facts)) {
            return true;
        }

        return $this->resolve($facts) === InvoiceItemState::DONE;
    }

    private function fromAppointment(InvoiceItemFacts $facts): InvoiceItemState
    {
        if ($facts->appointmentStatus === 'completed') {
            return InvoiceItemState::DONE;
        }

        if (
            in_array($facts->appointmentStatus, ['cancelled', 'no_show'], true)
            && $facts->appointmentWasCompleted
        ) {
            return InvoiceItemState::DONE;
        }

        return InvoiceItemState::PLANNED;
    }

    private function fromToothStatus(?string $status): InvoiceItemState
    {
        return match ($status) {
            'planned' => InvoiceItemState::PLANNED,
            'in_progress' => InvoiceItemState::IN_PROGRESS,
            'done' => InvoiceItemState::DONE,
            'skipped' => InvoiceItemState::SKIPPED,
            default => InvoiceItemState::PLANNED,
        };
    }

    private function fromClinicalStatus(?string $status): InvoiceItemState
    {
        return match ($status) {
            'planned', 'on_hold' => InvoiceItemState::PLANNED,
            'in_progress' => InvoiceItemState::IN_PROGRESS,
            'completed', 'failed' => InvoiceItemState::DONE,
            'cancelled' => InvoiceItemState::SKIPPED,
            default => InvoiceItemState::PLANNED,
        };
    }

    private function isManual(InvoiceItemFacts $facts): bool
    {
        return ! $this->present($facts->patientTreatmentToothId)
            && ! $this->present($facts->appointmentId)
            && ! $this->present($facts->patientTreatmentId);
    }

    private function present(?string $id): bool
    {
        return $id !== null && $id !== '';
    }
}
