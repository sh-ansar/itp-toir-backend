<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirCrew;
use App\Models\ToirCrewShift;
use App\Models\ToirEmployee;
use App\Models\ToirWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ToirChecklistTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code='qacompany'): array
    {
        $company=Company::create(['name'=>'Checklist QA '.$code,'code'=>$code]);
        $owner=User::factory()->create();
        $company->users()->attach($owner->id,['role'=>'owner','is_active'=>true]);
        $this->actingAs($owner)->withSession(['toir_company_id'=>$company->id]);
        $asset=ToirAsset::forceCreate(['company_id'=>$company->id,'name'=>'Pump','code'=>'PUMP',
            'category'=>'equipment','node_type'=>'aggregate']);
        $employee=ToirEmployee::forceCreate(['company_id'=>$company->id,'name'=>'Inspector',
            'status'=>'active']);
        return [$company,$owner,$asset,$employee];
    }

    private function plan(ToirAsset $asset,ToirEmployee $employee,array $more=[]): array
    {
        return $this->postJson('/api/v1/resources/plans',array_merge([
            'title'=>'Condition inspection','asset_id'=>$asset->id,
            'employee_id'=>$employee->id,'status'=>'active',
            'periodicity_days'=>30,'next_due_date'=>'2026-11-04',
        ],$more))->assertCreated()->json('data');
    }

    public function test_templates_are_snapshotted_and_required_steps_block_closure(): void
    {
        Carbon::setTestNow('2026-11-03 09:00:00');
        try{
            [$company,$owner,$asset,$employee]=$this->company();
            $plan=$this->plan($asset,$employee);
            $route='/api/v1/plans/'.$plan['id'].'/checklist';
            $step=$this->postJson($route,[
                'title'=>'Isolate the pump electrical supply',
                'kind'=>'safety','is_required'=>true,
            ])->assertCreated()->json('data');
            $measurement=$this->postJson($route,[
                'title'=>'Record bearing vibration','kind'=>'measurement',
                'unit'=>'mm/s','is_required'=>true,
            ])->assertCreated()->json('data');
            $optional=$this->postJson($route,[
                'title'=>'Wash enclosure','kind'=>'service','is_required'=>false,
            ])->assertCreated()->json('data');
            $this->getJson($route)->assertOk()->assertJsonCount(3,'data');
            $issued=$this->postJson('/api/v1/plans/'.$plan['id'].'/issue',[])
                ->assertCreated()->json('data');
            $oid=(int)$issued['id'];
            $url='/api/v1/work-orders/'.$oid.'/checklist';
            $snap=$this->getJson($url)->assertOk()->assertJsonCount(3,'data')->json('data');
            $this->assertSame('Isolate the pump electrical supply',$snap[0]['title']);
            $this->assertSame('mm/s',$snap[1]['unit']);
            // Altering a live plan changes only future orders, not issued evidence.
            $this->patchJson($route.'/'.$step['id'],[
                'title'=>'Updated template for subsequent issues',
            ])->assertOk();
            $this->getJson($url)->assertJsonPath('data.0.title',
                'Isolate the pump electrical supply');

            $this->postJson('/api/v1/work-orders/'.$oid.'/transition',[
                'action'=>'start',
            ])->assertOk();
            $this->postJson('/api/v1/work-orders/'.$oid.'/transition',[
                'action'=>'complete','comment'=>'Inspect complete',
            ])->assertUnprocessable()->assertJsonValidationErrors('checklist');

            $this->patchJson($url.'/'.$snap[0]['id'],[
                'status'=>'skipped','comment'=>'Cannot isolate',
            ])->assertUnprocessable()->assertJsonValidationErrors('status');
            $this->patchJson($url.'/'.$snap[1]['id'],[
                'status'=>'done',
            ])->assertUnprocessable()->assertJsonValidationErrors('numeric_value');
            $this->patchJson($url.'/'.$snap[0]['id'],[
                'status'=>'done','comment'=>'Power safely disconnected',
            ])->assertOk()->assertJsonPath('data.status','done');
            $this->patchJson($url.'/'.$snap[1]['id'],[
                'status'=>'done','numeric_value'=>3.125,'comment'=>'Measured in three positions',
            ])->assertOk()->assertJsonPath('data.status','done');
            $this->patchJson($url.'/'.$snap[2]['id'],[
                'status'=>'skipped','comment'=>' ',
            ])->assertUnprocessable()->assertJsonValidationErrors('comment');
            $this->patchJson($url.'/'.$snap[2]['id'],[
                'status'=>'skipped','comment'=>'No contamination',
            ])->assertOk()->assertJsonPath('data.status','skipped');
            $this->postJson('/api/v1/work-orders/'.$oid.'/transition',[
                'action'=>'complete','comment'=>'Safety and vibration verified',
            ])->assertOk()->assertJsonPath('data.status','closed');
            $this->assertDatabaseHas('toir_work_order_steps',[
                'company_id'=>$company->id,'work_order_id'=>$oid,'kind'=>'measurement',
                'status'=>'done','performed_by'=>$owner->id,
            ]);
            $this->assertDatabaseHas('toir_work_order_events',[
                'work_order_id'=>$oid,'action'=>'checklist_step_updated',
            ]);
            $this->patchJson($url.'/'.$snap[1]['id'],[
                'status'=>'pending',
            ])->assertStatus(409);
            $this->deleteJson($route.'/'.$step['id'])->assertOk();
            $this->getJson($url)->assertOk()->assertJsonCount(3,'data');
        }finally{Carbon::setTestNow();}
    }

    public function test_generated_orders_receive_safety_steps_and_crew_technician_can_record_results(): void
    {
        Carbon::setTestNow('2026-11-03 09:00:00');
        try{
            [$company,$owner,$asset,$employee]=$this->company();
            $tech=User::factory()->create();
            $company->users()->attach($tech->id,['role'=>'technician','is_active'=>true]);
            $employee->user_id=$tech->id;$employee->save();
            $crew=ToirCrew::create(['company_id'=>$company->id,'name'=>'Service team','code'=>'TEAM']);
            $crew->members()->attach($employee->id,[
                'company_id'=>$company->id,'role'=>'lead',
            ]);
            ToirCrewShift::create([
                'company_id'=>$company->id,'crew_id'=>$crew->id,
                'work_date'=>'2026-11-04','starts_at'=>'08:00:00',
                'ends_at'=>'16:00:00','status'=>'confirmed',
            ]);
            $plan=$this->plan($asset,$employee,['crew_id'=>$crew->id]);
            $this->postJson('/api/v1/plans/'.$plan['id'].'/checklist',[
                'title'=>'Lockout/tagout before service','kind'=>'safety','is_required'=>true,
            ])->assertCreated();
            $created=$this->postJson('/api/v1/operations/generate',[
                'days'=>5,'dry_run'=>false,
            ])->assertOk()->json('data');
            $this->assertSame(1,$created['created']);
            $orderId=(int)$created['created_orders'][0];
            $steps=$this->getJson('/api/v1/work-orders/'.$orderId.'/checklist')
                ->assertOk()->assertJsonCount(1,'data')->json('data');
            $this->actingAs($tech)->withSession(['toir_company_id'=>$company->id]);
            $this->postJson('/api/v1/plans/'.$plan['id'].'/checklist',[
                'title'=>'Do not permit technician to alter plan',
            ])->assertForbidden();
            $this->postJson('/api/v1/work-orders/'.$orderId.'/transition',[
                'action'=>'start',
            ])->assertOk();
            $this->patchJson('/api/v1/work-orders/'.$orderId.'/checklist/'.$steps[0]['id'],[
                'status'=>'done','comment'=>'Lockout verified by mechanic',
            ])->assertOk()->assertJsonPath('data.performed_by',$tech->id);
            $this->postJson('/api/v1/work-orders/'.$orderId.'/transition',[
                'action'=>'complete','comment'=>'Work and safety checks completed',
            ])->assertOk()->assertJsonPath('data.status','closed');
        }finally{Carbon::setTestNow();}
    }

    public function test_cross_company_viewers_cannot_write_or_see_checklists(): void
    {
        Carbon::setTestNow('2026-11-03 09:00:00');
        try{
            [$company,$owner,$asset,$employee]=$this->company();
            $plan=$this->plan($asset,$employee);
            $template=$this->postJson('/api/v1/plans/'.$plan['id'].'/checklist',[
                'title'=>'Check bearing temperature','kind'=>'measurement',
            ])->assertCreated()->json('data');
            $order=$this->postJson('/api/v1/plans/'.$plan['id'].'/issue',[])
                ->assertCreated()->json('data');
            $step=$this->getJson('/api/v1/work-orders/'.$order['id'].'/checklist')
                ->assertOk()->json('data.0');
            $viewer=User::factory()->create();
            $company->users()->attach($viewer->id,['role'=>'viewer','is_active'=>true]);
            $this->actingAs($viewer)->withSession(['toir_company_id'=>$company->id]);
            $this->getJson('/api/v1/plans/'.$plan['id'].'/checklist')->assertOk();
            $this->postJson('/api/v1/plans/'.$plan['id'].'/checklist',[
                'title'=>'Unauthorised operation',
            ])->assertForbidden();
            $this->patchJson('/api/v1/work-orders/'.$order['id'].'/checklist/'.$step['id'],[
                'status'=>'done','numeric_value'=>50,
            ])->assertForbidden();

            [$otherCompany]=$this->company('otherco');
            $this->getJson('/api/v1/plans/'.$plan['id'].'/checklist')->assertNotFound();
            $this->getJson('/api/v1/work-orders/'.$order['id'].'/checklist')->assertNotFound();
            $this->postJson('/api/v1/plans/'.$plan['id'].'/checklist',[
                'title'=>'Attempt cross-company',
            ])->assertNotFound();
            $this->patchJson('/api/v1/work-orders/'.$order['id'].'/checklist/'.$step['id'],[
                'status'=>'done','numeric_value'=>100,
            ])->assertNotFound();
            $this->assertSame(1,DB::table('toir_work_order_steps')
                ->where('company_id',$company->id)->count());
        }finally{Carbon::setTestNow();}
    }
}
