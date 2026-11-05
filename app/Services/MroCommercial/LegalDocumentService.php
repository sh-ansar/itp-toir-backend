<?php
namespace App\Services\MroCommercial;
use App\Models\User;
use Illuminate\Support\Facades\{DB,Validator,Storage};
use Illuminate\Validation\Rule;
final class LegalDocumentService {
 public const TYPES=['offer','tariff_policy','acceptable_use','personal_data_consent','privacy'];
 public function draft(array $input,int $actor):object {
  $d=Validator::make($input,['type'=>['required',Rule::in(self::TYPES)],'locale'=>['required',Rule::in(['ru','en','ge','de','fr','es'])],'jurisdiction'=>['required',Rule::in(['KZ','GE'])],'title'=>'required|string|max:250','body'=>'required|string|min:50|max:250000','acceptance'=>['required',Rule::in(['accepted','acknowledged','consented'])],'required'=>'required|boolean','effective_at'=>'nullable|date'])->validate();
  return DB::transaction(function()use($d,$actor){
   $version=1+(int)DB::table('mro_legal_versions')->where('type',$d['type'])->where('locale',$d['locale'])->where('jurisdiction',$d['jurisdiction'])->max('version');
   $operator=app(InvoiceService::class)->profile();
   $canonical=json_encode(['type'=>$d['type'],'locale'=>$d['locale'],'jurisdiction'=>$d['jurisdiction'],'version'=>$version,'title'=>$d['title'],'body'=>str_replace("\r\n","\n",$d['body']),'operator'=>$operator,'acceptance'=>$d['acceptance'],'required'=>$d['required'],'effective_at'=>$d['effective_at']??null],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
   $d['body']=str_replace("\r\n","\n",$d['body']);$d['operator_snapshot']=json_encode($operator,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$d['version']=$version;$d['sha256']=hash('sha256',$canonical);$d['created_by']=$actor;$d['created_at']=$d['updated_at']=now();
   $id=DB::table('mro_legal_versions')->insertGetId($d);PortalAudit::record($actor,null,'legal.draft',"legal:$id",['sha256'=>$d['sha256']]);return $this->find($id);
  },3);
 }
 public function find(int $id):object {$v=DB::table('mro_legal_versions')->where('id',$id)->first();abort_unless($v,404);$v->operator_snapshot=json_decode($v->operator_snapshot,true);return $v;}
 public function approve(int $id,int $actor,string $note):object {
  abort_unless(strlen(trim($note))>=10,422,'Record the legal approval reference');
  DB::transaction(function()use($id,$actor,$note){$v=DB::table('mro_legal_versions')->where('id',$id)->lockForUpdate()->first();abort_unless($v,404);abort_unless($v->status==='draft',409);DB::table('mro_legal_versions')->where('id',$id)->update(['status'=>'approved','approved_by'=>$actor,'approval_note'=>$note,'updated_at'=>now()]);PortalAudit::record($actor,null,'legal.approved',"legal:$id",['reference'=>$note]);});return $this->find($id);
 }
 public function publish(int $id,int $actor):object {
  abort_unless(config('mro_commercial.legal_publication_enabled'),409,'Legal publication is disabled pending approval');
  DB::transaction(function()use($id,$actor){$v=DB::table('mro_legal_versions')->where('id',$id)->lockForUpdate()->first();abort_unless($v,404);abort_unless($v->status==='approved' && $v->approved_by && $v->effective_at,409,'Legal approval and effective date are required');
   $bytes=$this->pdf($id);$path='mro-legal/'.\Illuminate\Support\Str::uuid().'.pdf';abort_unless(Storage::disk('local')->put($path,$bytes),500);
   DB::table('mro_legal_versions')->where('id',$id)->update(['status'=>'published','published_at'=>now(),'pdf_path'=>$path,'pdf_sha256'=>hash('sha256',$bytes),'updated_at'=>now()]);PortalAudit::record($actor,null,'legal.published',"legal:$id",['sha256'=>$v->sha256]);});return $this->find($id);
 }
 public function current(string $locale,string $jurisdiction):array {
  abort_unless(in_array($locale,['ru','en','ge','de','fr','es'],true) && in_array($jurisdiction,['KZ','GE'],true),422);
  $translated=DB::table('mro_legal_versions')->where('locale',$locale)->where('jurisdiction',$jurisdiction)->where('status','published')->where('effective_at','<=',now())->exists();if(!$translated && $jurisdiction==='KZ')$locale='ru';
  return DB::table('mro_legal_versions as v')->where('v.status','published')->where('v.effective_at','<=',now())->where('v.locale',$locale)->where('v.jurisdiction',$jurisdiction)->whereNotExists(fn($q)=>$q->selectRaw('1')->from('mro_legal_versions as n')->whereColumn('n.type','v.type')->whereColumn('n.locale','v.locale')->whereColumn('n.jurisdiction','v.jurisdiction')->where('n.status','published')->where('n.effective_at','<=',now())->whereColumn('n.version','>','v.version'))->orderBy('v.type')->get(['v.id','v.type','v.locale','v.jurisdiction','v.version','v.title','v.sha256','v.acceptance','v.required','v.effective_at'])->all();
 }
 public function accept(User $user,array $choices,string $source,string $locale,string $jurisdiction,int $companyId):void {
  abort_unless(DB::table('company_user')->where('company_id',$companyId)->where('user_id',$user->id)->where('is_active',true)->exists(),403);
  $docs=$this->current($locale,$jurisdiction);
  DB::transaction(function()use($docs,$user,$choices,$source,$locale,$companyId) {
   foreach($docs as $doc) {
    $selected=($choices[$doc->type]??false)===true;
    abort_if($doc->required && !$selected,422,'Review the published legal terms: '.$doc->type);
    if(!$selected)continue;
    DB::table('mro_legal_consents')->insertOrIgnore([
      'user_id'=>$user->id,'company_id'=>$companyId,'legal_version_id'=>$doc->id,
      'sha256'=>$doc->sha256,'action'=>$doc->acceptance,'source'=>$source,
      'locale'=>$locale,'created_at'=>now(),
    ]);
   }
  });
 }
 public function pdf(int $id):string {
  $v=$this->find($id);
  if($v->pdf_path){$bytes=Storage::disk('local')->get($v->pdf_path);abort_unless(hash_equals($v->pdf_sha256,hash('sha256',$bytes)),409,'Document integrity check failed');return $bytes;}
  return app(InvoicePdfService::class)->render('billing.legal',['document'=>$v]);
 }
}
