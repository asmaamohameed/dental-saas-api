<?php

namespace App\Billing\Queries;

use App\Enums\InvoiceStatus;
use App\Support\Tenancy\CurrentTenant;
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

    /**
     * Sum max(0, performed_total - settled) over non-cancelled tenant invoices.
     * Performed lines use countsAsPerformedSql(); settled matches InvoiceBalance (net paid + deduct).
     */
    public function sumOutstandingPerformedAllTime(): float
    {
        $tenantId = app(CurrentTenant::class)->id();
        if ($tenantId === null || $tenantId === '') {
            throw new \RuntimeException('Cannot sum outstanding performed work without a tenant context.');
        }

        $performedPredicate = '('.$this->countsAsPerformedSql().') = 1';
        $cancelled = InvoiceStatus::CANCELLED->value;
        $refunded = InvoiceStatus::REFUNDED->value;

        $sql = 'SELECT COALESCE(SUM(GREATEST(0::numeric, '
            .'COALESCE(performed.performed_total, 0::numeric) - ('
            .'GREATEST(0::numeric, COALESCE(payments.gross_paid, 0::numeric) - COALESCE(refunds.refunded, 0::numeric))'
            .'+ COALESCE(payments.deducted, 0::numeric)'
            .'))), 0::numeric) AS outstanding '
            .'FROM invoices inv '
            .'LEFT JOIN ('
            .'SELECT ii.invoice_id, '
            .'COALESCE(SUM(CASE WHEN '.$performedPredicate.' THEN (ii.price * ii.quantity) ELSE 0 END), 0) AS performed_total '
            .$this->fromSql()
            .' INNER JOIN invoices inner_inv ON inner_inv.id = ii.invoice_id '
            .'AND inner_inv.tenant_id = ? AND inner_inv.deleted_at IS NULL '
            .'AND inner_inv.status NOT IN (?, ?) '
            .'GROUP BY ii.invoice_id'
            .') performed ON performed.invoice_id = inv.id '
            .'LEFT JOIN ('
            .'SELECT invoice_id, SUM(amount) AS gross_paid, SUM(deduct_amount) AS deducted '
            .'FROM payments GROUP BY invoice_id'
            .') payments ON payments.invoice_id = inv.id '
            .'LEFT JOIN ('
            .'SELECT invoice_id, SUM(amount) AS refunded FROM refunds GROUP BY invoice_id'
            .') refunds ON refunds.invoice_id = inv.id '
            .'WHERE inv.deleted_at IS NULL AND inv.status NOT IN (?, ?) AND inv.tenant_id = ?';

        $record = DB::selectOne($sql, [$tenantId, $cancelled, $refunded, $cancelled, $refunded, $tenantId]);

        if (! is_object($record)) {
            return 0.0;
        }

        $values = get_object_vars($record);

        return (float) $this->numericString($values['outstanding'] ?? null);
    }

    /**
     * Sum of invoice total_amount for open billing (excludes cancelled and fully refunded status).
     * Refunds on partial/unpaid invoices do not change total_amount; only status REFUNDED excludes the row.
     */
    public function sumTotalInvoicedAllTime(): float
    {
        $tenantId = app(CurrentTenant::class)->id();
        if ($tenantId === null || $tenantId === '') {
            throw new \RuntimeException('Cannot sum total invoiced without a tenant context.');
        }

        $cancelled = InvoiceStatus::CANCELLED->value;
        $refunded = InvoiceStatus::REFUNDED->value;

        $record = DB::table('invoices')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', [$cancelled, $refunded])
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total')
            ->first();

        if ($record === null) {
            return 0.0;
        }

        return (float) $this->numericString($record->total ?? null);
    }

    /**
     * Sum of remaining balance on non-cancelled, non-refunded invoices.
     * Matches InvoiceBalance: total - (max(0, gross_paid - refunded) + deducted).
     */
    public function sumOutstandingInvoiceBalanceAllTime(): float
    {
        $tenantId = app(CurrentTenant::class)->id();
        if ($tenantId === null || $tenantId === '') {
            throw new \RuntimeException('Cannot sum outstanding invoice balance without a tenant context.');
        }

        $cancelled = InvoiceStatus::CANCELLED->value;
        $refunded = InvoiceStatus::REFUNDED->value;

        $sql = 'SELECT COALESCE(SUM(GREATEST(0::numeric, inv.total_amount::numeric - ('
            .'GREATEST(0::numeric, COALESCE(payments.gross_paid, 0::numeric) - COALESCE(refunds.refunded, 0::numeric))'
            .'+ COALESCE(payments.deducted, 0::numeric)'
            .'))), 0::numeric) AS outstanding '
            .'FROM invoices inv '
            .'LEFT JOIN ('
            .'SELECT invoice_id, SUM(amount) AS gross_paid, SUM(deduct_amount) AS deducted '
            .'FROM payments GROUP BY invoice_id'
            .') payments ON payments.invoice_id = inv.id '
            .'LEFT JOIN ('
            .'SELECT invoice_id, SUM(amount) AS refunded FROM refunds GROUP BY invoice_id'
            .') refunds ON refunds.invoice_id = inv.id '
            .'WHERE inv.deleted_at IS NULL AND inv.status NOT IN (?, ?) AND inv.tenant_id = ?';

        $record = DB::selectOne($sql, [$cancelled, $refunded, $tenantId]);

        if (! is_object($record)) {
            return 0.0;
        }

        $values = get_object_vars($record);

        return (float) $this->numericString($values['outstanding'] ?? null);
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
