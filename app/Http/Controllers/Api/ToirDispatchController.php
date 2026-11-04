<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ToirCrew;
use App\Models\ToirEmployee;
use App\Models\ToirWorkOrder;
use App\Models\ToirWorkOrderEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ToirDispatchController extends Controller
{
    private function company(Request $request)
    {
        $company = $request->user()->activeCompanies()
            ->whereKey($request->session()->get('toir_company_id'))->first();
        abort_unless($company, 403, 'Нет доступа к компании.');

        return $company;
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        abort_unless(in_array($company->pivot->role, ['owner', 'admin', 'manager', 'engineer'], true), 403);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('toir_employees', 'id')
                ->where('company_id', $company->id)->where('status', 'active')->whereNull('deleted_at')],
            'planned_start' => ['nullable', 'date'],
            'planned_end' => ['nullable', 'date', 'after_or_equal:planned_start'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $order = DB::transaction(function () use ($company, $request, $id, $data) {
            $order = ToirWorkOrder::where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            if (! in_array($order->status, ['waiting', 'overdue'], true)) {
                throw new HttpException(409, 'Назначение возможно только до начала работ.');
            }
            $from = $data['planned_start'] ?? $order->planned_start;
            $to = $data['planned_end'] ?? $order->planned_end;
            if ($from && $to && strtotime((string) $to) < strtotime((string) $from)) {
                throw ValidationException::withMessages(['planned_end' => 'Окончание не может быть раньше начала.']);
            }
            $before = $order->employee_id;
            $order->employee_id = $data['employee_id'];
            if (array_key_exists('planned_start', $data)) {
                $order->planned_start = $data['planned_start'];
            }
            if (array_key_exists('planned_end', $data)) {
                $order->planned_end = $data['planned_end'];
            }
            $order->save();
            ToirWorkOrderEvent::forceCreate([
                'company_id' => $company->id, 'work_order_id' => $order->id,
                'user_id' => $request->user()->id, 'action' => 'assigned',
                'message' => $data['comment'] ?? null,
                'changes' => ['employee_from' => $before, 'employee_to' => $order->employee_id,
                    'planned_start' => $order->planned_start, 'planned_end' => $order->planned_end],
            ]);

            return $order->load(['employee', 'asset', 'events']);
        }, 3);

        return response()->json(['data' => $order]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        abort_unless(in_array($company->pivot->role, ['owner', 'admin', 'manager'], true), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'comment' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $order = DB::transaction(function () use ($company, $request, $id, $data) {
            $order = ToirWorkOrder::where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            if ($order->status !== 'awaiting_acceptance' || $order->type !== 'emergency') {
                throw new HttpException(409, 'Наряд не ожидает приёмки.');
            }
            if ($order->employee_id && ToirEmployee::where('company_id', $company->id)
                ->whereKey($order->employee_id)->where('user_id', $request->user()->id)->exists()) {
                abort(403, 'Исполнитель не может принимать собственную работу.');
            }
            if ($order->crew_id && ToirCrew::where('company_id', $company->id)
                ->whereKey($order->crew_id)
                ->whereHas('members', fn ($q) => $q->where('toir_employees.user_id', $request->user()->id))
                ->exists()) {
                abort(403, 'Участник бригады не может принимать собственную работу.');
            }
            $accepted = $data['decision'] === 'accept';
            $order->status = $accepted ? 'closed' : 'in_progress';
            if (! $accepted) {
                $order->actual_end = null;
            }
            $order->save();
            ToirWorkOrderEvent::forceCreate([
                'company_id' => $company->id, 'work_order_id' => $order->id,
                'user_id' => $request->user()->id, 'action' => $accepted ? 'accepted' : 'rejected',
                'message' => $data['comment'],
                'changes' => ['from' => 'awaiting_acceptance', 'to' => $order->status],
            ]);

            return $order->load(['employee', 'asset', 'events']);
        }, 3);

        return response()->json(['data' => $order]);
    }
}
