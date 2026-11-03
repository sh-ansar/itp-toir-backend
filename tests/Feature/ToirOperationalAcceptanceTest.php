<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ToirOperationalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_emergency_repair_from_report_to_passport_and_stock_audit(): void
    {
        $company=Company::create(['name'=>'Operational acceptance','code'=>'acceptance']);
        $other=Company::create(['name'=>'Other','code'=>'other']);
        $dispatcher=User::factory()->create();
        $technician=User::factory()->create();
        $inspector=User::factory()->create();
        $viewer=User::factory()->create();
        $outsider=User::factory()->create();
        foreach ([[$dispatcher,'manager'],[$technician,'technician'],[$inspector,'manager'],[$viewer,'viewer']] as [$user,$role]) {
            $company->users()->attach($user->id,['role'=>$role,'is_active'=>true]);
        }
        $other->users()->attach($outsider->id,['role'=>'owner','is_active'=>true]);
        $asset=ToirAsset::forceCreate(['company_id'=>$company->id,'name'=>'Pump A','code'=>'EQ-A','category'=>'equipment']);
        $employee=ToirEmployee::forceCreate(['company_id'=>$company->id,'name'=>'Fitter','user_id'=>$technician->id,'status'=>'active']);
        $login=function(User $user,Company $scope) {
            $this->actingAs($user)->withSession(['toir_company_id'=>$scope->id]);
        };
        $login($viewer,$company);
        $order=$this->postJson('/api/v1/assets/'.$asset->id.'/faults',[
            'title'=>'Pump bearing failed','description'=>'Pump vibration has exceeded the safe limit',
            'priority'=>'critical',
        ])->assertCreated()->assertJsonPath('data.priority',1)->json('data');
        $id=$order['id'];$orderUrl='/api/v1/work-orders/'.$id;
        $this->getJson('/api/v1/assets/'.$asset->id.'/passport')
            ->assertJsonPath('data.summary.open_orders',1)->assertJsonPath('data.summary.completed_orders',0);
        $this->postJson($orderUrl.'/dispatch',['employee_id'=>$employee->id])->assertForbidden();
        $login($outsider,$other);
        $this->postJson($orderUrl.'/dispatch',['employee_id'=>$employee->id])->assertUnprocessable();
        $this->getJson($orderUrl.'/materials')->assertNotFound();
        $this->getJson('/api/v1/assets/'.$asset->id.'/passport')->assertNotFound();
        $login($dispatcher,$company);
        $sku=$this->postJson('/api/v1/stock/items',[
            'sku'=>'BEARING-A','name'=>'Bearing assembly','unit'=>'pcs','unit_cost'=>'125.50',
            'minimum_quantity'=>'1',
        ])->assertCreated()->json('data');
        $itemUrl='/api/v1/stock/items/'.$sku['id'];
        $this->postJson($itemUrl.'/receive',['quantity'=>'5.000'])->assertOk();
        $this->postJson($orderUrl.'/dispatch',[
            'employee_id'=>$employee->id,'planned_start'=>'2026-12-01 09:00:00',
            'planned_end'=>'2026-12-01 12:00:00','comment'=>'Assigned for safe repair',
        ])->assertOk()->assertJsonPath('data.employee_id',$employee->id);
        $materials=$orderUrl.'/materials';
        $this->postJson($materials,['stock_item_id'=>$sku['id'],'action'=>'reserve','quantity'=>'2.000'])
            ->assertOk()->assertJsonPath('data.item.quantity_reserved','2.000');

        $login($viewer,$company);
        $this->postJson($materials,['stock_item_id'=>$sku['id'],'action'=>'consume','quantity'=>'1.000'])->assertForbidden();
        $login($technician,$company);
        $this->postJson($orderUrl.'/dispatch',['employee_id'=>$employee->id])->assertForbidden();
        $this->postJson($orderUrl.'/transition',['action'=>'start'])->assertOk()->assertJsonPath('data.status','in_progress');
        $this->postJson($orderUrl.'/transition',[
            'action'=>'complete','comment'=>'Replaced bearing','actual_labor_minutes'=>75,
        ])->assertStatus(409);
        $this->postJson($materials,['stock_item_id'=>$sku['id'],'action'=>'consume','quantity'=>'2.000'])
            ->assertOk()->assertJsonPath('data.line.actual_cost','251.00');
        $this->postJson($orderUrl.'/transition',[
            'action'=>'complete','comment'=>'Bearing replaced and measured','actual_labor_minutes'=>75,
        ])->assertOk()->assertJsonPath('data.status','awaiting_acceptance')
          ->assertJsonPath('data.actual_labor_minutes',75);
        $this->postJson($materials,['stock_item_id'=>$sku['id'],'action'=>'consume','quantity'=>'1.000'])->assertStatus(409);
        $this->postJson($orderUrl.'/review',['decision'=>'accept','comment'=>'Self check'])->assertForbidden();
        $login($inspector,$company);
        $this->postJson($orderUrl.'/review',['decision'=>'reject','comment'=>'Noise still above tolerance'])
            ->assertOk()->assertJsonPath('data.status','in_progress');
        $login($technician,$company);
        $this->postJson($orderUrl.'/transition',[
            'action'=>'complete','comment'=>'Bearing seating adjusted','actual_labor_minutes'=>25,
        ])->assertOk()->assertJsonPath('data.actual_labor_minutes',100);
        $login($inspector,$company);
        $this->postJson($orderUrl.'/review',['decision'=>'accept','comment'=>'Noise and vibration within limits'])
            ->assertOk()->assertJsonPath('data.status','closed');
        $this->postJson($orderUrl.'/review',['decision'=>'accept','comment'=>'Again'])->assertStatus(409);
        $this->getJson('/api/v1/resources/work-orders/'.$id)->assertJsonPath('data.actual_labor_minutes',100)
            ->assertJsonPath('data.status','closed');
        $this->getJson('/api/v1/assets/'.$asset->id.'/passport')
            ->assertOk()->assertJsonPath('data.summary.total_orders',1)
            ->assertJsonPath('data.summary.completed_orders',1)
            ->assertJsonPath('data.summary.open_orders',0)
            ->assertJsonPath('data.orders.0.id',$id)
            ->assertJsonPath('data.orders.0.priority',1)
            ->assertJsonPath('data.orders.0.actual_labor_minutes',100);
        $this->getJson($materials)->assertJsonPath('actual_material_cost','251.00')
            ->assertJsonPath('data.0.quantity_used','2.000');
        $this->getJson($itemUrl.'/movements')->assertJsonCount(3,'data');
        $this->assertDatabaseHas('toir_stock_items',['id'=>$sku['id'],'quantity_on_hand'=>'3.000','quantity_reserved'=>'0.000']);
        $this->assertSame(
            ['fault_reported','assigned','material_reserve','start','material_consume','complete','rejected','complete','accepted'],
            \App\Models\ToirWorkOrderEvent::where('work_order_id',$id)->orderBy('id')->pluck('action')->all()
        );
    }
}
