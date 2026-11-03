<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirAssetReading;
use App\Models\ToirCrew;
use App\Models\ToirCrewShift;
use App\Models\ToirEmployee;
use App\Models\ToirWorkOrder;
use App\Services\ToirPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ToirOperationsController extends Controller
{
    public function __construct(private readonly ToirPlanningService $planning) {}

    private function company(Request $request): Company
    {
        $company=$request->user()->activeCompanies()
            ->whereKey($request->session()->get('toir_company_id'))->first();
        abort_unless($company,403,'Нет доступа к компании.');
        return $company;
    }

    private function edit(Company $company,bool $engineering=false): void
    {
        $roles=$engineering?['owner','admin','manager','engineer']:['owner','admin','manager'];
        abort_unless(in_array($company->pivot->role,$roles,true),403,'Недостаточно прав.');
    }

    public function assetTree(Request $request): JsonResponse
    {
        $company=$this->company($request);
        $assets=ToirAsset::where('company_id',$company->id)->orderBy('id')->get();
        return response()->json(['data'=>$assets,'total'=>$assets->count()]);
    }

    public function recordReading(Request $request,int $id): JsonResponse
    {
        $company=$this->company($request);
        $this->edit($company,true);
        $values=$request->validate([
            'meter'=>['required',Rule::in(['operating_hours','condition_score'])],
            'value'=>['required','numeric','min:0','max:999999999'],
            'recorded_at'=>['required','date','before_or_equal:'.now()->addMinutes(5)->toDateTimeString()],
            'note'=>['nullable','string','max:255'],
        ]);
        if($values['meter']==='condition_score' &&
            ((float)$values['value']>100 || (float)$values['value']!=floor((float)$values['value']))){
            throw ValidationException::withMessages(['value'=>'Состояние задаётся целым числом от 0 до 100.']);
        }
        $entry=DB::transaction(function()use($request,$company,$id,$values){
            $asset=ToirAsset::where('company_id',$company->id)->lockForUpdate()->findOrFail($id);
            $previous=ToirAssetReading::where('company_id',$company->id)
                ->where('asset_id',$asset->id)->where('meter',$values['meter'])
                ->orderByDesc('recorded_at')->orderByDesc('id')->first();
            $at=Carbon::parse($values['recorded_at']);
            if($previous && $at->lte(Carbon::parse($previous->recorded_at))){
                throw ValidationException::withMessages(['recorded_at'=>'Время нового показания должно быть позже предыдущего.']);
            }
            if($values['meter']==='operating_hours' &&
                ((float)$values['value'] < (float)$asset->operating_hours ||
                 ($previous && (float)$values['value']<(float)$previous->value))){
                throw ValidationException::withMessages(['value'=>'Наработка не может уменьшаться.']);
            }
            $entry=ToirAssetReading::create([
                'company_id'=>$company->id,'asset_id'=>$asset->id,
                'user_id'=>$request->user()->id,'meter'=>$values['meter'],
                'value'=>$values['value'],'recorded_at'=>$at,
                'source'=>'manual','note'=>$values['note']??null,
            ]);
            if($values['meter']==='operating_hours')$asset->operating_hours=$values['value'];
            else{
                $asset->condition_score=(int)$values['value'];
                $asset->condition_recorded_at=$at;
            }
            $asset->save();
            return $entry;
        },3);
        return response()->json(['data'=>$entry],201);
    }

    public function readings(Request $request,int $id): JsonResponse
    {
        $company=$this->company($request);
        ToirAsset::where('company_id',$company->id)->findOrFail($id);
        return response()->json(['data'=>ToirAssetReading::where('company_id',$company->id)
            ->where('asset_id',$id)->orderByDesc('recorded_at')
            ->orderByDesc('id')->limit(50)->get()]);
    }

    public function risk(Request $request): JsonResponse
    {
        $company=$this->company($request);
        return response()->json($this->planning->risks($company->id));
    }

    public function forecast(Request $request): JsonResponse
    {
        $company=$this->company($request);
        $data=$request->validate(['days'=>['sometimes','integer','min:1','max:180']]);
        return response()->json($this->planning->forecast($company->id,(int)($data['days']??60)));
    }

    public function generate(Request $request): JsonResponse
    {
        $company=$this->company($request);
        $this->edit($company,true);
        $data=$request->validate([
            'days'=>['required','integer','min:1','max:90'],
            'dry_run'=>['sometimes','boolean'],
        ]);
        return response()->json(['data'=>$this->planning->generate(
            $request,$company,(int)$data['days'],(bool)($data['dry_run']??true)
        )]);
    }

    private function crew(Company $company,int $id): ToirCrew
    {
        return ToirCrew::where('company_id',$company->id)->findOrFail($id);
    }

    public function crews(Request $request): JsonResponse
    {
        $company=$this->company($request);
        return response()->json(['data'=>ToirCrew::where('company_id',$company->id)
            ->with(['lead','members'])->withCount('shifts')
            ->orderBy('name')->get()]);
    }

    public function createCrew(Request $request): JsonResponse
    {
        $company=$this->company($request);
        $this->edit($company);
        $data=$request->validate([
            'name'=>['required','string','max:200'],
            'code'=>['required','string','max:64',
                Rule::unique('toir_crews','code')->where('company_id',$company->id)],
            'lead_employee_id'=>['nullable','integer',
                Rule::exists('toir_employees','id')->where('company_id',$company->id)
                    ->whereNull('deleted_at')->where('status','active')],
        ]);
        $crew=DB::transaction(function()use($company,$data){
            $crew=ToirCrew::create($data+['company_id'=>$company->id,'status'=>'active']);
            if(!empty($data['lead_employee_id'])){
                $crew->members()->attach($data['lead_employee_id'],[
                    'role'=>'lead','company_id'=>$company->id,
                ]);
            }
            return $crew;
        },3);
        return response()->json(['data'=>$crew->load(['members','lead'])],201);
    }

    public function updateCrew(Request $request,int $id): JsonResponse
    {
        $company=$this->company($request);
        $this->edit($company);
        $crew=$this->crew($company,$id);
        $data=$request->validate([
            'name'=>['sometimes','required','string','max:200'],
            'status'=>['sometimes',Rule::in(['active','inactive'])],
            'lead_employee_id'=>['nullable','integer',
                Rule::exists('toir_employees','id')->where('company_id',$company->id)
                    ->whereNull('deleted_at')->where('status','active')],
        ]);
        if(($data['status']??null)==='inactive'){
            if(ToirWorkOrder::where('company_id',$company->id)->where('crew_id',$id)
                ->whereNotIn('status',['closed'])->exists()){
                return response()->json(['message'=>'У бригады есть незавершённые наряды.'],409);
            }
        }
        DB::transaction(function()use($crew,$data,$company){
            $crew->fill($data)->save();
            if(array_key_exists('lead_employee_id',$data) && $data['lead_employee_id']){
                if(!$crew->members()->whereKey($data['lead_employee_id'])->exists()){
                    $crew->members()->attach($data['lead_employee_id'],[
                        'role'=>'lead','company_id'=>$company->id,
                    ]);
                }
                DB::table('toir_crew_members')->where('crew_id',$crew->id)
                    ->where('employee_id',$data['lead_employee_id'])->update(['role'=>'lead']);
            }
        },3);
        return response()->json(['data'=>$crew->refresh()->load(['members','lead'])]);
    }

    public function addCrewMember(Request $request,int $id): JsonResponse
    {
        $company=$this->company($request);
        $this->edit($company);
        $crew=$this->crew($company,$id);
        $data=$request->validate([
            'employee_id'=>['required','integer',
                Rule::exists('toir_employees','id')->where('company_id',$company->id)
                    ->whereNull('deleted_at')->where('status','active')],
        ]);
        $employeeId=(int)$data['employee_id'];
        DB::transaction(function()use($crew,$company,$employeeId){
            if(!$crew->members()->whereKey($employeeId)->exists()){
                $crew->members()->attach($employeeId,[
                    'role'=>'member','company_id'=>$company->id,
                ]);
            }
        },3);
        return response()->json(['data'=>$crew->refresh()->load(['members','lead'])]);
    }

    public function removeCrewMember(Request $request,int $id,int $employeeId): JsonResponse
    {
        $company=$this->company($request);
        $this->edit($company);
        $crew=$this->crew($company,$id);
        if((int)$crew->lead_employee_id===$employeeId){
            return response()->json(['message'=>'Сначала назначьте другого руководителя бригады.'],409);
        }
        $crew->members()->detach($employeeId);
        return response()->json(['data'=>$crew->refresh()->load('members')]);
    }

    public function shifts(Request $request): JsonResponse
    {
        $company=$this->company($request);
        $today=Carbon::today();
        $from=$request->query('from',$today->toDateString());
        $to=$request->query('to',$today->copy()->addDays(30)->toDateString());
        $dates=validator(['from'=>$from,'to'=>$to],[
            'from'=>['required','date_format:Y-m-d'],
            'to'=>['required','date_format:Y-m-d','after_or_equal:from'],
        ])->validate();
        if(Carbon::parse($dates['from'])->diffInDays(Carbon::parse($dates['to']))>180){
            throw ValidationException::withMessages(['to'=>'Период просмотра не больше 180 дней.']);
        }
        return response()->json(['data'=>ToirCrewShift::where('company_id',$company->id)
            ->whereBetween('work_date',[$dates['from'],$dates['to']])
            ->with('crew')->orderBy('work_date')->orderBy('starts_at')->limit(3000)->get()]);
    }

    /**
     * Weekly recurring shifts materialised for up to 62 days.
     * weekdays ISO: Monday=1 ... Sunday=7.
     */
    public function generateShifts(Request $request): JsonResponse
    {
        $company=$this->company($request);
        $this->edit($company);
        $data=$request->validate([
            'crew_id'=>['required','integer',Rule::exists('toir_crews','id')
                ->where('company_id',$company->id)->where('status','active')],
            'from'=>['required','date_format:Y-m-d'],
            'to'=>['required','date_format:Y-m-d','after_or_equal:from'],
            'weekdays'=>['required','array','min:1','max:7'],
            'weekdays.*'=>['required','integer','between:1,7','distinct'],
            'starts_at'=>['required','date_format:H:i'],
            'ends_at'=>['required','date_format:H:i','after:starts_at'],
            'note'=>['nullable','string','max:255'],
        ]);
        $start=Carbon::parse($data['from']);
        $end=Carbon::parse($data['to']);
        if($start->diffInDays($end)>62){
            throw ValidationException::withMessages(['to'=>'Создавайте смены партиями не более 62 дней.']);
        }
        $crew=$this->planning->ensureCrew($company->id,(int)$data['crew_id']);
        if(!$crew->members()->where('toir_employees.status','active')->exists()){
            throw ValidationException::withMessages(['crew_id'=>'Добавьте действующих сотрудников в бригаду.']);
        }
        $response=DB::transaction(function()use($company,$data,$start,$end){
            $result=['created'=>0,'existing'=>0,'blocked'=>[]];
            for($date=$start->copy();$date->lte($end);$date->addDay()){
                if(!in_array($date->isoWeekday(),$data['weekdays'],true))continue;
                $iso=$date->toDateString();
                $same=ToirCrewShift::where('company_id',$company->id)
                    ->where('crew_id',$data['crew_id'])->whereDate('work_date',$iso)->get();
                $from=$data['starts_at'].':00';$to=$data['ends_at'].':00';
                if($same->contains(fn($s)=>$s->starts_at===$from && $s->ends_at===$to)){
                    $result['existing']++;continue;
                }
                if($same->contains(fn($s)=>$s->starts_at<$to && $s->ends_at>$from)){
                    $result['blocked'][]=['date'=>$iso,'reason'=>'overlapping_shift'];
                    continue;
                }
                ToirCrewShift::create([
                    'company_id'=>$company->id,'crew_id'=>$data['crew_id'],
                    'work_date'=>$iso,'starts_at'=>$from,'ends_at'=>$to,
                    'status'=>'confirmed','note'=>$data['note']??null,
                ]);
                $result['created']++;
            }
            return $result;
        },3);
        return response()->json(['data'=>$response],201);
    }

    public function cancelShift(Request $request,int $id): JsonResponse
    {
        $company=$this->company($request);
        $this->edit($company);
        $shift=ToirCrewShift::where('company_id',$company->id)->findOrFail($id);
        $from=$shift->work_date.' '.$shift->starts_at;
        $to=$shift->work_date.' '.$shift->ends_at;
        $occupied=ToirWorkOrder::where('company_id',$company->id)
            ->where('crew_id',$shift->crew_id)->where('planned_start','<',$to)
            ->where('planned_end','>',$from)->exists();
        if($occupied)return response()->json([
            'message'=>'Перенесите наряды бригады перед удалением смены.',
        ],409);
        $shift->delete();
        return response()->json(['deleted'=>true]);
    }
}
