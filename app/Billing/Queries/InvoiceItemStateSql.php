<?php

namespace App\Billing\Queries;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL 10 CASE twin of InvoiceItemStateResolver.
 * Aliases: ii invoice_items, tooth patient_treatment_teeth,
 * treatment patient_treatments, appointment appointments.
 */
final class InvoiceItemStateSql
{
    public function stateSql(): string
    {
        return <<<'SQL'
CASE
    WHEN ii.deleted_at IS NOT NULL THEN 'deleted'
    WHEN ii.voided_at IS NOT NULL THEN 'voided'
    WHEN ii.patient_treatment_tooth_id IS NOT NULL THEN
        CASE tooth.status
            WHEN 'planned' THEN 'planned'
            WHEN 'in_progress' THEN 'in_progress'
            WHEN 'done' THEN 'done'
            WHEN 'skipped' THEN 'skipped'
            ELSE 'planned'
        END
    WHEN ii.appointment_id IS NOT NULL THEN
        CASE
            WHEN appointment.status = 'completed' THEN 'done'
            WHEN appointment.status IN ('cancelled', 'no_show') AND appointment.completed_at IS NOT NULL THEN 'done'
            ELSE 'planned'
        END
    WHEN ii.patient_treatment_id IS NOT NULL THEN
        CASE treatment.clinical_status
            WHEN 'planned' THEN 'planned'
            WHEN 'on_hold' THEN 'planned'
            WHEN 'in_progress' THEN 'in_progress'
            WHEN 'completed' THEN 'done'
            WHEN 'failed' THEN 'done'
            WHEN 'cancelled' THEN 'skipped'
            ELSE 'planned'
        END
    ELSE 'planned'
END
SQL;
    }

    public function countsAsPerformedSql(): string
    {
        $state = $this->stateSql();

        return <<<SQL
CASE
    WHEN ii.deleted_at IS NOT NULL OR ii.voided_at IS NOT NULL THEN 0
    WHEN ii.patient_treatment_tooth_id IS NULL
        AND ii.appointment_id IS NULL
        AND ii.patient_treatment_id IS NULL THEN 1
    WHEN ({$state}) = 'done' THEN 1
    ELSE 0
END
SQL;
    }

    public function fromSql(): string
    {
        return <<<'SQL'
FROM invoice_items ii
LEFT JOIN patient_treatment_teeth tooth ON tooth.id = ii.patient_treatment_tooth_id
LEFT JOIN patient_treatments treatment ON treatment.id = ii.patient_treatment_id
LEFT JOIN appointments appointment ON appointment.id = ii.appointment_id
SQL;
    }

    /**
     * Single billable predicate. RecomputeInvoiceTotal and later dashboard SQL must use this,
     * not a copied WHERE.
     */
    public function billableSql(): string
    {
        return 'ii.deleted_at IS NULL AND ii.voided_at IS NULL AND ('.$this->stateSql().") <> 'skipped'";
    }

    /**
     * Billable total excludes deleted, voided, and skipped lines.
     * Performed total is lines where countsAsPerformed is true (done, or a live manual line).
     *
     * @return array{total: string, performed_total: string}
     */
    public function totalsForInvoice(string $invoiceId): array
    {
        $billable = $this->billableSql();
        $performed = '('.$this->countsAsPerformedSql().') = 1';

        $query = 'SELECT COALESCE(SUM(CASE WHEN '.$billable.' THEN (ii.price * ii.quantity) ELSE 0 END), 0) AS total, '
            .'COALESCE(SUM(CASE WHEN '.$performed.' THEN (ii.price * ii.quantity) ELSE 0 END), 0) AS performed_total '
            .$this->fromSql()
            .' WHERE ii.invoice_id = ?';

        $record = DB::selectOne($query, [$invoiceId]);

        if (! is_object($record)) {
            return ['total' => '0', 'performed_total' => '0'];
        }

        $values = get_object_vars($record);

        return [
            'total' => $this->numericString($values['total'] ?? null),
            'performed_total' => $this->numericString($values['performed_total'] ?? null),
        ];
    }

    private function numericString(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        return '0';
    }
}
