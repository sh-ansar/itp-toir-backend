<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{User,Company};
use App\Services\MroCommercial\{PlatformAdminAccess as Access,PortalAudit,PlanCatalogService,SubscriptionService,InvoiceService,LegalDocumentService};
use App\Services\ToirMailReadiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Password};
use Illuminate\Validation\Rule;

final class MroPlatformAdministrationController extends Controller
{
 private function gate(Request $r,string $permission):int {
  Access::require($r->user(),$permission);
  return (int)$r->user()->id;
 }
 public function overview(Request $r) {
  $this->gate($r,'platform.access');
  return response()->json([
   'flags'=>config('mro_commercial'),
   'modules'=>app(PlanCatalogService::class)->modules(),
   'permissions'=>DB::table('mro_admin_grants')->where('user_id',$r->user()->id)->pluck('permission'),
  ]);
 }
 public function organizations(Request $r) {
  $this->gate($r,'companies.read');
  $d=$r->validate([
   'search'=>'nullable|string|max:150','page'=>'integer|min:1','per_page'=>'integer|min:1|max:100',
   'sort'=>['nullable',Rule::in(['name','created_at','users_count','companies_count'])],
   'direction'=>['nullable',Rule::in(['asc','desc'])],
   'verified'=>'nullable|boolean','plan'=>'nullable|string|max:40',
   'status'=>['nullable',Rule::in(['active','expired','none'])],
  ]);
  $members=DB::table('company_user')->select('company_id')->selectRaw('COUNT(*) AS users_count')->groupBy('company_id');
  $q=DB::table('companies as c')->leftJoinSub($members,'members','members.company_id','=','c.id')
   ->leftJoin('mro_subscriptions as s','s.company_id','=','c.id')
   ->leftJoin('mro_plan_versions as v','v.id','=','s.plan_version_id')
   ->leftJoin('mro_plans as p','p.id','=','v.plan_id');
  if($r->filled('search')) {
   $needle='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$d['search']).'%';
   $q->where(fn($x)=>$x->where('c.name','like',$needle)->orWhere('c.code','like',$needle));
  }
  if($r->filled('plan'))$q->where('p.code',$d['plan']);
  if(($d['status']??'')==='none')$q->whereNull('s.id');
  if(($d['status']??'')==='active')$q->where('s.status','active')->where(fn($x)=>$x->whereNull('s.ends_at')->orWhere('s.ends_at','>',now()));
  if(($d['status']??'')==='expired')$q->where('s.ends_at','<=',now());
  if($r->has('verified')) {
   $verified=$r->boolean('verified');
   $q->whereExists(function($sub)use($verified){
    $sub->selectRaw('1')->from('company_user as cu')
      ->join('users as u','u.id','=','cu.user_id')
      ->whereColumn('cu.company_id','c.id')
      ->whereIn('cu.role',['owner','admin'])
      ->where('cu.is_active',true);
    if($verified)$sub->whereNotNull('u.email_verified_at');
    else $sub->whereNull('u.email_verified_at');
   });
  }
  $sort=['name'=>'c.name','created_at'=>'c.created_at','users_count'=>'members.users_count','companies_count'=>'c.id'][$d['sort']??'created_at'];
  $rows=$q->select('c.id','c.name','c.code as subdomain','c.created_at','c.is_active',
      's.status as subscription_status','s.ends_at','p.code as plan_code')
    ->selectRaw('COALESCE(members.users_count,0) as users_count, 1 as companies_count, 0 as used_bytes')
    ->orderBy($sort,$d['direction']??'desc')->orderBy('c.id')->paginate($d['per_page']??20);
  $rows->getCollection()->transform(function($c){
   $admin=DB::table('company_user as cu')->join('users as u','u.id','=','cu.user_id')
    ->where('cu.company_id',$c->id)->whereIn('cu.role',['owner','admin'])
    ->orderByRaw("CASE WHEN cu.role = 'owner' THEN 0 ELSE 1 END")
    ->select('u.id','u.email','u.email_verified_at','u.is_active')->first();
   $c->email=$admin?->email;
   $c->administrator_id=$admin?->id;
   $c->is_verified=(bool)$admin?->email_verified_at;
   $c->is_blocked=!((bool)$admin?->is_active);
   return $c;
  });
  return response()->json($rows);
 }
 public function organization(Request $r,int $company) {
  $this->gate($r,'companies.read');
  $c=Company::findOrFail($company);
  return response()->json([
   'organization'=>['id'=>$c->id,'name'=>$c->name,'subdomain'=>$c->code,
     'staff_size'=>$c->users()->count(),'created_at'=>$c->created_at,'author_id'=>null],
   'companies'=>[['id'=>$c->id,'name'=>$c->name,'used_bytes'=>0,'technical_limit_bytes'=>1073741824]],
   'subscription'=>app(SubscriptionService::class)->get($company),
   'billing_profile'=>Access::allowed($r->user(),'billing.read')?app(InvoiceService::class)->profile($company):null,
   'audit'=>DB::table('mro_audits')->where('company_id',$company)->latest('id')->limit(100)->get(['id','actor_id','action','subject','created_at']),
  ]);
 }
 public function users(Request $r,int $company) {
  $this->gate($r,'companies.read');
  Company::findOrFail($company);
  $d=$r->validate(['page'=>'integer|min:1','search'=>'nullable|string|max:150']);
  $q=DB::table('company_user as cu')->join('users as u','u.id','=','cu.user_id')->where('cu.company_id',$company);
  if($r->filled('search'))$q->where('u.email','like','%'.$d['search'].'%');
  $rows=$q->orderBy('u.id')->select('u.id','u.email','u.name','u.is_active','u.email_verified_at','u.created_at','cu.role','cu.is_active as membership_active')->paginate(30);
  $rows->getCollection()->transform(function($row){
   $row->is_verified=(bool)$row->email_verified_at;
   $row->is_blocked=!$row->is_active;
   $row->verification_status=$row->is_verified?'verified':'resend_required';
   $row->roles=[['name'=>$row->role]];
   return $row;
  });
  return response()->json($rows);
 }
 public function resend(Request $r,int $company,int $user) {
  $actor=$this->gate($r,'users.write');
  abort_unless(app(ToirMailReadiness::class)->configured(),503,'SMTP is not configured');
  $u=User::where('is_active',true)->whereHas('companies',fn($q)=>$q->where('companies.id',$company))->findOrFail($user);
  abort_if($u->email_verified_at,409,'Already verified');
  $result=Password::sendResetLink(['email'=>$u->email]);
  abort_unless($result===Password::RESET_LINK_SENT,503,'Email delivery is unavailable');
  PortalAudit::record($actor,$company,'email.resend.requested',"user:$user");
  return response()->json(['status'=>'queued'],202);
 }
 public function plans(Request $r){$this->gate($r,'plans.read');return response()->json(['data'=>array_values(array_filter(app(PlanCatalogService::class)->catalog(false),fn($p)=>$p->code!=='legacy'))]);}
 public function savePlan(Request $r){$actor=$this->gate($r,'plans.write');return response()->json(['data'=>app(PlanCatalogService::class)->save($r->all(),$actor)],201);}
 public function planState(Request $r,int $version){$actor=$this->gate($r,'plans.write');$d=$r->validate(['status'=>['required',Rule::in(['published','archived'])]]);return response()->json(['data'=>app(PlanCatalogService::class)->transition($version,$d['status'],$actor)]);}
 public function assign(Request $r,int $customer){$actor=$this->gate($r,'subscriptions.write');$d=$r->validate(['plan_version_id'=>'required|integer','ends_at'=>'nullable|date']);return response()->json(['data'=>app(SubscriptionService::class)->assign($customer,$d['plan_version_id'],$actor,$d['ends_at']??null)]);}
 public function billingProfile(Request $r){$this->gate($r,'billing.read');return response()->json(['data'=>app(InvoiceService::class)->profile()]);}
 public function billingAsset(Request $r,string $kind){$actor=$this->gate($r,'billing.write');abort_unless(in_array($kind,['logo','stamp'],true),404);$r->validate(['asset'=>'required|file|mimes:png,jpg,jpeg|max:3072','approved'=>$kind==='stamp'?'required|accepted':'nullable|boolean']);return response()->json(['data'=>app(InvoiceService::class)->asset($r->file('asset'),$kind,$actor)]);}
 public function saveSupplier(Request $r){$actor=$this->gate($r,'billing.write');return response()->json(['data'=>app(InvoiceService::class)->saveProfile($r->all(),null,$actor)]);}
 public function saveBuyer(Request $r,int $customer){$actor=$this->gate($r,'billing.write');Company::findOrFail($customer);return response()->json(['data'=>app(InvoiceService::class)->saveProfile($r->all(),$customer,$actor)]);}
 public function invoices(Request $r){$this->gate($r,'billing.read');$d=$r->validate(['company_id'=>'nullable|integer|exists:companies,id','page'=>'integer|min:1']);$q=DB::table('mro_invoices')->select('id','company_id','number','status','currency','subtotal_minor','tax_minor','total_minor','due_date','pdf_sha256','pdf_size','created_at','issued_at');if($r->filled('company_id'))$q->where('company_id',$d['company_id']);return response()->json($q->latest('id')->paginate(25));}
 public function createInvoice(Request $r){$actor=$this->gate($r,'billing.write');$i=app(InvoiceService::class)->create($r->all(),$actor);unset($i->pdf_path);return response()->json(['data'=>$i],201);}
 public function invoiceState(Request $r,int $invoice){$actor=$this->gate($r,'billing.write');$d=$r->validate(['status'=>['required',Rule::in(['issued','awaiting_payment','overdue','cancelled'])]]);$service=app(InvoiceService::class);$i=$d['status']==='issued'?$service->issue($invoice,$actor):$service->transition($invoice,$d['status'],$actor);unset($i->pdf_path);return response()->json(['data'=>$i]);}
 public function invoicePdf(Request $r,int $invoice){$this->gate($r,'billing.read');$service=app(InvoiceService::class);$bytes=$r->boolean('preview')?$service->preview($invoice):$service->pdf($invoice);return response($bytes)->header('Content-Type','application/pdf')->header('Cache-Control','private, no-store')->header('Content-Disposition','inline; filename="invoice-'.$invoice.'.pdf"');}
 public function proofs(Request $r,int $invoice){$this->gate($r,'billing.read');abort_unless(DB::table('mro_invoices')->where('id',$invoice)->exists(),404);return response()->json(['data'=>DB::table('mro_payment_proofs')->where('invoice_id',$invoice)->select('id','invoice_id','user_id','sha256','size','mime','status','reviewed_by','review_note','created_at')->latest('id')->get()]);}
 public function proofFile(Request $r,int $proof){$this->gate($r,'billing.read');$p=DB::table('mro_payment_proofs')->where('id',$proof)->first();abort_unless($p,404);$bytes=\Illuminate\Support\Facades\Storage::disk('local')->get($p->path);abort_unless(hash_equals($p->sha256,hash('sha256',$bytes)),409);return response($bytes)->header('Content-Type',$p->mime)->header('Content-Disposition','attachment; filename="payment-proof-'.$proof.'.'.($p->mime==='application/pdf'?'pdf':($p->mime==='image/png'?'png':'jpg')).'"')->header('Cache-Control','private, no-store')->header('X-Content-Type-Options','nosniff');}
 public function reviewProof(Request $r,int $proof){$actor=$this->gate($r,'billing.write');abort_unless(config('mro_commercial.billing_enabled'),409,'Billing is disabled');$d=$r->validate(['status'=>['required',Rule::in(['approved','rejected'])],'note'=>'required|string|min:5|max:4000']);DB::transaction(function()use($proof,$actor,$d){$p=DB::table('mro_payment_proofs')->where('id',$proof)->first();abort_unless($p,404);$i=DB::table('mro_invoices')->where('id',$p->invoice_id)->lockForUpdate()->first();$p=DB::table('mro_payment_proofs')->where('id',$proof)->lockForUpdate()->first();abort_unless($p->status==='pending',409);if($d['status']==='approved'){abort_unless(in_array($i->status,['issued','awaiting_payment','overdue'],true),409);DB::table('mro_invoices')->where('id',$i->id)->update(['status'=>'paid','updated_at'=>now()]);app(\App\Services\MroCommercial\SubscriptionCheckoutService::class)->activate($i->id,$actor);}DB::table('mro_payment_proofs')->where('id',$proof)->update(['status'=>$d['status'],'reviewed_by'=>$actor,'review_note'=>$d['note'],'updated_at'=>now()]);PortalAudit::record($actor,$i->company_id,'payment.proof.'.$d['status'],'proof:'.$proof,['note'=>$d['note']]);});return response()->json(['status'=>$d['status']]);}
 public function legalVersions(Request $r){$this->gate($r,'legal.read');return response()->json(DB::table('mro_legal_versions')->select('id','type','locale','jurisdiction','version','status','title','sha256','acceptance','required','effective_at','approved_by','published_at','created_at')->latest('id')->paginate(25));}
 public function legalVersion(Request $r,int $version){$this->gate($r,'legal.read');$v=app(LegalDocumentService::class)->find($version);unset($v->pdf_path);return response()->json(['data'=>$v]);}
 public function saveLegal(Request $r){$actor=$this->gate($r,'legal.write');return response()->json(['data'=>app(LegalDocumentService::class)->draft($r->all(),$actor)],201);}
 public function legalState(Request $r,int $version){$d=$r->validate(['status'=>['required',Rule::in(['approved','published'])],'approval_note'=>'required_if:status,approved|string|max:4000']);$actor=$this->gate($r,$d['status']==='approved'?'legal.approve':'legal.publish');$s=app(LegalDocumentService::class);$v=$d['status']==='approved'?$s->approve($version,$actor,$d['approval_note']):$s->publish($version,$actor);unset($v->pdf_path);return response()->json(['data'=>$v]);}
 public function legalPdf(Request $r,int $version){$this->gate($r,'legal.read');return response(app(LegalDocumentService::class)->pdf($version))->header('Content-Type','application/pdf')->header('Cache-Control','private, no-store');}
 public function consents(Request $r){$this->gate($r,'legal.read');$d=$r->validate(['search'=>'nullable|string|max:100','company_id'=>'nullable|integer','page'=>'integer|min:1']);$q=DB::table('mro_legal_consents as c')->leftJoin('users as u','u.id','=','c.user_id')->join('mro_legal_versions as v','v.id','=','c.legal_version_id')->select('c.*','u.email','v.type','v.version','v.title');if($r->filled('company_id'))$q->where('c.company_id',$d['company_id']);if($r->filled('search'))$q->where('u.email','like','%'.$d['search'].'%');return response()->json($q->orderByDesc('c.id')->paginate(30));}
 public function grants(Request $r){$this->gate($r,'platform.manage');return response()->json(['data'=>DB::table('platform_admin_grants as g')->join('users as u','u.id','=','g.user_id')->select('g.id','g.user_id','g.permission','g.created_at','u.email')->orderBy('g.id')->get()]);}
 public function grant(Request $r){$actor=$this->gate($r,'platform.manage');$d=$r->validate(['user_id'=>'required|integer|exists:users,id','permission'=>['required',Rule::in(['platform.manage','companies.read','users.write','plans.read','plans.write','subscriptions.write','billing.read','billing.write','legal.read','legal.write','legal.approve','legal.publish'])]]);$u=User::findOrFail($d['user_id']);abort_unless($u->email_verified_at && $u->is_active,422);DB::transaction(function()use($d,$actor){DB::table('platform_admin_grants')->insertOrIgnore($d+['granted_by'=>$actor,'created_at'=>now()]);PortalAudit::record($actor,null,'platform.grant','user:'.$d['user_id'],['permission'=>$d['permission']]);});return response()->json(['status'=>'granted']);}
 public function revoke(Request $r,int $grant){$actor=$this->gate($r,'platform.manage');DB::transaction(function()use($grant,$actor){$admins=DB::table('platform_admin_grants')->where('permission','platform.manage')->lockForUpdate()->get();$g=DB::table('platform_admin_grants')->where('id',$grant)->lockForUpdate()->first();abort_unless($g,404);abort_if($g->permission==='platform.manage' && $admins->count()<=1,409,'Keep at least one platform manager');DB::table('platform_admin_grants')->where('id',$grant)->delete();PortalAudit::record($actor,null,'platform.revoke','user:'.$g->user_id,['permission'=>$g->permission]);});return response()->json(['status'=>'revoked']);}
}
