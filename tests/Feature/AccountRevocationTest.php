<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivation_revokes_an_existing_session(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'ITP Mining', 'code' => 'mining']);
        $company->users()->attach($user->id, ['role' => 'engineer', 'is_active' => true]);
        $this->actingAs($user);
        $this->getJson('/api/v1/auth/me')->assertOk();

        $user->update(['is_active' => false]);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_losing_every_company_membership_revokes_session(): void
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => 'ITP Mining', 'code' => 'mining']);
        $company->users()->attach($user->id, ['role' => 'engineer', 'is_active' => true]);
        $this->actingAs($user);
        $this->getJson('/api/v1/auth/me')->assertOk();

        $company->users()->updateExistingPivot($user->id, ['is_active' => false]);
        $this->getJson('/api/v1/auth/me')->assertForbidden();
    }
}
