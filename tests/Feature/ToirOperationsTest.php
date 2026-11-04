<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirEmployee;
use App\Models\ToirMaintenancePlan;
use App\Models\ToirWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ToirOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $code = 'mine', string $role = 'owner'): array
    {
        $company = Company::create(['name' => $code, 'code' => $code]);
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => $role, 'is_active' => true]);
        $this->actingAs($user)->withSession(['toir_company_id' => $company->id]);

        return [$company, $user];
    }

    private function employee(Company $company, ?User $user = null): ToirEmployee
    {
        return ToirEmployee::forceCreate([
            'company_id' => $company->id, 'name' => 'Механик', 'position' => 'Механик',
            'status' => 'active', 'user_id' => $user?->id,
        ]);
    }

    private function asset(string $code = 'A1', string $nodeType = 'aggregate'): array
    {
        return $this->postJson('/api/v1/resources/assets', [
            'code' => $code, 'name' => 'Узел '.$code, 'category' => 'equipment',
            'node_type' => $nodeType, 'criticality' => 5,
        ])->assertCreated()->json('data');
    }

    public function test_asset_hierarchy_meter_validation_and_isolation(): void
    {
        [$company] = $this->member();
        $root = $this->asset('AREA', 'object');
        $pump = $this->postJson('/api/v1/resources/assets', [
            'name' => 'Насос', 'code' => 'PUMP', 'category' => 'equipment',
            'parent_id' => $root['id'], 'node_type' => 'aggregate', 'criticality' => 4,
        ])->assertCreated()->json('data');
        $bearing = $this->postJson('/api/v1/resources/assets', [
            'name' => 'Подшипник', 'code' => 'BR-1', 'category' => 'equipment',
            'parent_id' => $pump['id'], 'node_type' => 'component',
        ])->assertCreated()->json('data');
        $this->patchJson('/api/v1/resources/assets/'.$root['id'], [
            'parent_id' => $bearing['id'],
        ])->assertUnprocessable();
        $this->getJson('/api/v1/operations/asset-tree')->assertOk()
            ->assertJsonPath('total', 3);

        $this->postJson('/api/v1/operations/assets/'.$bearing['id'].'/readings', [
            'meter' => 'condition_score', 'value' => 18, 'recorded_at' => now()->subHour()->toDateTimeString(),
        ])->assertCreated();
        $this->postJson('/api/v1/operations/assets/'.$bearing['id'].'/readings', [
            'meter' => 'operating_hours', 'value' => 100, 'recorded_at' => now()->subHour()->toDateTimeString(),
        ])->assertCreated();
        $this->postJson('/api/v1/operations/assets/'.$bearing['id'].'/readings', [
            'meter' => 'operating_hours', 'value' => 99, 'recorded_at' => now()->toDateTimeString(),
        ])->assertUnprocessable();
        $this->patchJson('/api/v1/resources/assets/'.$bearing['id'], [
            'operating_hours' => 999999, 'condition_score' => 99,
        ])->assertUnprocessable();
        $this->assertSame(2, DB::table('toir_asset_readings')->count());
        $this->deleteJson('/api/v1/resources/assets/'.$bearing['id'])->assertStatus(409);

        [$other] = $this->member('other');
        $this->getJson('/api/v1/operations/asset-tree')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/operations/assets/'.$bearing['id'].'/readings')->assertNotFound();
        $this->postJson('/api/v1/operations/assets/'.$bearing['id'].'/readings', [
            'meter' => 'condition_score', 'value' => 100, 'recorded_at' => now()->toDateTimeString(),
        ])->assertNotFound();
        $this->assertSame(3, ToirAsset::where('company_id', $company->id)->count());
    }

    public function test_risk_inherits_damaged_component_and_explains_score(): void
    {
        $this->member();
        $root = $this->asset('ZONE', 'object');
        $pump = $this->postJson('/api/v1/resources/assets', [
            'name' => 'Двигатель', 'code' => 'MOT', 'category' => 'equipment',
            'parent_id' => $root['id'], 'node_type' => 'component', 'criticality' => 5,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/operations/assets/'.$pump['id'].'/readings', [
            'meter' => 'condition_score', 'value' => 0, 'recorded_at' => now()->toDateTimeString(),
        ])->assertCreated();
        $assessment = $this->getJson('/api/v1/operations/risk')->assertOk()->json();
        $nodes = collect($assessment['data'])->keyBy('id');
        $this->assertGreaterThanOrEqual(60, $nodes[$pump['id']]['score']);
        $this->assertGreaterThan(0, $nodes[$root['id']]['score']);
        $this->assertSame($pump['id'], $nodes[$root['id']]['highest_risk_descendant_id']);
        $this->assertSame('transparent_rules_v1', $assessment['method']);
        $this->assertNotEmpty($nodes[$pump['id']]['reasons']);
        $this->assertTrue(isset($assessment['summary']['incomplete_data']));
    }

    public function test_recurrence_shifts_crew_capacity_and_idempotent_order_generation(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');
        try {
            [$company,$owner] = $this->member();
            $workerUser = User::factory()->create();
            $company->users()->attach($workerUser->id, ['role' => 'technician', 'is_active' => true]);
            $mechanic = $this->employee($company, $workerUser);
            $asset = $this->asset('PUMP-55');
            $crew = $this->postJson('/api/v1/operations/crews', [
                'name' => 'Механическая бригада', 'code' => 'MECH-1', 'lead_employee_id' => $mechanic->id,
            ])->assertCreated()->json('data');
            $cid = $crew['id'];
            $this->getJson('/api/v1/operations/crews')->assertOk()->assertJsonCount(1, 'data');
            $shift = ['crew_id' => $cid, 'from' => '2026-11-03', 'to' => '2026-11-06',
                'weekdays' => [2, 3, 4, 5], 'starts_at' => '08:00', 'ends_at' => '16:00'];
            $this->postJson('/api/v1/operations/shifts/generate', $shift)
                ->assertCreated()->assertJsonPath('data.created', 4);
            $this->postJson('/api/v1/operations/shifts/generate', $shift)
                ->assertCreated()->assertJsonPath('data.existing', 4);
            $this->getJson('/api/v1/operations/shifts?from=2026-11-03&to=2026-11-06')
                ->assertOk()->assertJsonCount(4, 'data');

            $plan = $this->postJson('/api/v1/resources/plans', [
                'title' => 'Ежедневный осмотр насоса', 'asset_id' => $asset['id'],
                'crew_id' => $cid, 'periodicity_days' => 1, 'next_due_date' => '2026-11-03',
                'duration_minutes' => 120, 'priority' => 4,
                'work_instructions' => 'Проверить уплотнения и давление.',
                'safety_instructions' => 'Перед работой отключить питание.',
            ])->assertCreated()->json('data');
            $dry = $this->postJson('/api/v1/operations/generate', [
                'days' => 4, 'dry_run' => true,
            ])->assertOk()->json('data');
            $this->assertSame(4, $dry['created']);
            $this->assertDatabaseCount('toir_work_orders', 0);

            $generated = $this->postJson('/api/v1/operations/generate', [
                'days' => 4, 'dry_run' => false,
            ])->assertOk()->json('data');
            $this->assertSame(4, $generated['created']);
            $this->postJson('/api/v1/operations/generate', [
                'days' => 4, 'dry_run' => false,
            ])->assertOk()->assertJsonPath('data.existing', 4);
            $this->assertDatabaseCount('toir_work_orders', 4);

            $orders = ToirWorkOrder::where('company_id', $company->id)
                ->orderBy('scheduled_for')->get();
            $this->assertSame('2026-11-03 08:00:00', $orders[0]->planned_start);
            $this->assertSame('2026-11-03 10:00:00', $orders[0]->planned_end);
            $this->assertSame($cid, (int) $orders[0]->crew_id);
            $this->postJson('/api/v1/resources/work-orders', [
                'title' => 'Overbooked', 'asset_id' => $asset['id'], 'crew_id' => $cid,
                'planned_start' => '2026-11-03 08:30', 'planned_end' => '2026-11-03 09:30',
            ])->assertUnprocessable()->assertJsonValidationErrors('planned_start');

            $this->actingAs($workerUser)->withSession(['toir_company_id' => $company->id]);
            $this->postJson('/api/v1/work-orders/'.$orders[0]->id.'/transition', [
                'action' => 'start',
            ])->assertOk()->assertJsonPath('data.status', 'in_progress');
            $this->postJson('/api/v1/work-orders/'.$orders[0]->id.'/transition', [
                'action' => 'complete', 'comment' => 'Проверено, давление в норме.',
            ])->assertOk()->assertJsonPath('data.status', 'closed');
            $this->assertSame('2026-11-04',
                ToirMaintenancePlan::findOrFail($plan['id'])->next_due_date);
            $this->getJson('/api/v1/operations/forecast?days=10')
                ->assertOk()->assertJsonStructure(['data', 'range', 'method']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_crew_permissions_and_missing_shift_block_work_generation(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');
        try {
            [$company,$owner] = $this->member();
            $asset = $this->asset('CHILLER');
            $crew = $this->postJson('/api/v1/operations/crews', [
                'name' => 'Бригада № 2', 'code' => 'C2',
            ])->assertCreated()->json('data');
            $this->postJson('/api/v1/resources/plans', [
                'title' => 'Осмотр чиллера', 'asset_id' => $asset['id'],
                'crew_id' => $crew['id'], 'next_due_date' => '2026-11-03',
            ])->assertCreated();
            $this->postJson('/api/v1/operations/generate', [
                'days' => 7, 'dry_run' => false,
            ])->assertOk()->assertJsonPath('data.created', 0)
                ->assertJsonPath('data.blocked.0.reason', 'no_members');

            $worker = $this->employee($company);
            $this->postJson('/api/v1/operations/crews/'.$crew['id'].'/members', [
                'employee_id' => $worker->id,
            ])->assertOk();
            $this->postJson('/api/v1/operations/generate', [
                'days' => 7, 'dry_run' => false,
            ])->assertOk()->assertJsonPath('data.blocked.0.reason', 'no_capacity');
            $viewer = User::factory()->create();
            $company->users()->attach($viewer->id, ['role' => 'viewer', 'is_active' => true]);
            $this->actingAs($viewer)->withSession(['toir_company_id' => $company->id]);
            $this->postJson('/api/v1/operations/crews', [
                'name' => 'No', 'code' => 'DENY',
            ])->assertForbidden();
            $this->postJson('/api/v1/operations/generate', [
                'days' => 7, 'dry_run' => false,
            ])->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_operating_hours_forecast_uses_real_readings_and_no_fake_ml(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');
        try {
            $this->member();
            $asset = $this->asset('GEAR');
            $this->postJson('/api/v1/operations/assets/'.$asset['id'].'/readings', [
                'meter' => 'operating_hours', 'value' => 120,
                'recorded_at' => '2026-10-30 10:00:00',
            ])->assertCreated();
            $this->postJson('/api/v1/operations/assets/'.$asset['id'].'/readings', [
                'meter' => 'operating_hours', 'value' => 144,
                'recorded_at' => '2026-11-02 10:00:00',
            ])->assertCreated();
            $this->postJson('/api/v1/resources/plans', [
                'title' => 'Проверка редуктора', 'asset_id' => $asset['id'],
                'periodicity_days' => 90, 'next_due_date' => '2027-01-01',
                'interval_hours' => 100, 'next_due_hours' => 160,
            ])->assertCreated();
            $forecast = $this->getJson('/api/v1/operations/forecast?days=30')->assertOk()->json('data');
            $meter = collect($forecast)->firstWhere('trigger', 'operating_hours');
            $this->assertNotNull($meter);
            $this->assertTrue($meter['estimated']);
            $this->assertEqualsWithDelta(8, $meter['daily_hours_estimate'], 0.1);
            $this->assertSame('2026-11-04', $meter['date']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
