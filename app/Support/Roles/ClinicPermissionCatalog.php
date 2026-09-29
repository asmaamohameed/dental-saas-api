<?php

namespace App\Support\Roles;

/**
 * Read-only description of the clinic permission matrix.
 *
 * Enforcement lives in route middleware and policies. This catalog is for
 * inspection in the platform admin panel and cannot grant or revoke access.
 */
class ClinicPermissionCatalog
{
    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'patient:view' => 'View patients',
            'patient:create' => 'Create patients',
            'patient:edit' => 'Edit patients',
            'patient:delete' => 'Delete patients',
            'patient:viewMedicalHistory' => 'View medical history',
            'appointment:view' => 'View appointments',
            'appointment:create' => 'Create appointments',
            'appointment:edit' => 'Edit appointments',
            'appointment:delete' => 'Delete appointments',
            'toothRecord:view' => 'View tooth records',
            'toothRecord:create' => 'Create tooth records',
            'treatment:view' => 'View treatments',
            'treatment:create' => 'Create treatments',
            'treatment:edit' => 'Edit treatments',
            'treatment:delete' => 'Delete treatments',
            'treatment:toggleActive' => 'Activate or deactivate treatments',
            'invoice:view' => 'View invoices',
            'invoice:create' => 'Create invoices',
            'invoice:edit' => 'Edit invoices',
            'invoice:delete' => 'Delete invoices',
            'payment:view' => 'View payments',
            'payment:create' => 'Record payments',
            'payment:edit' => 'Edit payments',
            'payment:delete' => 'Delete payments',
            'inventory:view' => 'View inventory',
            'inventory:create' => 'Create inventory items',
            'inventory:edit' => 'Edit inventory items',
            'inventory:delete' => 'Delete inventory items',
            'inventory:restore' => 'Restore inventory items',
            'inventory:transact' => 'Record stock movements',
            'staff:view' => 'View staff',
            'staff:create' => 'Create staff',
            'staff:edit' => 'Edit staff',
            'staff:toggleActive' => 'Activate or deactivate staff',
            'reports:view' => 'View reports',
            'auditLog:view' => 'View clinic audit logs',
            'clinic:branding' => 'Update clinic logo and theme',
        ];
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public static function matrix(): array
    {
        $owner = array_fill_keys(array_keys(self::labels()), true);

        $clinical = $owner;
        foreach ([
            'patient:delete',
            'treatment:delete',
            'invoice:delete',
            'payment:edit',
            'payment:delete',
            'inventory:create',
            'inventory:edit',
            'inventory:delete',
            'inventory:restore',
            'inventory:transact',
            'staff:view',
            'staff:create',
            'staff:edit',
            'staff:toggleActive',
            'reports:view',
            'auditLog:view',
            'clinic:branding',
        ] as $denied) {
            $clinical[$denied] = false;
        }

        $receptionist = $owner;
        foreach ([
            'patient:delete',
            'patient:viewMedicalHistory',
            'toothRecord:create',
            'treatment:delete',
            'invoice:delete',
            'payment:edit',
            'payment:delete',
            'inventory:create',
            'inventory:edit',
            'inventory:delete',
            'inventory:restore',
            'staff:view',
            'staff:create',
            'staff:edit',
            'staff:toggleActive',
            'reports:view',
            'auditLog:view',
            'clinic:branding',
        ] as $denied) {
            $receptionist[$denied] = false;
        }

        return [
            'owner' => $owner,
            'doctor' => $clinical,
            'assistant' => $clinical,
            'receptionist' => $receptionist,
        ];
    }
}
