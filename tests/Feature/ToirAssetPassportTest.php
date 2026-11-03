<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ToirAssetPassportTest extends TestCase
{
    use RefreshDatabase;

    public function test_passport_summarizes_only_selected_company_history(): void
    {
        $user = User::factory()->create();
        $first = Company::create(['name'=>'Mine A','code'=>'mine-a']);
        $second = Company::create(['name'=>'Mine B','code'=>'mine-b']);
        $first->users()->attach($user->id, ['role'=>'owner','is_active'=>true]);
        $second->users()->attach($user->id, ['role'=>'viewer','is_active'=>true]);
        $asset = ToirAsset::forceCreate(['company_id'=>$first->id,'name'=>'Pump','code'=>'P-01','category'=>'equipment']);
        $other = ToirAsset::forceCreate(['company_id'=>$second->id,'name'=>'Other','code'=>'P-01','category'=>'equipment']);
        ToirWorkOrder::forceCreate(['company_id'=>$first->id,'asset_id'=>$asset->id,'title'=>'Inspection','number'=>'WO-A','status'=>'closed']);
        ToirWorkOrder::forceCreate(['company_id'=>$first->id,'asset_id'=>$asset->id,'title'=>'Repair','number'=>'WO-B','status'=>'waiting']);
        ToirWorkOrder::forceCreate(['company_id'=>$second->id,'asset_id'=>$other->id,'title'=>'Secret','number'=>'WO-C','status'=>'closed']);

        $this->actingAs($user)->withSession(['toir_company_id'=>$first->id]);
        $this->getJson('/api/v1/assets/'.$asset->id.'/passport')
            ->assertOk()
            ->assertJsonPath('data.asset.code','P-01')
            ->assertJsonPath('data.summary.total_orders',2)
            ->assertJsonPath('data.summary.completed_orders',1)
            ->assertJsonPath('data.summary.open_orders',1)
            ->assertJsonCount(2,'data.orders')
            ->assertDontSee('Secret');
        $this->getJson('/api/v1/assets/'.$other->id.'/passport')->assertNotFound();

        $this->withSession(['toir_company_id'=>$second->id]);
        $this->getJson('/api/v1/assets/'.$other->id.'/passport')
            ->assertOk()->assertJsonCount(1,'data.orders');
        $this->getJson('/api/v1/assets/'.$asset->id.'/passport')->assertNotFound();
    }

    public function test_passport_requires_authenticated_active_company_member(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $company = Company::create(['name'=>'Mine','code'=>'mine']);
        $company->users()->attach($owner->id,['role'=>'owner','is_active'=>true]);
        $asset = ToirAsset::forceCreate(['company_id'=>$company->id,'name'=>'Fan','code'=>'F-1','category'=>'equipment']);
        $url='/api/v1/assets/'.$asset->id.'/passport';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($outsider)->withSession(['toir_company_id'=>$company->id])
            ->getJson($url)->assertForbidden();
    }
}
