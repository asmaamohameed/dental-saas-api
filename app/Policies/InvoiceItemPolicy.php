<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class InvoiceItemPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, ['create', 'update', 'delete'], true)) {
            return null;
        }

        if ($user->isOwner()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user, Invoice $invoice): bool
    {
        return $user->tenant_id === $invoice->tenant_id
            && $user->clinicCan('invoice:view', $user->hasAnyClinicRole([UserRole::DOCTOR, UserRole::RECEPTIONIST]));
    }

    public function create(User $user, Invoice $invoice): bool
    {
        if (in_array($invoice->status, [InvoiceStatus::PAID, InvoiceStatus::REFUNDED, InvoiceStatus::CANCELLED], true)) {
            return false;
        }

        if ($user->isOwner()) {
            return true;
        }

        return $user->tenant_id === $invoice->tenant_id
            && $user->clinicCan('invoice:edit', $user->hasAnyClinicRole([UserRole::DOCTOR, UserRole::ASSISTANT, UserRole::RECEPTIONIST]))
            && Gate::forUser($user)->allows('updateItems', $invoice);
    }

    public function update(User $user, InvoiceItem $invoiceItem): bool
    {
        if (in_array($invoiceItem->invoice->status, [InvoiceStatus::PAID, InvoiceStatus::REFUNDED, InvoiceStatus::CANCELLED], true)) {
            return false;
        }

        if ($user->isOwner()) {
            return true;
        }

        return $user->tenant_id === $invoiceItem->invoice->tenant_id
            && $user->clinicCan('invoice:edit', $user->hasAnyClinicRole([UserRole::DOCTOR, UserRole::ASSISTANT, UserRole::RECEPTIONIST]))
            && Gate::forUser($user)->allows('updateItems', $invoiceItem->invoice);
    }

    public function delete(User $user, InvoiceItem $invoiceItem): bool
    {
        if (in_array($invoiceItem->invoice->status, [InvoiceStatus::PAID, InvoiceStatus::REFUNDED, InvoiceStatus::CANCELLED], true)) {
            return false;
        }

        if ($user->isOwner()) {
            return true;
        }

        return $user->tenant_id === $invoiceItem->invoice->tenant_id
            && $user->clinicCan('invoice:edit', $user->hasAnyClinicRole([UserRole::DOCTOR, UserRole::ASSISTANT, UserRole::RECEPTIONIST]))
            && Gate::forUser($user)->allows('updateItems', $invoiceItem->invoice);
    }
}
