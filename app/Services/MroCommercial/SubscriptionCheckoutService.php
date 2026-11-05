<?php
namespace App\Services\MroCommercial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
final class SubscriptionCheckoutService {
 public static function ready():bool {
  $s=app(InvoiceService::class)->profile();
  foreach(['legal_name','registration_number','address','bank','iban','bic','signatory','currency','tax_label','prefix','service_name'] as $key)if(empty($s[$key]))return false;
  return (bool)config('mro_commercial.invoice_issuance_enabled');
 }
 public function generate(int $company,int $actor,array $d):object {
  abort_unless(self::ready(),409,'Invoice issuance is not configured');
  // The tenant lock serializes double clicks across HTTP workers. A retry returns the same invoice.
  return DB::transaction(function()use($company,$actor,$d){
   DB::table('companies')->where('id',$company)->lockForUpdate()->first();
   $requestKey='checkout:'.$company.':'.$d['request_id'];
   $previous=DB::table('mro_audits')->where('company_id',$company)->where('action','checkout.generated')->where('subject',$requestKey)->first();
   if($previous){$meta=json_decode($previous->data,true);abort_unless((int)$meta['plan_version_id']===(int)$d['plan_version_id'] && (int)$meta['months']===(int)$d['months'] && $meta['starts_on']===$d['starts_on'],409,'Request already used');return app(InvoiceService::class)->find((int)$meta['invoice_id']);}
   $plan=null;foreach(app(PlanCatalogService::class)->catalog() as $p)if((int)$p->id===(int)$d['plan_version_id'])$plan=$p;
   abort_unless($plan && $plan->code!=='legacy' && $plan->terms['period_months']===1 && $plan->terms['price_minor']>0,422,'Select a current monthly tariff');
   $seller=app(InvoiceService::class)->profile();abort_unless($seller['currency']===$plan->terms['currency'],422,'Tariff currency does not match supplier');
   $start=Carbon::parse($d['starts_on'])->startOfDay();abort_if($start->gt(today()->addYear()),422,'Start date is too far in the future');
   $end=$start->copy()->addMonthsNoOverflow((int)$d['months']);
   $i=app(InvoiceService::class)->create(['company_id'=>$company,'items'=>[['description'=>'ITP ТОиР — '.$plan->terms['names']['ru'].' / календарный месяц','quantity'=>(int)$d['months'],'unit_minor'=>$plan->terms['price_minor']]],'period_from'=>$start->toDateString(),'period_to'=>$end->copy()->subDay()->toDateString(),'due_date'=>today()->addDays((int)$seller['valid_days'])->toDateString(),'basis'=>'Публичная оферта ITP ТОиР; кабинет №'.$company,'tax_inclusive'=>true],$actor);
   $snapshot=$i->snapshot;$snapshot['subscription']=['plan_version_id'=>$plan->id,'code'=>$plan->code,'version'=>$plan->version,'sha256'=>$plan->sha256,'terms'=>$plan->terms];
   $snapshot['checkout']=['months'=>(int)$d['months'],'starts_on'=>$start->toDateString(),'ends_exclusive'=>$end->toDateString()];
   DB::table('mro_invoices')->where('id',$i->id)->update(['snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
   $issued=app(InvoiceService::class)->issue($i->id,$actor);
   PortalAudit::record($actor,$company,'checkout.generated',$requestKey,['invoice_id'=>$i->id,'plan_version_id'=>$plan->id,'months'=>(int)$d['months'],'starts_on'=>$d['starts_on']]);return $issued;
  });
 }
 public function activate(int $invoice,int $actor):void {
  $i=app(InvoiceService::class)->find($invoice);$checkout=$i->snapshot['checkout']??null;if(!$checkout)return;
  abort_unless($i->status==='paid',409);
  DB::table('companies')->where('id',$i->company_id)->lockForUpdate()->first();
  abort_if(DB::table('mro_audits')->where('action','subscription.payment_applied')->where('subject','invoice:'.$invoice)->exists(),409,'Payment already applied');
  $old=app(SubscriptionService::class)->get($i->company_id);$start=Carbon::parse($checkout['starts_on'])->max(today());
  // Preserve paid days when renewing. Legacy has no paid end date and is replaced only on payment.
  if($old?->ends_at && Carbon::parse($old->ends_at)->gt($start))$start=Carbon::parse($old->ends_at);
  $end=$start->copy()->addMonthsNoOverflow((int)$checkout['months']);$snap=$i->snapshot['subscription'];
  DB::table('mro_subscriptions')->updateOrInsert(['company_id'=>$i->company_id],['plan_version_id'=>$snap['plan_version_id'],'snapshot'=>json_encode($snap,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'status'=>'active','starts_at'=>$start,'ends_at'=>$end,'assigned_by'=>$actor,'created_at'=>$old?->created_at??now(),'updated_at'=>now()]);
  PortalAudit::record($actor,$i->company_id,'subscription.payment_applied','invoice:'.$invoice,['previous'=>$old?->snapshot,'current'=>$snap,'starts_at'=>$start->toDateTimeString(),'ends_at'=>$end->toDateTimeString()]);
 }
}
