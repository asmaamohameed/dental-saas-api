<?php

namespace Tests\Feature\Admin;

use App\Enums\SubscriptionStatus;
use App\Models\AdminUser;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create();
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_admin_can_create_a_subscription_for_a_tenant(): void
    {
        $this->actingAsAdmin();
        $tenant = Tenant::factory()->create();

        $this->postJson('/admin/subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_type' => 'monthly',
            'status' => 'trial',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.tenant_id', $tenant->id);

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_type' => 'monthly',
        ]);
    }

    /**
     * Regression test: قبل إصلاح Subscription model، الـ tenant_id اللي
     * بيبعته الـ admin كان بيتجاهل ويتستبدل بـ CurrentTenant (بسبب
     * BelongsToTenant). بعد الإصلاح لازم يتحفظ بالظبط اللي اتبعت.
     */
    public function test_subscription_is_saved_under_the_exact_tenant_id_sent_by_admin(): void
    {
        $this->actingAsAdmin();
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $this->postJson('/admin/subscriptions', [
            'tenant_id' => $tenantB->id,
            'plan_type' => 'yearly',
            'status' => 'active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ])->assertCreated();

        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $tenantB->id]);
        $this->assertDatabaseMissing('subscriptions', ['tenant_id' => $tenantA->id]);
    }

    /**
     * Regression test: قبل الإصلاح، أي GET على /admin/subscriptions كان
     * بيرمي RuntimeException (500) لعدم وجود CurrentTenant في سياق الـ
     * admin routes خالص.
     */
    public function test_listing_subscriptions_does_not_crash_and_can_filter_by_tenant(): void
    {
        $this->actingAsAdmin();
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Subscription::factory()->create(['tenant_id' => $tenantA->id]);
        Subscription::factory()->create(['tenant_id' => $tenantB->id]);

        $this->getJson('/admin/subscriptions')->assertOk();

        $filtered = $this->getJson("/admin/subscriptions?tenant_id={$tenantA->id}");
        $filtered->assertOk();

        $body = json_encode($filtered->json());
        $this->assertStringContainsString($tenantA->id, $body);
        $this->assertStringNotContainsString($tenantB->id, $body);
    }

    public function test_creating_a_subscription_with_an_invalid_status_returns_a_validation_error_not_a_crash(): void
    {
        $this->actingAsAdmin();
        $tenant = Tenant::factory()->create();

        $this->postJson('/admin/subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_type' => 'monthly',
            'status' => 'canceled',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_admin_can_update_a_subscription(): void
    {
        $this->actingAsAdmin();
        $subscription = Subscription::factory()->create(['status' => SubscriptionStatus::TRIAL]);

        $this->putJson("/admin/subscriptions/{$subscription->id}", [
            'status' => 'active',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
    }

    public function test_mark_as_paid_sets_marked_paid_at_and_activates_subscription(): void
    {
        $this->actingAsAdmin();
        $subscription = Subscription::factory()->create([
            'status' => SubscriptionStatus::TRIAL,
            'marked_paid_at' => null,
        ]);

        $this->patchJson("/admin/subscriptions/{$subscription->id}/mark-paid")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertNotNull($subscription->marked_paid_at);
    }

    public function test_end_date_must_be_on_or_after_start_date(): void
    {
        $this->actingAsAdmin();
        $tenant = Tenant::factory()->create();

        $this->postJson('/admin/subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_type' => 'monthly',
            'status' => 'trial',
            'start_date' => now()->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['end_date']);
    }
}
