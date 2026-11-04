<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirEmployee;
use App\Models\ToirMaintenancePlan;
use App\Models\ToirWorkOrder;
use App\Models\ToirWorkOrderEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ToirWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $code = 'mining'): array
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => $code, 'code' => $code]);
        $company->users()->attach($user->id, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($user)->withSession(['toir_company_id' => $company->id]);

        return [$company, $user];
    }

    private function member(Company $company, string $role): User
    {
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => $role, 'is_active' => true]);

        return $user;
    }

    private function asUser(User $user, Company $company): void
    {
        $this->actingAs($user)->withSession(['toir_company_id' => $company->id]);
    }

    private function asset(Company $company, string $code = 'EQ-1', ?int $parent = null): ToirAsset
    {
        return ToirAsset::forceCreate([
            'company_id' => $company->id, 'name' => $code, 'code' => $code,
            'category' => 'equipment', 'parent_id' => $parent,
        ]);
    }

    private function employee(Company $company, ?User $user = null): ToirEmployee
    {
        return ToirEmployee::forceCreate([
            'company_id' => $company->id, 'name' => 'Technician',
            'status' => 'active', 'user_id' => $user?->id,
        ]);
    }

    private function order(Company $company, ToirAsset $asset, ToirEmployee $employee, User $owner): ToirWorkOrder
    {
        return ToirWorkOrder::forceCreate([
            'company_id' => $company->id, 'title' => 'Inspect valve',
            'number' => 'НЗ-TEST-1', 'status' => 'waiting',
            'asset_id' => $asset->id, 'employee_id' => $employee->id, 'initiator_id' => $owner->id,
        ]);
    }

    public function test_manual_work_order_follows_start_complete_reopen_with_audit_trail(): void
    {
        [$company, $owner] = $this->owner();
        $asset = $this->asset($company);
        $tech = $this->employee($company);
        $order = $this->order($company, $asset, $tech, $owner);
        $url = '/api/v1/work-orders/'.$order->id.'/transition';

        $this->postJson($url, ['action' => 'complete', 'comment' => 'Done'])->assertStatus(409);
        $this->patchJson('/api/v1/resources/work-orders/'.$order->id, [
            'status' => 'closed',
        ])->assertUnprocessable();
        $this->patchJson('/api/v1/resources/work-orders/'.$order->id, [
            'actual_end' => now()->toDateTimeString(),
        ])->assertUnprocessable();

        $this->postJson($url, ['action' => 'start'])->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $order->refresh();
        $this->assertNotNull($order->actual_start);
        $this->assertNull($order->actual_end);
        $this->postJson($url, ['action' => 'start'])->assertStatus(409);
        $this->postJson($url, ['action' => 'complete'])->assertUnprocessable();

        $this->postJson($url, ['action' => 'complete', 'comment' => 'Valve tested and sealed.'])
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $order->refresh();
        $this->assertNotNull($order->actual_end);
        $this->patchJson('/api/v1/resources/work-orders/'.$order->id, [
            'title' => 'Unauthorized edit after closure',
        ])->assertStatus(409);
        $this->postJson($url, ['action' => 'reopen'])->assertUnprocessable();
        $this->postJson($url, ['action' => 'reopen', 'comment' => 'Retest after defect'])
            ->assertOk()->assertJsonPath('data.status', 'waiting');
        $order->refresh();
        $this->assertNull($order->actual_start);
        $this->assertNull($order->actual_end);
        $this->assertSame(
            ['start', 'complete', 'reopen'],
            ToirWorkOrderEvent::where('work_order_id', $order->id)->reorder('id')->pluck('action')->all()
        );
        $this->assertSame('Retest after defect', $order->events()->latest('id')->first()->message);
        $this->getJson('/api/v1/resources/work-orders/'.$order->id)
            ->assertJsonCount(3, 'data.events');
    }

    public function test_start_requires_assigned_employee_and_asset(): void
    {
        [$company] = $this->owner();
        $order = $this->postJson('/api/v1/resources/work-orders', [
            'title' => 'Missing assignment',
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/work-orders/'.$order['id'].'/transition', [
            'action' => 'start',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_id', 'employee_id']);
        $this->postJson('/api/v1/resources/work-orders', [
            'title' => 'Preclosed work', 'status' => 'closed',
        ])->assertUnprocessable();
    }

    public function test_work_cannot_start_with_retired_asset_or_inactive_employee(): void
    {
        [$company,$owner] = $this->owner();
        $asset = $this->asset($company);
        $employee = $this->employee($company);
        $order = $this->order($company, $asset, $employee, $owner);
        $url = '/api/v1/work-orders/'.$order->id.'/transition';

        $asset->status = 'retired';
        $asset->save();
        $this->postJson($url, ['action' => 'start'])
            ->assertUnprocessable()->assertJsonValidationErrors('asset_id');

        $asset->status = 'active';
        $asset->save();
        $employee->status = 'inactive';
        $employee->save();
        $this->postJson($url, ['action' => 'start'])
            ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->assertSame('waiting', $order->fresh()->status);
    }

    public function test_technician_can_execute_only_own_assigned_work_but_cannot_reopen(): void
    {
        [$company, $owner] = $this->owner();
        $asset = $this->asset($company);
        $assigned = $this->member($company, 'technician');
        $other = $this->member($company, 'technician');
        $tech = $this->employee($company, $assigned);
        $order = $this->order($company, $asset, $tech, $owner);
        $url = '/api/v1/work-orders/'.$order->id.'/transition';

        $this->asUser($other, $company);
        $this->postJson($url, ['action' => 'start'])->assertForbidden();
        $this->asUser($assigned, $company);
        $this->patchJson('/api/v1/resources/work-orders/'.$order->id, [
            'title' => 'No write',
        ])->assertForbidden();
        $this->postJson($url, ['action' => 'start'])->assertOk();
        $this->postJson($url, ['action' => 'complete', 'comment' => 'Fixed'])->assertOk();
        $this->postJson($url, ['action' => 'reopen', 'comment' => 'Again'])->assertForbidden();
        $this->asUser($owner, $company);
        $this->getJson('/api/v1/resources/work-orders/'.$order->id)
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_plan_issue_is_idempotent_and_close_advances_exactly_one_cycle(): void
    {
        [$company] = $this->owner();
        $asset = $this->asset($company);
        $employee = $this->employee($company);
        $plan = ToirMaintenancePlan::forceCreate([
            'company_id' => $company->id, 'asset_id' => $asset->id, 'employee_id' => $employee->id,
            'title' => 'Monthly checks', 'status' => 'planned',
            'next_due_date' => '2026-11-15', 'periodicity_days' => 30,
        ]);

        $url = '/api/v1/plans/'.$plan->id.'/issue';
        $issued = $this->postJson($url)->assertCreated()
            ->assertJsonPath('data.scheduled_for', '2026-11-15')
            ->assertJsonPath('data.maintenance_plan_id', $plan->id)
            ->assertJsonPath('created', true)->json('data');
        $this->postJson($url)->assertOk()
            ->assertJsonPath('data.id', $issued['id'])->assertJsonPath('created', false);
        $this->assertSame(1, ToirWorkOrder::where('maintenance_plan_id', $plan->id)->count());
        $this->assertSame('2026-11-15', $plan->fresh()->next_due_date);

        $this->patchJson('/api/v1/resources/plans/'.$plan->id, [
            'periodicity_days' => 5,
        ])->assertStatus(409);

        $transition = '/api/v1/work-orders/'.$issued['id'].'/transition';
        $this->postJson($transition, ['action' => 'start'])->assertOk();
        $this->postJson($transition, ['action' => 'complete', 'comment' => 'Inspected'])->assertOk();
        $this->assertSame('2026-12-15', $plan->fresh()->next_due_date);
        $this->postJson($transition, ['action' => 'reopen', 'comment' => 'Reinspect'])->assertStatus(409);

        $next = $this->postJson($url)->assertCreated()
            ->assertJsonPath('data.scheduled_for', '2026-12-15')->json('data');
        $this->assertNotEquals($issued['id'], $next['id']);
        $this->postJson($url)->assertOk()->assertJsonPath('data.id', $next['id']);
        $this->assertSame(2, ToirWorkOrder::where('maintenance_plan_id', $plan->id)->count());
        $this->deleteJson('/api/v1/resources/plans/'.$plan->id)->assertStatus(409);
    }

    public function test_plans_are_protected_from_other_tenants_and_paused_states(): void
    {
        [$first] = $this->owner('mining');
        $asset = $this->asset($first);
        $plan = ToirMaintenancePlan::forceCreate([
            'company_id' => $first->id, 'asset_id' => $asset->id, 'title' => 'PM',
            'status' => 'paused', 'next_due_date' => '2026-11-15', 'periodicity_days' => 90,
        ]);
        $this->postJson('/api/v1/plans/'.$plan->id.'/issue')->assertStatus(409);
        $this->owner('solutions');
        $this->postJson('/api/v1/plans/'.$plan->id.'/issue')->assertNotFound();
    }

    public function test_technician_and_viewer_cannot_issue_plan(): void
    {
        [$company,$owner] = $this->owner();
        $asset = $this->asset($company);
        $plan = ToirMaintenancePlan::forceCreate([
            'company_id' => $company->id, 'asset_id' => $asset->id, 'title' => 'PM',
            'status' => 'active', 'next_due_date' => '2026-11-15', 'periodicity_days' => 30,
        ]);
        $viewer = $this->member($company, 'viewer');
        $this->asUser($viewer, $company);
        $this->postJson('/api/v1/plans/'.$plan->id.'/issue')->assertForbidden();
        $this->asUser($owner, $company);
        $this->postJson('/api/v1/plans/'.$plan->id.'/issue')->assertCreated();
    }

    public function test_overdue_command_marks_pending_only_once_with_system_audit(): void
    {
        [$company,$owner] = $this->owner();
        $asset = $this->asset($company);
        $employee = $this->employee($company);
        $pending = $this->order($company, $asset, $employee, $owner);
        $pending->update([
            'planned_start' => now()->subDays(3),
            'planned_end' => now()->subDays(2),
        ]);
        $running = ToirWorkOrder::forceCreate([
            'company_id' => $company->id, 'title' => 'Already running',
            'number' => 'НЗ-TEST-RUN', 'status' => 'in_progress',
            'asset_id' => $asset->id, 'employee_id' => $employee->id,
            'initiator_id' => $owner->id,
            'planned_end' => now()->subDay(),
            'actual_start' => now()->subDays(2),
        ]);
        $upcoming = ToirWorkOrder::forceCreate([
            'company_id' => $company->id, 'title' => 'Future',
            'number' => 'НЗ-TEST-FUT', 'status' => 'waiting',
            'planned_end' => now()->addDays(2),
        ]);

        $this->artisan('toir:sync-overdue', ['--dry-run' => true])
            ->expectsOutput('DRY_RUN TOIR_OVERDUE_CHANGED=1')->assertExitCode(0);
        $this->assertSame('waiting', $pending->fresh()->status);

        $this->artisan('toir:sync-overdue')
            ->expectsOutput('TOIR_OVERDUE_CHANGED=1')->assertExitCode(0);
        $this->artisan('toir:sync-overdue')
            ->expectsOutput('TOIR_OVERDUE_CHANGED=0')->assertExitCode(0);

        $this->assertSame('overdue', $pending->fresh()->status);
        $this->assertSame('in_progress', $running->fresh()->status);
        $this->assertSame('waiting', $upcoming->fresh()->status);
        $this->assertSame(1, $pending->events()->where('action', 'overdue')->count());
        $this->assertNull($pending->events()->where('action', 'overdue')->first()->user_id);
    }

    public function test_partial_update_cannot_invert_work_order_schedule(): void
    {
        [$company,$owner] = $this->owner();
        $asset = $this->asset($company);
        $employee = $this->employee($company);
        $order = $this->order($company, $asset, $employee, $owner);
        $order->update([
            'planned_start' => '2026-12-20 09:00:00',
            'planned_end' => '2026-12-20 17:00:00',
        ]);

        $this->patchJson('/api/v1/resources/work-orders/'.$order->id, [
            'planned_end' => '2026-12-19 17:00:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('planned_end');
        $this->patchJson('/api/v1/resources/work-orders/'.$order->id, [
            'planned_start' => '2026-12-21 09:00:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('planned_end');
        $this->assertSame('2026-12-20 09:00:00', $order->fresh()->planned_start);
    }

    public function test_asset_hierarchy_does_not_allow_cycles(): void
    {
        [$company] = $this->owner();
        $root = $this->asset($company, 'ROOT');
        $child = $this->asset($company, 'CHILD', $root->id);
        $grandchild = $this->asset($company, 'GRANDCHILD', $child->id);

        $this->patchJson('/api/v1/resources/assets/'.$root->id, [
            'parent_id' => $grandchild->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson('/api/v1/resources/assets/'.$child->id,[
            'parent_id' => $child->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->deleteJson('/api/v1/resources/assets/'.$root->id)->assertStatus(409);
        $this->assertNull($root->fresh()->parent_id);
    }
}
