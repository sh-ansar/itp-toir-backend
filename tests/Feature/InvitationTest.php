<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_cannot_sign_in(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'engineer@toir.test',
            'password' => Hash::make('StrongExample!Pass789'),
        ]);
        $company = Company::create(['name' => 'ITP Mining', 'code' => 'mining']);
        $company->users()->attach($user->id, ['role' => 'engineer', 'is_active' => true]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'engineer@toir.test',
            'password' => 'StrongExample!Pass789',
        ])->assertUnprocessable();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_private_one_use_password_link_activates_account(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'invitee@toir.test']);
        $company = Company::create(['name' => 'ITP Mining', 'code' => 'mining']);
        $company->users()->attach($user->id, ['role' => 'viewer', 'is_active' => true]);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ActivatedStrong!Pass789',
            'password_confirmation' => 'ActivatedStrong!Pass789',
        ])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'ActivatedStrong!Pass789',
        ])->assertOk()
            ->assertJsonPath('data.active_role', 'viewer');
    }

    public function test_admin_cli_invites_user_with_a_verification_link(): void
    {
        Notification::fake();
        $company = Company::create(['name' => 'ITP Mining', 'code' => 'mining']);

        $this->artisan('toir:invite-user', [
            '--email' => 'new.engineer@toir.test',
            '--name' => 'Example Engineer',
            '--company-code' => 'mining',
            '--role' => 'engineer',
        ])->assertExitCode(0);

        $user = User::where('email', 'new.engineer@toir.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->is_active);
        $this->assertSame(
            'engineer',
            $company->users()->whereKey($user->id)->firstOrFail()->pivot->role
        );
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $this->assertDatabaseHas('toir_audit_events', [
            'action' => 'auth.user.invited',
            'actor_id' => $user->id,
            'company_id' => $company->id,
        ]);
    }

    public function test_cli_rejects_invalid_role_without_creating_user(): void
    {
        Company::create(['name' => 'ITP Mining', 'code' => 'mining']);

        $this->artisan('toir:invite-user', [
            '--email' => 'invalid.role@toir.test',
            '--name' => 'Invalid Role',
            '--company-code' => 'mining',
            '--role' => 'superadmin',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'invalid.role@toir.test']);
    }
}
