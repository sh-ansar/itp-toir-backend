<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ToirAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'owner'): array
    {
        $user = User::factory()->create([
            'email' => 'admin@toir.test',
            'password' => Hash::make('ValidStrong!Pass123'),
        ]);
        $company = Company::create([
            'code' => 'mining',
            'name' => 'ITP Mining',
            'is_active' => true,
        ]);
        $company->users()->attach($user->id, ['role' => $role, 'is_active' => true]);

        return [$user, $company];
    }

    public function test_health_endpoint_is_available_without_login(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('service', 'itp-toir-api');
    }

    public function test_protected_session_is_not_public(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/companies/switch', ['company_id' => 1])
            ->assertUnauthorized();
    }

    public function test_login_me_logout_are_real_session_operations(): void
    {
        [$user, $company] = $this->member();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ADMIN@TOIR.TEST',
            'password' => 'ValidStrong!Pass123',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.active_company_id', $company->id)
            ->assertJsonPath('data.active_role', 'owner');

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'admin@toir.test');

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_invalid_password_and_inactive_user_are_rejected(): void
    {
        [$user] = $this->member();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertUnprocessable();

        $user->update(['is_active' => false]);
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'ValidStrong!Pass123',
        ])->assertUnprocessable();
    }

    public function test_user_with_no_active_company_cannot_login(): void
    {
        [$user, $company] = $this->member();
        $company->users()->updateExistingPivot($user->id, ['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'ValidStrong!Pass123',
        ])->assertUnprocessable();
    }

    public function test_company_switch_requires_active_membership(): void
    {
        [$user, $primary] = $this->member();
        $other = Company::create(['code' => 'solutions', 'name' => 'ITP Solutions']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'ValidStrong!Pass123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/companies/switch', [
            'company_id' => $other->id,
        ])->assertForbidden();
        $this->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.active_company_id', $primary->id);

        $other->users()->attach($user->id, ['role' => 'technician', 'is_active' => true]);
        $this->postJson('/api/v1/auth/companies/switch', [
            'company_id' => $other->id,
        ])->assertOk()
            ->assertJsonPath('data.active_company_id', $other->id)
            ->assertJsonPath('data.active_role', 'technician');
    }

    public function test_changing_password_requires_old_password(): void
    {
        [$user] = $this->member();
        $this->actingAs($user);

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong',
            'password' => 'AnotherStrong!Pass456',
            'password_confirmation' => 'AnotherStrong!Pass456',
        ])->assertUnprocessable();

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'ValidStrong!Pass123',
            'password' => 'AnotherStrong!Pass456',
            'password_confirmation' => 'AnotherStrong!Pass456',
        ])->assertOk();
        $this->assertTrue(Hash::check('AnotherStrong!Pass456', $user->fresh()->password));
    }

    public function test_recovery_response_does_not_disclose_accounts(): void
    {
        Notification::fake();
        [$user] = $this->member();

        $known = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertOk();
        $unknown = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'unknown@toir.test',
        ])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_valid_reset_token_changes_password(): void
    {
        [$user] = $this->member();
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ResetStrong!Pass456',
            'password_confirmation' => 'ResetStrong!Pass456',
        ])->assertOk();

        $this->assertTrue(Hash::check('ResetStrong!Pass456', $user->fresh()->password));
    }
}
