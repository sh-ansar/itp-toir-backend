<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ToirMaintenancePlan;
use App\Models\ToirWorkOrder;
use App\Models\ToirWorkOrderEvent;
use App\Models\ToirCrew;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ToirWorkflowService
{
    public function __construct(private readonly ToirPlanningService $planning,
        private readonly ToirChecklistService $checklist) {}
    private function role(Company $company): string
    {
        return (string) $company->pivot->role;
    }

    public function canManage(Request $request, Company $company, ToirWorkOrder $order, string $action): void
    {
        $role = $this->role($company);
        if (in_array($role, ['owner', 'admin', 'manager'], true)) {
            return;
        }
        if ($action !== 'reopen' && $role === 'engineer') {
            return;
        }
        if ($action !== 'reopen' && $role === 'technician' && $order->crew_id &&
            ToirCrew::where('company_id',$company->id)->whereKey($order->crew_id)
                ->whereHas('members',fn($q)=>$q->where('toir_employees.user_id',$request->user()->id)
                    ->where('toir_employees.status','active'))->exists()){
            return;
        }
        if ($action !== 'reopen' && $role === 'technician' &&
            $order->employee_id !== null &&
            $order->employee()->where('company_id', $company->id)
                ->where('user_id', $request->user()->id)
                ->where('status', 'active')->exists()) {
            return;
        }
        abort(403, 'Недостаточно прав для этой операции над нарядом.');
    }

    public function transition(Request $request, Company $company, int $id, string $action, ?string $comment, ?int $laborMinutes = null): ToirWorkOrder
    {
        return DB::transaction(function () use ($request, $company, $id, $action, $comment, $laborMinutes): ToirWorkOrder {
            $order = ToirWorkOrder::where('company_id', $company->id)
                ->lockForUpdate()->findOrFail($id);
            $this->canManage($request, $company, $order, $action);

            $before = (string) $order->status;
            $allowed = match ($action) {
                'start' => in_array($before, ['waiting', 'overdue'], true),
                'complete' => $before === 'in_progress',
                'reopen' => $before === 'closed',
                default => false,
            };
            if (!$allowed) {
                throw new HttpException(409, 'Недопустимый переход статуса наряда.');
            }

            if ($action === 'complete' && $order->materials()->where('quantity_reserved', '>', 0)->exists()) {
                throw new HttpException(409, 'Сначала спишите или снимите резерв материалов перед закрытием наряда.');
            }
            if ($action === 'start') {
                $errors = [];
                if (!$order->asset_id) $errors['asset_id'] = 'Для начала работ выберите актив.';
                if (!$order->employee_id && !$order->crew_id) $errors['employee_id'] = 'Для начала работ назначьте исполнителя.';
                if ($order->asset_id && !$order->asset()->whereNotIn('status', ['retired'])->exists()) {
                    $errors['asset_id'] = 'Актив списан или недоступен для обслуживания.';
                }
                if ($order->crew_id && !ToirCrew::where('company_id',$company->id)
                    ->whereKey($order->crew_id)->where('status','active')
                    ->whereHas('members',fn($q)=>$q->where('toir_employees.status','active'))->exists()){
                    $errors['crew_id']='Назначьте действующую бригаду с активными участниками.';
                }
                if ($order->employee_id && !$order->employee()->where('status', 'active')->exists()) {
                    $errors['employee_id'] = 'Назначенный исполнитель неактивен.';
                }
                if ($errors) throw ValidationException::withMessages($errors);

                $order->status = 'in_progress';
                $order->actual_start = now();
                $order->actual_end = null;
            } elseif ($action === 'complete') {
                if (!$order->actual_start) {
                    throw new HttpException(409, 'Начало работ не зафиксировано.');
                }
                if (!is_string($comment) || trim($comment) === '') {
                    throw ValidationException::withMessages([
                        'comment' => 'Укажите результат выполненных работ.',
                    ]);
                }
                $this->checklist->assertCompletable($order);
                // Corrective emergency repairs require independent acceptance before closure.
                $order->status = $order->type === 'emergency' ? 'awaiting_acceptance' : 'closed';
                $order->actual_end = now();
                if ($laborMinutes !== null) $order->actual_labor_minutes = (int) ($order->actual_labor_minutes ?? 0) + $laborMinutes;
            } else {
                if ($order->maintenance_plan_id !== null) {
                    throw new HttpException(409, 'Наряд из плана ТО нельзя открыть повторно после завершения цикла. Создайте новый наряд.');
                }
                if (!is_string($comment) || trim($comment) === '') {
                    throw ValidationException::withMessages([
                        'comment' => 'Укажите причину повторного открытия.',
                    ]);
                }
                $order->status = 'waiting';
                $order->actual_start = null;
                $order->actual_end = null;
            }

            $order->save();
            $this->event($company, $order, $request, $action, $comment, [
                'from' => $before, 'to' => (string) $order->status,
                'labor_minutes_added' => $action === 'complete' ? $laborMinutes : null,
            ]);

            if ($action === 'complete' && $order->maintenance_plan_id !== null && $order->scheduled_for !== null) {
                $plan = ToirMaintenancePlan::where('company_id', $company->id)
                    ->lockForUpdate()->find($order->maintenance_plan_id);
                if($plan){
                    $changed=false;
                    // A meter-based service must not push the calendar due date
                    // unless it also fulfils the current calendar cycle.
                    if($plan->next_due_date === $order->scheduled_for){
                        $next=Carbon::parse($order->scheduled_for)
                            ->addDays((int)$plan->periodicity_days);
                        for($step=0;$step<120;$step++){
                            $complete=ToirWorkOrder::where('company_id',$company->id)
                                ->where('maintenance_plan_id',$plan->id)
                                ->whereDate('scheduled_for',$next->toDateString())
                                ->where('status','closed')->exists();
                            if(!$complete)break;
                            $next->addDays((int)$plan->periodicity_days);
                        }
                        $plan->next_due_date=$next->toDateString();
                        $changed=true;
                    }
                    $thresholdMatches=$order->trigger_hours!==null
                        && $plan->next_due_hours!==null
                        && (float)$order->trigger_hours >= (float)$plan->next_due_hours;
                    if($plan->interval_hours!==null && ($changed || $thresholdMatches)){
                        $asset=$plan->asset()->where('company_id',$company->id)->first();
                        if($asset){
                            $plan->next_due_hours=round((float)$asset->operating_hours+
                                (float)$plan->interval_hours,2);
                            $changed=true;
                        }
                    }
                    if($changed)$plan->save();
                }
            }

            return $order->refresh()->load(['asset', 'employee', 'project', 'maintenancePlan', 'crew','events']);
        }, 3);
    }

    /**
     * Repeated issuance for the same maintenance cycle returns the same order.
     * next_due_date is advanced ONLY when the corresponding order is completed.
     *
     * @return array{order:ToirWorkOrder,created:bool}
     */
    public function issuePlan(Request $request, Company $company, int $id): array
    {
        if (!in_array($this->role($company), ['owner', 'admin', 'manager', 'engineer'], true)) {
            abort(403, 'Недостаточно прав для выпуска наряда по плану ТО.');
        }

        return DB::transaction(function () use ($request, $company, $id): array {
            $plan = ToirMaintenancePlan::where('company_id', $company->id)
                ->lockForUpdate()->findOrFail($id);
            if (!in_array((string) $plan->status, ['planned', 'active'], true)) {
                throw new HttpException(409, 'Для выпуска наряда план ТО должен быть запланирован или активен.');
            }
            if (!$plan->asset()->whereNotIn('status', ['retired'])->exists()) {
                throw new HttpException(409, 'Актив списан. Выпуск наряда по этому плану запрещён.');
            }
            $cycle = Carbon::parse($plan->next_due_date)->toDateString();

            $existing = ToirWorkOrder::where('company_id', $company->id)
                ->where('maintenance_plan_id', $plan->id)
                ->whereDate('scheduled_for', $cycle)
                ->first();

            if ($existing) {
                return ['order' => $existing->load(['asset','employee','project','maintenancePlan','crew','events']), 'created' => false];
            }

            $order = new ToirWorkOrder();
            $order->company_id = $company->id;
            $order->maintenance_plan_id = $plan->id;
            $order->scheduled_for = $cycle;
            $order->title = $plan->title;
            $order->description = $plan->description;
            $order->type = 'planned';
            $order->status = 'waiting';
            $order->asset_id = $plan->asset_id;
            $order->employee_id = $plan->employee_id;
            $order->crew_id = $plan->crew_id;
            $order->duration_minutes = $plan->duration_minutes;
            $order->trigger_source = 'calendar';
            $order->initiator_id = $request->user()->id;
            $duration=(int)$plan->duration_minutes;
            if($plan->crew_id){
                $slot=$this->planning->availableSlot($company->id,(int)$plan->crew_id,$cycle,$duration);
                if(!$slot){
                    throw ValidationException::withMessages(['crew_id'=>
                        'Создайте подтверждённую смену бригады со свободным временем на дату ТО.']);
                }
                [$order->planned_start,$order->planned_end]=$slot;
            }else{
                $order->planned_start=$cycle.' 09:00:00';
                $order->planned_end=Carbon::parse($order->planned_start)
                    ->addMinutes($duration)->toDateTimeString();
            }
            $order->save();
            $this->checklist->snapshot($plan,$order);

            $order->number = 'НЗ-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
            $order->save();

            if ($plan->status === 'planned') {
                $plan->status = 'active';
                $plan->save();
            }

            $this->event($company, $order, $request, 'issued_from_plan', 'Наряд сформирован из плана ТО.', [
                'maintenance_plan_id' => $plan->id,
                'scheduled_for' => $cycle,
            ]);

            return ['order' => $order->load(['asset','employee','project','maintenancePlan','crew','events']), 'created' => true];
        }, 3);
    }

    private function event(Company $company, ToirWorkOrder $order, Request $request,
        string $action, ?string $comment, array $changes): void
    {
        $event = new ToirWorkOrderEvent();
        $event->company_id = $company->id;
        $event->work_order_id = $order->id;
        $event->user_id = $request->user()->id;
        $event->action = $action;
        $event->message = $comment;
        $event->changes = $changes;
        $event->save();
    }
}
