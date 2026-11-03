<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirEmployee;
use App\Models\ToirWorkOrder;
use App\Models\ToirStockItem;
use App\Models\ToirWorkOrderMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ToirWorkControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_is_company_scoped_and_flags_late_unassigned_and_real_cost(): void
    {
        $user=User::factory()->create();
        $company=Company::create(['name'=>'A','code'=>'a']);
        $foreign=Company::create(['name'=>'B','code'=>'b']);
        $company->users()->attach($user->id,['role'=>'manager','is_active'=>true]);
        $asset=ToirAsset::forceCreate(['company_id'=>$company->id,'name'=>'Pump','code'=>'P','category'=>'equipment']);
        $late=ToirWorkOrder::forceCreate(['company_id'=>$company->id,'asset_id'=>$asset->id,
            'title'=>'Urgent repair','number'=>'W-1','status'=>'waiting','type'=>'emergency',
            'priority'=>1,'planned_end'=>now()->subDay(),'estimated_cost'=>500]);
        $stock=ToirStockItem::forceCreate(['company_id'=>$company->id,'sku'=>'SP-1',
            'name'=>'Bearing','unit'=>'pcs','quantity_on_hand'=>3,'unit_cost'=>95]);
        ToirWorkOrderMaterial::forceCreate(['company_id'=>$company->id,'work_order_id'=>$late->id,
            'stock_item_id'=>$stock->id,'actual_cost'=>237.50,'quantity_used'=>2.5]);
        ToirWorkOrder::forceCreate(['company_id'=>$company->id,'asset_id'=>$asset->id,
            'title'=>'Inspection','number'=>'W-2','status'=>'awaiting_acceptance','priority'=>3]);
        ToirWorkOrder::forceCreate(['company_id'=>$company->id,'asset_id'=>$asset->id,
            'title'=>'Finished','number'=>'W-3','status'=>'closed','actual_labor_minutes'=>75]);
        ToirWorkOrder::forceCreate(['company_id'=>$foreign->id,
            'title'=>'Secret','number'=>'F-1','status'=>'overdue','priority'=>1]);
        $this->actingAs($user)->withSession(['toir_company_id'=>$company->id]);
        $this->getJson('/api/v1/operations/work-control')->assertOk()
            ->assertJsonPath('data.summary.open_orders',2)
            ->assertJsonPath('data.summary.late_orders',1)
            ->assertJsonPath('data.summary.unassigned_orders',1)
            ->assertJsonPath('data.summary.awaiting_acceptance',1)
            ->assertJsonPath('data.summary.recorded_material_cost',237.5)
            ->assertJsonPath('data.summary.recorded_labor_minutes',75)
            ->assertJsonPath('data.summary.orders_with_labor_recorded',1)
            ->assertJsonPath('data.orders.0.id',$late->id)
            ->assertJsonPath('data.orders.0.is_late',true)
            ->assertJsonCount(2,'data.orders')->assertDontSee('Secret');
        $this->actingAs(User::factory()->create())->withSession(['toir_company_id'=>$company->id])
            ->getJson('/api/v1/operations/work-control')->assertForbidden();
    }

    public function test_fault_priority_is_persisted_and_labor_is_explicit(): void
    {
        $user=User::factory()->create();
        $company=Company::create(['name'=>'A','code'=>'a']);
        $company->users()->attach($user->id,['role'=>'manager','is_active'=>true]);
        $asset=ToirAsset::forceCreate(['company_id'=>$company->id,'name'=>'Pump','code'=>'P','category'=>'equipment']);
        $employee=ToirEmployee::forceCreate(['company_id'=>$company->id,'name'=>'Tech','status'=>'active']);
        $this->actingAs($user)->withSession(['toir_company_id'=>$company->id]);
        $response=$this->postJson('/api/v1/assets/'.$asset->id.'/faults',[
            'title'=>'Bearing failure','description'=>'Bearing overheating badly','priority'=>'critical'
        ])->assertCreated();
        $id=$response->json('data.id');
        $this->assertSame(1,(int)ToirWorkOrder::findOrFail($id)->priority);
        $this->postJson('/api/v1/work-orders/'.$id.'/dispatch',['employee_id'=>$employee->id])->assertOk();
        $this->postJson('/api/v1/work-orders/'.$id.'/transition',['action'=>'start'])->assertOk();
        $this->postJson('/api/v1/work-orders/'.$id.'/transition',[
            'action'=>'complete','comment'=>'Bearing replaced','actual_labor_minutes'=>120
        ])->assertOk()->assertJsonPath('data.status','awaiting_acceptance')
          ->assertJsonPath('data.actual_labor_minutes',120);
    }
}
