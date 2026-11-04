<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirCrew;
use App\Models\ToirEmployee;
use App\Models\ToirWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ToirRoleMatrixAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_six_roles_enforce_dispatch_and_independent_acceptance(): void
    {
        $company = Company::create(['name' => 'Permissions QA', 'code' => 'permissions-qa']);
        $people = [];
        foreach (['owner', 'admin', 'manager', 'engineer', 'technician', 'viewer'] as $role) {
            $user = User::factory()->create();
            $company->users()->attach($user->id, ['role' => $role, 'is_active' => true]);
            $people[$role] = $user;
        }
        $asset = ToirAsset::forceCreate(['company_id' => $company->id, 'code' => 'A1', 'name' => 'Main Pump', 'category' => 'equipment']);
        $tech = ToirEmployee::forceCreate(['company_id' => $company->id, 'name' => 'Technician', 'status' => 'active',
            'user_id' => $people['technician']->id]);
        $order = ToirWorkOrder::forceCreate(['company_id' => $company->id, 'asset_id' => $asset->id,
            'title' => 'Fault', 'number' => 'WO-1', 'type' => 'emergency', 'status' => 'waiting']);
        foreach ($people as $role => $user) {
            $this->actingAs($user)->withSession(['toir_company_id' => $company->id]);
            $response = $this->postJson('/api/v1/work-orders/'.$order->id.'/dispatch', ['employee_id' => $tech->id]);
            if (in_array($role, ['owner', 'admin', 'manager', 'engineer'], true)) {
                $response->assertOk()->assertJsonPath('data.employee_id', $tech->id);
            } else {
                $response->assertForbidden();
            }
        }
        $this->assertSame($tech->id, $order->fresh()->employee_id);

        foreach ($people as $role => $user) {
            $order->refresh();
            $order->status = 'awaiting_acceptance';
            $order->actual_start = now()->subHour();
            $order->actual_end = now();
            $order->save();
            $this->actingAs($user)->withSession(['toir_company_id' => $company->id]);
            $response = $this->postJson('/api/v1/work-orders/'.$order->id.'/review', [
                'decision' => 'accept', 'comment' => 'Verified independently',
            ]);
            if (in_array($role, ['owner', 'admin', 'manager'], true)) {
                $response->assertOk()->assertJsonPath('data.status', 'closed');
            } else {
                $response->assertForbidden();
            }
        }
    }

    public function test_manager_belonging_to_executing_crew_cannot_accept_own_team_work(): void
    {
        $company = Company::create(['name' => 'Crew QA', 'code' => 'crew-qa']);
        $manager = User::factory()->create();
        $inspector = User::factory()->create();
        $company->users()->attach($manager->id, ['role' => 'manager', 'is_active' => true]);
        $company->users()->attach($inspector->id, ['role' => 'admin', 'is_active' => true]);
        $member = ToirEmployee::forceCreate(['company_id' => $company->id, 'name' => 'Manager on crew',
            'status' => 'active', 'user_id' => $manager->id]);
        $crew = ToirCrew::forceCreate(['company_id' => $company->id, 'name' => 'Repair team', 'code' => 'TEAM']);
        $crew->members()->attach($member->id, ['company_id' => $company->id, 'role' => 'member']);
        $order = ToirWorkOrder::forceCreate(['company_id' => $company->id, 'title' => 'Bearing replacement',
            'number' => 'WO-2', 'type' => 'emergency', 'status' => 'awaiting_acceptance', 'crew_id' => $crew->id]);

        $url = '/api/v1/work-orders/'.$order->id.'/review';
        $payload = ['decision' => 'accept', 'comment' => 'Pass inspection'];
        $this->actingAs($manager)->withSession(['toir_company_id' => $company->id]);
        $this->postJson($url, $payload)->assertForbidden();
        $this->assertSame('awaiting_acceptance', $order->fresh()->status);
        $this->actingAs($inspector)->withSession(['toir_company_id' => $company->id]);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.status', 'closed');
    }
}
