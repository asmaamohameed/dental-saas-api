<?php

namespace Tests\Feature\Clinic;

use App\Models\Clinic;
use App\Models\ClinicMember;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicMembershipRoleTest extends TestCase
{
    public function test_a_clinic_can_have_several_owners_who_are_also_doctors(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $clinic = Clinic::query()->findOrFail($tenant->id);
        $doctorRoleId = Role::query()->where('name', 'doctor')->value('id');
        $owners = User::factory()->owner()->count(4)->create();

        foreach ($owners as $owner) {
            $membership = $owner->membershipIn($clinic);
            $this->assertNotNull($membership);
            $membership->roles()->syncWithoutDetaching([$doctorRoleId]);
            $owner->unsetRelation('clinicMembers');

            $this->assertTrue($owner->isOwner());
            $this->assertTrue($owner->isDoctor());
            $this->assertTrue($owner->hasRoleIn($clinic, 'owner'));
            $this->assertTrue($owner->hasRoleIn($clinic, 'doctor'));
        }

        $this->assertCount(4, $clinic->owners()->get());
    }

    public function test_one_user_can_hold_different_roles_in_two_clinics(): void
    {
        $clinicATenant = Tenant::factory()->create();
        $clinicBTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($clinicATenant->id);

        $user = User::factory()->owner()->create();
        $clinicA = Clinic::query()->findOrFail($clinicATenant->id);
        $clinicB = Clinic::query()->findOrFail($clinicBTenant->id);

        $membership = ClinicMember::query()->create([
            'clinic_id' => $clinicB->id,
            'user_id' => $user->id,
        ]);
        $membership->roles()->attach(Role::query()->where('name', 'doctor')->value('id'));

        $user->unsetRelation('clinicMembers');

        $this->assertTrue($user->hasRoleIn($clinicA, 'owner'));
        $this->assertFalse($user->hasRoleIn($clinicA, 'doctor'));
        $this->assertTrue($user->hasRoleIn($clinicB, 'doctor'));
        $this->assertFalse($user->hasRoleIn($clinicB, 'owner'));
        $this->assertTrue($user->isOwner());
        $this->assertFalse($user->isDoctor());
    }

    public function test_an_owner_cannot_edit_another_owner_in_the_same_clinic(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $actor = User::factory()->owner()->create(['name' => 'Acting Owner']);
        $other = User::factory()->owner()->create(['name' => 'Other Owner']);
        $doctor = User::factory()->doctor()->create();
        $clinic = Clinic::query()->findOrFail($tenant->id);

        $actorMembership = $actor->membershipIn($clinic);
        $otherMembership = $other->membershipIn($clinic);

        $this->assertNotNull($actorMembership);
        $this->assertNotNull($otherMembership);
        $this->assertFalse($actorMembership->canManage($otherMembership));
        $this->assertTrue($actorMembership->canManage($actorMembership));

        Sanctum::actingAs($actor);

        $this->putJson("/api/v1/staff/{$other->id}", [
            'name' => 'Hijacked',
        ])->assertForbidden();

        $this->patchJson("/api/v1/staff/{$other->id}/toggle-active")->assertForbidden();

        $this->assertSame('Other Owner', $other->fresh()->name);

        $this->putJson("/api/v1/staff/{$doctor->id}", [
            'name' => 'Updated Doctor',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Doctor');
    }
}
