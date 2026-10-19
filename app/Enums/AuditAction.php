<?php

namespace App\Enums;

enum AuditAction: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case DELETED = 'deleted';
    case RESTORED = 'restored';
    case TREATMENT_TOOTH_PRICE_CHANGED = 'treatment_tooth_price_changed';
    case TREATMENT_TEMPLATE_DEFAULT_PRICE_CHANGED = 'treatment_template_default_price_changed';
    case CLINIC_WORKING_HOURS_CHANGED = 'clinic_working_hours_changed';
    case CLINIC_ROLE_PERMISSIONS_CHANGED = 'clinic_role_permissions_changed';
    case STAFF_ROLE_CHANGED = 'staff_role_changed';
    case STAFF_ACTIVE_STATUS_CHANGED = 'staff_active_status_changed';
    case INVOICE_ITEM_VOIDED = 'invoice_item_voided';
    case INVOICE_ITEM_DONE_REPRICED = 'invoice_item_done_repriced';
    case INVOICE_ITEM_PLANNED_DELETED = 'invoice_item_planned_deleted';
}
