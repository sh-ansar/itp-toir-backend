<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ToirAcceptanceSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_is_explicit_idempotent_and_populates_real_operating_modules(): void
    {
        $company=Company::create(['name'=>'Acceptance','code'=>'qa-acceptance']);
        $this->artisan('toir:seed-acceptance',['--company-id'=>$company->id])->assertExitCode(0);
        $this->assertSame(0,DB::table('toir_assets')->where('company_id',$company->id)->count());
        $this->artisan('toir:seed-acceptance',['--company-id'=>$company->id,'--apply'=>true])->assertExitCode(0);
        $tables=[
          'toir_assets'=>16,'toir_employees'=>5,'toir_crews'=>2,'toir_crew_members'=>4,
          'toir_crew_shifts'=>10,'toir_projects'=>1,'toir_maintenance_plans'=>5,
          'toir_maintenance_steps'=>15,'toir_work_orders'=>6,'toir_stock_items'=>4,
          'toir_stock_movements'=>6,'toir_work_order_materials'=>2,
          'toir_asset_readings'=>5,'toir_supplies'=>1,'toir_tenders'=>1
        ];
        foreach($tables as $table=>$minimum){
            $actual=DB::table($table)->where('company_id',$company->id)->count();
            $this->assertGreaterThanOrEqual($minimum,$actual,$table);
        }
        $before=[];
        foreach($tables as $table=>$_){$before[$table]=DB::table($table)->where('company_id',$company->id)->count();}
        $this->artisan('toir:seed-acceptance',['--company-id'=>$company->id,'--apply'=>true])->assertExitCode(0);
        foreach($before as $table=>$count){
            $this->assertSame($count,DB::table($table)->where('company_id',$company->id)->count(),'idempotency '.$table);
        }
        $this->assertSame(0,DB::table('users')->count(),'QA never creates accounts');
        $this->assertSame(16,DB::table('toir_assets')->where('company_id',$company->id)->count());
    }

    public function test_seeded_data_is_readable_from_real_api_and_chat_for_owner(): void
    {
        $company=Company::create(['name'=>'API QA','code'=>'api-qa']);
        $owner=User::factory()->create();
        $company->users()->attach($owner->id,['role'=>'owner','is_active'=>true]);
        $this->artisan('toir:seed-acceptance',['--company-id'=>$company->id,'--apply'=>true])->assertExitCode(0);
        $this->actingAs($owner)->withSession(['toir_company_id'=>$company->id]);
        $this->getJson('/api/v1/resources/assets?per_page=30')->assertOk()
            ->assertJsonPath('meta.total',16);
        $this->getJson('/api/v1/operations/asset-tree')->assertOk()
            ->assertJsonCount(16,'data');
        $this->getJson('/api/v1/operations/risk')->assertOk()
            ->assertJsonCount(16,'data');
        $this->getJson('/api/v1/resources/work-orders?per_page=30')->assertOk()
            ->assertJsonPath('meta.total',7);
        $this->getJson('/api/v1/operations/crews')->assertOk()->assertJsonCount(2,'data');
        $this->getJson('/api/v1/operations/work-control')->assertOk()
            ->assertJsonPath('data.summary.open_orders',6);
        $this->getJson('/api/v1/overview')->assertOk();
        $this->getJson('/api/v1/chats')->assertOk()->assertJsonCount(1,'data');
        $this->assertDatabaseHas('toir_chats',['company_id'=>$company->id,'title'=>'[ТЕСТ] Канал ТОиР QA26']);
        $this->assertSame(1,DB::table('toir_chat_messages')->where('company_id',$company->id)->count());
    }
}
