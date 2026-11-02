<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ToirMaintenancePlan;
use App\Models\ToirWorkOrder;
use App\Models\ToirWorkOrderEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ToirWorkflowService
{
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
        if ($action !== 'reopen' && $role === 'technician' &&
            $order->employee_id !== null &&
            $order->employee()->where('company_id', $company->id)
                ->where('user_id', $request->user()->id)
                ->where('status', 'active')->exists()) {
            return;
        }
        abort(403, 'Недостаточно прав для этой операции над нарядом.');
    }

    public function transition(Request $request, Company $company, int $id, string $action, ?string $comment): ToirWorkOrder
    {
        return DB::transaction(function () use ($request, $company, $id, $action, $comment): ToirWorkOrder {
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

            if ($action === 'start') {
                $errors = [];
                if (!$order->asset_id) $errors['asset_id'] = 'Для начала работ выберите актив.';
                if (!$order->employee_id) $errors['employee_id'] = 'Для начала работ назначьте исполнителя.';
                if ($order->asset_id && !$order->asset()->whereNotIn('status', ['retired'])->exists()) {
                    $errors['asset_id'] = 'Актив списан или недоступен для обслуживания.';
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
                $order->status = 'closed';
                $order->actual_end = now();
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
            ]);

            if ($action === 'complete' && $order->maintenance_plan_id !== null && $order->scheduled_for !== null) {
                $plan = ToirMaintenancePlan::where('company_id', $company->id)
                    ->lockForUpdate()->find($order->maintenance_plan_id);
                if ($plan && $plan->next_due_date === $order->scheduled_for) {
                    $plan->next_due_date = Carbon::parse($order->scheduled_for)
                        ->addDays((int) $plan->periodicity_days)->toDateString();
                    $plan->save();
                }
            }

            return $order->refresh()->load(['asset', 'employee', 'project', 'maintenancePlan', 'events']);
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
                return ['order' => $existing->load(['asset','employee','project','maintenancePlan','events']), 'created' => false];
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
            $order->initiator_id = $request->user()->id;
            $order->planned_start = $cycle.' 09:00:00';
            $order->planned_end = $cycle.' 17:00:00';
            $order->save();

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

            return ['order' => $order->load(['asset','employee','project','maintenancePlan','events']), 'created' => true];
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
