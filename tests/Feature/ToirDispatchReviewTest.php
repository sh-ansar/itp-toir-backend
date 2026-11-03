<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirEmployee;
use App\Models\ToirWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ToirDispatchReviewTest extends TestCase
{
    use RefreshDatabase;

    private function setupOrder(): array
    {
        $company=Company::create(['name'=>'Mine','code'=>'mine']);
        $manager=User::factory()->create();
        $technician=User::factory()->create();
        $viewer=User::factory()->create();
        foreach ([[$manager,'manager'],[$technician,'technician'],[$viewer,'viewer']] as [$user,$role])
            $company->users()->attach($user->id,['role'=>$role,'is_active'=>true]);
        $asset=ToirAsset::forceCreate(['company_id'=>$company->id,'name'=>'Pump','code'=>'P-1','category'=>'equipment']);
        $employee=ToirEmployee::forceCreate(['company_id'=>$company->id,'name'=>'Tech','user_id'=>$technician->id,'status'=>'active']);
        $order=ToirWorkOrder::forceCreate(['company_id'=>$company->id,'asset_id'=>$asset->id,
            'title'=>'Pump leaking','number'=>'E-001','type'=>'emergency','status'=>'waiting',
            'initiator_id'=>$viewer->id]);
        return compact('company','manager','technician','viewer','asset','employee','order');
    }

    private function login(User $user,Company $company): void
    {
        $this->actingAs($user)->withSession(['toir_company_id'=>$company->id]);
    }

    public function test_dispatch_completion_review_and_rejection_are_audited(): void
    {
        extract($this->setupOrder());
        $url='/api/v1/work-orders/'.$order->id;
        $this->login($viewer,$company);
        $this->postJson($url.'/dispatch',['employee_id'=>$employee->id])->assertForbidden();
        $this->login($manager,$company);
        $this->postJson($url.'/dispatch',['employee_id'=>$employee->id,
            'planned_start'=>'2026-12-01 09:00:00','planned_end'=>'2026-12-01 10:00:00'])
            ->assertOk()->assertJsonPath('data.employee_id',$employee->id);
        $this->login($technician,$company);
        $this->postJson($url.'/transition',['action'=>'start'])->assertOk();
        $this->postJson($url.'/transition',['action'=>'complete','comment'=>'Seal replaced','actual_labor_minutes'=>45])
            ->assertOk()->assertJsonPath('data.status','awaiting_acceptance');
        $this->postJson($url.'/review',['decision'=>'accept','comment'=>'Verified'])->assertForbidden();
        $this->postJson($url.'/transition',['action'=>'start'])->assertStatus(409);
        $this->login($manager,$company);
        $this->patchJson('/api/v1/resources/work-orders/'.$order->id,['title'=>'Silently modified'])
            ->assertStatus(409);
        $this->postJson($url.'/review',['decision'=>'reject','comment'=>'Still leaking'])
            ->assertOk()->assertJsonPath('data.status','in_progress');
        $this->assertNull($order->fresh()->actual_end);
        $this->assertSame(45,(int)$order->fresh()->actual_labor_minutes);
        $this->login($technician,$company);
        $this->postJson($url.'/transition',['action'=>'complete','comment'=>'Replaced gasket','actual_labor_minutes'=>30])
            ->assertOk()->assertJsonPath('data.status','awaiting_acceptance');
        $this->login($manager,$company);
        $this->postJson($url.'/review',['decision'=>'accept','comment'=>'Leak resolved'])
            ->assertOk()->assertJsonPath('data.status','closed');
        $this->assertSame(75,(int)$order->fresh()->actual_labor_minutes);
        $this->postJson($url.'/review',['decision'=>'accept','comment'=>'Again'])->assertStatus(409);
        $this->postJson($url.'/dispatch',['employee_id'=>$employee->id])->assertStatus(409);
        $this->assertSame(['assigned','start','complete','rejected','complete','accepted'],
            $order->events()->reorder('id')->pluck('action')->all());
    }

    public function test_assignments_are_tenant_scoped_and_require_valid_dates(): void
    {
        extract($this->setupOrder());
        $other=Company::create(['name'=>'Other','code'=>'other']);
        $foreign=ToirEmployee::forceCreate(['company_id'=>$other->id,'name'=>'Foreign','status'=>'active']);
        $this->login($manager,$company);
        $url='/api/v1/work-orders/'.$order->id.'/dispatch';
        $this->postJson($url,['employee_id'=>$foreign->id])->assertUnprocessable();
        $this->postJson($url,['employee_id'=>$employee->id,
            'planned_start'=>'2026-12-02 12:00:00','planned_end'=>'2026-12-01 08:00:00'])
            ->assertUnprocessable();
        $this->assertNull($order->fresh()->employee_id);
    }

    public function test_own_work_cannot_be_accepted_by_executor_even_if_manager(): void
    {
        extract($this->setupOrder());
        $employee->user_id=$manager->id;
        $employee->save();
        $order->employee_id=$employee->id;
        $order->status='awaiting_acceptance';
        $order->save();
        $this->login($manager,$company);
        $this->postJson('/api/v1/work-orders/'.$order->id.'/review',[
            'decision'=>'accept','comment'=>'My own work'])->assertForbidden();
    }
}
