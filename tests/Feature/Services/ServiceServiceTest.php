<?php

namespace Tests\Feature\Services;

use App\Exceptions\ServiceProtectedException;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\ServiceService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private ServiceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $this->service = app(ServiceService::class);
    }

    public function test_updating_other_service_ignores_protected_fields_but_keeps_unprotected_changes(): void
    {
        $other = Service::factory()->other()->create([
            'name_ar' => 'أخرى',
            'name_en' => 'Other',
        ]);

        $updated = $this->service->update($other, [
            'name_ar' => 'اسم جديد',
            'name_en' => 'New Name',
            'is_other' => false,
            'is_active' => false,
        ]);

        $this->assertSame('أخرى', $updated->name_ar);
        $this->assertSame('Other', $updated->name_en);
        $this->assertTrue($updated->is_other);
        $this->assertFalse($updated->is_active);
    }

    public function test_updating_a_regular_service_applies_all_given_fields(): void
    {
        $svc = Service::factory()->create(['name_ar' => 'حشو']);

        $updated = $this->service->update($svc, [
            'name_ar' => 'حشو تجميلي',
            'default_price' => 300,
        ]);

        $this->assertSame('حشو تجميلي', $updated->name_ar);
        $this->assertSame('300.00', $updated->default_price);
    }

    public function test_deleting_other_service_throws_protected_exception(): void
    {
        $other = Service::factory()->other()->create();

        $this->expectException(ServiceProtectedException::class);

        $this->service->delete($other);
    }

    public function test_deleting_service_with_invoice_items_throws_protected_exception_and_keeps_it(): void
    {
        $svc = Service::factory()->create();
        InvoiceItem::factory()->create(['service_id' => $svc->id]);

        try {
            $this->service->delete($svc);
            $this->fail('Expected a ServiceProtectedException.');
        } catch (ServiceProtectedException) {
        }

        $this->assertDatabaseHas('services', ['id' => $svc->id]);
    }

    public function test_deleting_a_regular_unused_service_succeeds(): void
    {
        $svc = Service::factory()->create();

        $this->assertTrue($this->service->delete($svc));
        $this->assertDatabaseMissing('services', ['id' => $svc->id]);
    }

    public function test_toggle_active_on_other_service_throws_protected_exception(): void
    {
        $other = Service::factory()->other()->create(['is_active' => true]);

        $this->expectException(ServiceProtectedException::class);

        $this->service->toggleActive($other);
    }

    public function test_toggle_active_on_regular_service_flips_the_flag(): void
    {
        $svc = Service::factory()->create(['is_active' => true]);

        $updated = $this->service->toggleActive($svc);
        $this->assertFalse($updated->is_active);

        $updated = $this->service->toggleActive($svc->fresh());
        $this->assertTrue($updated->is_active);
    }

    public function test_list_filters_by_is_active_and_search_term(): void
    {
        Service::factory()->create(['name_ar' => 'تنظيف أسنان', 'is_active' => true]);
        Service::factory()->create(['name_ar' => 'تبييض أسنان', 'is_active' => false]);
        Service::factory()->create(['name_ar' => 'حشو عصب', 'is_active' => true]);

        $result = $this->service->list(['is_active' => '1', 'search' => 'أسنان']);

        $this->assertCount(1, $result->items());
        $this->assertSame('تنظيف أسنان', $result->items()[0]->name_ar);
    }
}
