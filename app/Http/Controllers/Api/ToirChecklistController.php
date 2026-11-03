<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ToirMaintenancePlan;
use App\Models\ToirMaintenanceStep;
use App\Models\ToirWorkOrder;
use App\Models\ToirWorkOrderEvent;
use App\Services\ToirWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ToirChecklistController extends Controller
{
    public function __construct(private readonly ToirWorkflowService $workflow) {}

    private function company(Request $request): Company
    {
        $company=$request->user()->activeCompanies()
            ->whereKey($request->session()->get('toir_company_id'))->first();
        abort_unless($company,403,'Нет доступа к компании.');
        return $company;
    }

    private function plan(Company $company,int $id): ToirMaintenancePlan
    {
        return ToirMaintenancePlan::where('company_id',$company->id)->findOrFail($id);
    }

    private function order(Company $company,int $id): ToirWorkOrder
    {
        return ToirWorkOrder::where('company_id',$company->id)->findOrFail($id);
    }

    private function managePlan(Company $company,ToirMaintenancePlan $plan): void
    {
        abort_unless(in_array($company->pivot->role,
            ['owner','admin','manager','engineer'],true),403,'Недостаточно прав.');
        abort_if($plan->status==='completed',409,
            'Завершённый регламент нельзя изменять.');
    }

    private function templateRules(bool $creating): array
    {
        $required=$creating?'required':'sometimes';
        return [
            'title'=>[$required,'string','min:3','max:200'],
            'kind'=>['sometimes',Rule::in(['inspection','service','safety','measurement'])],
            'instructions'=>['nullable','string','max:4000'],
            'unit'=>['nullable','string','max:30'],
            'is_required'=>['sometimes','boolean'],
        ];
    }

    public function planSteps(Request $request,int $planId): JsonResponse
    {
        $company=$this->company($request);
        $plan=$this->plan($company,$planId);
        return response()->json(['data'=>$plan->steps()->orderBy('position')->get()]);
    }

    public function createPlanStep(Request $request,int $planId): JsonResponse
    {
        $company=$this->company($request);
        $plan=$this->plan($company,$planId);
        $this->managePlan($company,$plan);
        $data=$request->validate($this->templateRules(true));
        $step=DB::transaction(function()use($company,$planId,$data){
            $plan=ToirMaintenancePlan::where('company_id',$company->id)
                ->lockForUpdate()->findOrFail($planId);
            $position=(int)$plan->steps()->max('position')+1;
            return ToirMaintenanceStep::forceCreate([
                'company_id'=>$company->id,'maintenance_plan_id'=>$plan->id,
                'position'=>$position,'title'=>$data['title'],
                'kind'=>$data['kind']??'inspection',
                'is_required'=>$data['is_required']??true,
                'instructions'=>$data['instructions']??null,'unit'=>$data['unit']??null,
            ]);
        },3);
        return response()->json(['data'=>$step],201);
    }

    public function updatePlanStep(Request $request,int $planId,int $stepId): JsonResponse
    {
        $company=$this->company($request);
        $plan=$this->plan($company,$planId);
        $this->managePlan($company,$plan);
        $step=$plan->steps()->where('company_id',$company->id)->findOrFail($stepId);
        $data=$request->validate($this->templateRules(false));
        $step->fill($data)->save();
        return response()->json(['data'=>$step->refresh()]);
    }

    public function deletePlanStep(Request $request,int $planId,int $stepId): JsonResponse
    {
        $company=$this->company($request);
        $plan=$this->plan($company,$planId);
        $this->managePlan($company,$plan);
        $step=$plan->steps()->where('company_id',$company->id)->findOrFail($stepId);
        $step->delete();
        return response()->json(['deleted'=>true]);
    }

    public function orderSteps(Request $request,int $orderId): JsonResponse
    {
        $company=$this->company($request);
        $order=$this->order($company,$orderId);
        return response()->json(['data'=>$order->steps()->with('performer')
            ->orderBy('position')->get()]);
    }

    public function recordOrderStep(Request $request,int $orderId,int $stepId): JsonResponse
    {
        $company=$this->company($request);
        $data=$request->validate([
            'status'=>['required',Rule::in(['pending','done','skipped'])],
            'numeric_value'=>['nullable','numeric','between:-999999999,999999999'],
            'comment'=>['nullable','string','max:2000'],
        ]);
        $row=DB::transaction(function()use($company,$request,$orderId,$stepId,$data){
            $order=ToirWorkOrder::where('company_id',$company->id)
                ->lockForUpdate()->findOrFail($orderId);
            $this->workflow->canManage($request,$company,$order,'complete');
            abort_unless($order->status==='in_progress',409,
                'Операции регламента отмечаются только во время выполнения наряда.');

            $step=$order->steps()->where('company_id',$company->id)
                ->lockForUpdate()->findOrFail($stepId);
            $status=$data['status'];
            $comment=trim((string)($data['comment']??''));
            $value=$data['numeric_value']??null;
            if($status==='skipped' && $step->is_required){
                throw ValidationException::withMessages([
                    'status'=>'Обязательный пункт регламента нельзя пропустить.',
                ]);
            }
            if($status==='skipped' && mb_strlen($comment)<3){
                throw ValidationException::withMessages([
                    'comment'=>'Укажите причину пропуска необязательной операции.',
                ]);
            }
            if($status==='done' && $step->kind==='measurement' && $value===null){
                throw ValidationException::withMessages([
                    'numeric_value'=>'Укажите фактическое числовое значение измерения.',
                ]);
            }
            if($status!=='done' && $value!==null){
                throw ValidationException::withMessages([
                    'numeric_value'=>'Измерение разрешено только при выполненной операции.',
                ]);
            }

            $before=['status'=>$step->status,'numeric_value'=>$step->numeric_value,
                'comment'=>$step->comment];
            $step->status=$status;
            $step->numeric_value=$status==='done'?$value:null;
            $step->comment=$comment?:null;
            $step->performed_by=$status==='pending'?null:$request->user()->id;
            $step->performed_at=$status==='pending'?null:now();
            $step->save();
            if($step->wasChanged()){
                ToirWorkOrderEvent::forceCreate([
                    'company_id'=>$company->id,'work_order_id'=>$order->id,
                    'user_id'=>$request->user()->id,'action'=>'checklist_step_updated',
                    'message'=>$step->title,'changes'=>[
                        'step_id'=>$step->id,'position'=>$step->position,
                        'before'=>$before,'after'=>[
                            'status'=>$step->status,'numeric_value'=>$step->numeric_value,
                            'comment'=>$step->comment,
                        ],
                    ],
                ]);
            }
            return $step->load('performer');
        },3);
        return response()->json(['data'=>$row]);
    }
}
