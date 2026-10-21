<?php

namespace Tests\Feature\Invoices;

use App\Enums\UserRole;
use Tests\TestCase;

class InvoiceFromTreatmentRouteTest extends TestCase
{
    public function test_post_invoices_from_treatment_returns_404(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);

        $this->postJson('/api/v1/invoices/from-treatment/11111111-1111-1111-1111-111111111111')
            ->assertNotFound();
    }
}
