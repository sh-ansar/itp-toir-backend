<?php
namespace App\Services\MroCommercial;
use Illuminate\Support\Facades\DB;
final class SubscriptionService {
 public function assign(int $company,int $version,?int $actor,?string $ends=null):object {
  return DB::transaction(function()use($company,$version,$actor,$ends){
   abort_unless(DB::table('companies')->where('id',$company)->lockForUpdate()->exists(),404);
   $plan=app(PlanCatalogService::class)->find($version);abort_unless($plan->status==='published',422,'Assign a published plan');
   if($ends)abort_unless(\Illuminate\Support\Carbon::parse($ends)->isFuture(),422,'End date must be in the future');
   $old=DB::table('mro_subscriptions')->where('company_id',$company)->first();
   $snapshot=json_encode(['plan_version_id'=>$plan->id,'code'=>$plan->code,'version'=>$plan->version,'sha256'=>$plan->sha256,'terms'=>$plan->terms],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
   DB::table('mro_subscriptions')->updateOrInsert(['company_id'=>$company],['plan_version_id'=>$plan->id,'snapshot'=>$snapshot,'status'=>'active','starts_at'=>now(),'ends_at'=>$ends,'assigned_by'=>$actor,'created_at'=>$old?->created_at??now(),'updated_at'=>now()]);
   PortalAudit::record($actor,$company,'subscription.assigned',"company:$company",['previous'=>$old?json_decode($old->snapshot,true):null,'current'=>json_decode($snapshot,true),'ends_at'=>$ends]);return $this->get($company);
  },3);
 }
 public function get(int $company):?object { $s=DB::table('mro_subscriptions')->where('company_id',$company)->first();if($s){$s->snapshot=json_decode($s->snapshot,true);if($s->ends_at && now()->greaterThan($s->ends_at))$s->status='expired';}return $s; }
 public function ensure(int $company):void {
  if($this->get($company))return;
  $v=DB::table('mro_plan_versions as v')->join('mro_plans as p','p.id','=','v.plan_id')->where('p.code','legacy')->where('v.status','published')->orderByDesc('v.version')->value('v.id');
  if(!$v){$this->backfill();return;}$this->assign($company,$v,null);
 }
 public function backfill():int {
  return DB::transaction(function(){
   DB::table('mro_plans')->insertOrIgnore(['code'=>'legacy','created_at'=>now(),'updated_at'=>now()]);
   $p=DB::table('mro_plans')->where('code','legacy')->lockForUpdate()->first();
   $v=DB::table('mro_plan_versions')->where('plan_id',$p->id)->where('status','published')->orderByDesc('version')->first();
   if(!$v){$v=app(PlanCatalogService::class)->save(['code'=>'legacy','names'=>['ru'=>'Legacy / Полный доступ','en'=>'Legacy / Full access','ge'=>'Legacy / სრული წვდომა'],'description'=>'Existing tenant access is preserved. Billing and enforcement are disabled.','price_minor'=>0,'currency'=>'KZT','period_months'=>1,'is_public'=>false,'limits'=>array_fill_keys(['users','companies','storage_bytes','max_file_bytes'],null),'modules'=>array_fill_keys(app(PlanCatalogService::class)->modules(),true)],null);DB::table('mro_plan_versions')->where('id',$v->id)->update(['status'=>'published']);}
   $count=0; foreach(DB::table('companies')->orderBy('id')->get(['id']) as $c){if(!DB::table('mro_subscriptions')->where('company_id',$c->id)->exists()){$this->assign($c->id,$v->id,null);$count++;}} return $count;
  },3);
 }
}
