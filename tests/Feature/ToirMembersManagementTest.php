<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use App\Notifications\ToirCompanyAccessNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Tests\TestCase;

final class ToirMembersManagementTest extends TestCase
{
    use RefreshDatabase;

    private function setupCompany(): array
    {
        $company=Company::create(['name'=>'QA Company','code'=>'qa-company']);
        $owner=User::factory()->create();
        $admin=User::factory()->create();
        $company->users()->attach($owner->id,['role'=>'owner','is_active'=>true]);
        $company->users()->attach($admin->id,['role'=>'admin','is_active'=>true]);
        return compact('company','owner','admin');
    }

    private function login(User $user,Company $company): void
    {
        $this->actingAs($user)->withSession(['toir_company_id'=>$company->id]);
    }

    public function test_admin_invites_user_by_email_and_can_change_or_suspend_access(): void
    {
        Notification::fake();
        extract($this->setupCompany());
        $this->login($admin,$company);
        $this->getJson('/api/v1/company/members/mail-status')->assertOk()
            ->assertJsonPath('data.delivery_enabled',true);
        $payload=['name'=>'Service technician','email'=>'tech@example.test','role'=>'technician'];
        $this->postJson('/api/v1/company/members',$payload)->assertCreated()
            ->assertJsonPath('data.email','tech@example.test')
            ->assertJsonPath('data.email_sent',true);
        $user=User::where('email',$payload['email'])->firstOrFail();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user,ResetPassword::class);
        $this->getJson('/api/v1/company/members')->assertOk()
            ->assertJsonFragment(['email'=>'tech@example.test','role'=>'technician']);
        $this->patchJson('/api/v1/company/members/'.$user->id,[
            'role'=>'engineer','membership_active'=>true,
        ])->assertOk()->assertJsonPath('data.role','engineer');
        $this->postJson('/api/v1/company/members/'.$user->id.'/resend',[])
            ->assertStatus(429);
        Notification::assertSentToTimes($user,ResetPassword::class,1);
        $this->patchJson('/api/v1/company/members/'.$user->id,[
            'role'=>'viewer','membership_active'=>false,
        ])->assertOk()->assertJsonPath('data.membership_active',false);
        $this->assertFalse((bool)$company->users()->where('users.id',$user->id)->firstOrFail()->pivot->is_active);
    }

    public function test_last_owner_is_protected_and_admin_cannot_grant_owner_or_mutate_other_company(): void
    {
        extract($this->setupCompany());
        $this->login($owner,$company);
        $this->patchJson('/api/v1/company/members/'.$owner->id,[
            'role'=>'admin','membership_active'=>true,
        ])->assertUnprocessable();
        $this->patchJson('/api/v1/company/members/'.$owner->id,[
            'role'=>'owner','membership_active'=>false,
        ])->assertUnprocessable();
        $this->login($admin,$company);
        $this->patchJson('/api/v1/company/members/'.$owner->id,[
            'role'=>'viewer','membership_active'=>true,
        ])->assertForbidden();
        $this->postJson('/api/v1/company/members',[
            'name'=>'Other owner','email'=>'owner-two@example.test','role'=>'owner',
        ])->assertForbidden();
        $otherCompany=Company::create(['name'=>'Other','code'=>'other']);
        $foreignUser=User::factory()->create();
        $otherCompany->users()->attach($foreignUser->id,['role'=>'viewer','is_active'=>true]);
        $this->patchJson('/api/v1/company/members/'.$foreignUser->id,[
            'role'=>'manager','membership_active'=>true,
        ])->assertNotFound();
    }

    public function test_viewer_and_technician_cannot_manage_members_and_verified_user_has_no_activation_reset(): void
    {
        extract($this->setupCompany());
        $viewer=User::factory()->create();
        $company->users()->attach($viewer->id,['role'=>'viewer','is_active'=>true]);
        $this->login($viewer,$company);
        $this->getJson('/api/v1/company/members')->assertForbidden();
        $this->getJson('/api/v1/company/members/mail-status')->assertForbidden();
        $this->postJson('/api/v1/company/members',[
            'name'=>'Unapproved','email'=>'unapproved@example.test','role'=>'viewer',
        ])->assertForbidden();
        Notification::fake();
        $verified=User::factory()->create(['email'=>'existing@example.test','email_verified_at'=>now()]);
        $this->login($admin,$company);
        $this->postJson('/api/v1/company/members',[
            'name'=>'Existing user','email'=>$verified->email,'role'=>'viewer',
        ])->assertCreated()->assertJsonPath('data.email_sent',false);
        $this->assertDatabaseHas('company_user',['company_id'=>$company->id,'user_id'=>$verified->id,'role'=>'viewer']);
        Notification::assertSentTo($verified,ToirCompanyAccessNotification::class);
    }
}
