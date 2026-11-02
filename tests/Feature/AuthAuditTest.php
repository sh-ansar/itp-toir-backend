<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthAuditTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $user = User::factory()->create([
            'email' => 'owner@toir.test',
            'password' => Hash::make('ExampleStrong!Pass123'),
        ]);
        $company = Company::create(['name' => 'ITP Mining', 'code' => 'mining']);
        $company->users()->attach($user->id, ['role' => 'owner', 'is_active' => true]);

        return [$user, $company];
    }

    public function test_login_switch_and_logout_are_audited_without_credentials(): void
    {
        [$user, $company] = $this->owner();
        $second = Company::create(['name' => 'ITP Solutions', 'code' => 'solutions']);
        $second->users()->attach($user->id, ['role' => 'viewer', 'is_active' => true]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'ExampleStrong!Pass123',
        ])->assertOk();
        $this->postJson('/api/v1/auth/companies/switch', [
            'company_id' => $second->id,
        ])->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertOk();

        $events = DB::table('toir_audit_events')->orderBy('id')->get();
        $this->assertSame(
            ['auth.login.success', 'auth.company.switched', 'auth.logout'],
            $events->pluck('action')->all()
        );
        $this->assertEquals($company->id, $events[0]->company_id);
        $this->assertEquals($second->id, $events[1]->company_id);
        $this->assertEquals($user->id, $events[2]->actor_id);
        $this->assertStringNotContainsString('ExampleStrong!', json_encode($events));
    }

    public function test_password_changes_and_resets_are_audited(): void
    {
        [$user, $company] = $this->owner();
        $this->actingAs($user);
        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'ExampleStrong!Pass123',
            'password' => 'NewStrong!Pass456',
            'password_confirmation' => 'NewStrong!Pass456',
        ])->assertOk();

        $this->assertDatabaseHas('toir_audit_events', [
            'action' => 'auth.password.changed',
            'actor_id' => $user->id,
            'company_id' => $company->id,
        ]);

        $token = Password::createToken($user);
        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ResetStrong!Pass789',
            'password_confirmation' => 'ResetStrong!Pass789',
        ])->assertOk();

        $this->assertDatabaseHas('toir_audit_events', [
            'action' => 'auth.password.reset',
            'actor_id' => $user->id,
        ]);
    }

    public function test_deactivated_user_cannot_reset_password(): void
    {
        [$user] = $this->owner();
        $token = Password::createToken($user);
        $user->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ResetStrong!Pass789',
            'password_confirmation' => 'ResetStrong!Pass789',
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check('ExampleStrong!Pass123', $user->fresh()->password));
    }
}
