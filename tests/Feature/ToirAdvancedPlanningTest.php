<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirCrew;
use App\Models\ToirCrewShift;
use App\Models\ToirEmployee;
use App\Models\ToirMaintenancePlan;
use App\Models\ToirWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ToirAdvancedPlanningTest extends TestCase
{
    use RefreshDatabase;

    private function setupCompany(): array
    {
        $user=User::factory()->create();
        $company=Company::create(['name'=>'Planning QA','code'=>'planning-qa']);
        $company->users()->attach($user->id,['role'=>'owner','is_active'=>true]);
        $this->actingAs($user)->withSession(['toir_company_id'=>$company->id]);
        $asset=ToirAsset::forceCreate([
            'company_id'=>$company->id,'name'=>'Compressor A','code'=>'CMP-A',
            'category'=>'equipment','node_type'=>'aggregate','operating_hours'=>175,
        ]);
        $employee=ToirEmployee::forceCreate([
            'company_id'=>$company->id,'name'=>'Alex','status'=>'active',
        ]);
        return [$company,$user,$asset,$employee];
    }

    public function test_meter_threshold_creates_real_order_only_once_and_completion_moves_hours_not_calendar(): void
    {
        Carbon::setTestNow('2026-11-03 12:00:00');
        try{
            [$company,$user,$asset,$employee]=$this->setupCompany();
            $plan=$this->postJson('/api/v1/resources/plans',[
                'title'=>'Compressor hour based inspection',
                'asset_id'=>$asset->id,'employee_id'=>$employee->id,
                'status'=>'active','next_due_date'=>'2027-02-01',
                'periodicity_days'=>90,'interval_hours'=>200,
                'next_due_hours'=>150,'duration_minutes'=>60,
            ])->assertCreated()->json('data');
            $url='/api/v1/operations/generate';
            $dry=$this->postJson($url,['days'=>15,'dry_run'=>true])
                ->assertOk()->json('data');
            $this->assertSame(1,$dry['created']);
            $this->assertDatabaseCount('toir_work_orders',0);
            $real=$this->postJson($url,['days'=>15,'dry_run'=>false])
                ->assertOk()->json('data');
            $this->assertSame(1,$real['created']);
            $order=ToirWorkOrder::findOrFail($real['created_orders'][0]);
            $this->assertSame('operating_hours',$order->trigger_source);
            $this->assertEqualsWithDelta(150,(float)$order->trigger_hours,0.01);
            $this->assertSame('2026-11-03',$order->scheduled_for);
            $this->postJson($url,['days'=>15,'dry_run'=>false])
                ->assertOk()->assertJsonPath('data.existing',1);
            $this->assertDatabaseCount('toir_work_orders',1);
            $this->postJson('/api/v1/work-orders/'.$order->id.'/transition',['action'=>'start'])
                ->assertOk();
            $this->postJson('/api/v1/work-orders/'.$order->id.'/transition',[
                'action'=>'complete','comment'=>'Meter inspection complete',
            ])->assertOk();
            $planDb=ToirMaintenancePlan::findOrFail($plan['id']);
            $this->assertSame('2027-02-01',$planDb->next_due_date);
            $this->assertEqualsWithDelta(375,(float)$planDb->next_due_hours,0.01);
            $this->postJson($url,['days'=>15,'dry_run'=>false])
                ->assertOk()->assertJsonPath('data.created',0);
            $this->assertDatabaseCount('toir_work_orders',1);
        }finally{Carbon::setTestNow();}
    }

    public function test_two_plans_share_one_crew_shift_without_overlapping_in_dry_run_or_commit(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        try{
            [$company,$user,$asset,$employee]=$this->setupCompany();
            $crew=ToirCrew::create(['company_id'=>$company->id,'name'=>'Mechanics','code'=>'MECH']);
            $crew->members()->attach($employee->id,['company_id'=>$company->id,'role'=>'lead']);
            ToirCrewShift::create([
                'company_id'=>$company->id,'crew_id'=>$crew->id,'work_date'=>'2026-11-04',
                'starts_at'=>'08:00:00','ends_at'=>'14:00:00','status'=>'confirmed',
            ]);
            foreach(['First service','Second service'] as $title){
                $this->postJson('/api/v1/resources/plans',[
                    'title'=>$title,'asset_id'=>$asset->id,'crew_id'=>$crew->id,
                    'next_due_date'=>'2026-11-04','periodicity_days'=>30,'duration_minutes'=>120,
                ])->assertCreated();
            }
            $url='/api/v1/operations/generate';
            $this->postJson($url,['days'=>5,'dry_run'=>true])
                ->assertOk()->assertJsonPath('data.created',2);
            $this->assertDatabaseCount('toir_work_orders',0);
            $this->postJson($url,['days'=>5,'dry_run'=>false])
                ->assertOk()->assertJsonPath('data.created',2);
            $orders=ToirWorkOrder::orderBy('planned_start')->get();
            $this->assertCount(2,$orders);
            $this->assertTrue(Carbon::parse($orders[0]->planned_end)
                ->lte(Carbon::parse($orders[1]->planned_start)));
            $this->assertSame('08:00:00',Carbon::parse($orders[0]->planned_start)->format('H:i:s'));
            $this->assertSame('10:00:00',Carbon::parse($orders[1]->planned_start)->format('H:i:s'));
        }finally{Carbon::setTestNow();}
    }

    public function test_explicit_employee_appointment_cannot_overlap_own_crew_order(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        try{
            [$company,$user,$asset,$employee]=$this->setupCompany();
            $crew=ToirCrew::create(['company_id'=>$company->id,'name'=>'Mechanics','code'=>'M2']);
            $crew->members()->attach($employee->id,['company_id'=>$company->id,'role'=>'lead']);
            ToirCrewShift::create([
                'company_id'=>$company->id,'crew_id'=>$crew->id,'work_date'=>'2026-11-04',
                'starts_at'=>'08:00:00','ends_at'=>'16:00:00','status'=>'confirmed',
            ]);
            $this->postJson('/api/v1/resources/work-orders',[
                'title'=>'Crew assigned','crew_id'=>$crew->id,'asset_id'=>$asset->id,
                'planned_start'=>'2026-11-04 08:00:00','planned_end'=>'2026-11-04 10:00:00',
            ])->assertCreated();
            $this->postJson('/api/v1/resources/work-orders',[
                'title'=>'Person assigned','employee_id'=>$employee->id,'asset_id'=>$asset->id,
                'planned_start'=>'2026-11-04 09:00:00','planned_end'=>'2026-11-04 11:00:00',
            ])->assertUnprocessable()->assertJsonValidationErrors('planned_start');
        }finally{Carbon::setTestNow();}
    }
}
